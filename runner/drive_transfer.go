package main

// Transfers of drive mode. Two paths behind one switch (the claim's drive.transfer, answered back):
//   proxy:  the runner itself GETs and PUTs presigned URLs that PHP issues, through the in-cluster transfer proxy.
//           Requests go to the proxy in absolute form over plain HTTP (https becomes http) with X-Transfer-Token,
//           which the proxy strips; it forwards only our bucket host signed with our key and opens TLS upstream.
//           The headers PHP signed into the URL are sent exactly as given. The default once a proxy is configured.
//   stream: today's path. PHP pushes blobs with PUT …/drive/blobs/{sha256} and reads snapshots with GET of the same
//           route; the runner opens no connection.
// The proxy address and token live in the runner process only: sessions get neither (their environment is built
// from scratch, README "Limits").

import (
	"crypto/md5"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"sync"
	"time"
)

var errTransfer = errors.New("transfer failed")

type transferClient struct {
	proxy  *url.URL
	token  string
	client *http.Client
}

func newTransferClient(config Config) (*transferClient, error) {
	if config.TransferProxyURL == "" {
		return nil, nil
	}
	proxy, err := url.Parse(config.TransferProxyURL)
	if err != nil || proxy.Scheme != "http" || proxy.Host == "" {
		return nil, fmt.Errorf("RUNNER_TRANSFER_PROXY_URL must be http://host:port")
	}
	transport := &http.Transport{
		Proxy:                 http.ProxyURL(proxy),
		MaxIdleConnsPerHost:   config.DriveTransferWorkers,
		ResponseHeaderTimeout: 60 * time.Second,
		IdleConnTimeout:       60 * time.Second,
	}
	return &transferClient{proxy: proxy, token: config.TransferProxyToken,
		client: &http.Client{Transport: transport, Timeout: 15 * time.Minute,
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}, nil
}

// request builds a request for a presigned URL in absolute form: the scheme becomes http (the proxy originates TLS);
// the signature covers host, path, query and the signed headers, not the scheme. headers are PHP's, sent unchanged.
func (client *transferClient) request(method, rawURL string, body io.Reader, size int64, headers map[string]string) (*http.Request, error) {
	target, err := url.Parse(rawURL)
	if err != nil || (target.Scheme != "https" && target.Scheme != "http") || target.Host == "" {
		return nil, fmt.Errorf("invalid transfer url")
	}
	target.Scheme = "http"
	request, err := http.NewRequest(method, target.String(), body)
	if err != nil {
		return nil, err
	}
	if body != nil {
		request.ContentLength = size
	}
	for name, value := range headers {
		if http.CanonicalHeaderKey(name) == "X-Transfer-Token" {
			continue
		}
		request.Header.Set(name, value)
	}
	if client.token != "" {
		request.Header.Set("X-Transfer-Token", client.token)
	}
	return request, nil
}

// download fetches one blob into the cache; its bytes must hash to sha.
func (client *transferClient) download(state *DriveState, sha string, size int64, rawURL string, limit int64) error {
	request, err := client.request(http.MethodGet, rawURL, nil, 0, nil)
	if err != nil {
		return errTransfer
	}
	response, err := client.client.Do(request)
	if err != nil {
		return errTransfer
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		io.Copy(io.Discard, io.LimitReader(response.Body, 64<<10))
		return errTransfer
	}
	if size > limit || response.ContentLength > limit {
		return errTransfer
	}
	return receiveBlob(state, sha, response.Body, limit)
}

// UploadItem is one presigned PUT of a staged snapshot.
// Offset and Length select one part of a multipart upload (Length 0: the whole blob).
type UploadItem struct {
	SHA256  string            `json:"sha256"`
	URL     string            `json:"url"`
	Headers map[string]string `json:"headers,omitempty"`
	Offset  int64             `json:"offset,omitempty"`
	Length  int64             `json:"length,omitempty"`
}

// UploadOutcome answers one upload (ETag: the store's answer, needed to complete a multipart upload).
type UploadOutcome struct {
	SHA256     string `json:"sha256"`
	Offset     int64  `json:"offset,omitempty"`
	ETag       string `json:"etag,omitempty"`
	OK         bool   `json:"ok"`
	StatusCode int    `json:"statusCode,omitempty"`
	Error      string `json:"error,omitempty"`
}

func (client *transferClient) upload(state *DriveState, item UploadItem) UploadOutcome {
	outcome := UploadOutcome{SHA256: item.SHA256}
	if !state.hasBlob(item.SHA256) {
		outcome.Error = "BLOB_NOT_STAGED"
		return outcome
	}
	file, err := os.Open(state.cachePath(item.SHA256))
	if err != nil {
		outcome.Error = "BLOB_NOT_STAGED"
		return outcome
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil {
		outcome.Error = "BLOB_NOT_STAGED"
		return outcome
	}
	offset, length := item.Offset, item.Length
	if length == 0 {
		length = info.Size() - offset
	}
	if offset < 0 || length < 0 || offset+length > info.Size() {
		outcome.Error = "INVALID_RANGE"
		return outcome
	}
	outcome.Offset = item.Offset
	// the presigned PUT carries a signed Content-MD5 (Hetzner verifies it, it ignores x-amz-checksum-sha256): check it
	// against the snapshot before sending, so a wrong URL fails here and not as a store refusal
	md5Sum := md5.New()
	if _, err := io.Copy(md5Sum, io.NewSectionReader(file, offset, length)); err != nil {
		outcome.Error = "BLOB_NOT_STAGED"
		return outcome
	}
	for name, value := range item.Headers {
		if http.CanonicalHeaderKey(name) == "Content-Md5" && value != base64.StdEncoding.EncodeToString(md5Sum.Sum(nil)) {
			outcome.Error = "CONTENT_MD5_MISMATCH"
			return outcome
		}
	}
	request, err := client.request(http.MethodPut, item.URL, io.NewSectionReader(file, offset, length), length, item.Headers)
	if err != nil {
		outcome.Error = "INVALID_URL"
		return outcome
	}
	response, err := client.client.Do(request)
	if err != nil {
		outcome.Error = "TRANSFER_FAILED"
		return outcome
	}
	io.Copy(io.Discard, io.LimitReader(response.Body, 64<<10))
	response.Body.Close()
	outcome.StatusCode = response.StatusCode
	outcome.ETag = response.Header.Get("ETag")
	outcome.OK = response.StatusCode >= 200 && response.StatusCode < 300
	if !outcome.OK {
		outcome.Error = "UPSTREAM_REFUSED"
	}
	return outcome
}

// Upload PUTs staged snapshots to presigned URLs (proxy transfer only); uploads read only from the snapshots.
func (session *Session) Upload(items []UploadItem) ([]UploadOutcome, error) {
	state := session.drive
	if state == nil {
		return nil, ErrDriveOff
	}
	client := session.runner.transfer
	if client == nil || state.Transfer != "proxy" {
		return nil, fmt.Errorf("no transfer proxy: read snapshots with GET …/drive/blobs/{sha256}")
	}
	outcomes := make([]UploadOutcome, len(items))
	work := make(chan int)
	var group sync.WaitGroup
	for worker := 0; worker < session.runner.config.DriveTransferWorkers; worker++ {
		group.Add(1)
		go func() {
			defer group.Done()
			for index := range work {
				outcomes[index] = client.upload(state, items[index])
			}
		}()
	}
	for index := range items {
		work <- index
	}
	close(work)
	group.Wait()
	return outcomes, nil
}

// prefetch downloads the blobs of changes that carry a URL and are not cached yet, in parallel (proxy transfer).
// Failures are reported per path; the apply step then reports the blob as needed.
func (session *Session) prefetch(state *DriveState, changes []DriveChange, result *PullResult) {
	client := session.runner.transfer
	if client == nil || state.Transfer != "proxy" {
		return
	}
	wanted := map[string]DriveChange{}
	for _, change := range changes {
		if change.URL != "" && validSHA256(change.SHA256) && !state.hasBlob(change.SHA256) {
			wanted[change.SHA256] = change
		}
	}
	if len(wanted) == 0 {
		return
	}
	work := make(chan DriveChange)
	var group sync.WaitGroup
	var mutex sync.Mutex
	limit := int64(session.runner.config.MaxFileBytes)
	for worker := 0; worker < session.runner.config.DriveTransferWorkers; worker++ {
		group.Add(1)
		go func() {
			defer group.Done()
			for change := range work {
				if err := client.download(state, change.SHA256, change.Size, change.URL, limit); err != nil {
					mutex.Lock()
					result.Failed = append(result.Failed, PathFailure{Path: change.Path, Reason: reasonOf(err)})
					mutex.Unlock()
				}
			}
		}()
	}
	for _, change := range wanted {
		work <- change
	}
	close(work)
	group.Wait()
}

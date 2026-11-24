package main

import (
	"bytes"
	"io"
	"strings"
	"sync"
	"unicode/utf8"
)

// OutputView is what the caller sees of one output stream: the head, and the tail when the stream was longer than
// head plus tail. Lengths are in characters (runes); Bytes is the full length of the stream.
type OutputView struct {
	Head      string `json:"head"`
	Tail      string `json:"tail,omitempty"`
	Bytes     int64  `json:"bytes"`
	Truncated bool   `json:"truncated"`
}

// streamCapture keeps the first and the last bytes of one stream: enough bytes for headLimit and tailLimit runes.
type streamCapture struct {
	headLimit int
	tailLimit int
	headCap   int
	tailCap   int
	head      []byte
	tail      []byte
	total     int64
}

func newStreamCapture(headLimit, tailLimit int) *streamCapture {
	return &streamCapture{
		headLimit: headLimit,
		tailLimit: tailLimit,
		headCap:   headLimit * utf8.UTFMax,
		tailCap:   tailLimit * utf8.UTFMax,
	}
}

func (capture *streamCapture) write(data []byte) {
	capture.total += int64(len(data))
	if room := capture.headCap - len(capture.head); room > 0 {
		count := min(room, len(data))
		capture.head = append(capture.head, data[:count]...)
		data = data[count:]
	}
	if len(data) == 0 || capture.tailCap == 0 {
		return
	}
	capture.tail = append(capture.tail, data...)
	if len(capture.tail) > 2*capture.tailCap {
		kept := make([]byte, capture.tailCap, 2*capture.tailCap)
		copy(kept, capture.tail[len(capture.tail)-capture.tailCap:])
		capture.tail = kept
	}
}

func (capture *streamCapture) view() OutputView {
	tail := capture.tail
	if len(tail) > capture.tailCap {
		tail = tail[len(tail)-capture.tailCap:]
	}
	if capture.total <= int64(len(capture.head)+len(tail)) {
		// nothing was dropped: the stream is complete in head + tail
		full := []rune(strings.ToValidUTF8(string(capture.head)+string(tail), "�"))
		if len(full) <= capture.headLimit+capture.tailLimit {
			return OutputView{Head: string(full), Bytes: capture.total}
		}
		return OutputView{
			Head:      string(full[:capture.headLimit]),
			Tail:      string(full[len(full)-capture.tailLimit:]),
			Bytes:     capture.total,
			Truncated: true,
		}
	}
	headRunes := []rune(strings.ToValidUTF8(string(trimIncompleteEnd(capture.head)), "�"))
	if len(headRunes) > capture.headLimit {
		headRunes = headRunes[:capture.headLimit]
	}
	tailRunes := []rune(strings.ToValidUTF8(string(trimIncompleteStart(tail)), "�"))
	if len(tailRunes) > capture.tailLimit {
		tailRunes = tailRunes[len(tailRunes)-capture.tailLimit:]
	}
	return OutputView{Head: string(headRunes), Tail: string(tailRunes), Bytes: capture.total, Truncated: true}
}

// trimIncompleteEnd drops a UTF-8 sequence cut off at the end.
func trimIncompleteEnd(data []byte) []byte {
	for back := 1; back <= utf8.UTFMax && back <= len(data); back++ {
		start := len(data) - back
		if utf8.RuneStart(data[start]) {
			if !utf8.FullRune(data[start:]) {
				return data[:start]
			}
			return data
		}
	}
	return data
}

// trimIncompleteStart drops continuation bytes at the start (a sequence whose beginning was cut off).
func trimIncompleteStart(data []byte) []byte {
	for skipped := 0; skipped < utf8.UTFMax && len(data) > 0 && !utf8.RuneStart(data[0]); skipped++ {
		data = data[1:]
	}
	return data
}

// SpillOpener opens the spill file of one exec or job; it is called at most once, and only when needed.
type SpillOpener func() (io.WriteCloser, error)

// Recorder captures stdout and stderr of one exec or job. It keeps head and tail per stream and the combined output
// in arrival order in memory; when a stream is truncated, the combined output goes to the spill file. Beyond
// SpillMemBytes the spill file is opened early and written as the output arrives.
type Recorder struct {
	mutex       sync.Mutex
	stdout      *streamCapture
	stderr      *streamCapture
	memory      []byte
	memoryLimit int
	openSpill   SpillOpener
	spillPath   string
	spill       io.WriteCloser
	spillOpened bool
	spillFailed bool
	// noSpace: a stream said "No space left on device" (ENOSPC of a full workspace tmpfs)
	noSpace     bool
	noSpaceTail [2][]byte
}

// NoSpaceMessage is what tools print for ENOSPC; the runner reads it as the hard workspace limit.
const NoSpaceMessage = "No space left on device"

// scanNoSpace looks for NoSpaceMessage, also across chunk boundaries; the caller holds the mutex.
func (recorder *Recorder) scanNoSpace(stream int, data []byte) {
	if recorder.noSpace {
		return
	}
	window := append(recorder.noSpaceTail[stream], data...)
	if bytes.Contains(window, []byte(NoSpaceMessage)) {
		recorder.noSpace = true
		return
	}
	keep := len(NoSpaceMessage) - 1
	if len(window) > keep {
		window = window[len(window)-keep:]
	}
	recorder.noSpaceTail[stream] = append([]byte(nil), window...)
}

// NewRecorder creates a recorder; openSpill may be nil (no spill file).
func NewRecorder(headChars, tailChars, memoryLimit int, spillPath string, openSpill SpillOpener) *Recorder {
	return &Recorder{
		stdout:      newStreamCapture(headChars, tailChars),
		stderr:      newStreamCapture(headChars, tailChars),
		memoryLimit: memoryLimit,
		openSpill:   openSpill,
		spillPath:   spillPath,
	}
}

type recorderStream struct {
	recorder *Recorder
	stderr   bool
}

func (stream recorderStream) Write(data []byte) (int, error) {
	stream.recorder.write(stream.stderr, data)
	return len(data), nil
}

// Stdout is the writer of the stdout stream.
func (recorder *Recorder) Stdout() io.Writer { return recorderStream{recorder: recorder} }

// Stderr is the writer of the stderr stream.
func (recorder *Recorder) Stderr() io.Writer { return recorderStream{recorder: recorder, stderr: true} }

func (recorder *Recorder) write(stderr bool, data []byte) {
	recorder.mutex.Lock()
	defer recorder.mutex.Unlock()
	if stderr {
		recorder.stderr.write(data)
		recorder.scanNoSpace(1, data)
	} else {
		recorder.stdout.write(data)
		recorder.scanNoSpace(0, data)
	}
	if recorder.spill != nil {
		recorder.writeSpill(data)
		return
	}
	if recorder.spillFailed {
		return
	}
	if len(recorder.memory)+len(data) <= recorder.memoryLimit {
		recorder.memory = append(recorder.memory, data...)
		return
	}
	recorder.startSpill()
	recorder.writeSpill(data)
}

// startSpill opens the spill file and writes what is in memory; the caller holds the mutex.
func (recorder *Recorder) startSpill() {
	if recorder.spill != nil || recorder.spillFailed {
		return
	}
	if recorder.openSpill == nil {
		recorder.spillFailed = true
		recorder.memory = nil
		return
	}
	spill, err := recorder.openSpill()
	if err != nil {
		recorder.spillFailed = true
		recorder.memory = nil
		return
	}
	recorder.spill = spill
	recorder.spillOpened = true
	recorder.writeSpill(recorder.memory)
	recorder.memory = nil
}

func (recorder *Recorder) writeSpill(data []byte) {
	if recorder.spill == nil || len(data) == 0 {
		return
	}
	if _, err := recorder.spill.Write(data); err != nil {
		recorder.spillFailed = true
		recorder.spill.Close()
		recorder.spill = nil
	}
}

// RecordedOutput is the result of a finished exec or job.
type RecordedOutput struct {
	Stdout        OutputView `json:"stdout"`
	Stderr        OutputView `json:"stderr"`
	SpillPath     string     `json:"spillPath,omitempty"`
	SpillComplete bool       `json:"spillComplete,omitempty"`
	// noSpace: the output reported ENOSPC
	noSpace bool
}

// Snapshot answers the current state without closing anything (running jobs).
func (recorder *Recorder) Snapshot() RecordedOutput {
	recorder.mutex.Lock()
	defer recorder.mutex.Unlock()
	output := RecordedOutput{Stdout: recorder.stdout.view(), Stderr: recorder.stderr.view()}
	if recorder.spill != nil {
		output.SpillPath = recorder.spillPath
	}
	return output
}

// Finish closes the recorder: a truncated stream gets the spill file (written now if it was not started yet).
func (recorder *Recorder) Finish() RecordedOutput {
	recorder.mutex.Lock()
	defer recorder.mutex.Unlock()
	output := RecordedOutput{Stdout: recorder.stdout.view(), Stderr: recorder.stderr.view()}
	truncated := output.Stdout.Truncated || output.Stderr.Truncated
	if truncated && recorder.spill == nil && !recorder.spillFailed {
		recorder.startSpill()
	}
	if recorder.spill != nil {
		if err := recorder.spill.Close(); err != nil {
			recorder.spillFailed = true
		}
		recorder.spill = nil
	}
	recorder.memory = nil
	output.noSpace = recorder.noSpace
	if recorder.spillOpened && truncated {
		output.SpillPath = recorder.spillPath
		output.SpillComplete = !recorder.spillFailed
	}
	return output
}

// limitedBuffer keeps at most limit bytes and remembers whether more arrived.
type limitedBuffer struct {
	mutex    sync.Mutex
	data     []byte
	limit    int
	overflow bool
}

func (buffer *limitedBuffer) Write(data []byte) (int, error) {
	buffer.mutex.Lock()
	defer buffer.mutex.Unlock()
	written := len(data)
	room := buffer.limit - len(buffer.data)
	if len(data) > room {
		buffer.overflow = true
		data = data[:max(room, 0)]
	}
	buffer.data = append(buffer.data, data...)
	return written, nil
}

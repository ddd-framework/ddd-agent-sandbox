package main

import (
	"os"
	"strings"
)

// contractVersion is the version of mgamadeus/ddd-agent-sandbox this binary belongs to, set at build time
// (-ldflags "-X main.contractVersion=X.Y.Z", the release workflow passes the tag's version). GET /healthz reports it.
var contractVersion = "0.0.0-dev"

const (
	// toolkitFile names the toolkit the image was built with (base or full), written by the Dockerfile
	toolkitFile = "/etc/sandbox-toolkit"
	// imageConfigHashFile holds the hash of the project's image.yaml a project image was built from (absent in the
	// module's own image); app:agent-sandbox:check-runner compares it with the project's file
	imageConfigHashFile = "/etc/sandbox-image-config-hash"
)

// readImageFact reads a one-line fact the image build wrote, or the fallback when the file is missing.
func readImageFact(path string, fallback string) string {
	content, err := os.ReadFile(path)
	if err != nil {
		return fallback
	}
	value := strings.TrimSpace(string(content))
	if value == "" {
		return fallback
	}
	return value
}

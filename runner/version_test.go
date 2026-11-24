package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestReadImageFactReadsTheFirstLineOrFallsBack(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "fact")
	if got := readImageFact(path, "fallback"); got != "fallback" {
		t.Fatalf("missing file: got %q", got)
	}
	if err := os.WriteFile(path, []byte("full\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if got := readImageFact(path, "fallback"); got != "full" {
		t.Fatalf("got %q, want full", got)
	}
	if err := os.WriteFile(path, []byte("  \n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if got := readImageFact(path, "fallback"); got != "fallback" {
		t.Fatalf("blank file: got %q", got)
	}
}

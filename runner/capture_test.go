package main

import (
	"bytes"
	"io"
	"strings"
	"testing"
	"unicode/utf8"
)

type memorySpill struct {
	buffer *bytes.Buffer
	closed bool
}

func (spill *memorySpill) Write(data []byte) (int, error) { return spill.buffer.Write(data) }
func (spill *memorySpill) Close() error                   { spill.closed = true; return nil }

func newTestRecorder(head, tail, memory int) (*Recorder, *bytes.Buffer, *int) {
	buffer := &bytes.Buffer{}
	opened := 0
	recorder := NewRecorder(head, tail, memory, "/workspace/.runner/out-1.log", func() (io.WriteCloser, error) {
		opened++
		return &memorySpill{buffer: buffer}, nil
	})
	return recorder, buffer, &opened
}

func TestShortOutputIsComplete(t *testing.T) {
	recorder, _, opened := newTestRecorder(10, 10, 1<<20)
	recorder.Stdout().Write([]byte("hello\n"))
	recorder.Stderr().Write([]byte("warn\n"))
	output := recorder.Finish()
	if output.Stdout.Head != "hello\n" || output.Stdout.Truncated || output.Stdout.Tail != "" || output.Stdout.Bytes != 6 {
		t.Fatalf("stdout = %+v", output.Stdout)
	}
	if output.Stderr.Head != "warn\n" || output.Stderr.Truncated {
		t.Fatalf("stderr = %+v", output.Stderr)
	}
	if output.SpillPath != "" || *opened != 0 {
		t.Fatalf("no spill expected, got %q (opened %d)", output.SpillPath, *opened)
	}
}

func TestExactlyHeadPlusTailIsNotTruncated(t *testing.T) {
	recorder, _, _ := newTestRecorder(5, 5, 1<<20)
	recorder.Stdout().Write([]byte("0123456789"))
	output := recorder.Finish()
	if output.Stdout.Truncated || output.Stdout.Head != "0123456789" {
		t.Fatalf("stdout = %+v", output.Stdout)
	}
}

func TestLongOutputKeepsHeadAndTailAndSpills(t *testing.T) {
	recorder, spill, opened := newTestRecorder(2000, 2000, 1<<20)
	var full strings.Builder
	for line := 0; line < 5000; line++ {
		text := strings.Repeat("x", 9) + "\n"
		if line == 0 {
			text = "FIRST-LINE\n"
		}
		if line == 4999 {
			text = "LAST-LINE\n"
		}
		full.WriteString(text)
		recorder.Stdout().Write([]byte(text))
	}
	output := recorder.Finish()
	if !output.Stdout.Truncated {
		t.Fatal("expected truncation")
	}
	if utf8.RuneCountInString(output.Stdout.Head) != 2000 || utf8.RuneCountInString(output.Stdout.Tail) != 2000 {
		t.Fatalf("head %d, tail %d characters", utf8.RuneCountInString(output.Stdout.Head), utf8.RuneCountInString(output.Stdout.Tail))
	}
	if !strings.HasPrefix(output.Stdout.Head, "FIRST-LINE\n") || !strings.HasSuffix(output.Stdout.Tail, "LAST-LINE\n") {
		t.Fatal("head or tail misses the first or last line")
	}
	if output.Stdout.Bytes != int64(full.Len()) {
		t.Fatalf("bytes = %d, want %d", output.Stdout.Bytes, full.Len())
	}
	if *opened != 1 || spill.String() != full.String() || output.SpillPath == "" || !output.SpillComplete {
		t.Fatalf("spill: opened %d, %d bytes, path %q, complete %v", *opened, spill.Len(), output.SpillPath, output.SpillComplete)
	}
}

func TestSpillStartsEarlyBeyondMemoryLimit(t *testing.T) {
	recorder, spill, opened := newTestRecorder(10, 10, 100)
	recorder.Stdout().Write(bytes.Repeat([]byte("a"), 60))
	recorder.Stderr().Write(bytes.Repeat([]byte("b"), 60))
	if *opened != 1 {
		t.Fatalf("spill should open once the memory limit is passed, opened %d", *opened)
	}
	recorder.Stdout().Write([]byte("end"))
	output := recorder.Finish()
	want := strings.Repeat("a", 60) + strings.Repeat("b", 60) + "end"
	if spill.String() != want {
		t.Fatalf("spill holds the combined output in arrival order; got %d bytes", spill.Len())
	}
	if !output.Stdout.Truncated || output.Stdout.Tail != "aaaaaaaend" {
		t.Fatalf("stdout = %+v", output.Stdout)
	}
}

func TestMultibyteCharactersAreNotCut(t *testing.T) {
	recorder, _, _ := newTestRecorder(3, 3, 1<<20)
	text := strings.Repeat("ä€😀", 50)
	// write byte by byte, splitting every multi-byte sequence
	for index := 0; index < len(text); index++ {
		recorder.Stdout().Write([]byte{text[index]})
	}
	output := recorder.Finish()
	if !utf8.ValidString(output.Stdout.Head) || !utf8.ValidString(output.Stdout.Tail) {
		t.Fatal("invalid UTF-8 in head or tail")
	}
	if output.Stdout.Head != "ä€😀" || output.Stdout.Tail != "ä€😀" {
		t.Fatalf("head %q tail %q", output.Stdout.Head, output.Stdout.Tail)
	}
	if strings.ContainsRune(output.Stdout.Head+output.Stdout.Tail, utf8.RuneError) {
		t.Fatal("replacement character in output")
	}
}

func TestMarkerReaderSplitsAcrossChunks(t *testing.T) {
	reader := &markerReader{}
	var first, second bytes.Buffer
	done := reader.begin([]byte("__TOKEN__"), &first)
	reader.feed([]byte("output of one__TO"))
	reader.feed([]byte("KEN__rest"))
	<-done
	if first.String() != "output of one" {
		t.Fatalf("first = %q", first.String())
	}
	reader.feed([]byte(" from background"))
	done = reader.begin([]byte("__NEXT__"), &second)
	reader.feed([]byte("!__NEXT__"))
	<-done
	if second.String() != "rest from background!" {
		t.Fatalf("second = %q", second.String())
	}
}

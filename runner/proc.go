package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"os"
	"strconv"
	"strings"
	"syscall"
)

func randomHex(size int) string {
	buffer := make([]byte, size)
	rand.Read(buffer)
	return hex.EncodeToString(buffer)
}

// processChildren maps every parent pid to its children, from /proc (works under gVisor).
func processChildren() map[int][]int {
	children := map[int][]int{}
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return children
	}
	for _, entry := range entries {
		pid, err := strconv.Atoi(entry.Name())
		if err != nil {
			continue
		}
		data, err := os.ReadFile("/proc/" + entry.Name() + "/stat")
		if err != nil {
			continue
		}
		// "pid (comm) state ppid …": comm may contain spaces and parentheses
		closing := bytes.LastIndexByte(data, ')')
		if closing < 0 || closing+2 > len(data) {
			continue
		}
		fields := strings.Fields(string(data[closing+2:]))
		if len(fields) < 2 {
			continue
		}
		parent, err := strconv.Atoi(fields[1])
		if err != nil {
			continue
		}
		children[parent] = append(children[parent], pid)
	}
	return children
}

// descendantSet answers every descendant of root.
func descendantSet(root int) map[int]bool {
	children := processChildren()
	found := map[int]bool{}
	queue := []int{root}
	for len(queue) > 0 {
		current := queue[0]
		queue = queue[1:]
		for _, child := range children[current] {
			if !found[child] {
				found[child] = true
				queue = append(queue, child)
			}
		}
	}
	return found
}

// killNewDescendants kills every descendant of root that was not in before: the processes of the command that timed
// out. Background jobs started by earlier commands survive. A few rounds catch processes forked meanwhile.
func killNewDescendants(root int, before map[int]bool) {
	for round := 0; round < 3; round++ {
		killed := 0
		for pid := range descendantSet(root) {
			if !before[pid] {
				syscall.Kill(pid, syscall.SIGKILL)
				killed++
			}
		}
		if killed == 0 {
			return
		}
	}
}

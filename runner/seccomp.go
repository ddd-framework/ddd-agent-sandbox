package main

// The seccomp filter of every session process (README "Session isolation"). gVisor already confines the sandbox as a
// whole, but inside it a session uid could create a user namespace (`unshare --user --mount --map-root-user`) and, as
// its root, mount tmpfs or its own FUSE, set up network namespaces and reach more of the Sentry's code. The helper
// therefore installs this filter (with no_new_privs) right before it executes the session's bash, and before every
// other helper operation that runs as the session uid; every process of the session inherits it and cannot remove it.
//
// Refused with EPERM: unshare and clone with any namespace flag, setns, mount and the new mount API, umount2,
// pivot_root, chroot, keyctl/add_key/request_key, bpf, perf_event_open, ptrace (no tracing at all: no tool of the
// toolkit needs it), and foreign architectures. clone3 answers ENOSYS: its flags sit in a struct the filter cannot
// read, and glibc (fork, posix_spawn, pthread_create) falls back to clone on ENOSYS.

import (
	"fmt"
	"unsafe"

	"golang.org/x/sys/unix"
)

// Namespace flags of clone and unshare.
const (
	seccompCloneNamespaces = unix.CLONE_NEWUSER | unix.CLONE_NEWNS | unix.CLONE_NEWNET | unix.CLONE_NEWPID |
		unix.CLONE_NEWUTS | unix.CLONE_NEWIPC | unix.CLONE_NEWCGROUP
	// CLONE_NEWTIME (0x80) is only valid for unshare: for clone the low byte is the exit signal
	seccompUnshareNamespaces = seccompCloneNamespaces | unix.CLONE_NEWTIME
)

// seccompDenied are the system calls refused whatever their arguments.
var seccompDenied = []uint32{
	unix.SYS_SETNS, unix.SYS_MOUNT, unix.SYS_UMOUNT2, unix.SYS_PIVOT_ROOT, unix.SYS_CHROOT,
	unix.SYS_OPEN_TREE, unix.SYS_MOVE_MOUNT, unix.SYS_FSOPEN, unix.SYS_FSCONFIG, unix.SYS_FSMOUNT, unix.SYS_FSPICK,
	unix.SYS_MOUNT_SETATTR,
	unix.SYS_KEYCTL, unix.SYS_ADD_KEY, unix.SYS_REQUEST_KEY,
	unix.SYS_BPF, unix.SYS_PERF_EVENT_OPEN, unix.SYS_PTRACE,
}

// BPF opcodes of the classic filter language.
const (
	bpfLoadWord  = unix.BPF_LD | unix.BPF_W | unix.BPF_ABS
	bpfJumpEqual = unix.BPF_JMP | unix.BPF_JEQ | unix.BPF_K
	bpfJumpSet   = unix.BPF_JMP | unix.BPF_JSET | unix.BPF_K
	bpfJumpAbove = unix.BPF_JMP | unix.BPF_JGE | unix.BPF_K
	bpfReturn    = unix.BPF_RET | unix.BPF_K
	// offsets in struct seccomp_data: nr, arch, instruction pointer, then args[6] (the low word first: little endian)
	seccompOffsetNR    = 0
	seccompOffsetArch  = 4
	seccompOffsetArg0  = 16
	seccompReturnAllow = unix.SECCOMP_RET_ALLOW
)

func seccompErrno(errno unix.Errno) uint32 {
	return unix.SECCOMP_RET_ERRNO | uint32(errno)
}

// filterBuilder assembles a classic BPF program with forward jumps to labels.
type filterBuilder struct {
	program []unix.SockFilter
	// pending: instruction index → label of its true branch
	pending map[int]string
	labels  map[string]int
}

func (builder *filterBuilder) statement(code uint16, value uint32) {
	builder.program = append(builder.program, unix.SockFilter{Code: code, K: value})
}

// jumpTo adds a conditional jump whose true branch goes to label; false falls through.
func (builder *filterBuilder) jumpTo(code uint16, value uint32, label string) {
	builder.pending[len(builder.program)] = label
	builder.program = append(builder.program, unix.SockFilter{Code: code, K: value})
}

func (builder *filterBuilder) label(name string) {
	builder.labels[name] = len(builder.program)
}

func (builder *filterBuilder) resolve() ([]unix.SockFilter, error) {
	for index, name := range builder.pending {
		target, ok := builder.labels[name]
		distance := target - index - 1
		if !ok || distance < 0 || distance > 255 {
			return nil, fmt.Errorf("seccomp: jump to %s out of range", name)
		}
		builder.program[index].Jt = uint8(distance)
	}
	return builder.program, nil
}

// sessionSeccompProgram builds the filter for the runner's own architecture.
func sessionSeccompProgram() ([]unix.SockFilter, error) {
	builder := &filterBuilder{pending: map[int]string{}, labels: map[string]int{}}
	builder.statement(bpfLoadWord, seccompOffsetArch)
	builder.jumpTo(bpfJumpEqual, seccompArch, "native")
	builder.statement(bpfReturn, seccompErrno(unix.ENOSYS))
	builder.label("native")
	builder.statement(bpfLoadWord, seccompOffsetNR)
	if seccompX32Bit != 0 {
		builder.jumpTo(bpfJumpAbove, seccompX32Bit, "enosys")
	}
	builder.jumpTo(bpfJumpEqual, unix.SYS_CLONE3, "enosys")
	builder.jumpTo(bpfJumpEqual, unix.SYS_UNSHARE, "unshare")
	builder.jumpTo(bpfJumpEqual, unix.SYS_CLONE, "clone")
	for _, number := range seccompDenied {
		builder.jumpTo(bpfJumpEqual, number, "eperm")
	}
	builder.statement(bpfReturn, seccompReturnAllow)
	builder.label("unshare")
	builder.statement(bpfLoadWord, seccompOffsetArg0)
	builder.jumpTo(bpfJumpSet, seccompUnshareNamespaces, "eperm")
	builder.statement(bpfReturn, seccompReturnAllow)
	builder.label("clone")
	builder.statement(bpfLoadWord, seccompOffsetArg0)
	builder.jumpTo(bpfJumpSet, seccompCloneNamespaces, "eperm")
	builder.statement(bpfReturn, seccompReturnAllow)
	builder.label("enosys")
	builder.statement(bpfReturn, seccompErrno(unix.ENOSYS))
	builder.label("eperm")
	builder.statement(bpfReturn, seccompErrno(unix.EPERM))
	return builder.resolve()
}

// applySessionSeccomp sets no_new_privs and installs the filter on every thread of the calling process (TSYNC: the Go
// runtime is multi-threaded); whatever the process executes next inherits it.
func applySessionSeccomp() error {
	program, err := sessionSeccompProgram()
	if err != nil {
		return err
	}
	if err := unix.Prctl(unix.PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0); err != nil {
		return fmt.Errorf("no_new_privs: %w", err)
	}
	fprog := unix.SockFprog{Len: uint16(len(program)), Filter: &program[0]}
	if _, _, errno := unix.Syscall(unix.SYS_SECCOMP, unix.SECCOMP_SET_MODE_FILTER, unix.SECCOMP_FILTER_FLAG_TSYNC,
		uintptr(unsafe.Pointer(&fprog))); errno != 0 {
		return fmt.Errorf("seccomp: %w", errno)
	}
	return nil
}

package main

import "golang.org/x/sys/unix"

// seccompArch is the audit arch of the runner's own build: session processes of another arch (i386, x32) are refused.
const seccompArch = unix.AUDIT_ARCH_X86_64

// seccompX32Bit marks x32 system calls on amd64 (same audit arch, numbers with this bit).
const seccompX32Bit = 0x40000000

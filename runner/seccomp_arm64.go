package main

import "golang.org/x/sys/unix"

// seccompArch is the audit arch of the runner's own build: session processes of another arch (arm32) are refused.
const seccompArch = unix.AUDIT_ARCH_AARCH64

// seccompX32Bit: arm64 has no x32-like ABI on the same audit arch.
const seccompX32Bit = 0

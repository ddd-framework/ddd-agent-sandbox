package main

// The drive CLI inside the session: the runner binary started as `drive` (a symlink on PATH). It holds no state and
// no credential: every verb is one request over the runner's socket (DRIVE_SOCKET).

import (
	"encoding/json"
	"fmt"
	"net"
	"os"
	"time"
)

const cliUsage = `usage: drive <command>
  status [--all] [--json]   uncommitted changes, conflicts, not-local files, mounts
  diff <path>               the local change against the base version
  commit -m <message> [path…]
  pull                      bring the drive and the pinned folders up to date
  get <path>…               make not-local files local (files or folders)
  pin <folder>              keep a folder local and current
  unpin <folder>            drop a pinned folder's contents (mounts)
`

// RunDriveCLI runs the CLI and exits.
func RunDriveCLI(arguments []string) {
	request, ok := parseCLI(arguments)
	if !ok {
		fmt.Fprint(os.Stderr, cliUsage)
		os.Exit(2)
	}
	request.Cwd, _ = os.Getwd()
	socket := os.Getenv("DRIVE_SOCKET")
	if socket == "" {
		socket = "/tmp/.drive.sock"
	}
	connection, err := net.DialTimeout("unix", socket, 5*time.Second)
	if err != nil {
		fmt.Fprintln(os.Stderr, "drive: drive mode is off for this session (no runner socket)")
		os.Exit(1)
	}
	defer connection.Close()
	if err := json.NewEncoder(connection).Encode(request); err != nil {
		fmt.Fprintln(os.Stderr, "drive: the runner did not take the request")
		os.Exit(1)
	}
	var reply cliReply
	if err := json.NewDecoder(connection).Decode(&reply); err != nil {
		fmt.Fprintln(os.Stderr, "drive: no answer from the runner")
		os.Exit(1)
	}
	fmt.Fprint(os.Stdout, reply.Stdout)
	fmt.Fprint(os.Stderr, reply.Stderr)
	os.Exit(reply.ExitCode)
}

func parseCLI(arguments []string) (cliRequest, bool) {
	if len(arguments) == 0 {
		return cliRequest{}, false
	}
	request := cliRequest{Verb: arguments[0], Args: []string{}}
	switch request.Verb {
	case "status", "diff", "commit", "pull", "get", "pin", "unpin":
	default:
		return request, false
	}
	rest := arguments[1:]
	for index := 0; index < len(rest); index++ {
		argument := rest[index]
		switch {
		case argument == "--all" && request.Verb == "status":
			request.All = true
		case argument == "--json" && request.Verb == "status":
			request.JSON = true
		case (argument == "-m" || argument == "--message") && request.Verb == "commit":
			if index+1 >= len(rest) {
				return request, false
			}
			index++
			request.Message = rest[index]
		case argument == "--":
			request.Args = append(request.Args, rest[index+1:]...)
			index = len(rest)
		case argument == "-h" || argument == "--help":
			return request, false
		default:
			request.Args = append(request.Args, argument)
		}
	}
	return request, true
}

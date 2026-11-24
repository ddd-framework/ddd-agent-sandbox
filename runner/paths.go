package main

import (
	"errors"
	"path/filepath"
	"strings"
)

// ErrPathRefused is returned for every path that is not a plain relative path inside the workspace.
var ErrPathRefused = errors.New("path refused")

// RunnerDir is the runner's own folder inside the workspace (spilled output). The manifest leaves it out.
const RunnerDir = ".runner"

// IsBelowRunnerDir reports whether a clean workspace-relative path is the runner's folder or lies inside it.
func IsBelowRunnerDir(relativePath string) bool {
	return relativePath == RunnerDir || strings.HasPrefix(relativePath, RunnerDir+"/")
}

// LeadsIntoRunnerDir reports whether a clean workspace-relative path names the runner's folder or something inside
// it, also through symlinks of the workspace (a link to .runner/out-1.log). A path that does not resolve (missing,
// or a link out of the workspace that os.Root refuses later) does not lead there.
func LeadsIntoRunnerDir(workspace, relativePath string) bool {
	if IsBelowRunnerDir(relativePath) {
		return true
	}
	resolvedWorkspace, err := filepath.EvalSymlinks(workspace)
	if err != nil {
		return false
	}
	resolved, err := filepath.EvalSymlinks(filepath.Join(workspace, relativePath))
	if err != nil {
		return false
	}
	inside, err := filepath.Rel(resolvedWorkspace, resolved)
	return err == nil && IsBelowRunnerDir(filepath.ToSlash(inside))
}

// CleanRelativePath checks a workspace path given by the caller and returns it in canonical form (no "." segments,
// no duplicate or trailing slashes). It refuses absolute paths, any ".." segment, NUL bytes, backslashes and the
// empty path. Symlink escapes are refused later, when the file is opened (os.Root in the helper).
func CleanRelativePath(path string) (string, error) {
	if path == "" || strings.HasPrefix(path, "/") || strings.ContainsAny(path, "\x00\\") {
		return "", ErrPathRefused
	}
	if len(path) > 4096 {
		return "", ErrPathRefused
	}
	segments := make([]string, 0, 8)
	for _, segment := range strings.Split(path, "/") {
		switch segment {
		case "", ".":
			continue
		case "..":
			return "", ErrPathRefused
		}
		if len(segment) > 255 {
			return "", ErrPathRefused
		}
		segments = append(segments, segment)
	}
	if len(segments) == 0 {
		return "", ErrPathRefused
	}
	return strings.Join(segments, "/"), nil
}

// ValidShellName checks the optional shell key of an exec call.
func ValidShellName(name string) bool {
	if name == "" || len(name) > 64 {
		return false
	}
	for _, character := range name {
		if !(character >= 'a' && character <= 'z' || character >= 'A' && character <= 'Z' ||
			character >= '0' && character <= '9' || character == '-' || character == '_') {
			return false
		}
	}
	return true
}

// ValidEnvName checks the name of an environment variable the caller sets. Names that change how bash or the
// dynamic loader start are refused.
func ValidEnvName(name string) bool {
	if name == "" || len(name) > 128 {
		return false
	}
	for index, character := range name {
		if !(character >= 'A' && character <= 'Z' || character >= 'a' && character <= 'z' || character == '_' ||
			index > 0 && character >= '0' && character <= '9') {
			return false
		}
	}
	switch name {
	case "BASH_ENV", "ENV", "HOME", "PATH", "SHELLOPTS", "BASHOPTS", "PROMPT_COMMAND", "PS4", "IFS":
		return false
	}
	return !strings.HasPrefix(name, "LD_") && !strings.HasPrefix(name, "BASH_FUNC_")
}

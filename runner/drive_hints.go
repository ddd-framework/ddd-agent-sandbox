package main

// Hints for not-local files. Two independent halves:
//   - a miss source names workspace paths or globs a call may have touched. Today (Plan B, no FUSE) that is a cheap
//     heuristic over the command text: path tokens and globs. A placeholder FUSE later feeds its exact miss log
//     (opens answered with ENODATA) into the same matcher.
//   - the matcher answers which of them are not local, from the index (base map entries with Local false).
// Without FUSE there is no open() interception: a path built at run time (a script that lists a folder, a variable)
// is not seen, and a token that names a not-local file the command never opened still yields a hint.

import (
	"path"
	"sort"
	"strings"
)

// notLocalHintLimit: at most this many paths in the answer; count and bytes cover all.
const notLocalHintLimit = 10

// NotLocalPath is one not-local file a call touched (or probably touched).
type NotLocalPath struct {
	Path string `json:"path"`
	Size int64  `json:"size"`
}

// NotLocalHint is appended to exec and job answers; PHP renders the hint block.
// It is embedded in the answers, so the fields appear flat: notLocal: [{path, size}], notLocalCount, …
type NotLocalHint struct {
	Paths []NotLocalPath `json:"notLocal"`
	Count int            `json:"notLocalCount"`
	Bytes int64          `json:"notLocalBytes"`
	// Source: "command" (the heuristic) or later "fuse" (the miss log)
	Source string `json:"notLocalSource"`
}

// missPattern is a workspace-relative path, folder or glob a call may have touched.
type missPattern struct {
	path string
	glob bool
}

// commandMissPatterns is the heuristic miss source: words of the command that look like paths, resolved against
// the call's starting directory (workspace-relative, "" for the root).
func commandMissPatterns(command, cwdRelative, workspace string) []missPattern {
	var patterns []missPattern
	for _, token := range shellWords(command) {
		if strings.HasPrefix(token, "-") {
			_, value, found := strings.Cut(token, "=")
			if !found {
				continue
			}
			token = value
		}
		if token == "" || !strings.ContainsAny(token, "/.*?[") {
			continue
		}
		var relative string
		switch {
		case strings.HasPrefix(token, workspace+"/") || token == workspace:
			relative = strings.TrimPrefix(strings.TrimPrefix(token, workspace), "/")
		case strings.HasPrefix(token, "/") || strings.HasPrefix(token, "~"):
			continue
		default:
			relative = path.Join(cwdRelative, token)
		}
		relative = path.Clean(relative)
		if relative == ".." || strings.HasPrefix(relative, "../") {
			continue
		}
		if relative == "." {
			relative = ""
		}
		patterns = append(patterns, missPattern{path: relative, glob: strings.ContainsAny(token, "*?[")})
	}
	return patterns
}

// shellWords splits a command into words: whitespace and shell operators separate, quotes are removed. Good enough
// for a hint, not a parser.
func shellWords(command string) []string {
	var words []string
	var current strings.Builder
	quote := rune(0)
	flush := func() {
		if current.Len() > 0 {
			words = append(words, current.String())
			current.Reset()
		}
	}
	for _, character := range command {
		switch {
		case quote != 0:
			if character == quote {
				quote = 0
			} else {
				current.WriteRune(character)
			}
		case character == '\'' || character == '"':
			quote = character
		case strings.ContainsRune(" \t\n;|&<>()`$={},", character):
			flush()
		default:
			current.WriteRune(character)
		}
	}
	flush()
	return words
}

// matchesPattern: the entry is the pattern, lies below it (a folder), or matches the glob (or lies below a match).
func matchesPattern(pattern missPattern, entryPath string) bool {
	if pattern.path == "" {
		return true
	}
	if !pattern.glob {
		return entryPath == pattern.path || strings.HasPrefix(entryPath, pattern.path+"/")
	}
	for candidate := entryPath; candidate != "." && candidate != ""; candidate = path.Dir(candidate) {
		if matched, _ := path.Match(pattern.path, candidate); matched {
			return true
		}
	}
	return false
}

// notLocalHint matches miss patterns against the not-local files of every space; nil when none matched.
func (state *DriveState) notLocalHint(patterns []missPattern, source string) *NotLocalHint {
	if state == nil || len(patterns) == 0 {
		return nil
	}
	state.mutex.Lock()
	defer state.mutex.Unlock()
	hint := &NotLocalHint{Paths: []NotLocalPath{}, Source: source}
	var hits []NotLocalPath
	for _, space := range append([]*DriveSpaceState{state.Own}, state.spacesOfMounts()...) {
		for entryPath, entry := range space.Entries {
			if entry.Local || entry.LinkTarget != "" {
				continue
			}
			full := spaceWorkspacePath(space, entryPath)
			for _, pattern := range patterns {
				// a bare folder or the workspace root as the only word would list everything: only a pattern that
				// names at least something inside a space counts
				if pattern.path == "" || pattern.path == DriveSpacesDir {
					continue
				}
				if matchesPattern(pattern, full) {
					hits = append(hits, NotLocalPath{Path: full, Size: entry.Size})
					break
				}
			}
		}
	}
	if len(hits) == 0 {
		return nil
	}
	sort.Slice(hits, func(left, right int) bool { return hits[left].Path < hits[right].Path })
	for _, hit := range hits {
		hint.Count++
		hint.Bytes += hit.Size
		if len(hint.Paths) < notLocalHintLimit {
			hint.Paths = append(hint.Paths, hit)
		}
	}
	return hint
}

// commandHint is the hint of one exec or job: the command heuristic as the miss source.
func (session *Session) commandHint(command, cwd string) *NotLocalHint {
	if session.drive == nil {
		return nil
	}
	workspace := session.runner.config.Workspace
	cwdRelative := ""
	if rest, found := strings.CutPrefix(cwd, workspace+"/"); found {
		cwdRelative = rest
	} else if cwd != workspace && cwd != "" {
		return nil
	}
	return session.drive.notLocalHint(commandMissPatterns(command, cwdRelative, workspace), "command")
}

// callHint is the hint of one exec or job. Plan B: the command heuristic for every space. Streamed mounts: the exact
// misses of the placeholder FUSE (opens answered with ENODATA) for the mounts, the heuristic for the own drive only.
func (session *Session) callHint(command, cwd string, misses []string) *NotLocalHint {
	state := session.drive
	if state == nil {
		return nil
	}
	if !state.Streamed {
		return session.commandHint(command, cwd)
	}
	var patterns []missPattern
	workspace := session.runner.config.Workspace
	cwdRelative, inside := strings.CutPrefix(cwd, workspace+"/")
	if inside || cwd == workspace || cwd == "" {
		if !inside {
			cwdRelative = ""
		}
		for _, pattern := range commandMissPatterns(command, cwdRelative, workspace) {
			if pattern.path != DriveSpacesDir && !strings.HasPrefix(pattern.path, DriveSpacesDir+"/") {
				patterns = append(patterns, pattern)
			}
		}
	}
	for _, miss := range misses {
		patterns = append(patterns, missPattern{path: miss})
	}
	source := "command"
	if len(misses) > 0 {
		source = "fuse"
	}
	return state.notLocalHint(patterns, source)
}

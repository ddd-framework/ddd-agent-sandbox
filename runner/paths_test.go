package main

import "testing"

func TestCleanRelativePath(t *testing.T) {
	accepted := map[string]string{
		"a.txt":           "a.txt",
		"sub/a.txt":       "sub/a.txt",
		"sub//a.txt":      "sub/a.txt",
		"./sub/./a.txt":   "sub/a.txt",
		"sub/":            "sub",
		".runner/x.log":   ".runner/x.log",
		"name with space": "name with space",
		"..hidden":        "..hidden",
	}
	for input, want := range accepted {
		got, err := CleanRelativePath(input)
		if err != nil || got != want {
			t.Errorf("CleanRelativePath(%q) = %q, %v; want %q", input, got, err, want)
		}
	}
	refused := []string{"", "/etc/passwd", "..", "../x", "sub/../../x", "sub/..", "a\x00b", `a\..\b`, ".", "./", "//x"}
	for _, input := range refused {
		if got, err := CleanRelativePath(input); err == nil {
			t.Errorf("CleanRelativePath(%q) = %q; want refusal", input, got)
		}
	}
}

func TestValidEnvName(t *testing.T) {
	for _, name := range []string{"TZ", "AGENT_NOW", "LANG", "x1"} {
		if !ValidEnvName(name) {
			t.Errorf("%s should be accepted", name)
		}
	}
	for _, name := range []string{"", "1X", "BASH_ENV", "ENV", "PATH", "HOME", "LD_PRELOAD", "BASH_FUNC_x%%", "A-B"} {
		if ValidEnvName(name) {
			t.Errorf("%s should be refused", name)
		}
	}
}

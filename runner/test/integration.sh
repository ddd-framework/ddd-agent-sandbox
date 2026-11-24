#!/usr/bin/env bash
# Integration test of the sandbox runner image with Docker, shaped like the Kubernetes pod of the README: no network,
# read-only root, root with only SETUID, SETGID and KILL, no new privileges, /workspace and /tmp as tmpfs, pids limit.
#
#   runner/test/integration.sh [image]        (default ddd-agent-sandbox-runner:base)
#
# With --network none no port reaches the host, so every request runs inside the container through `docker exec` as
# uid 65534 (Python's http.client to 127.0.0.1:8080). Needs Docker and python3 on the host.
set -euo pipefail

image="${1:-ddd-agent-sandbox-runner:base}"
# RUNNER_TEST_EXACT_BUDGET_MS: the runner's walk budget for the main container (default 500 as in production; a small
# value such as 10 makes the watcher switch to statfs locally too)
budget="${RUNNER_TEST_EXACT_BUDGET_MS:-500}"
name="sandbox-runner-it-$$"
passed=0
failed=0

small="sandbox-runner-it-small-$$"
cleanup() { [[ -n "${RUNNER_TEST_KEEP:-}" ]] || docker rm -f "$name" "$small" >/dev/null 2>&1 || true; }
trap cleanup EXIT

docker run -d --name "$name" \
  --network none \
  --read-only \
  --cap-drop ALL --cap-add SETUID --cap-add SETGID --cap-add KILL \
  --security-opt no-new-privileges \
  --tmpfs /workspace:rw,exec,mode=0777,size=2g \
  --tmpfs /tmp:rw,exec,mode=1777,size=256m \
  --tmpfs /var/lib/sandbox-drive:rw,mode=0700,size=256m -e RUNNER_DRIVE_STATE_DIR=/var/lib/sandbox-drive \
  --pids-limit 1024 \
  --memory 3g --cpus 2 \
  -e RUNNER_WORKSPACE_LIMIT_BYTES=$((64 << 20)) -e RUNNER_QUOTA_EXACT_BUDGET_MS="$budget" \
  "$image" >/dev/null

# http METHOD PATH [BODY] — prints the status line, then the body
http() {
  docker exec -i -u 65534 "$name" python3 -c '
import http.client, sys
method, path = sys.argv[1], sys.argv[2]
body = sys.stdin.buffer.read() if method in ("POST", "PUT") else None
connection = http.client.HTTPConnection("127.0.0.1", 8080, timeout=300)
connection.request(method, path, body=body, headers={"Content-Type": "application/json"})
response = connection.getresponse()
sys.stdout.write(str(response.status) + "\n")
sys.stdout.flush()
sys.stdout.buffer.write(response.read())
' "$1" "$2" < <(printf '%s' "${3:-}")
}

# json EXPR — evaluates a Python expression over the JSON body on stdin (j); python3 is in every toolkit
json() { python3 -c "import json,sys; j=json.loads(sys.stdin.read()); print($1)"; }

check() {
  local label="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    echo "PASS  $label"
    passed=$((passed + 1))
  else
    echo "FAIL  $label: expected [$expected], got [$actual]"
    failed=$((failed + 1))
  fi
}

body() { tail -n +2; }
status() { head -1; }

for attempt in $(seq 1 50); do
  if out=$(http GET /healthz 2>/dev/null) && [[ "$(status <<<"$out")" == 200 ]]; then break; fi
  sleep 0.2
done
check "healthz" "idle" "$(body <<<"$out" | json 'j["state"]')"
# the type depends on the runtime (tmpfs or ext4 under runc, 9p under gVisor): checked only when
# RUNNER_TEST_EXPECT_MOUNT_TYPE names it
check "healthz reports the workspace as its own mount" "True|yes" \
  "$(body <<<"$out" | json 'str(j["workspaceMount"]["ownMount"]) + "|" + ("yes" if j["workspaceMount"]["sizeBytes"] > 0 else "no")')"
if [[ -n "${RUNNER_TEST_EXPECT_MOUNT_TYPE:-}" ]]; then
  check "healthz mount type" "$RUNNER_TEST_EXPECT_MOUNT_TYPE" "$(body <<<"$out" | json 'j["workspaceMount"]["type"]')"
fi

out=$(http POST /v1/sessions '{"workspaceKey":"it-space-1","env":{"TZ":"Europe/Berlin"}}')
check "claim answers 201" "201" "$(status <<<"$out")"
session=$(body <<<"$out" | json 'j["sessionId"]')
uid=$(body <<<"$out" | json 'j["uid"]')

out=$(http POST /v1/sessions '{"workspaceKey":"it-space-2"}')
check "second claim answers 503" "503" "$(status <<<"$out")"

exec_json() { http POST "/v1/sessions/$session/exec" "$1"; }

out=$(exec_json '{"command":"mkdir -p sub && cd sub && export X=1"}')
check "cd and export" "0" "$(body <<<"$out" | json 'j["exitCode"]')"
out=$(exec_json '{"command":"pwd; echo $X"}')
check "cwd and env persist" "/workspace/sub|1" "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"
out=$(exec_json '{"command":"id -u; echo $TZ"}')
check "runs under the session uid with the session env" "$uid|Europe/Berlin" "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"

out=$(exec_json '{"command":"getent hosts example.com || echo no-dns; (exec 3<>/dev/tcp/1.1.1.1/80) 2>/dev/null && echo net || echo no-net"}')
check "no DNS, no network" "no-dns|no-net" "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"

# seccomp filter of every session process: no user namespace (EPERM; under gVisor nothing else would stop it), no
# mount, no setns; clone3 answers ENOSYS so glibc falls back to clone; fork, threads and the toolkit keep working
out=$(exec_json '{"command":"unshare -U true 2>&1; echo rc=$?; python3 -c \"import os\ntry:\n    os.unshare(os.CLONE_NEWUSER | os.CLONE_NEWNS)\nexcept OSError as error:\n    print(error.errno)\""}')
check "session processes cannot create a user namespace (seccomp)" "yes|rc=1|1" \
  "$(body <<<"$out" | json '"|".join(["yes" if "Operation not permitted" in j["stdout"]["head"] else "no"] + j["stdout"]["head"].split("\n")[1:3])')"
out=$(exec_json '{"command":"grep ^Seccomp: /proc/self/status | tr -d \"\\t \"; python3 -c \"import threading, subprocess; t = threading.Thread(target=print, args=(\\\"thread\\\",)); t.start(); t.join(); print(subprocess.run([\\\"echo\\\", \\\"child\\\"], capture_output=True, text=True).stdout.strip())\"; git --version | cut -d\" \" -f1; rg --version | head -1 | cut -d\" \" -f1"}')
check "under the filter: threads, subprocesses, git, rg" "Seccomp:2|thread|child|git|ripgrep" \
  "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"

out=$(exec_json '{"command":"python3 -c \"import openpyxl, pandas, docx; wb = openpyxl.Workbook(); wb.active[\\\"A1\\\"] = 42; wb.save(\\\"t.xlsx\\\"); print(pandas.read_excel(\\\"t.xlsx\\\", header=None).iloc[0, 0])\""}')
check "python toolkit (openpyxl, pandas, python-docx)" "42" "$(body <<<"$out" | json 'j["stdout"]["head"].strip()')"

if [[ "$(docker exec "$name" cat /etc/sandbox-toolkit)" == full ]]; then
  out=$(exec_json '{"command":"python3 -c \"from playwright.sync_api import sync_playwright\nwith sync_playwright() as p:\n    b = p.chromium.launch(); pg = b.new_page(); pg.set_content(\\\"<h1>x</h1>\\\"); pg.screenshot(path=\\\"pw.png\\\"); b.close()\nprint(\\\"py-ok\\\")\""}')
  check "playwright (python) inside a session" "py-ok" "$(body <<<"$out" | json 'j["stdout"]["head"].strip() or j["stderr"]["head"][-300:]')"
  out=$(exec_json '{"command":"node -e \"const { chromium } = require(\\\"playwright\\\"); (async () => { const b = await chromium.launch(); const p = await b.newPage(); await p.setContent(\\\"<h1>x</h1>\\\"); await p.pdf({ path: \\\"pw.pdf\\\" }); await b.close(); console.log(\\\"node-ok\\\"); })()\""}')
  check "playwright (node) inside a session" "node-ok" "$(body <<<"$out" | json 'j["stdout"]["head"].strip() or j["stderr"]["head"][-300:]')"
  out=$(exec_json '{"command":"python3 -c \"import plotly.graph_objects as go; go.Figure(go.Bar(y=[1, 2])).write_image(\\\"chart.png\\\"); print(\\\"kaleido-ok\\\")\"","timeoutSeconds":90}')
  check "plotly picture export (kaleido) inside a session" "kaleido-ok" "$(body <<<"$out" | json 'j["stdout"]["head"].strip() or j["stderr"]["head"][-300:]')"
  out=$(exec_json '{"command":"echo x > a.txt && soffice --convert-to pdf a.txt >/dev/null 2>&1; ls a.pdf"}')
  check "libreoffice inside a session" "a.pdf" "$(body <<<"$out" | json 'j["stdout"]["head"].strip()')"
fi

out=$(http PUT "/v1/sessions/$session/files/docs/hello.txt" 'hello world')
check "PUT file" "200" "$(status <<<"$out")"
check "PUT answers sha256" "$(printf 'hello world' | sha256sum | cut -d' ' -f1)" "$(body <<<"$out" | json 'j["sha256"]')"
out=$(http GET "/v1/sessions/$session/files/docs/hello.txt")
check "GET file" "200|hello world" "$(status <<<"$out")|$(body <<<"$out" | head -1)"
out=$(http GET "/v1/sessions/$session/files/..%2F..%2Fetc%2Fpasswd")
check "GET with .. refused" "400" "$(status <<<"$out")"
exec_json '{"command":"ln -s /etc/passwd /workspace/pw"}' >/dev/null
out=$(http GET "/v1/sessions/$session/files/pw")
check "GET through a symlink to /etc refused" "400" "$(status <<<"$out")"

out=$(http GET "/v1/sessions/$session/manifest")
check "manifest lists the file with its hash" "docs/hello.txt" \
  "$(body <<<"$out" | json '[f["path"] for f in j["files"] if f["sha256"] == "'"$(printf 'hello world' | sha256sum | cut -d' ' -f1)"'"][0]')"

out=$(exec_json '{"command":"seq 1 100000"}')
check "long output truncated with head and tail" "True|1|100000" \
  "$(body <<<"$out" | json '"|".join([str(j["stdout"]["truncated"]), j["stdout"]["head"].split()[0], j["stdout"]["tail"].split()[-1]])')"
spill=$(body <<<"$out" | json 'j["spillPath"]')
out=$(http GET "/v1/sessions/$session/files/${spill#/workspace/}")
check "spill file holds the full output" "100000" "$(body <<<"$out" | tail -1)"

out=$(exec_json '{"command":"sleep 5; echo done","background":true}')
check "background job accepted" "202" "$(status <<<"$out")"
job=$(body <<<"$out" | json 'j["jobId"]')
out=$(http GET "/v1/sessions/$session/jobs/$job")
check "job running" "running" "$(body <<<"$out" | json 'j["state"]')"
sleep 6
out=$(http GET "/v1/sessions/$session/jobs/$job")
check "job finished with its output" "exited|0|done" "$(body <<<"$out" | json '"|".join([j["state"], str(j["exitCode"]), j["stdout"]["head"].strip()])')"

start=$(date +%s)
out=$(exec_json '{"command":"sleep 100","timeoutSeconds":2}')
elapsed=$(( $(date +%s) - start ))
check "timeout kills the command" "True" "$(body <<<"$out" | json 'j["timedOut"]')"
check "timeout answers within 6 s" "yes" "$([[ $elapsed -le 6 ]] && echo yes || echo "no ($elapsed s)")"
out=$(exec_json '{"command":"echo $X"}')
check "shell state survives the timeout" "1" "$(body <<<"$out" | json 'j["stdout"]["head"].strip()')"

# administration reads (README "Administration"): the env value (TZ) is masked, reads leave the activity alone
out=$(exec_json '{"command":"echo tz=$TZ; sleep 20 # $TZ","background":true}')
admin_job=$(body <<<"$out" | json 'j["jobId"]')
sleep 1
out=$(http GET /v1/sessions)
check "admin: the session with its key, memory, disk and running job" "it-space-1|1|yes|yes" \
  "$(body <<<"$out" | json '"|".join([j["sessions"][0]["workspaceKey"], str(j["sessions"][0]["runningJobCount"]), "yes" if j["sessions"][0]["memoryLimitBytes"] > 0 else "no", "yes" if j["sessions"][0]["diskQuotaBytes"] > 0 else "no"])')"
activity=$(body <<<"$out" | json 'j["sessions"][0]["lastActivityAt"]')
out=$(http GET "/v1/sessions/$session/processes")
check "admin: processes with the job, argv never shows the env value" "yes|no" \
  "$(body <<<"$out" | json '"|".join(["yes" if any(p.get("jobId") == "'"$admin_job"'" for p in j["processes"]) else "no", "yes" if any("Europe/Berlin" in p["argv"] for p in j["processes"]) else "no"])')"
out=$(http GET "/v1/sessions/$session/execs?limit=3")
check "admin: execs newest first, masked, with the timeout" "running|TIMED_OUT|no" \
  "$(body <<<"$out" | json '"|".join([j["execs"][0]["status"], j["execs"][2]["status"], "yes" if "Europe/Berlin" in json.dumps(j) else "no"])')"
out=$(http GET "/v1/sessions/$session/events")
check "admin: events name the timeout" "yes" "$(body <<<"$out" | json '"yes" if any(e["type"] == "TIMEOUT" for e in j["events"]) else "no"')"
out=$(http GET "/v1/sessions/$session/files?path=docs")
check "admin: one directory listed" "hello.txt|file" "$(body <<<"$out" | json 'j["entries"][0]["name"] + "|" + j["entries"][0]["type"]')"
out=$(http GET "/v1/sessions/$session/files?path=../etc")
check "admin: listing outside the workspace refused" "400" "$(status <<<"$out")"
out=$(http GET "/v1/sessions/$session/disk")
check "admin: disk usage" "yes" "$(body <<<"$out" | json '"yes" if j["usedBytes"] > 0 and j["quotaBytes"] > 0 else "no"')"
out=$(http GET /v1/sessions)
check "admin: reads are no activity" "$activity" "$(body <<<"$out" | json 'j["sessions"][0]["lastActivityAt"]')"
http DELETE "/v1/sessions/$session/jobs/$admin_job" >/dev/null

# memory: the session's limit (default 2 GiB of the container's 3g) ends the session's processes, not the runner (on
# gVisor past the pod limit the host would kill the Sentry and the whole pod); the measure is a session cgroup where
# the runtime offers one (gVisor), else the RSS sum of the session uid
out=$(http GET /healthz)
memory_measure=$(body <<<"$out" | json 'j["memory"]["measure"]')
echo "      memory measure: $memory_measure"
out=$(exec_json '{"command":"python3 -c \"import time; b = b\\\"x\\\" * (2600 << 20); print(\\\"allocated\\\"); time.sleep(30)\"","timeoutSeconds":60}')
check "exec past the session memory limit answers MEMORY_EXCEEDED" "MEMORY_EXCEEDED|2147483648|yes|no" \
  "$(body <<<"$out" | json '"|".join([j["status"], str(j["memory"]["limitBytes"]), "yes" if "memory limit of the sandbox session exceeded" in j["stderr"]["head"] else "no", "yes" if "allocated" in j["stdout"]["head"] else "no"])')"
out=$(http GET /healthz)
check "the runner survives the memory excess" "200|busy" "$(status <<<"$out")|$(body <<<"$out" | json 'j["state"]')"
out=$(exec_json '{"command":"pwd"}')
check "the shell comes back after the memory excess (cwd kept, env lost)" "OK|/workspace/sub|True" \
  "$(body <<<"$out" | json '"|".join([j["status"], j["stdout"]["head"].strip(), str(j.get("shellRestarted", False))])')"
exec_json '{"command":"export X=1"}' >/dev/null

# quota: the container runs with RUNNER_WORKSPACE_LIMIT_BYTES = 64 MiB
out=$(exec_json '{"command":"for i in $(seq 1 100); do head -c 1048576 /dev/zero > q-$i; done; sleep 30"}')
check "exec past the quota answers QUOTA_EXCEEDED" "QUOTA_EXCEEDED|67108864|5" \
  "$(body <<<"$out" | json '"|".join([j["status"], str(j["quota"]["limitBytes"]), str(len(j["quota"]["largest"]))])')"
out=$(exec_json '{"command":"rm -f q-*; echo $X"}')
check "shell recovers after the quota (cwd kept, env lost)" "OK|/workspace/sub" "$(body <<<"$out" | json '"|".join([j["status"], j["cwd"]])')"
out=$(dd if=/dev/zero bs=1048576 count=70 2>/dev/null | docker exec -i -u 65534 "$name" python3 -c '
import http.client, sys
body = sys.stdin.buffer.read()
connection = http.client.HTTPConnection("127.0.0.1", 8080)
try:
    connection.request("PUT", "/v1/sessions/'"$session"'/files/big.bin", body=body)
except (BrokenPipeError, ConnectionResetError):
    pass  # the runner answers before reading a body past the quota and closes
response = connection.getresponse(); print(response.status); print(response.read().decode())')
check "PUT past the quota answers 413 QUOTA_EXCEEDED" "413|QUOTA_EXCEEDED" "$(status <<<"$out")|$(body <<<"$out" | json 'j["error"]')"
# regression (cluster 2026-11-22): a refused PUT changes no state; the next exec below the quota answers OK
out=$(exec_json '{"command":"sleep 1.5; true"}')
check "exec after a refused PUT answers OK" "OK" "$(body <<<"$out" | json 'j["status"]')"
out=$(http GET "/v1/sessions/$session/manifest")
check "manifest carries usedBytes and limitBytes" "67108864|yes" "$(body <<<"$out" | json 'str(j["limitBytes"]) + "|" + ("yes" if j["usedBytes"] < j["limitBytes"] else "no")')"
exec_json '{"command":"export X=1"}' >/dev/null
# a burst inside one watcher tick: three parallel writers finish before the watcher looks; the measurement at the end
# of the exec reports it (no kill: exit code 0, the shell keeps its state, the files stay)
out=$(exec_json '{"command":"for i in 1 2 3; do dd if=/dev/zero of=burst-$i bs=1M count=30 status=none & done; wait"}')
check "burst within one tick answers QUOTA_EXCEEDED" "QUOTA_EXCEEDED|LIMIT|0|False" \
  "$(body <<<"$out" | json '"|".join([j["status"], j["quota"]["reason"], str(j["exitCode"]), str(j.get("shellExited", False))])')"
out=$(exec_json '{"command":"ls burst-* | wc -l; rm burst-*; echo $X"}')
check "files and shell state stay after the burst" "3|1" "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"

out=$(exec_json '{"command":"nohup sleep 600 >/dev/null 2>&1 & setsid sleep 600 >/dev/null 2>&1 < /dev/null & echo h > $HOME/h; echo t > /tmp/t; echo s > /dev/shm/s; ps -o pid= -u '"$uid"' | wc -l"}')
check "session processes running before delete" "yes" "$([[ $(body <<<"$out" | json 'j["stdout"]["head"].strip()') -ge 3 ]] && echo yes || echo no)"

out=$(http DELETE "/v1/sessions/$session")
check "DELETE releases" "200" "$(status <<<"$out")"
check "no process of the uid survives" "0" "$(docker exec "$name" bash -c "ps -o pid= -u $uid | wc -l")"
# lost+found belongs to an ext4 volume and survives the wipe
check "workspace wiped" "0" "$(docker exec "$name" bash -c 'ls -A /workspace | grep -v "^lost+found$" | wc -l')"
check "/tmp and /dev/shm wiped" "0" "$(docker exec "$name" bash -c "find /tmp /dev/shm -mindepth 1 -user $uid | wc -l")"
out=$(http POST "/v1/sessions/$session/exec" '{"command":"true"}')
check "deleted session answers 404" "404" "$(status <<<"$out")"

out=$(http POST /v1/sessions '{"workspaceKey":"it-space-2"}')
check "runner takes the next session" "201" "$(status <<<"$out")"
next_uid=$(body <<<"$out" | json 'j["uid"]')
check "next session has a new uid" "yes" "$([[ "$next_uid" != "$uid" ]] && echo yes || echo no)"
next=$(body <<<"$out" | json 'j["sessionId"]')

# 50,000 files: the exact walk fits the watcher's one-second tick (locally about 40 ms), or, where it takes longer
# than RUNNER_QUOTA_EXACT_BUDGET_MS ($budget; under gVisor about 2 s), the watcher switches the session to statfs
out=$(http POST "/v1/sessions/$next/exec" '{"command":"mkdir -p walk && cd walk && for d in $(seq 1 500); do mkdir d$d; (cd d$d && for f in $(seq 1 100); do : > f$f; done); done; cd ..","timeoutSeconds":180}')
walk_ms=$(docker exec "$name" bash -c 'start=$(date +%s%N); /usr/local/bin/sandbox-runner __helper usage - /workspace /tmp >/dev/null; echo $(( ($(date +%s%N) - start) / 1000000 ))')
if [[ $walk_ms -le $budget ]]; then
  check "exact usage walk over 50,000 files within the budget ($walk_ms ms)" "yes" "yes"
else
  http POST "/v1/sessions/$next/exec" '{"command":"sleep 3"}' >/dev/null
  check "walk of $walk_ms ms: the watcher switches to statfs" "yes" \
    "$(docker logs "$name" 2>&1 | grep -q "quota watcher uses statfs.*$next" && echo yes || echo no)"
fi
http DELETE "/v1/sessions/$next" >/dev/null

# lost+found as mkfs.ext4 leaves it (root, 0700): an empty one is removed at the claim, so recursive tools of the
# session see no permission error; a non-empty one stays, closed to 0700
docker exec -u 0 "$name" mkdir -m 0700 /workspace/lost+found
out=$(http POST /v1/sessions '{"workspaceKey":"it-lost-found"}')
lf=$(body <<<"$out" | json 'j["sessionId"]')
out=$(http POST "/v1/sessions/$lf/exec" '{"command":"echo x > found.txt; rg -l x /workspace; echo rc=$?; ls -A /workspace"}')
check "rg over the workspace without a permission error (empty lost+found removed)" "/workspace/found.txt|rc=0|found.txt|" \
  "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split()) + "|" + j["stderr"]["head"]')"
http DELETE "/v1/sessions/$lf" >/dev/null
docker exec -u 0 "$name" sh -c 'mkdir -m 0755 /workspace/lost+found && echo recovered > "/workspace/lost+found/#12"'
out=$(http POST /v1/sessions '{"workspaceKey":"it-lost-found-kept"}')
lf=$(body <<<"$out" | json 'j["sessionId"]')
check "a non-empty lost+found stays, closed to 0700" "700|0" \
  "$(docker exec -u 0 "$name" stat -c %a /workspace/lost+found)|$(http GET "/v1/sessions/$lf/manifest" | body | json 'j["count"]')"
http DELETE "/v1/sessions/$lf" >/dev/null
docker exec -u 0 "$name" rm -rf /workspace/lost+found

# Drive mode (stream transfer: PHP pushes blobs, no proxy): layout, read-only mount, index, hint, CLI pause, job
sha() { printf '%s' "$1" | sha256sum | cut -d' ' -f1; }
alpha_sha=$(sha 'alpha'); readme_sha=$(sha 'read me')
out=$(http POST /v1/sessions '{"workspaceKey":"it-drive","mode":"drive","drive":{"spaceId":7,"cursor":1,
  "entries":[{"path":"notes/a.txt","nodeId":1,"versionId":11,"sha256":"'"$alpha_sha"'","size":5},
             {"path":"data/big.bin","nodeId":2,"versionId":21,"sha256":"'"$(sha big)"'","size":31457280}],
  "mounts":[{"name":"library","spaceId":9,"mode":"READ_ONLY","spaceMd":"# Library\n","cursor":5,
    "entries":[{"path":"readme.md","nodeId":90,"versionId":901,"sha256":"'"$readme_sha"'","size":7},
               {"path":"Brand/Logo.svg","nodeId":91,"versionId":911,"sha256":"'"$(sha svg)"'","size":6}]}]}}')
check "drive claim answers 201 with transfer stream and two missing blobs" "201|drive|stream|2" \
  "$(status <<<"$out")|$(body <<<"$out" | json '"|".join([j["mode"], j["transfer"], str(len(j["drive"]["needBlobs"]))])')"
drive=$(body <<<"$out" | json 'j["sessionId"]')
http PUT "/v1/sessions/$drive/drive/blobs/$alpha_sha" 'alpha' >/dev/null
http PUT "/v1/sessions/$drive/drive/blobs/$readme_sha" 'read me' >/dev/null
out=$(http PUT "/v1/sessions/$drive/drive/blobs/$alpha_sha" 'not alpha')
check "a cached blob is not taken again" "200" "$(status <<<"$out")"
out=$(http PUT "/v1/sessions/$drive/drive/blobs/$(sha other)" 'not other')
check "blob PUT with the wrong bytes answers 400 HASH_MISMATCH" "400|HASH_MISMATCH" "$(status <<<"$out")|$(body <<<"$out" | json 'j["error"]')"
out=$(http POST "/v1/sessions/$drive/drive/materialize" '{"items":[]}')
check "materialize writes the pending files" "2|0" "$(body <<<"$out" | json 'str(len(j["applied"])) + "|" + str(len(j["needBlobs"]))')"
dexec() { http POST "/v1/sessions/$drive/exec" "$1"; }
out=$(dexec '{"command":"cat notes/a.txt; echo; cat drivespaces/library/readme.md; echo; head -1 drivespaces/library/SPACE.md; tail -n +3 .drive-index | cut -f1; tail -n +3 drivespaces/library/.drive-index | cut -f1"}')
check "drive layout: tree, mount, SPACE.md, both indexes" "alpha|read|me|#|Library|data/big.bin|Brand/Logo.svg" \
  "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"
out=$(http GET "/v1/sessions/$drive/drive/status")
mount_fs=$(body <<<"$out" | json 'j.get("mountFilesystem", "")')
echo "      mount filesystem: $mount_fs"
# RUNNER_TEST_EXPECT_MOUNT_FS=fuse (cluster, local runsc): the placeholder FUSE must have mounted; locally under runc
# Docker's seccomp profile refuses the user namespace, so the runner falls back to Plan B
if [[ -n "${RUNNER_TEST_EXPECT_MOUNT_FS:-}" ]]; then
  check "mount filesystem" "$RUNNER_TEST_EXPECT_MOUNT_FS" "${mount_fs%%:*}"
fi
out=$(dexec '{"command":"stat -c %a drivespaces/library/readme.md"}')
check "mount files are read-only (0444)" "444" "$(body <<<"$out" | json 'j["stdout"]["head"].strip()')"
out=$(dexec '{"command":"echo x > drivespaces/library/readme.md 2>/dev/null && echo wrote || echo refused; mv drivespaces x 2>/dev/null && echo moved || echo kept"}')
check "the session cannot write into a mount or move it" "refused|kept" "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"
if [[ "$mount_fs" == fuse ]]; then
  # placeholders: every file is listed, the not-local ones answer ENODATA; the hint comes from the FUSE miss log, so
  # paths built at run time count (find), and the runner outside the namespace sees an empty mount point
  out=$(dexec '{"command":"ls drivespaces/library/Brand; cat drivespaces/library/Brand/Logo.svg; echo rc=$?; find drivespaces -type f -name \"*.svg\" -exec cat {} + 2>/dev/null; grep ^Seccomp: /proc/self/status | tr -d \"\\t \"; unshare -U true 2>/dev/null || echo no-userns"}')
  check "fuse: placeholder listed, ENODATA on open, exact miss in the hint" "Logo.svg|rc=1|Seccomp:2|no-userns|yes|fuse|drivespaces/library/Brand/Logo.svg|1" \
    "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split() + ["yes" if "No data available" in j["stderr"]["head"] else "no", j["notLocalSource"], j["notLocal"][0]["path"], str(j["notLocalCount"])])')"
  check "fuse: outside the namespace the mount point is empty" "0" "$(docker exec "$name" sh -c 'ls -A /workspace/drivespaces/library | wc -l')"
  out=$(dexec '{"command":"drive get drivespaces/library/Brand/Logo.svg && cat drivespaces/library/Brand/Logo.svg"}')
  seq=$(body <<<"$out" | json 'j["seq"]'); request=$(body <<<"$out" | json 'j["driveRequests"][0]["requestId"]')
  http PUT "/v1/sessions/$drive/drive/blobs/$(sha svg)" 'svg' >/dev/null
  out=$(http POST "/v1/sessions/$drive/execs/$seq/continue" '{"responses":[{"requestId":"'"$request"'","ok":true,"materialize":[{"path":"drivespaces/library/Brand/Logo.svg"}]}]}')
  check "fuse: drive get makes the placeholder readable (served from the cache)" "OK|svg" \
    "$(body <<<"$out" | json 'j["status"] + "|" + j["stdout"]["head"].strip().split()[-1]')"
  out=$(http GET "/v1/sessions/$drive/files/drivespaces/library/Brand/Logo.svg")
  check "fuse: GET of a local mount file answers it from the cache" "200|svg" "$(status <<<"$out")|$(body <<<"$out")"
  http PUT "/v1/sessions/$drive/drive/blobs/$(sha new)" 'new' >/dev/null
  http POST "/v1/sessions/$drive/drive/pull" '{"target":"library","cursor":6,"changes":[{"op":"create","path":"New/notes.md","nodeId":93,"versionId":931,"sha256":"'"$(sha new)"'","size":3},{"op":"create","path":"Media/Clip.mp4","nodeId":94,"versionId":941,"sha256":"'"$(sha clip)"'","size":41943040}]}' >/dev/null
  out=$(dexec '{"command":"sleep 1.2; cat drivespaces/library/New/notes.md; echo; tail -n +3 drivespaces/library/.drive-index | cut -f1 | tr \"\\n\" \" \""}')
  check "fuse: a pull shows in the mount (new file local, index current)" "new|Media/Clip.mp4" \
    "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split())')"
  if [[ "$(docker exec "$name" cat /etc/sandbox-toolkit)" == full ]]; then
    # the toolkit inside the user and mount namespace of the placeholder file system
    out=$(dexec '{"command":"(mkdir -p work && cd work && python3 -c \"from playwright.sync_api import sync_playwright\nwith sync_playwright() as p:\n    b = p.chromium.launch(); pg = b.new_page(); pg.set_content(\\\"<h1>x</h1>\\\"); pg.screenshot(path=\\\"pw.png\\\"); b.close()\nprint(\\\"py-ok\\\")\" && cp ../drivespaces/library/readme.md r.txt && soffice --convert-to pdf r.txt >/dev/null 2>&1 && ls r.pdf)","timeoutSeconds":120}')
    check "fuse: Chromium (Playwright) and LibreOffice inside the namespace" "py-ok|r.pdf" \
      "$(body <<<"$out" | json '"|".join(j["stdout"]["head"].split()) or j["stderr"]["head"][-300:]')"
  fi
  out=$(dexec '{"command":"cat drivespaces/library/Media/Clip.mp4 >/dev/null 2>&1; sleep 1","background":true}')
  job=$(body <<<"$out" | json 'j["jobId"]'); sleep 2
  out=$(http GET "/v1/sessions/$drive/jobs/$job")
  check "fuse: a job's misses reach its hint" "exited|drivespaces/library/Media/Clip.mp4|fuse" \
    "$(body <<<"$out" | json '"|".join([j["state"], j["notLocal"][0]["path"], j["notLocalSource"]])')"
fi
out=$(dexec '{"command":"cat data/big.bin 2>/dev/null; true"}')
check "an exec naming a not-local file carries notLocal" "data/big.bin|31457280|1" \
  "$(body <<<"$out" | json '"|".join([j["notLocal"][0]["path"], str(j["notLocal"][0]["size"]), str(j["notLocalCount"])])')"
out=$(dexec '{"command":"echo beta >> notes/a.txt; ln -s /etc/passwd pw; drive status"}')
check "drive status in the session (local, no pause)" "OK|yes|yes" \
  "$(body <<<"$out" | json '"|".join([j["status"], "yes" if "modified  notes/a.txt" in j["stdout"]["head"] else "no", "yes" if "refused symlink: pw" in j["stdout"]["head"] else "no"])')"
out=$(http POST "/v1/sessions/$drive/drive/stage" '')
staged_sha=$(body <<<"$out" | json '[c["sha256"] for c in j["changes"] if c["path"] == "notes/a.txt"][0]')
check "stage snapshots the change and names the refused symlink" "$(printf 'alphabeta\n' | sha256sum | cut -d' ' -f1)|pw" \
  "$staged_sha|$(body <<<"$out" | json 'j["refusedSymlinks"][0]["path"]')"
out=$(http GET "/v1/sessions/$drive/drive/blobs/$staged_sha")
check "the snapshot is readable as a blob" "200|alphabeta" "$(status <<<"$out")|$(body <<<"$out" | head -1)"
out=$(dexec '{"command":"drive commit -m Plan && echo done","timeoutSeconds":2}')
check "drive commit pauses the exec with a drive request" "DRIVE_REQUESTS|commit|Plan" \
  "$(body <<<"$out" | json '"|".join([j["status"], j["driveRequests"][0]["verb"], j["driveRequests"][0]["payload"]["message"]])')"
seq=$(body <<<"$out" | json 'j["seq"]'); request=$(body <<<"$out" | json 'j["driveRequests"][0]["requestId"]')
sleep 3
out=$(http POST "/v1/sessions/$drive/execs/$seq/continue" '{"responses":[{"requestId":"'"$request"'","ok":true,"commit":{"committed":[{"path":"notes/a.txt","nodeId":1,"versionId":12,"versionNumber":3,"sha256":"'"$staged_sha"'","size":11}]}}]}')
check "continue finishes the exec; the timer stood still while paused" "OK|False|yes|yes" \
  "$(body <<<"$out" | json '"|".join([j["status"], str(j["timedOut"]), "yes" if "committed 1 files: notes/a.txt v3" in j["stdout"]["head"] else "no", "yes" if "done" in j["stdout"]["head"] else "no"])')"
out=$(dexec '{"command":"drive pull","background":true}')
job=$(body <<<"$out" | json 'j["jobId"]'); sleep 1
out=$(http GET "/v1/sessions/$drive/jobs/$job")
check "drive pull in a background job fails at once with the hint" "exited|1|yes" \
  "$(body <<<"$out" | json '"|".join([j["state"], str(j["exitCode"]), "yes" if "background job" in j["stderr"]["head"] else "no"])')"
out=$(http GET "/v1/sessions/$drive/manifest")
check "the legacy manifest leaves the drive's own entries out" "0" \
  "$(body <<<"$out" | json 'len([f for f in j["files"] if f["path"].startswith("drivespaces/") or f["path"] == ".drive-index"])')"
http DELETE "/v1/sessions/$drive" >/dev/null
check "release removes tree, mounts and socket and empties the mounted state dir" "0|0|0|700" \
  "$(docker exec "$name" bash -c 'ls -A /workspace | grep -v "^lost+found$" | wc -l')|$(docker exec "$name" bash -c 'ls -A /tmp | grep -c "drive" || true')|$(docker exec "$name" bash -c 'ls -A /var/lib/sandbox-drive | wc -l')|$(docker exec "$name" stat -c %a /var/lib/sandbox-drive)"
docker exec "$name" sh -c 'mkdir -p /var/lib/sandbox-drive/cache && echo old > /var/lib/sandbox-drive/cache/'"$alpha_sha"' && echo "{}" > /var/lib/sandbox-drive/state.json'
out=$(http POST /v1/sessions '{"workspaceKey":"it-drive-2","mode":"drive","drive":{"spaceId":8,"mounts":[{"name":"other","spaceId":10,"mode":"READ_ONLY","spaceMd":"# Other\n","cursor":1,"entries":[{"path":"x.bin","nodeId":5,"versionId":6,"sha256":"'"$(sha x)"'","size":1}]}]}}')
drive=$(body <<<"$out" | json 'j["sessionId"]')
out=$(dexec '{"command":"ls -A drivespaces | tr \"\\n\" \" \""}')
check "the next session sees only its own mounts" "other" "$(body <<<"$out" | json 'j["stdout"]["head"].strip()')"
out=$(http GET "/v1/sessions/$drive/drive/status")
check "a second claim starts from an empty state dir (leftovers removed and logged)" "0|0|0|yes" \
  "$(body <<<"$out" | json '"|".join([str(len(j["changes"])), str(j["notLocalFiles"]), str(j["budget"]["cacheBytes"])])')|$(docker logs "$name" 2>&1 | grep -q '"drive state leftovers removed at claim".*"entries":2' && echo yes || echo no)"
http DELETE "/v1/sessions/$drive" >/dev/null

# ENOSPC: a second runner whose workspace tmpfs holds only 64 MiB (the default quota of 3.6 GiB never applies), as
# the 4Gi workspace volume does on the cluster: the free space of the mount is the limit that decides
docker run -d --name "$small" --network none --read-only \
  --cap-drop ALL --cap-add SETUID --cap-add SETGID --cap-add KILL --security-opt no-new-privileges \
  --tmpfs /workspace:rw,exec,mode=0777,size=64m --tmpfs /tmp:rw,exec,mode=1777,size=256m --pids-limit 1024 \
  -e RUNNER_QUOTA_WATCH_MODE=statfs \
  "$image" >/dev/null
main="$name"; name="$small"
for attempt in $(seq 1 50); do
  if out=$(http GET /healthz 2>/dev/null) && [[ "$(status <<<"$out")" == 200 ]]; then break; fi
  sleep 0.2
done
out=$(http POST /v1/sessions '{"workspaceKey":"it-small"}')
session=$(body <<<"$out" | json 'j["sessionId"]')
out=$(exec_json '{"command":"dd if=/dev/zero of=fill bs=1M count=100 status=none"}')
check "ENOSPC in an exec answers QUOTA_EXCEEDED (NO_SPACE)" "QUOTA_EXCEEDED|NO_SPACE|yes" \
  "$(body <<<"$out" | json '"|".join([j["status"], j["quota"]["reason"], "yes" if j["quota"]["largest"][0]["path"] == "/workspace/fill" else "no"])')"
exec_json '{"command":"rm -f fill"}' >/dev/null
# the watcher in statfs mode (as it switches on the cluster when a walk is too slow): a slow writer is stopped once the
# mount's free space falls below the reserve (16 MiB), long before ENOSPC
out=$(exec_json '{"command":"for i in $(seq 1 60); do head -c 1048576 /dev/zero > slow-$i; sleep 0.05; done; sleep 30"}')
check "statfs watcher stops a slow writer on a filling mount" "QUOTA_EXCEEDED|NO_SPACE|True|5" \
  "$(body <<<"$out" | json '"|".join([j["status"], j["quota"]["reason"], str(j.get("shellExited", False)), str(len(j["quota"]["largest"]))])')"
exec_json '{"command":"rm -f slow-*"}' >/dev/null
out=$(dd if=/dev/zero bs=1048576 count=70 2>/dev/null | docker exec -i -u 65534 "$name" python3 -c '
import http.client, sys
body = sys.stdin.buffer.read()
connection = http.client.HTTPConnection("127.0.0.1", 8080)
try:
    connection.request("PUT", "/v1/sessions/'"$session"'/files/big.bin", body=body)
except (BrokenPipeError, ConnectionResetError):
    pass
response = connection.getresponse(); print(response.status); print(response.read().decode())')
check "ENOSPC in a PUT answers 413 QUOTA_EXCEEDED (NO_SPACE)" "413|QUOTA_EXCEEDED|NO_SPACE" \
  "$(status <<<"$out")|$(body <<<"$out" | json 'j["error"] + "|" + j["reason"]')"
check "the failed PUT left no file" "0" "$(docker exec "$name" bash -c 'ls -A /workspace | grep -v -e "^.runner$" -e "^lost+found$" | wc -l')"
out=$(http DELETE "/v1/sessions/$session")
check "small runner releases" "200" "$(status <<<"$out")"
name="$main"

# without a volume at /workspace the runner refuses to start
docker run --name "$small-nomount" --network none --read-only --tmpfs /tmp:rw,mode=1777 \
  --cap-drop ALL --cap-add SETUID --cap-add SETGID --cap-add KILL "$image" >/dev/null 2>&1 && code=0 || code=$?
docker rm -f "$small-nomount" >/dev/null 2>&1 || true
check "runner refuses to start without a workspace mount" "2" "$code"

# A loop-backed ext4 workspace is not tested locally: Docker's local volume driver hands "loop" to mount(2), which
# cannot set up loop devices ("loop: invalid argument"), and every other way needs a privileged container. On the
# cluster the workspace is the ext4/XFS volume of agent-sandbox-local.

echo
echo "logs (ids, durations and states only):"
docker logs "$name" 2>&1 | head -30
echo
echo "passed $passed, failed $failed"
[[ $failed -eq 0 ]]

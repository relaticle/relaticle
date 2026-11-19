#!/usr/bin/env bash
# Root cron, every 5 minutes. Writes the unexpected readers of the secret files to a status file the app's health check reads.

set -euo pipefail

SITE_DIR="${SITE_DIR:-/home/forge/relaticle.com}"
STATE_DIR="${STATE_DIR:-/var/lib/relaticle-audit}"
STATUS_FILE="${STATUS_FILE:-$STATE_DIR/status.json}"
CHECKPOINT="$STATE_DIR/ausearch.checkpoint"
SESSIONS_FILE="$STATE_DIR/scheduler-sessions.json"
RAW="$STATE_DIR/ausearch.out"

export LC_ALL=C

mkdir -p "$STATE_DIR"
chmod 755 "$STATE_DIR"

search() {
    local start="$1" status=0
    ausearch -k relaticle-secrets -i --checkpoint "$CHECKPOINT" --start "$start" > "$RAW" 2>/dev/null || status=$?
    return "$status"
}

start=today
if [[ -f "$CHECKPOINT" ]]; then
    start=checkpoint
fi

status=0
search "$start" || status=$?

# 10, 11 and 12 mean the checkpoint no longer matches the log, as after a rotation.
if [[ "$status" -ge 10 && "$status" -le 12 ]]; then
    rm -f "$CHECKPOINT"
    status=0
    search today || status=$?
fi

# 1 is "no matches". Any other failure leaves the status stale, which the health check reports.
if [[ "$status" -eq 1 ]]; then
    : > "$RAW"
elif [[ "$status" -ne 0 ]]; then
    echo "ausearch failed with status $status" >&2
    exit 1
fi

SITE_DIR="$SITE_DIR" STATUS_FILE="$STATUS_FILE" SESSIONS_FILE="$SESSIONS_FILE" RAW="$RAW" python3 -I - <<'PY'
import json
import os
import re
import time

SITE = os.environ["SITE_DIR"]
STATUS = os.environ["STATUS_FILE"]
SESSIONS = os.environ["SESSIONS_FILE"]
RAW = os.environ["RAW"]

DAY = 24 * 3600
WINDOW_MAX = 30 * 60
PHP_EXES = {"/usr/bin/php8.5", "/usr/sbin/php-fpm8.5"}
SCHEDULER = re.compile(r"\bartisan\s+schedule:(run|finish)\b")
BLOG_MCP = re.compile(r"\bartisan\s+blog:mcp\b")

now = int(time.time())


def load(path, default):
    try:
        with open(path) as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return default


def parse_time(stamp):
    match = re.match(r"(\d\d)/(\d\d)/(\d{2,4}) (\d\d):(\d\d):(\d\d)", stamp)
    month, day, year, hour, minute, second = (int(part) for part in match.groups())
    if year < 100:
        year += 2000
    return int(time.mktime((year, month, day, hour, minute, second, 0, 0, -1)))


def proctitle(value):
    if re.fullmatch(r"(?:[0-9A-Fa-f]{2})+", value):
        value = bytes.fromhex(value).decode("utf-8", "replace").replace("\x00", " ")
    return value


def events():
    with open(RAW, errors="replace") as handle:
        blocks = handle.read().split("----")
    for block in blocks:
        event = {"path": None, "proctitle": ""}
        for line in block.splitlines():
            stamp = re.search(r"msg=audit\((.+?)(?:\.\d+)?:\d+\)", line)
            if stamp and "at" not in event:
                event["at"] = parse_time(stamp.group(1))
            if line.startswith("type=SYSCALL"):
                for field in ("auid", "ses", "exe"):
                    found = re.search(r"\b%s=(\S+)" % field, line)
                    event[field] = found.group(1).strip('"') if found else ""
            elif line.startswith("type=PATH") and event["path"] is None:
                found = re.search(r"\bname=(\S+)", line)
                if found and found.group(1).strip('"').startswith("/home/forge/"):
                    event["path"] = found.group(1).strip('"')
            elif line.startswith("type=PROCTITLE"):
                found = re.search(r"\bproctitle=(.*)$", line)
                event["proctitle"] = proctitle(found.group(1)) if found else ""
        if "at" in event and "exe" in event and event["path"]:
            yield event


def deploy_windows():
    windows = []
    active = os.path.join(SITE, "storage/framework/deploy.window")
    try:
        if now - os.path.getmtime(active) < WINDOW_MAX:
            start = int(open(active).read().strip() or os.path.getmtime(active))
            windows.append((start, now))
    except (OSError, ValueError):
        pass
    try:
        with open(os.path.join(SITE, "storage/framework/deploy.windows")) as handle:
            for line in handle:
                parts = line.split()
                if len(parts) == 2:
                    start, end = int(parts[0]), int(parts[1])
                    windows.append((start, min(end, start + WINDOW_MAX)))
    except (OSError, ValueError):
        pass
    return windows


def expected(event, windows, scheduler_sessions):
    auid = event["auid"]
    exe = event["exe"]
    title = event["proctitle"]
    if auid in ("unset", "4294967295") and exe in PHP_EXES:
        return True
    if exe in PHP_EXES and SCHEDULER.search(title):
        return True
    if event["ses"] in scheduler_sessions:
        return True
    if exe in PHP_EXES and BLOG_MCP.search(title):
        return True
    return any(start <= event["at"] <= end for start, end in windows)


previous = load(STATUS, {"unexpected": []})
sessions = {ses: seen for ses, seen in load(SESSIONS, {}).items() if now - seen < 2 * 3600}
found = list(events()) if os.path.getsize(RAW) else []

for event in found:
    if event["exe"] in PHP_EXES and SCHEDULER.search(event["proctitle"]) and event["ses"] not in ("unset", ""):
        sessions[event["ses"]] = now

windows = deploy_windows()
unexpected = [
    {"at": e["at"], "exe": e["exe"], "auid": e["auid"], "path": e["path"]}
    for e in found
    if not expected(e, windows, sessions)
]

merged = {}
for read in previous.get("unexpected", []) + unexpected:
    if now - read["at"] < DAY:
        merged[(read["at"], read["exe"], read["auid"], read["path"])] = read

result = {"scanned_at": now, "unexpected": sorted(merged.values(), key=lambda r: r["at"])}

with open(SESSIONS, "w") as handle:
    json.dump(sessions, handle)

tmp = STATUS + ".tmp"
with open(tmp, "w") as handle:
    json.dump(result, handle)
os.chmod(tmp, 0o644)
os.replace(tmp, STATUS)
PY

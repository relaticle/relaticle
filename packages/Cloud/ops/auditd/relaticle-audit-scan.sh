#!/usr/bin/env bash
# Root cron, every 5 minutes. Writes the unexpected readers of the secret files to a status file the app's health check reads.

set -euo pipefail

export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export LC_ALL=C

AUSEARCH="${AUSEARCH:-/usr/sbin/ausearch}"
PYTHON="${PYTHON:-/usr/bin/python3}"
SITE_DIR="${SITE_DIR:-/home/forge/relaticle.com}"
BACKUP_DIR="${BACKUP_DIR:-/home/forge/backups}"
STATE_DIR="${STATE_DIR:-/var/lib/relaticle-audit}"
STATUS_FILE="${STATUS_FILE:-$STATE_DIR/status.json}"
CHECKPOINT="$STATE_DIR/ausearch.checkpoint"
CHECKPOINT_NEXT="$CHECKPOINT.next"
RAW="$STATE_DIR/ausearch.out"

/bin/mkdir -p "$STATE_DIR"
/bin/chmod 755 "$STATE_DIR"

# Cron gives a job a pipe on stdin, and ausearch reads that instead of the logs unless told to use them.
search() {
    local start="$1" status=0

    /bin/rm -f "$CHECKPOINT_NEXT"
    if [[ -f "$CHECKPOINT" ]]; then
        /bin/cp "$CHECKPOINT" "$CHECKPOINT_NEXT"
    fi

    "$AUSEARCH" --input-logs -k relaticle-secrets -i --checkpoint "$CHECKPOINT_NEXT" --start "$start" > "$RAW" 2>/dev/null < /dev/null || status=$?
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
    /bin/rm -f "$CHECKPOINT"
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

AUSEARCH="$AUSEARCH" SITE_DIR="$SITE_DIR" BACKUP_DIR="$BACKUP_DIR" STATUS_FILE="$STATUS_FILE" RAW="$RAW" "$PYTHON" -I - <<'PY'
import json
import os
import re
import subprocess
import time

AUSEARCH = os.environ["AUSEARCH"]
SITE = os.environ["SITE_DIR"]
BACKUPS = os.environ["BACKUP_DIR"].rstrip("/")
STATUS = os.environ["STATUS_FILE"]
RAW = os.environ["RAW"]

DAY = 24 * 3600
WINDOW_MAX = 30 * 60
CLOSED_WINDOWS_KEPT = 3
PHP_EXES = {"/usr/bin/php8.5", "/usr/sbin/php-fpm8.5"}
UNSET = ("unset", "4294967295")
ARTISAN = r"^(?:\S*/)?php[\d.]*\s+(?:\S*/)?artisan\s+"
CRON_ARTISAN = re.compile(ARTISAN + r"(?!tinker\b)[a-z][a-z0-9:_-]*(?:\s.*)?$")
BLOG_MCP = re.compile(ARTISAN + r"blog:mcp\s*$")
# The nightly backup script lists the directory to prune it and uploads the new dump with sftp.
BACKUP_JOB_EXES = {"/usr/bin/sftp", "/usr/bin/find"}

now = int(time.time())
cron_sessions = {}


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


def decode(value):
    value = value.strip().strip('"')
    if re.fullmatch(r"(?:[0-9A-Fa-f]{2})+", value):
        value = bytes.fromhex(value).decode("utf-8", "replace").replace("\x00", " ")
    return value


def field(line, name):
    found = re.search(r"\b%s=(\S+)" % name, line)
    return found.group(1).strip('"') if found else ""


def resolve(names, cwd):
    for name, nametype in names:
        if nametype != "PARENT" and name and name != "(null)":
            return os.path.normpath(name if name.startswith("/") else os.path.join(cwd or "/", name))
    return "unknown"


def events():
    with open(RAW, errors="replace") as handle:
        blocks = handle.read().split("----")
    for block in blocks:
        event = {"proctitle": ""}
        names = []
        cwd = ""
        for line in block.splitlines():
            stamp = re.search(r"msg=audit\((.+?)(?:\.\d+)?:\d+\)", line)
            if stamp and "at" not in event:
                event["at"] = parse_time(stamp.group(1))
            if line.startswith("type=SYSCALL"):
                for name in ("auid", "ses", "exe"):
                    event[name] = field(line, name)
            elif line.startswith("type=PATH"):
                names.append((field(line, "name"), field(line, "nametype")))
            elif line.startswith("type=CWD"):
                cwd = field(line, "cwd")
            elif line.startswith("type=PROCTITLE"):
                found = re.search(r"\bproctitle=(.*)$", line)
                event["proctitle"] = decode(found.group(1)) if found else ""
        if "at" in event and event.get("exe"):
            event["path"] = resolve(names, cwd)
            yield event


def started_by_cron(ses):
    if ses in ("", *UNSET):
        return False
    if ses not in cron_sessions:
        try:
            out = subprocess.run(
                [AUSEARCH, "--input-logs", "-i", "--start", "boot", "-m", "USER_START", "--session", ses],
                stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=60,
            ).stdout
        except (OSError, subprocess.SubprocessError):
            out = ""
        cron_sessions[ses] = any(
            re.search(r'\bexe="?/usr/sbin/cron"?(\s|$)', line)
            for line in out.splitlines()
            if line.startswith("type=USER_START")
        )
    return cron_sessions[ses]


def deploy_windows(floor):
    windows = []
    try:
        start = int(open(os.path.join(SITE, "storage/framework/deploy.window")).read().strip())
        if start <= now:
            windows.append((start, min(now, start + WINDOW_MAX)))
    except (OSError, ValueError):
        pass
    try:
        with open(os.path.join(SITE, "storage/framework/deploy.windows")) as handle:
            lines = handle.read().splitlines()[-CLOSED_WINDOWS_KEPT:]
        for line in lines:
            parts = line.split()
            if len(parts) != 2:
                continue
            start, end = int(parts[0]), int(parts[1])
            if start <= end <= now and end >= floor:
                windows.append((start, min(end, start + WINDOW_MAX)))
    except (OSError, ValueError):
        pass
    return windows


def in_backups(path):
    return path == BACKUPS or path.startswith(BACKUPS + "/")


def expected(event, windows):
    exe = event["exe"]
    title = event["proctitle"]
    if event["auid"] in UNSET and exe in PHP_EXES:
        return True
    if exe == "/usr/bin/php8.5" and BLOG_MCP.search(title):
        return True
    if exe == "/usr/bin/php8.5" and CRON_ARTISAN.search(title) and started_by_cron(event["ses"]):
        return True
    if exe in BACKUP_JOB_EXES and in_backups(event["path"]) and started_by_cron(event["ses"]):
        return True
    return any(start <= event["at"] <= end for start, end in windows)


previous = load(STATUS, {"scanned_at": 0, "unexpected": []})
floor = previous["scanned_at"] if previous.get("scanned_at") else now - 600
found = list(events()) if os.path.getsize(RAW) else []
windows = deploy_windows(floor)

unexpected = [
    {"at": e["at"], "exe": e["exe"], "auid": e["auid"], "path": e["path"]}
    for e in found
    if not expected(e, windows)
]

merged = {}
for read in previous.get("unexpected", []) + unexpected:
    if now - read["at"] < DAY:
        merged[(read["at"], read["exe"], read["auid"], read["path"])] = read

result = {"scanned_at": now, "unexpected": sorted(merged.values(), key=lambda r: r["at"])}

tmp = STATUS + ".tmp"
with open(tmp, "w") as handle:
    json.dump(result, handle)
os.chmod(tmp, 0o644)
os.replace(tmp, STATUS)
PY

# The checkpoint advances only after the status holds the events it covers.
if [[ -f "$CHECKPOINT_NEXT" ]]; then
    /bin/mv "$CHECKPOINT_NEXT" "$CHECKPOINT"
fi

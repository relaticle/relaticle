# Secret file auditing

auditd records every read, write and attribute change of the production secret files. A root cron job turns the records into a status file, and the app's `SecretFileAccessCheck` reads it. Oh Dear polls the check.

## Install (Forge Recipe, as root)

1. `apt-get install -y auditd`
2. Create the parent directories the rules name, because a rule on a missing path does not load: `/home/forge/.config/relaticle`, `/home/forge/backups`, and `storage` and `bootstrap/cache` under the site.
3. Copy `relaticle-secrets.rules` to `/etc/audit/rules.d/`, then run `augenrules --load`.
4. Verify `auditctl -s` shows `enabled 2` and `auditctl -l` lists every rule, b64 and b32. The rules end with `-e 2`, so changing them needs a reboot.
5. Disable io_uring, because an io_uring open skips syscall auditing: write `kernel.io_uring_disabled=2` to `/etc/sysctl.d/60-relaticle-io-uring.conf`, then run `sysctl --system`.
6. Copy `relaticle-audit-scan.sh` to `/usr/local/sbin/`, make it executable, and add a root cron line:
   `*/5 * * * * root /usr/local/sbin/relaticle-audit-scan.sh`
7. Set `HEALTH_SECRET_ACCESS_STATUS_PATH=/var/lib/relaticle-audit/status.json` in the production settings, then deploy.
8. Test: after a deploy, run `cat .env` from the site root over SSH. The Oh Dear check turns red within 5 minutes. A later deploy alone must not turn it red.

## What counts as expected

- A PHP 8.5 or PHP-FPM 8.5 process with no login session: FPM, Horizon and Reverb under supervisor.
- A PHP 8.5 `artisan` command whose session cron started. The scan asks auditd for the session's `USER_START` record and requires `/usr/sbin/cron`. Other programs in that session stay unexpected, and so does `artisan tinker`.
- `php8.5 artisan blog:mcp`.
- `sftp` or `find` reading `/home/forge/backups` in a session cron started: the nightly backup uploads the new dump and prunes old ones.
- Any event inside a deploy window.

## Deploy windows

`bin/deploy.sh` writes the start time to `storage/framework/deploy.window` when a deploy begins, and appends `start end` to `storage/framework/deploy.windows` when it ends. The scan caps each window at 30 minutes from its start. It ignores a window that starts in the future, reads only the last three closed windows, and ignores a closed window that ended before the previous scan.

## Known limits

- Anything that can run code as `forge` inside FPM or Horizon reads the secrets as an expected reader. The audit does not stop or flag it.
- The deploy window files belong to `forge`, so a `forge` process can open a window of up to 30 minutes and hide reads inside it. Only a root-owned window would close this, and no narrow mechanism exists for it today.
- Anyone who can edit `forge`'s crontab can run a PHP 8.5 `artisan` command in a cron session, or copy a backup out with `sftp`.
- The audit records stay on the server, and root can change them.
- The backups directory is watched for reads only, because the nightly backup job writes there. Moving a backup out of the directory is not recorded.
- After a checkpoint mismatch the scan re-reads the whole day, so reads from that day's earlier deploys can be reported once.

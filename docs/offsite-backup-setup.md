# Off-site backup setup

How to send an encrypted copy of the portal's database snapshot to somewhere
outside your hosting account, on a schedule. It protects you when the host
loses the whole account, snapshots included.

Getting a copy **back** is in
[the recovery runbook, section 8.6](disaster-recovery-runbook.md#86-the-servers-own-snapshots-are-gone).

**Tested** below means we ran it on 12 October 2026 on a throwaway copy of
release 1.4.0; the number is a row of the table "What was tested" at the end
of the runbook. **Your host** means it depends on your hosting company or your
destination, and we could not run it. The real rclone program (1.75.2) was
run against a folder on the test machine only. No cloud destination, S3 or
SFTP was used.

On **30 October 2026** the settings on the admin page were made to really
decide what a run does (#671), and the recording of a run was fixed. Those two
are tested by `tools/offsite-settings-selftest.php` — a real MySQL database,
real bash, real `openssl`, and real rclone sending to a folder on the test
machine. Still no cloud destination, and the `s3` and `sftp` branches are not
exercised at all: they need the `aws` and `scp` programs and a second host.

## What it does, and what it does not

It takes the newest finished snapshot folder,
`_backups/upgrade-YYYYMMDD-HHMMSS/`, packs it into one file, locks that file
with a key only you hold, and sends it. The destination only ever sees a
locked file named `upgrade-YYYYMMDD-HHMMSS.tar.gz.enc`.

While it works, the packed file sits in a folder the script makes for that
run, which only the account running it can open. The unlocked pack is deleted
as soon as the locked one exists, before anything is sent. *Tested (19).*

Four limits. Each one matters:

1. **It does not make a snapshot.** The portal makes one only when an upgrade
   runs, or when a global administrator presses **Run snapshot now** on
   `/admin/maintenance/backup`. If neither has happened since the last run,
   the same old snapshot is sent again. Press the button before each
   scheduled run, and check the name in the run's output.
2. **It sends the database only.** Uploaded files (`_uploads/`) and the keys
   (`_auth_keys/`) are not in it. Keep your own copies of those. Without
   `_auth_keys/enc.key`, the secrets inside a restored database cannot be
   read.
3. **It can only send a snapshot the portal managed to make, and it does not
   judge it.** The runbook's
   [section 2.1](disaster-recovery-runbook.md#21-what-the-portals-own-backup-still-cannot-do)
   says when the portal cannot make one, and when one it made cannot be put
   back whole (item 8); such a snapshot is sent like any other. Keep a full
   dump as well
   ([runbook section 9](disaster-recovery-runbook.md#9-the-second-kind-of-backup-a-full-dump)).
4. **It does not check that the snapshot is sound.** A snapshot folder whose
   name is not `upgrade-YYYYMMDD-HHMMSS` is never sent. One that has its
   `_manifest.json` but a damaged file inside is sent, and reported as a
   success. The check that finds a damaged file is the check-only run in
   [runbook 8.3](disaster-recovery-runbook.md#83-from-the-command-line), step 2.

## Set it up, once

You need a command line on the server.

1. Get **three** files from the portal's source, all in `tools/offsite-backup/`:
   `sync-offsite.sh.example`, `log-offsite-result.php` and
   `read-offsite-settings.php`. They are **not** on the server: only the
   `web/` folder is deployed. Upload them to any folder there.

   Take all three from the **same release**. The script and the settings
   reader agree on a format with a number in it; if they come from different
   releases the script says so and carries on with the values in its own file.
2. Set `PORTAL` to the portal's folder (the one holding `_core`, `_auth_keys`
   and `_backups`), then, in the folder holding the two files:

   ```bash
   cp sync-offsite.sh.example "$PORTAL/_backups/sync-offsite.sh"
   cp log-offsite-result.php "$PORTAL/_backups/log-offsite-result.php"
   cp read-offsite-settings.php "$PORTAL/_backups/read-offsite-settings.php"
   chmod +x "$PORTAL/_backups/sync-offsite.sh"
   openssl rand -base64 48 > "$PORTAL/_auth_keys/offsite.key"
   chmod 600 "$PORTAL/_auth_keys/offsite.key"
   ```

   *Tested (19).* It prints nothing. `offsite.key` now holds
   one line of 64 characters.
3. **Copy `offsite.key` somewhere that is not this server, today.** A password
   manager that stores files is right. Keep the file itself. If the key is
   lost, no copy can ever be opened.

   If you already have a key made with the older instruction
   (`openssl rand -base64 64`), it still works and you need not change it. It
   has two lines, and only the first is used: pasted back as one line it no
   longer opens anything. *Tested (20).* Keep that file exactly as it is.
4. Choose a destination on `/admin/maintenance/offsite-backup` and **tick
   Enabled**, then press Save. The page decides: which program sends the copy,
   the rclone remote, how many copies to keep, the alert address, and whether
   it sends at all.

   **Tick Enabled, or nothing is sent.** The setting ships switched off, so a
   scheduled run records itself as `skipped` and sends nothing until you tick
   it. The run log on that page says exactly that when it happens.

   Two addresses are **not** on that page, so if you choose `s3` or `sftp` you
   must still set them in `$PORTAL/_backups/sync-offsite.sh`
   (`S3_BUCKET` or `SFTP_TARGET`) — see issue #688.

   - **rclone** (Backblaze B2, Cloudflare R2, Google Drive, OneDrive and
     others): run `rclone config` once to make a remote, then put its name and
     folder in the **rclone remote** box, for example `b2:portal-backups`.
   - **S3**: have the `aws` program set up, then set `S3_BUCKET` in the script.
   - **SFTP**: have a key that lets this server sign in to the second one,
     then set `SFTP_TARGET` in the script.

   *Your host and your destination.*
5. The values inside `sync-offsite.sh` are now a **fallback**, used when a
   setting is not stored, when the database cannot be reached, or when a
   stored value is something the script will not put on a command line. It is
   worth setting them anyway: a run that cannot reach the database still sends.

   **Two of those three say so; the first does not.** A refused value, and a
   failure to read the settings at all, each print a `WARNING`. A setting with
   **no row stored** prints nothing — that is the ordinary state of a setting
   nobody has set, and warning about it every week would teach people to
   ignore the warnings that matter. So seeing no warning does not mean every
   value came from the page. The `Settings from:` line says how many did; if it
   is fewer than six, the rest came from the script file.

   The alert address needs the server's `mail` program. *Your host. Sending was
   not tested.*

   **If you set this up before 30 October 2026**, your `sync-offsite.sh` reads
   none of those boxes. Replace all three files, then tick **Enabled** on the
   page. Until you do, the page tells you plainly that the script on your
   server is ignoring it.

   Whenever a run stops before the end (a failed upload, a refusal, or being
   stopped), it prints a line starting `ERROR:`, records the run as failed,
   deletes its working folder and ends with exit code 1. *Tested (19).* It
   cannot do that after `kill -9` or a power cut: its working folder
   (`portal-offsite.` and eight more characters, in the server's temporary
   folder) is then left behind, readable only by the account that ran it.
   Delete it by hand.

Six boxes on `/admin/maintenance/offsite-backup` **are** read by the script,
once, at the start of each run, and they override the values in the script
file: Enabled, the destination, the rclone remote, the two "copies to keep"
numbers and the alert address. Until 30 October 2026 none of them was read by
anything at all (#671). *Tested: `tools/offsite-settings-selftest.php`, with a
real database and real rclone sending to a folder on the test machine.*

## Run it once by hand

Press **Run snapshot now** on `/admin/maintenance/backup` first. Then:

```bash
"$PORTAL/_backups/sync-offsite.sh"
```

*Tested (19), with a stand-in for rclone that copies to a local folder.* You
should see:

```text
Selected snapshot: upgrade-YYYYMMDD-HHMMSS
Bundle size: 85840 bytes
Upload completed in 0s
Copies at the destination: 1. Old ones deleted: 0. The newest 20 are kept.
Logged.
Off-site backup complete.
```

Your size, time and count will differ. The "Copies" line is printed for
rclone only. Then look at the destination: the file
`upgrade-YYYYMMDD-HHMMSS.tar.gz.enc` should be there. **Then practise getting
it back** (runbook 8.6) before you rely on it.

| It says | It means | Do this |
| --- | --- | --- |
| `Encryption key not found` | Step 2 was not finished. | Make the key. |
| `No complete snapshot ... found` | No snapshot exists yet. | Press **Run snapshot now**. |
| `WARNING: skipped unfinished snapshot(s)` | A newer snapshot failed while being made, or is being made at this moment. An older, finished one was sent. | If it is still there tomorrow, find out why: the health page says "a snapshot failed". Delete the unfinished one on `/admin/maintenance/backup`; the portal also removes it at the next upgrade once it is a day old. |
| `rclone not installed` / `aws CLI not installed` / `scp not installed` | The program for your destination is missing. | Install it, or ask your host. *Your host.* |
| `The destination is rclone but no rclone remote is set` | Neither the box on the admin page nor `RCLONE_REMOTE` in the script holds a remote. | Fill in the **rclone remote** box and save. |
| `The destination is s3 (or sftp) but ... is not set in this file` | Those two addresses are not on the admin page (#688). | Set `S3_BUCKET` or `SFTP_TARGET` in the script file. |
| `Off-site backup is switched off on /admin/...` | **Enabled** is not ticked. The run ends successfully having sent nothing, and is recorded as `skipped`. | Tick **Enabled** and save. |
| `Settings from: this script file` | The admin page was not read — the next line, or the `WARNING` above, says why. | Usually: copy `read-offsite-settings.php` to `_backups/`. |
| `WARNING: read-offsite-settings.php is not there` | Only two of the three files were copied. | Copy the third. |
| `WARNING: ... answered in a format this script does not know` | The script and the settings reader came from different releases. | Copy both again from one release. |
| `WARNING: the stored rclone remote cannot be used because ...` | A stored value is one the portal will not put on a command line, so it was held back and the script's own value used instead. | Correct it on the admin page, which now says the same thing under the box and when you press Save. |
| `WARNING: the stored ... is NNN characters long` | A stored value is longer than 256 characters, which no real value is. | Correct it on the admin page. |
| `WARNING: the settings reader sent a ... this script will not use` | The script's own second check refused a value the settings reader passed on. Normally this means the two files came from different releases, or one was edited on the server. | Copy all three files again from one release. |
| `WARNING: the copy WAS sent, but this run could not be recorded` | The copy is at the destination; the portal could not be told about it, so the page will not list it. | Check `log-offsite-result.php` is in `_backups/` and the database was reachable. |
| `KEEP_WEEKLY and KEEP_MONTHLY must be whole numbers` | One of the two keep numbers in the script is not a plain whole number (`8.5`, or a word). Nothing was sent. | Set both to whole numbers. `08` is read as eight. |
| `The first line of ... is blank` | The key file starts with an empty line, so the copy would be locked with an empty password. Nothing was sent. | Follow the message. Keep the old file if copies were made with it. |
| `ERROR: A step failed near line ...` | Packing, locking or sending failed. The lines above it say which. Nothing is left in the server's temporary folder; the copy at the destination may be missing or incomplete. | Put right what the lines above name, then run it again. |
| `ERROR: Stopped by a signal` | Somebody, or the server, stopped the run. | Run it again. |
| `WARNING: other accounts on this server can use the key file` | The key file's permissions are too open. The copy was still sent. | `chmod 600` on the key file. |
| `WARNING: left out folder(s) not named upgrade-YYYYMMDD-HHMMSS` | A folder in `_backups` was named by hand. It is never sent. | Nothing, if that is what you meant. |
| `WARNING: the destination could not be listed` | The new copy was sent, but no old copy was deleted. | If it says so every run, check the account's rights at the destination. |
| No `Logged.` line | The run was not recorded. See below. | |

*Tested (19): every row except the `aws` and `scp` messages.*

## Schedule it

In your hosting panel's scheduled jobs (often called "cron"), weekly, at a
quiet hour. The command is the full path of the script, for example
`/home/example_user/portal/web/_backups/sync-offsite.sh`. *Your host.*

## Where to see how it went

`/admin/maintenance/offsite-backup` lists each run and its result. Its **Run
now** button runs the same script. *Tested (19).* The button needs PHP to be
allowed to start programs, which some hosts forbid. *Your host.*

**This used to work only when the portal's folder was called `web`**, and it is
now fixed (#671). The recorder looked for the portal at a fixed path that was
right only in a checkout of the source; on a real server, where that folder is
named by you, it could not load the portal, so **no run was ever recorded and
this page showed nothing however many copies had really been sent** — a failed
run included. It now finds the portal whatever the folder is called. *Tested:
section B of `tools/offsite-settings-selftest.php` runs the recorder from a
portal folder named `portal-live` and reads the row back out of the database.*

Check the destination itself as well as this page. A copy can be sent and the
run still go unrecorded if the database is unreachable at the end of the run —
the page then shows nothing while the file really is there.

## Old copies at the destination

- **rclone:** after each run the script looks only at files named
  `upgrade-YYYYMMDD-HHMMSS.tar.gz.enc`. It keeps the newest 20 of them by
  name (the two numbers `KEEP_WEEKLY` and `KEEP_MONTHLY`, added together) and
  deletes the older ones, printing `Deleted old copy:` for each. It does not
  keep one per month. The copy just sent is never deleted. Any other file in
  that folder is never counted and never deleted. If the folder cannot be
  listed, nothing is deleted and the run prints a `WARNING`. *Tested (19) with
  rclone 1.75.2 and a folder on the test machine; not with a cloud
  destination.*

  **Give each portal a destination folder of its own.** Two portals sending
  to one folder count, and delete, each other's copies.

  "Newest" is judged by the name, never by the date a file was sent. If the
  folder already holds 20 copies with later names than today's (a server
  clock that was wrong, or copies from another server), those are kept, and
  each run's new copy only replaces the one the run before it sent. *Tested
  (19).*
- **S3 and SFTP:** nothing is deleted. Set a lifecycle rule on the bucket, or
  a clean-up on the second server. *Your host.*

## What protects what

- **On the way:** the destination's own program encrypts the connection.
- **At the destination:** the file is locked with AES-256. Whoever runs the
  destination cannot open it without `offsite.key`.
- **If the key is lost:** every copy is unreadable for good.
- **If the server is broken into:** the key is on it, and so is the database.
  The locking does not help then. Make a new key afterwards, and keep the old
  one for as long as you keep old copies.

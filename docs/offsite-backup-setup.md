# Off-site backup setup

Weekly encrypted copy of the newest snapshot to a destination outside of
DreamHost. Defends against single-host incidents that would lose `_backups/`
along with the portal.

## What gets uploaded

1. The newest `web/_backups/snapshot-*` directory (built by the existing
   `DbBackup` engine, PR #220).
2. `tar -czf` bundled.
3. AES-256-CBC encrypted with a key file you create at
   `web/_auth_keys/offsite.key`. The key file is **never committed**.

The destination only ever sees ciphertext.

## One-time setup

The reference scripts live under `tools/offsite-backup/` so they are tracked
in git. Copy them into the gitignored `web/_backups/` dir on the server:

```bash
cp tools/offsite-backup/sync-offsite.sh.example web/_backups/sync-offsite.sh
cp tools/offsite-backup/log-offsite-result.php  web/_backups/log-offsite-result.php
chmod +x web/_backups/sync-offsite.sh

# Generate the encryption key (never commit it):
openssl rand -base64 64 > web/_auth_keys/offsite.key
chmod 600 web/_auth_keys/offsite.key
```

Edit `sync-offsite.sh` and set ONE of the destination blocks:

- **rclone** (Backblaze B2 / R2 / GDrive / OneDrive): run `rclone config`
  once to create a remote, then set `RCLONE_REMOTE="myremote:portal-backups"`.
- **S3 / S3-compatible**: have `aws` CLI configured, set `S3_BUCKET`.
- **SFTP**: have an ssh key authorised on the second host, set `SFTP_TARGET`.

## Schedule via DreamHost

Panel → Goodies → Cron Jobs:

- Schedule: weekly, Sundays, 04:00 UTC
- Command: `/home/USER/portal/web/_backups/sync-offsite.sh`

Confirm the first run via **Admin → Maintenance → Off-site backup → Run now**.

## Restoring

```bash
# Pull the ciphertext bundle down from the destination
rclone copy myremote:portal-backups/snapshot-YYYYMMDD.tar.gz.enc ./

# Decrypt
openssl enc -aes-256-cbc -d -pbkdf2 \
    -in snapshot-YYYYMMDD.tar.gz.enc \
    -out snapshot.tar.gz \
    -pass file:web/_auth_keys/offsite.key

tar -xzf snapshot.tar.gz
# Then feed the snapshot into the existing /admin/maintenance/backup restore UI.
```

## Retention

`KEEP_WEEKLY` (default 8) and `KEEP_MONTHLY` (default 12) in the script.
rclone pruning is built in; for S3 / SFTP set a lifecycle rule or cron on
the destination host (the script just notes it can't manage that side).

## Failure handling

- The script logs every run into `tblOffsiteSyncLog` regardless of outcome.
- Failures email `backup.offsite.alertEmail` (set in the admin UI).
- The admin dashboard at `/admin/maintenance/offsite-backup` colour-codes the
  most recent run; investigate when red.

## Threat model

- **In transit**: TLS (rclone / aws / scp).
- **At rest on the destination**: AES-256-CBC ciphertext. Without
  `_auth_keys/offsite.key` the destination admin cannot decrypt.
- **Key file loss**: the off-site copies become unrecoverable. Keep a
  password-manager copy of the key for disaster recovery.
- **On-host theft of the key + on-host theft of the database**: equivalent
  to a full local compromise — encryption can't help there. Protect
  `_auth_keys/` with 0600 perms.

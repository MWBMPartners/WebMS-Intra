# Disaster-recovery runbook

**Audience:** a volunteer admin with shell access and minimal PHP / SQL
background. Every command is paste-ready. Read the section header, copy the
command beneath it.

**Linked from:** `docs/day2-support.md`, `/help/admin-first-steps`.

---

## 1. First five minutes — confirm it's broken

Before doing anything destructive, work out **what** is failing.

1. Open the portal in a private/incognito tab. Does the homepage load?
2. Visit `/admin/maintenance/health` (admin login). Which probe is red?
3. Visit `/admin/errors`. Anything in the last 60 minutes?
4. Check email — `Logger::criticalAlert` emails on rate-limited alarms.

If the homepage 500s before you can log in, jump to **section 3 — diagnose**.

If only one app is broken, you can usually leave the rest running and only
fix that app. Skip to **section 4 — common failure modes**.

If the whole portal is unresponsive AND the DreamHost status page
([status.dreamhost.com](https://status.dreamhost.com/)) shows nothing,
go to **section 2 — stop the bleeding**.

---

## 2. Stop the bleeding — maintenance mode

Tells visitors a friendly "we'll be back" page instead of a 500.

**Via UI** (preferred — if admin still works):

`/admin/maintenance/health` → toggle **maintenance mode**.

**Via SQL** (if admin pages are down):

```bash
ssh USER@portal.example.com
cd ~/portal/web
mysql -h mysql.example.com -u USER -p DATABASE_NAME -e \
  "UPDATE tblSettings SET settingValue='1' WHERE settingKey='portal.maintenance.active';"
```

To bring the portal back later:

```bash
mysql -h mysql.example.com -u USER -p DATABASE_NAME -e \
  "UPDATE tblSettings SET settingValue='0' WHERE settingKey='portal.maintenance.active';"
```

---

## 3. Diagnose — where to look

| Symptom | Where to look |
|---|---|
| White page / 500 | DreamHost panel → Error Log; `web/_logs/` if writable |
| Slow but loading | `/admin/maintenance/health` → DB latency probe |
| Login broken | `/admin/errors` → AUTH category |
| Email not arriving | `/admin/integrations/email-templates` → test send |
| One app 404s | `tblRoutes` row missing — re-run migrations |
| Migration error | `/admin/migrations` → red rows + error column |

```bash
# Tail PHP error log (location varies; check phpinfo()):
tail -200 ~/logs/portal.example.com/http/error.log

# Tail the portal's audit log:
mysql -h mysql.example.com -u USER -p DATABASE_NAME -e \
  "SELECT createdAt, severity, category, message FROM tblErrors \
   ORDER BY errorID DESC LIMIT 50;"
```

---

## 4. Common failure modes + recovery

### 4.1 DB connection lost

**Symptom:** every page shows "Database error" or a bare 500.

```bash
# Confirm MySQL is reachable from the shell:
mysql -h mysql.example.com -u USER -p -e "SELECT NOW();"
```

If that hangs → DreamHost MySQL is down. Check
[status.dreamhost.com](https://status.dreamhost.com/) and wait, OR open
a ticket. There is nothing the portal can do.

If that responds → application-level issue. Check the credentials in
`web/_auth_keys/db.php` haven't been edited.

### 4.2 Disk full

**Symptom:** uploads fail; backups fail; maintenance probe red.

```bash
# Where are the bytes?
du -sh ~/portal/web/_backups/* | sort -h | tail
du -sh ~/portal/web/_uploads/* | sort -h | tail
```

**Prune old backups** (KEEP at least 4 most recent):

```bash
ls -1tr ~/portal/web/_backups/snapshot-* | head -n -4 | xargs rm -rf
```

**Prune queue-rejected photos older than 30 days:**

```bash
find ~/portal/web/_uploads/photos/queue -type f -mtime +30 -delete
```

### 4.3 Bad migration

**Symptom:** site 500s right after a deploy. `/admin/migrations` red.

```bash
# Roll back to the last known-good snapshot via the admin UI:
#   /admin/maintenance/backup → select the previous snapshot → Restore.
```

If admin is also broken, do it from the shell — see **section 5**.

### 4.4 Stolen credentials

**Symptom:** unfamiliar admin activity in `/admin/activity` or an alert
from `Logger::criticalAlert`.

1. Force-revoke every session:
   ```sql
   DELETE FROM tblSessions;
   ```
2. Force a password reset for every user (sets `passwordResetRequired = 1`):
   ```sql
   UPDATE tblUsers SET passwordResetRequired = 1;
   ```
3. Rotate the encryption key (re-encrypts every sensitive setting):
   ```bash
   php ~/portal/web/_install/rotate-enc-key.php
   ```
   If that script doesn't exist on your release, regenerate manually:
   ```bash
   openssl rand -base64 64 > ~/portal/web/_auth_keys/enc.key.new
   # Then re-key every sensitive setting via the admin/settings UI.
   mv ~/portal/web/_auth_keys/enc.key.new ~/portal/web/_auth_keys/enc.key
   ```
4. Audit `/admin/activity` for the last 48 hours; flag anything suspect.
5. Rotate API keys for every integration in `/admin/integrations/*` whose
   keys were stored encrypted (Zoom, Stripe, MS365, Google, OpenAI,
   Anthropic, Twilio, etc).

### 4.5 Compromised user account (not admin)

```sql
-- Disable + force re-login:
UPDATE tblUsers SET isActive = 0 WHERE emailAddress = 'compromised@example.com';
DELETE FROM tblSessions WHERE userID = (
  SELECT userID FROM tblUsers WHERE emailAddress = 'compromised@example.com'
);
```

Then run the offboarding flow at
`/admin/users → Offboard → Revoke all access`.

---

## 5. Full restore from local snapshot

When `_backups/` still exists on the server.

### 5.1 Via UI (preferred)

1. SSH in and confirm `/admin/maintenance/backup` is reachable.
2. Toggle **maintenance mode** (section 2).
3. Pick the snapshot under `web/_backups/snapshot-YYYYMMDD_HHMMSS/`.
4. Click **Full restore**. Confirm the prompt.
5. Wait for "Restore complete" (1-10 minutes depending on size).
6. Toggle maintenance mode off.
7. Verify with `/admin/maintenance/health` — every probe green.

### 5.2 Via CLI (admin UI broken)

```bash
ssh USER@portal.example.com
cd ~/portal/web
SNAP=$(ls -1tr _backups/snapshot-* | tail -1)
echo "Restoring from ${SNAP}"

# Each table is a JSON file under the snapshot dir.
# DbBackup::restoreTable() is callable via a one-shot PHP harness:
php -r '
require "_core/bootstrap.php";
use Portal\Core\DbBackup;
$snap = $argv[1] ?? "";
foreach (glob("$snap/*.json") as $f) {
    $table = basename($f, ".json");
    echo "Restoring $table … ";
    $ok = DbBackup::restoreTable($table, $f);
    echo ($ok ? "ok" : "FAILED") . "\n";
}
' "${SNAP}"
```

If the harness fails midway, run it again — `restoreTable` is idempotent
(TRUNCATE + bulk insert).

---

## 6. Off-site restore — `_backups/` is gone

When DreamHost lost the disk or the account, the local backup directory
no longer exists. Fetch from the off-site copy you configured per
[`docs/offsite-backup-setup.md`](offsite-backup-setup.md).

### 6.1 Pull the latest ciphertext

```bash
# rclone destination
rclone copy myremote:portal-backups/snapshot-YYYYMMDD.tar.gz.enc ./
# OR S3:
aws s3 cp s3://my-bucket/portal-backups/snapshot-YYYYMMDD.tar.gz.enc ./
# OR SFTP:
scp user@backup.example.com:portal-backups/snapshot-YYYYMMDD.tar.gz.enc ./
```

### 6.2 Decrypt + extract

```bash
openssl enc -aes-256-cbc -d -pbkdf2 \
    -in snapshot-YYYYMMDD.tar.gz.enc \
    -out snapshot-YYYYMMDD.tar.gz \
    -pass file:web/_auth_keys/offsite.key

tar -xzf snapshot-YYYYMMDD.tar.gz -C web/_backups/
```

### 6.3 Restore

Now follow **section 5** with the extracted snapshot.

**If `offsite.key` was also lost** (single point of failure): the
ciphertext is permanently unrecoverable. This is why the key belongs in
a separate password manager — see the off-site-backup threat model.

---

## 7. Communication template

Paste verbatim into Slack / email / SMS once you've taken **section 2**'s
maintenance toggle.

> **Subject:** Portal temporarily unavailable
>
> Hi team,
>
> Our portal is currently unavailable while we investigate a technical
> issue. Sign-in, dashboards, and uploads are all affected.
>
> We're aware and working on it. Expected back: **[time + timezone]** /
> we'll update by **[time]** if we don't have a fix yet.
>
> Nothing you submitted is lost — we'll send a follow-up once we're
> sure everything is in order.
>
> Sorry for the disruption.
>
> — [Name]

---

## 8. Post-incident review

Within 7 days of any incident that triggered maintenance mode:

1. **What happened?** Two-sentence summary.
2. **Timeline.** When did each step occur? (Lift from `/admin/activity`.)
3. **What broke?** Specific failing component.
4. **What worked?** Probes that caught it, dashboards that helped.
5. **Where did the runbook fail us?** Update this file.
6. **Action items.** Ideally 1–3 concrete items, with owners + dates.
7. **Disclosure.** Did personal data leak? If yes, ICO notification within
   72 hours is mandatory under UK GDPR.

File the review under `docs/incidents/YYYY-MM-DD-summary.md`.

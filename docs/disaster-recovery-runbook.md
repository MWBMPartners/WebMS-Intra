# Disaster-recovery runbook

For the person who has to get the portal working again: after a break-in, a
failed upgrade, or lost data. You do not need to know how the portal is built.
Work down the page. Every step says what you should see, and what to do if you
do not.

**Linked from:** `docs/day2-support.md`, `/help/admin-first-steps`,
`/help/disaster-recovery`.

## How to read this page

Every command, and every button, carries one of two marks:

- **Tested** means we ran that exact command, or pressed that exact button, on
  12 October 2026, on a throwaway copy of release 1.4.0 (MySQL 8.0.36,
  PHP 8.5.10), and it did what this page says. The number points at a row in
  the table at the [end of this page](#what-was-tested).
- **Your host** means it depends on your hosting company. We could not run it,
  so check it before you need it.

The commands on this page were run on MySQL 8.0.36. The snapshot, the check and
the restore themselves were also proved on MySQL 8.4.11 (rows 29 and 30 of the
table). MariaDB was **not** tested.

Words used here: a **snapshot** is the portal's own backup of its database. A
**global administrator** is the account that owns the whole installation, not
an administrator of one organisation on it. Only a global administrator can use
the backup pages.

Addresses such as `/admin/users` go after your portal's own address, for
example `https://portal.example.com/admin/users`. Every name on this page
containing "example", and the folder `~/portal/web`, is an example: put your
own in its place.

---

## 1. Before you start

Many steps can be done from the portal's own pages. Some need a **command
line** on the server (a terminal, often called SSH). Where a step has no
other way, it says so. Not every host gives you one.

### 1.1 Set five values, once per terminal window

Every command below uses these. Open
`_auth_keys/auth_creds.php` in the portal's folder: it lists `db_host`,
`db_port`, `db_user`, `db_name` and `db_pass`. Then type the first four here.
`PORTAL` is the folder that holds `_core`, `_apps`, `_auth_keys` and
`_backups`.

```bash
PORTAL=~/portal/web
DB_HOST=mysql.example.com
DB_PORT=3306
DB_USER=example_user
DB_NAME=example_portal
```

*Example values. Yours come from your own server.*

If you open a new terminal window, set them again.

### 1.2 Check the server's PHP

```bash
php -v
php -m | grep -E '^(mysqli|sodium)$'
```

*Tested (22).* You should see `PHP 8.4` or newer, then the two words `mysqli`
and `sodium`.

If not: your host has more than one PHP. Ask them for the name of the right
one (often `php8.4`, or a full path) and use it wherever this page says `php`.
*Your host.*

### 1.3 How to run a piece of SQL

SQL is what you type to the database. This page shows it in grey boxes marked
`sql`. There are two ways to run one.

**With a command line.** Start the database program:

```bash
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p "$DB_NAME"
```

*Tested (16).* It asks `Enter password:`. Type the `db_pass` value (nothing
shows as you type) and press Enter. You get a `mysql>` prompt. Paste the SQL,
press Enter, read the answer. Type `exit` to leave.

If it says `ERROR 1045 ... Access denied`, a value in 1.1 or the password is
wrong. If it cannot connect at all, see [6.1](#61-the-database-cannot-be-reached).

**Without a command line.** Use the database tool in your hosting panel (often
called phpMyAdmin): choose the portal's database, open the "SQL" tab, paste,
press "Go". *Your host.*

---

## 2. What is backed up, and what is not

Read this on a calm day. It decides what you can get back.

| | |
| --- | --- |
| **What a snapshot holds** | Every row of every table in the database. |
| **What it does not hold** | Uploaded files (`_uploads/`), the keys (`_auth_keys/`), and the tables' own structure. |
| **Where it is** | A folder `_backups/upgrade-YYYYMMDD-HHMMSS/` in the portal's folder: one file per table (named after the table and ending `.jsonl`; snapshots made before this release hold files ending `.json` and still restore), and `_manifest.json`, written last. A folder with no `_manifest.json` is an unfinished snapshot and is not a backup. The time in the name is in the portal's own time zone. On the night the clocks go back the same clock time comes round twice; a snapshot that finds its name taken waits a second and takes the next one, so a name can be a second or two later than the moment it was started. |
| **When one is made** | Only when an upgrade runs, or when a global administrator presses **Run snapshot now** on `/admin/maintenance/backup`. **Nothing makes one on a timer.** |
| **Who can read it** | Anyone who can read that folder. It contains everything, password fingerprints included. |

*Tested (11, 23): the button, the folder, its contents.*
*Tested (L01, L02, proof 19: a name already taken; proof 7: a name that stays taken is still
refused).*

You do not need to close the portal to take a snapshot. Every table is copied
as it stood at the one moment the snapshot began, so a record somebody saves
while it runs is in the snapshot whole or not at all. *Tested (L01, L02,
proof 11: a record saved between two tables being copied was in neither.)*

### 2.1 What the portal's own backup still cannot do

Three faults found on 12 October 2026 are fixed in this release: a snapshot
can be taken once a calendar has been imported, a snapshot holding a record
that points at a newer record of the same kind can be put back, and a large
database no longer needs more memory than a host allows (60,000 activity-log
rows were saved, checked and put back with PHP limited to 32 MB). *Tested
(L01, L02, proofs 1, 2 and 5): MySQL 8.0.36 and 8.4.11.*

What is still true:

1. **A snapshot made before this release, of a large database, needs a lot
   of memory to put back**: up to four times the size of its largest file.
   The portal refuses in words if PHP has less than four and a half times
   that size to spare, and changes nothing. Take a new snapshot after
   upgrading; new ones do not have this limit. *Run by the builder (G26), not
   run again: a 13 MB file was refused at 16 MB and at 24 MB with the
   database unchanged, and put back at 256 MB using 51 MB.*
2. **One single very large record** (a row holding tens of megabytes) needs
   about five times its own size in memory to save and about four times to
   put back. *Run by the first fix round (F30), not run again: a 24 MB row
   peaked at 122 MB while it was saved and 100 MB while it was put back; a
   48 MB row at 242 MB and 196 MB.* The **Restore** button beside a single
   table costs about six times (*run by the first fix round (F27), not run
   again: a 50 MB row peaked at 301 MB*), one more reason not to use it. A
   record is also limited by the largest message your database accepts
   (64 MB unless your host changed it). A record under that limit goes back.
   *Tested (L01, L02, proof 20: a 50 MB row went back on a server that
   accepts 64 MB.)* A record OVER it is refused before it is sent: the
   message names the table, gives both sizes and says what to ask your host
   to change, and nothing is changed. *Tested (L25, L26: an 80 MB row
   against a server that accepts 64 MB, refused by the check, the restore
   and the one-table restore, connection still open, database identical;
   and L01, L02, proof 20.)*
3. **The portal refuses to take a snapshot if the database has a column of a
   kind it has not been taught to copy exactly** (two kinds, BIT and FLOAT,
   are known not to survive). The message names the table and the column.
   No table has such a column today. *Tested (L01, L02, proof 14).*
4. **It has never been tried on MariaDB.**
5. **A restore refuses a value when the database says it would have to
   change it to make it fit.** If an upgrade changed a column after the
   snapshot was taken (made it shorter, smaller, a list of choices, no
   longer allowed to be empty, or able to keep fewer decimal places), a
   value that no longer fits stops the restore, and nothing is changed.
   This does not depend on how your host set its database: some hosts run
   it loosely, and there a restore used to say "success" and bring back a
   shortened value. *Tested (L01, L02, proof 18: six kinds of changed column
   on a connection with `sql_mode` empty, each refused by the check, the
   restore and the one-table restore, database unchanged; and the two kinds
   the database only notes, refused as well on a connection with its notes
   switched off, `sql_notes = 0`.)* Dates of zero and a record numbered 0,
   which a loosely run database may hold, go back exactly as they were.
   *Tested (the same proof).* "When the database says" matters: see item 6
   for the changes it makes without saying anything.
6. **Some kinds of change go unnoticed, because the database makes them
   without a word.** If an upgrade changed a column after the snapshot was
   taken, the restore says "success" and the value comes back changed in
   these cases, and there may be others:
   - the column now keeps fewer digits of a second: a time is rounded;
   - the column now holds whole numbers: a number with a decimal part is
     rounded;
   - a fixed-width column of raw bytes was made wider: what it is given is
     padded with zero bytes;
   - the column is now a fixed-width text (CHAR): spaces at the end of a
     text are dropped;
   - a DOUBLE column became FLOAT, or a BIGINT became DOUBLE: digits are
     lost;
   - the column is now a YEAR: a two-digit number becomes a four-digit year
     (26 becomes 2026);
   - and, only where the host runs the database with
     `explicit_defaults_for_timestamp` switched off: "no value" in a
     date-and-time column that may no longer be empty comes back as the
     present time.

   *The first three: run by the first fix round (F12), not run again. The
   other four: run by the checker (C21, C23; the last one C13, on 8.0.36
   only), not run again.* No upgrade so far makes such a change. If you
   restore a snapshot from before an upgrade that changed a column's kind,
   use the full dump instead ([section 9](#9-the-second-kind-of-backup-a-full-dump)).
7. **Every table must be of the kind that can undo (InnoDB).** They all are
   as the portal is installed. If a host or a person has converted one, the
   check and the restore refuse before touching anything and name the
   table. *Tested (L01, L02, proof 21).*
8. **A snapshot holding a value that is not on its column's list of choices
   cannot be put back whole.** This can only come from a value saved while
   the portal's connection was loose: before the portal made its own
   connection strict (12 October 2026) on a host that runs its database
   loosely, or since then on a host whose database refuses that setting (the
   health page shows "Database strictness: NOT strict"), or written from
   outside the portal. On a loose connection a value that is not on a
   column's list is stored as an empty text, without a word. A restore
   refuses that value, because
   the only way to put it back is to let the database change other values
   too. The portal now TELLS you when it takes such a snapshot: "Run
   snapshot now" answers in yellow, the snapshot is marked **Cannot be put
   back whole** in the list, its own page says so above the Restore button,
   the health page says **Fresh, but cannot be put back whole**, and the
   upgrade page stops before changing anything
   ([6.3](#63-after-an-upgrade-that-went-wrong)). Each names the table, the
   column and how many values. What to do: mend those rows in the live
   database with your host's database tool (the message gives the statement
   that finds them: give each a value from the column's list, or empty it
   where the column may be empty), then take a new snapshot. **A snapshot
   already taken with such a value in it cannot be put back whole by the
   portal at all**; it is still good for looking at, and for a single table
   that holds no such value. For the whole database use a full dump
   (section 9). *Tested (L01, L02, proof 24; L03, L04, section I: two such
   values in one table and one in another, named with their counts by the
   snapshot, its manifest and every page; after the rows were mended a new
   snapshot carried no warning and went back exactly.)* A snapshot made by
   an earlier release does not say whether it holds one: **Check this
   snapshot** does.

Keep the second kind of backup as well
([section 9](#9-the-second-kind-of-backup-a-full-dump)): it is the only one
that also holds the tables' structure.

### 2.2 What to keep somewhere other than the server

- A recent **full dump** of the database ([section 9](#9-the-second-kind-of-backup-a-full-dump)).
- The three files in `_auth_keys/`: `auth_creds.php` (how to reach the
  database), `enc.key` (unlocks every secret stored in the database) and
  `offsite.key` (unlocks the off-site copies). **Keep the files themselves**,
  not text copied out of them.
- The `_uploads/` folder. Nothing in the portal copies it anywhere.
- The off-site copy described in `docs/offsite-backup-setup.md`.

**Back up `enc.key` together with the database, every time.** From this
version every integration's secret depends on it. A secret typed on the
Settings page is stored encrypted with it, and so is any secret an older
version stored as readable text: Upgrade now encrypts those by itself. A copy
of the database without the `enc.key` that goes with it gives back secrets
nobody can read.

A backup you have never put back is a hope, not a backup. Practise section 8
on a spare copy.

---

## 3. First five minutes: what is actually wrong?

Do nothing that changes anything yet.

1. Open the portal in a private browser window. What do you see?

   | You see | It means | Go to |
   | --- | --- | --- |
   | The sign-in page | The portal is up. Sign in and carry on with step 2. | |
   | "Portal Maintenance" | The portal is closed: somebody closed it, or an upgrade is waiting. | `/admin/upgrade` after signing in at `/login` |
   | `Database connection error.` | The portal cannot reach its database. | [6.1](#61-the-database-cannot-be-reached) |
   | "Install — Welcome" | **Stop.** The file `_auth_keys/auth_creds.php` is missing. The portal is offering its installation wizard to every visitor. | [6.1](#61-the-database-cannot-be-reached) |
   | A blank page or "500" | A fault in the code or the server. | [section 5](#5-where-to-look) |

   *Tested (16, 22).*
2. Open `/admin/maintenance/health`. Which card is not green?
3. Open `/admin/errors`. Anything in the last hour?
4. Open `/admin/activity`. Anything you do not recognise: sign-ins at odd
   hours, settings changed, accounts made?

*Tested (23): these pages open for a global administrator.*

- Something you do not recognise in step 4: go to [section 7](#7-after-a-break-in).
- Data is wrong or missing: go to [section 8](#8-put-a-snapshot-back).
- Everything is down and your host's status page shows a fault: wait for the
  host. There is nothing the portal can do. *Your host.*

---

## 4. Close the portal

Closing it shows everybody a holding page while you work. Global
administrators can still sign in and use every page. `/login` and
`/forgot-password` stay open to everyone.

The holding page always says the portal "is being upgraded". Type your own
message so people are not misled.

### 4.1 From the portal

1. Open `/admin/settings/maintenance`.
2. Tick **Maintenance mode active**. Type a message, for example "Back at
   3pm".
3. Press Save. You should see "Maintenance mode settings saved."

*Tested (16).* A visitor now gets the holding page with your message. Your own
dashboard shows "Maintenance mode is on" at the top.

To open the portal again, untick the box and save.

### 4.2 With SQL, when the pages will not load

```sql
UPDATE tblSettings SET settingValue = '1' WHERE settingKey = 'portal.maintenance.active' AND siteID IS NULL;
SELECT settingValue FROM tblSettings WHERE settingKey = 'portal.maintenance.active' AND siteID IS NULL;
```

*Tested (16).* The second line should answer `1`.

If it answers `Empty set`, the setting is missing. Add it:

```sql
INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'portal.maintenance.active', '1', '0', 0);
```

*Tested (16).*

To open the portal again:

```sql
UPDATE tblSettings SET settingValue = '0' WHERE settingKey = 'portal.maintenance.active' AND siteID IS NULL;
SELECT settingValue FROM tblSettings WHERE settingKey = 'portal.maintenance.active' AND siteID IS NULL;
```

*Tested (16).* It should answer `0`.

If the portal stays closed with the setting at `0`, an upgrade is waiting: the
code is newer than the database. Sign in and open `/admin/upgrade`.

---

## 5. Where to look

| You see | Look here |
| --- | --- |
| Blank page or "500" | `/admin/errors`, then your host's PHP error log. *Your host.* |
| `Database connection error.` | [6.1](#61-the-database-cannot-be-reached) |
| "Too many failed attempts" at sign-in | Three wrong passwords from one address block that address for 15 minutes. *Tested (17).* Wait, and make sure nobody at that address tries meanwhile: a try while blocked is counted as another wrong one and keeps the block going. *Read from the code; the 15 minutes were not waited out.* |
| Pages are slow | `/admin/maintenance/health` |
| Email is not arriving | `/admin/integrations/email`, "Send test" |
| An upgrade stopped | `/admin/upgrade` lists the database changes still waiting and those already run, and shows the error beside the one that failed when its button is pressed. (`/admin/migrations` only points there while an upgrade is unfinished.) Then [6.3](#63-after-an-upgrade-that-went-wrong). |

The newest errors, when the pages will not load:

```sql
SELECT createdAt, errorSeverity, errorPlatform, errorCode, errorTitle FROM tblErrors ORDER BY errorID DESC LIMIT 50;
```

*Tested (16).*

---

## 6. Common failures

### 6.1 The database cannot be reached

Every page shows `Database connection error.` *Tested (22).*

1. Start the database program ([1.3](#13-how-to-run-a-piece-of-sql)) and run:

   ```sql
   SELECT NOW();
   ```

   *Tested (16).* You should get today's date and time.
2. **If the program cannot connect either**, the fault is at your host or in
   the five values. Check your host's status page. Check the values against
   the database page of your hosting panel. *Your host.*
3. **If the program connects but the portal does not**, the portal's own copy
   of the details is wrong. Compare `_auth_keys/auth_creds.php` with what you
   typed in 1.1. A wrong password there gives exactly this message.
   *Tested (22).*

> **Never move, rename or delete `auth_creds.php` on a live portal.** Without
> it the portal shows its installation wizard to every visitor.
> *Tested (22).* Edit the file in place. If it is already missing, close the
> site at your host first (*your host*), then put the file back from your safe
> copy.

### 6.2 The disk is full

Uploads fail. "Run snapshot now" fails. (Not tested: we did not fill a disk.)

1. See what is using the space:

   ```bash
   du -sh "$PORTAL"/_backups/* | sort -h | tail
   du -sh "$PORTAL"/_uploads/* | sort -h | tail
   ```

   *Tested (22).* The biggest are listed last.
2. Remove old snapshots. **Keep at least the newest four.** The safest way is
   the **Delete** button beside each one on `/admin/maintenance/backup`.
   *Tested (23).*
3. Or from the command line. First list what would go:

   ```bash
   cd "$PORTAL/_backups" && for M in upgrade-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]/_manifest.json; do [ -f "$M" ] && [ ! -L "${M%/_manifest.json}" ] && echo "${M%/_manifest.json}"; done | sort -r | tail -n +5
   ```

   *Tested (24).* This lists every **finished** snapshot except the newest
   four. If it lists nothing, there are four or fewer finished snapshots and
   nothing will be deleted. `No such file or directory` means `PORTAL` is not
   set (1.1); nothing will be deleted then either.

   Only a folder named exactly the way the portal names a snapshot
   (`upgrade-`, eight digits, a hyphen, six digits) is counted. Unfinished
   folders (no `_manifest.json`), folders you named yourself and links are
   never listed, never counted and never deleted by these commands. The backup
   page labels each unfinished one **Unfinished, not a backup**. Remove it
   with the **Delete** button; the portal also removes one by itself, at the
   next upgrade, once nothing in it has changed for 24 hours. **Delete**
   removes that one folder and nothing else: a shortcut (link) in the backups
   folder is removed as a shortcut, and what it points at is left alone. It
   works on names made of letters, digits, hyphens and underscores; a folder
   you named with a dot or a space has to be removed with your host's file
   manager. *Tested (L01, L02, proof 12: the 24 hours; L03, L04, section E: the
   label; section G and proof 19: Delete and a link, and the names). The
   listing command: run by the builder (G23), not run again.*

   Read the list. Then delete exactly those:

   ```bash
   cd "$PORTAL/_backups" && for M in upgrade-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]/_manifest.json; do [ -f "$M" ] && [ ! -L "${M%/_manifest.json}" ] && echo "${M%/_manifest.json}"; done | sort -r | tail -n +5 | while IFS= read -r OLD; do rm -rf -- "$OLD"; done
   cd "$PORTAL/_backups" && ls -1d upgrade-*/
   ```

   *Tested (24).* The last line shows what is left, half-made folders
   included. Running it a second time deletes nothing more.

Do not delete anything under `_uploads/` by hand. The database points at those
files.

### 6.3 After an upgrade that went wrong

What the upgrade page does first: it closes the portal, takes a snapshot,
and only then changes the database. If the snapshot fails, or cannot be put
back whole, it stops and changes nothing. *Tested (L03, L04, sections F and
I): with an imported calendar event in the database, the upgrade page took
its snapshot and ran the waiting migration.*

- **"Backup failed, so the upgrade was not started. Nothing has been
  changed. The reason: …"** in a red box at the top of the upgrade page. The
  snapshot could not be taken; the words after "The reason:" say why, and
  the box says what to do about the usual causes (the backups folder is
  missing, cannot be written to, or the disk is full). For the others see
  [2.1](#21-what-the-portals-own-backup-still-cannot-do). No database
  change was run and the portal is open again. *Tested (L03, L04,
  section F).*
- **"The upgrade was NOT started, and nothing has been changed. … This
  snapshot CANNOT BE PUT BACK WHOLE as it stands. …"** in a red box. The
  snapshot was taken, but it holds a value that is not on its column's list
  of choices (2.1, item 8), so it would be no use as the way back. The box
  names each table and column, says how many values, and gives the
  statement that finds them. Mend those rows, then press the upgrade button
  again. The snapshot it took is kept. No database change was run and the
  portal is open again. *Tested (L03, L04, section I).*

  Below the ordinary button the page now shows a second one, **Upgrade
  anyway, without a way back**, once, straight after that refusal. Use it
  only if you have another backup of the database that you trust: a full
  dump made today ([section 9](#9-the-second-kind-of-backup-a-full-dump)),
  or your host's own. The upgrade then runs, the page says in yellow that it
  ran without a way back, and a line in the activity log records it. No
  older snapshot is removed to make room. *Tested (the same).*
- **"Invalid or expired form token. Please try again."** in a red box. You
  left the page open too long before pressing the button. Press it again.
  *Tested (L03, L04, section F).*

**Upgrading without the backup.** A setting,
`portal.upgrade.backup.enabled`, switches the backup before an upgrade off
when it is set to `0` (in SQL: `UPDATE tblSettings SET settingValue='0'
WHERE settingKey='portal.upgrade.backup.enabled';`). The page no longer
tells you about it, on purpose: an upgrade with no way back is the thing the
backup exists to prevent. It is acceptable in one case only: the portal's
own snapshot cannot be taken for a reason you cannot put right today, AND
you have just made a full dump
([section 9](#9-the-second-kind-of-backup-a-full-dump)) and checked that the
file is there and not empty. Set it back to `1` straight afterwards.
*The setting is read from the code (`web/_install/upgrade.php`); upgrading
with it switched off was not run by anybody working on this package.*
- **A database change failed part way.** The Upgrade page showed the error
  beside that change when its button was pressed. To see it again, open
  `/admin/upgrade` and press the button again: it takes a new snapshot and
  tries only the changes still waiting. (`/admin/migrations` runs nothing
  while an upgrade is unfinished; it points to the Upgrade page.) If you have
  a developer, send them that error before going back.
  *Read from the code (`web/_install/upgrade.php`,
  `web/_apps/admin/migrations/index.php`), 13 October 2026; a change that
  really fails was not run.*
- **To go back**, put back the snapshot the upgrade took
  ([section 8](#8-put-a-snapshot-back)). Know this first: **a restore puts the
  rows back. It never changes a table's shape.** If the upgrade had already
  added or removed columns, the restore may refuse, with one of the messages
  in [8.4](#84-when-it-refuses). It changes nothing when it refuses.
  *Tested (15).* In that case only a full dump made before the upgrade
  (section 9) brings everything back.

Not tested: we did not run a real upgrade that failed.

---

## 7. After a break-in

**The portal has no button that signs everybody out, and none that makes
everybody choose a new password.** The steps below are what works today.
Do them in this order. The order is what stops you locking yourself out.

### 7.1 Close the portal

[Section 4](#4-close-the-portal). You can still work; nobody else can.

### 7.2 Check the portal can send email

Open `/admin/integrations/email` and use "Send test" to send yourself a
message. *Tested (23): the page opens. Sending was not tested: our copy has no
mail settings.*

Why now: step 7.6 depends on it. Read from the code, not run: "Send test"
uses whichever mail service is set, but "Forgot password" sends its email only
when the Microsoft 365 mail settings are filled in, and says "sent" either
way. So one more test: in a private window, press "Forgot password" and type
your own address. If no email comes within a few minutes, treat email as not
working, whatever "Send test" did.

**If either test does not arrive, carry on anyway.** In 7.6 you will set
people's passwords yourself.

### 7.3 Make every stored password unusable

```sql
UPDATE tblLocalAccounts SET passwordHash = '';
SELECT COUNT(*) AS password_accounts, SUM(passwordHash = '') AS now_unusable FROM tblLocalAccounts;
```

*Tested (17).* The two numbers must be the same.

What this does: nobody can sign in with a password until they have set a new
one. What it does **not** do:

- People who are signed in **stay** signed in. That is step 7.5.
- Passkeys, and signing in with Microsoft or Google, are untouched. A
  Microsoft or Google password is changed at Microsoft or Google. A passkey
  has no provider: it is a row in the portal's own database.

**Look for a way back in that a new password does not close.** An intruder
who was signed in may have added a passkey, linked a Microsoft or Google
account of their own, or made an account a global administrator. None of the
steps on this page removes those. *Read from the code, not run: adding a
passkey does not ask for the password.* List all three:

```sql
SELECT U.userID, U.fullName, U.emailAddress, W.createdAt AS passkey_added FROM tblWebAuthnCredentials W JOIN tblUsers U ON U.userID = W.userID ORDER BY W.createdAt DESC;
SELECT U.userID, U.fullName, U.emailAddress, L.provider, L.linkedAt AS link_added FROM tblLinkedAccounts L JOIN tblUsers U ON U.userID = L.userID ORDER BY L.linkedAt DESC;
SELECT userID, fullName, emailAddress, isActive FROM tblUsers WHERE isRootAdmin = 1;
```

*Tested (25).* The first lists every passkey, newest first; the second every
linked account; the third every global administrator. For every row you do
not recognise, ask the person it names whether it is theirs. This page has no
tested command that removes one of these rows. Until somebody who knows the
database has removed it, lock that account out
([7.7](#77-lock-one-account-out)).

### 7.4 Give yourself a new password, now, while you are still signed in

1. Open `/admin/users`.
2. Press **Edit** beside your own name.
3. Type a new password. Save.

*Tested (17).* You should see `User "your name" updated successfully.`

**Before 7.5, sign in with the new password in a private window.** The page
says "updated successfully" even when no password was stored, which happens
for an account that never had a password of its own. *Seen once while this
page was being checked, on 12 October 2026; not one of the numbered tests.*

If you are not signed in, it will not save, or the new password does not let
you in, do [7.8](#78-no-administrator-can-get-in) instead.

### 7.5 Sign everybody out

The portal keeps each signed-in person in a small file on the server. Deleting
the files signs everybody out, you included.

1. Open `/admin/maintenance/health`. On the card **Active sessions**, read the
   line `Files in /some/folder (last 30 min)`. That folder is where the files
   are. *Tested (17).*
2. Count them, using that folder:

   ```bash
   SESSIONS=/folder/named/on/the/health/page
   ls "$SESSIONS" | grep -c '^sess_'
   ```

   *Tested (17).* It must say at least `1`: you are signed in. `0` here
   means the folder is wrong or was not typed. Put that right before step 3,
   because a wrong folder also makes step 3 end on `0`.
3. Delete them:

   ```bash
   SESSIONS=/folder/named/on/the/health/page
   find "$SESSIONS" -maxdepth 1 -type f -name 'sess_*' -user "$(id -un)" -delete
   ls "$SESSIONS" | grep -c '^sess_'
   ```

   *Tested (17).* The last line should say `0`. Everybody's next click takes
   them to the sign-in page.

   On a server shared with other customers the number may stay above 0: the
   files left are other people's, and were not touched. *Your host.*

Things to know:

- Every PHP website on the same hosting account that uses that folder is
  signed out too, including test copies of the portal.
- If the card says `save_path unreadable`, or names something that is not a
  folder, your host keeps sessions somewhere else. Ask them how to clear all
  PHP sessions for your account. *Your host.*
- Without a command line: use your host's file manager if it can reach that
  folder. Otherwise ask the host. *Your host.*

### 7.6 Sign in again, and tell everybody what to do

1. Sign in with the password from 7.4. *Tested (17).*
2. Tell people, in these words:

   > Go to the sign-in page and press "Forgot password". You will get an email
   > with a link. It works for 60 minutes. **Do not try your old password.**

   *Tested (17): the link lets a person set a new password and sign in. The
   email itself was not tested.*

Why the warning: three wrong passwords from one address block that address for
15 minutes. An office usually shares one address, so one person's guesses lock
out everybody there. While it is blocked, "Forgot password" still says the
email was sent, and sends nothing. *Tested (17).* Every try while blocked is
counted as another wrong one, so the block lasts until that address has made
fewer than three in 15 minutes: everybody there has to stop. *Read from the
code; not waited out.*

People can do this while the portal is still closed: the "Forgot password"
page stays open. *Tested (16).* After signing in they see the holding page
until you open the portal.

**If email is not working**, set each person's password yourself: `/admin/users`,
Edit, new password, Save. Tell them by phone, not by a message.

### 7.7 Lock one account out

Use this when one person's account is the problem.

From the portal: `/admin/users`, **Edit**, untick **Active**, Save.
*Tested (17).* Or with SQL:

```sql
UPDATE tblUsers SET isActive = 0 WHERE emailAddress = 'person@example.com';
SELECT userID, fullName, emailAddress, isActive FROM tblUsers WHERE emailAddress = 'person@example.com';
```

*Tested (17).* The last column should say `0`. Note the `userID`.

From then on that person cannot sign in with a password (*tested, 17*), a
passkey, Microsoft or Google (*read from the code, not run*).

**If they are signed in at this moment, they are signed out on their next
click.** On every request a signed-in person makes, the portal checks that the
account is still switched on, and ends the session if it is not. A page they
already have open stays on their screen until they click again. Switching the
account back on does not bring the old session back. *Tested (28).*

You no longer need to end the session yourself. The commands below are kept
for one case: ending one person's open sessions WITHOUT switching their
account off. Use the folder from 7.5 and the `userID` from above in place of
`NUMBER`:

```bash
SESSIONS=/folder/named/on/the/health/page
grep -l 'user_id|i:NUMBER;' "$SESSIONS"/sess_* 2>/dev/null | xargs rm -f
grep -l 'user_id|i:NUMBER;' "$SESSIONS"/sess_* 2>/dev/null | wc -l
```

*Tested (25).* The first command prints nothing whether it worked or not; the
last line counts that person's sessions still open and should say `0`. Only
that person is signed out. With no command line, sign everybody out (7.5).

`0` is also what a wrong folder or a wrong number gives. If you ran the count
in 7.5 step 2 with this folder and it said `1` or more, the folder is right.

To let the person back in, tick **Active** again, or run the same SQL with
`isActive = 1`.

### 7.8 No administrator can get in

Step 2 needs PHP on a command line. Without one on the server: press "Forgot
password", if email works (7.2). Or run step 2 on any computer that has PHP
(the line it prints works on any server), and paste steps 1 and 3 into your
host's database tool.

1. Find a global administrator's username:

   ```sql
   SELECT LA.username, U.emailAddress FROM tblLocalAccounts LA JOIN tblUsers U ON U.userID = LA.userID WHERE U.isRootAdmin = 1 AND U.isActive = 1;
   ```

   *Tested (17).*
2. Turn a new password into the form the database stores:

   ```bash
   php -r 'echo "New password: "; echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'
   ```

   *Tested (17).* Type the password and press Enter. It prints one line of 60
   characters starting `$2y$`. Copy that whole line.
3. Store it, with the line and the username in place of the two capitalised
   words:

   ```sql
   UPDATE tblLocalAccounts SET passwordHash = 'PASTE_THE_LINE_PHP_PRINTED' WHERE username = 'YOUR_USERNAME';
   SELECT username, passwordHash <> '' AS has_password FROM tblLocalAccounts WHERE username = 'YOUR_USERNAME';
   ```

   *Tested (17).* `has_password` should say `1`. Sign in with the new
   password.

Paste this at the `mysql>` prompt or into your host's tool. Do not put it
after `mysql -e` on a command line: the `$` signs would be changed.

### 7.9 Secrets and keys

**The file `_auth_keys/enc.key`.** The portal has no tool that changes this
key. **Do not replace the file by hand.** Everything stored with the old key
becomes unreadable at once: every integration's secret, and every person's
two-factor sign-in. Putting the old file back makes it readable again.
*Tested (18).*

What to do instead, after a break-in:

1. At each outside service (payments, text messages, video, email, maps and
   the rest), make a new secret and cancel the old one. *Your provider.*
2. Type the new secret into that service's page under `/admin/integrations`.
3. On `/admin/integrations/api-keys`, revoke every key and issue new ones.
   *Tested (23): the page opens. Revoking was not run.*
4. Change the database password in your hosting panel, then change `db_pass`
   in `_auth_keys/auth_creds.php` to match. Edit the file; do not move it.
   *Your host.*
5. If you use the off-site copy: the intruder could read `offsite.key`. Make a
   new one for future copies (`docs/offsite-backup-setup.md`). Keep the old
   one for as long as you keep the old copies.

Assume everything in the database was read, and everything in `_backups/`:
each snapshot is a full copy.

### 7.10 Work out what was done

Open `/admin/activity` and read back from now to before the break-in. Write
down anything changed. If data was altered, go to section 8. **If you
restore, come back and repeat 7.3 to 7.9**: a restore undoes them
([8.1](#81-what-a-restore-does)).

Open the portal again ([section 4](#4-close-the-portal)) only when you are
satisfied.

---

## 8. Put a snapshot back

### 8.1 What a restore does

- It replaces **every row of every table** with the rows in the snapshot.
  Everything entered since the snapshot was made is lost. If the present data
  might matter, make a full dump first (section 9).
- It is **all or nothing**. If anything goes wrong, nothing changes. We made
  it fail in seven different ways, and killed it half way; each time the
  database was exactly as before. *Tested (14, 15).*
- It does not touch files, keys or who is signed in.
- **After a break-in, a restore undoes section 7.** A snapshot from before
  you did those steps brings back every old password, switches back on any
  account you switched off, and undoes your own new password. From the page
  (8.2) a portal you closed yourself stays closed. From the command line the
  portal is open or closed as the snapshot held it, so it can be open
  afterwards even though you closed it (8.3 step 4).
  *Tested (26).* It brings back revoked keys too, because they are rows like
  any other (*not run*). Close the portal again straight after the restore
  ([4.2](#42-with-sql-when-the-pages-will-not-load)) and repeat 7.3 to 7.9
  before you open it.
- It needs the tables to exist already. If the database is empty, go to
  [8.5](#85-the-database-is-empty-or-gone) first.
- Put a snapshot back onto the **same release** that made it. To compare, with
  the snapshot's name in place of the example:

  ```bash
  cd "$PORTAL"
  SNAP=_backups/upgrade-YYYYMMDD-HHMMSS
  grep '"portal_version"' "$SNAP/_manifest.json"
  php -r 'echo "This portal: ", require "_core/version.php", "\n";'
  ```

  *Run by the builder (G23), not run again.* The two version numbers should
  match. If the first command prints nothing, the snapshot was made before
  this release; use
  `grep -A1 '"settingKey": "portal.installed_version"' "$SNAP/tblSettings.json"`
  for those. *Tested (22).* If the snapshot's is
  older, it was made before an upgrade: read
  [6.3](#63-after-an-upgrade-that-went-wrong) first.

### 8.2 From the portal

1. Open `/admin/maintenance/backup`.
2. Find the snapshot. The name is the date and time it was made.
3. Press **Inspect**. You see one line per table. If the list marks the
   snapshot **Cannot be put back whole**, a yellow box above the buttons
   says which values are in the way and what to do (2.1, item 8); **Restore
   ALL tables** will refuse that snapshot.
4. Press **Check this snapshot** first, if there is time. It rehearses the
   whole restore on this server: every row is written and then taken out
   again, so nothing is changed. It takes as long as the restore itself and
   the portal is closed while it runs. What the answer means:
   - **Green**, "This snapshot can be restored": a restore run now gets the
     same answer, because it is the same work. The one thing it cannot
     foresee is a failure at the very last step of a real restore.
   - **Yellow**, the same words followed by more: it can be restored, and
     something else will happen that you should read first. Either some
     pointers will be emptied, or tables that are not in the snapshot
     (ones a later upgrade added) will lose records linked to the ones
     being replaced. The message names each table and gives the numbers.
   - **Red**: it cannot be restored as things stand; the message says why,
     and nothing has changed.
5. Press **Restore ALL tables** and confirm.
6. Wait, and stay on the page. How long depends on your host. *Not timed.*

*Tested (L03, L04: both buttons, the mark and the yellow box in section I;
the yellow and red answers at class level in L01, L02, proofs 16 and 22;
MySQL 8.0.36 and 8.4.11).*

*Tested (11).* Green means done: `Snapshot put back in full: 224 tables, 1472 rows.`
(your numbers will differ). Red means it refused and changed nothing: go to
[8.4](#84-when-it-refuses).

The page closes the portal while it works and opens it again when it
finishes. If you had closed the portal yourself before pressing either
button ([section 4](#4-close-the-portal)), it is still closed afterwards,
with your own message. *Tested (L03, L04, section D2).*

Do not use the **Restore** button beside a single table. *Tested (L01, L02,
proof 17)*: it refuses for any table that other tables point at, which is
about a third of them.

The page asks PHP for 15 minutes and carries on if the browser stops waiting.
When it finishes it writes one line in the activity log (`/admin/activity`),
"Snapshot … put back in full" or "… failed; nothing was changed", so you can
find out how it ended even if your page timed out. If your host ends the
request before that, nothing has been changed, but the portal is still
closed: open it ([4.2](#42-with-sql-when-the-pages-will-not-load)) and use
8.3. *The log line is tested (L03, L04). A host ending the request is not; a
restore whose process was killed left the database unchanged (L01, L02,
proof 9; L30, L31: killed while pointers were being filled in, while it was
undoing, and at the moment it was made permanent, when it was wholly
restored).*

### 8.3 From the command line

Use this when the pages will not load. Close the portal first if you can
([4.2](#42-with-sql-when-the-pages-will-not-load)).

1. List the newest snapshots:

   ```bash
   cd "$PORTAL"
   ls -1d _backups/upgrade-*/ | sort | tail -3
   ```

   *Tested (23).* The newest is last.
2. Check the one you chose. **This changes nothing.** Put its name in place of
   the example:

   ```bash
   cd "$PORTAL"
   SNAP=_backups/upgrade-YYYYMMDD-HHMMSS
   php -r '
   require "_core/bootstrap.php";
   $checkOnly = ($argv[2] ?? "") !== "restore";
   echo $checkOnly ? "CHECK ONLY - nothing will be changed\n" : "RESTORE\n";
   $result = (new Portal\Core\DbBackup(Portal\Core\App::db()))->restoreAll($argv[1], $checkOnly);
   print_r($result);
   exit($result["success"] === true ? 0 : 1);
   ' "$SNAP" check
   ```

   *Run by the builder (G23), before two lines were added to what is printed;
   not run again.* You should see `CHECK ONLY`, then `[success] => 1` with the
   number of tables and rows, and the words `This was a rehearsal. Every row
   was written and then taken out again. Nothing was changed.` The list also
   holds `[other_tables_changed]` (empty unless a table that is not in the
   snapshot would lose records) and `[connection_closed]` (empty).

   The check is a full rehearsal of the restore, so its answer is the restore's
   own answer. It takes as long as the restore, and other people's saves wait
   while it runs, so close the portal first. The one thing it cannot foresee is
   a failure at the very last step of a real restore, when it is made permanent.
3. Do it. The same command, with the last word changed to `restore`:

   ```bash
   cd "$PORTAL"
   SNAP=_backups/upgrade-YYYYMMDD-HHMMSS
   php -r '
   require "_core/bootstrap.php";
   $checkOnly = ($argv[2] ?? "") !== "restore";
   echo $checkOnly ? "CHECK ONLY - nothing will be changed\n" : "RESTORE\n";
   $result = (new Portal\Core\DbBackup(Portal\Core\App::db()))->restoreAll($argv[1], $checkOnly);
   print_r($result);
   exit($result["success"] === true ? 0 : 1);
   ' "$SNAP" restore
   ```

   *Tested (12).* You should see `RESTORE`, then `[success] => 1`.
4. Open the portal if it should be open
   ([4.2](#42-with-sql-when-the-pages-will-not-load)). After this kind of
   restore it is open or closed as it was **when the snapshot was made**,
   because that setting is a row like any other. *Tested (12).* A snapshot
   taken by an upgrade was made with the portal closed. *Read from the code,
   not run.*

If your connection drops while it runs, nothing has changed. Start again.
*Tested (15).*

### 8.4 When it refuses

In every case below the database is unchanged, except where a row says
otherwise. *Tested (13, 14, 15, 23).*

| The message contains | It means | Do this |
| --- | --- | --- |
| `The saved copy of ... is damaged` | A file in the snapshot was cut short or altered. | Use another snapshot, or the off-site copy. |
| `This snapshot has no manifest` | The snapshot never finished being made. | Use another snapshot. |
| `not in the backups folder` | The name after `SNAP=` is wrong. | It must be a folder inside `_backups/`. |
| `points, through its column … at the row of … and that row is not in the snapshot` | A link between two tables was added after the snapshot was taken, and the snapshot holds a record pointing at one that had already been deleted. | The portal will not guess. Use a full dump (section 9), or ask for help. *Tested (L01, L02, proof 16).* |
| `Cannot add or update a child row` | One of three things. A file in the snapshot was altered. Or a link made of two columns was added after the snapshot was taken, and a record's pair of values matches nothing. Or you pressed **Restore** beside a single table that holds a record pointing at one that is gone. | For the first, use another snapshot or the off-site copy. For the third, restore the whole snapshot instead. For the second, use a full dump (section 9). *Tested (L01, L02: proof 4 the first, proof 16 the other two).* |
| `Unknown column`, `doesn't have a default value`, or `Table '...' doesn't exist` inside the `[error]` line | The tables have changed shape since the snapshot, usually by an upgrade. | [6.3](#63-after-an-upgrade-that-went-wrong) |
| No `Array` at all, only a long `Fatal error` naming `tblSites` | The database has no tables. | [8.5](#85-the-database-is-empty-or-gone) |
| `is in the older form, which has to be read whole`, or `Allowed memory size ... exhausted` | The snapshot was made before this release and its largest file is too big for PHP's memory limit, or it holds one very large record (2.1, item 2). | Use the command line, and change `php -r` to `php -d memory_limit=1G -r` in both commands of 8.3. *Tested (23).* If your host refuses that much, use a full dump. |
| **Yellow**, with `pointer(s) were emptied` | A link between two tables was added after the snapshot was taken. Where the link's own rule is "empty the pointer when the other record is deleted", the restore does exactly that, and says how many and where. | Nothing to do. The count is also in the activity log. *Tested (L01, L02, proof 16: the count and the words. That the page shows a warning as yellow is read from the code, `backup.php`, and seen for another warning in L03, L04, section I; not seen in a browser.)* |
| **Yellow**, with `table(s) that are NOT in this snapshot were changed as well` | A table was added after the snapshot was taken (by a later upgrade) and its records are linked to records the restore replaced. The database's own rule for the link deleted them or emptied their pointers. This follows the links onward (a table linked to such a table is counted too) and counts a table's links to itself. | Read the numbers. If those records matter, put them back from a full dump made after that upgrade. **Check this snapshot** shows the same message beforehand, without changing anything. *Tested (L01, L02, proof 22).* |
| `never knowingly puts back a changed value` | A value in the snapshot does not fit its column as the column is now (2.1, item 5), or it is a value that is not on its column's list of choices (2.1, item 8). The message names the column. | Nothing has changed. If an upgrade changed that column: go back to the release the snapshot was made by, then restore; or use a full dump. If NO upgrade changed it, the database was run loosely when the value was saved: this snapshot cannot be put back whole. Mend the rows in the live database and take a new snapshot for next time; for today, restore from a full dump, or put back single tables that do not hold such a value. *Tested (L01, L02, proofs 18 and 24).* |
| `CANNOT BE PUT BACK WHOLE` (a yellow box on the backup page, a warning on the health page, a red box on the upgrade page) | The snapshot holds a value that is not on its column's list of choices (2.1, item 8). The message names the table, the column and how many. | Mend those rows in the live database, then take a new snapshot. *Tested (L01, L02, proof 24; L03, L04, section I).* |
| `engine, which cannot undo a change` | A table is not of the kind that can undo (2.1, item 7). The message names it. | Ask your host to convert that table to InnoDB (`ALTER TABLE name ENGINE=InnoDB`), then try again. *Tested (L01, L02, proof 21: the refusal. The conversion itself was not run.)* |
| `max_allowed_packet` and `so the row was not sent` | One single record in the snapshot is bigger than the largest message your database accepts. The message names the table and gives both sizes. | Nothing has changed, and the portal is open or closed as it was. Ask your host to raise `max_allowed_packet` to at least the number of MB the message gives, or use a full dump. *Tested (L25, L26: an 80 MB record, a server that accepts 64 MB; L01, L02, proof 20).* |
| A plain page with no menu, saying one of: `The rehearsal ran, but the database did not confirm that it was undone`; `was stopped. The database did not confirm that the unfinished work was undone`; or `could not be put back as they were, so the connection was closed` | The portal had to close its connection to the database: either to be sure unfinished work was undone, or because the connection's own settings could not be put back after the work. The first two: nothing has changed. The third says how the work itself ended, and after a restore it can say `FINISHED and is permanent`. Underneath, the page says where the portal stands: `STILL CLOSED` (this page closed it and could not reopen it), `closed … as it was before this began` (you had closed it yourself), or, after a restore that finished, `is now as the snapshot held it`. | Open **Admin, Settings, Maintenance mode** (`/admin/settings/maintenance`), look at "Maintenance mode active", switch it off if the portal should be open, and save; or use [4.2](#42-with-sql-when-the-pages-will-not-load). No line could be written to the activity log, so note what the page said. Then try again. *Tested (L03, L04, sections H and H2; L01, L02, proof 23). The wording for a portal you had closed yourself is read from the code, `backup.php`; not run.* |
| `The connection to the database failed at the very moment the restore was being made permanent` | The one moment nothing can be promised about. Either every table was put back or nothing was changed, never a part. | Reload the page and look at the data before doing anything else. *The words are read from the code. Tested: a restore whose process was killed at that moment was wholly restored (L30, L31).* |

### 8.5 The database is empty or gone

Needs a command line. Not possible from the portal's pages: a new database has
no accounts, so nobody can sign in to press Restore.

1. Make sure an empty database exists, with the name and user in
   `auth_creds.php`. You make one in your hosting panel. *Your host.*
2. Count its tables:

   ```sql
   SELECT COUNT(*) AS tables_in_database FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
   ```

   *Tested (13).* It should say `0`. **If it does not, stop.** This section is
   only for an empty database; use 8.3 instead.
3. Build the empty tables:

   ```bash
   mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p "$DB_NAME" < "$PORTAL/_sql/full_schema.sql"
   ```

   *Tested (13).* It asks for the password, then prints eight lines that each
   say `1`. That is normal.
4. Count the tables again with the SQL in step 2. On release 1.4.0 it says
   `224`. *Tested (13).*
5. Put the snapshot back: [8.3](#83-from-the-command-line), steps 2 to 4.
6. Sign in with an account from before.

*Tested (13): we dropped the whole database, followed these steps, and every
table came back identical.*

Without a command line, your host's database tool can import
`_sql/full_schema.sql` for step 3 (*your host*), but step 5 still needs one,
so this route is closed. Put back a full dump instead
([9.2](#92-put-one-back), your host's "Import"). With no dump, you need
somebody who has a command line.

### 8.6 The server's own snapshots are gone

Use the off-site copy, if you set one up (`docs/offsite-backup-setup.md`).

1. Get the portal's files back onto a server. *Your host.*
2. Put your safe copies of `auth_creds.php`, `enc.key` and `offsite.key` back
   into `_auth_keys/`.
3. Fetch the newest file from your destination into the portal's `_backups`
   folder. It is named `upgrade-YYYYMMDD-HHMMSS.tar.gz.enc`. One of:

   ```bash
   rclone copy myremote:portal-backups/upgrade-YYYYMMDD-HHMMSS.tar.gz.enc "$PORTAL/_backups/"
   aws s3 cp s3://my-bucket/portal-backups/upgrade-YYYYMMDD-HHMMSS.tar.gz.enc "$PORTAL/_backups/"
   scp user@backup.example.com:portal-backups/upgrade-YYYYMMDD-HHMMSS.tar.gz.enc "$PORTAL/_backups/"
   ```

   *Your host and your destination. Not run by us.* `myremote`, `my-bucket`
   and the address are examples.
4. Unlock and unpack it, with the file's name in place of the example:

   ```bash
   cd "$PORTAL/_backups"
   NAME=upgrade-YYYYMMDD-HHMMSS
   openssl enc -aes-256-cbc -d -pbkdf2 -in "$NAME.tar.gz.enc" -out "$NAME.tar.gz" -pass file:../_auth_keys/offsite.key
   tar -xzf "$NAME.tar.gz"
   ls -1 "$NAME" | wc -l
   ```

   *Tested (27).* The number is one more than the number of tables: `225` on
   release 1.4.0.

   `bad decrypt` means the key is wrong, or is not the file as it was made.
   A key made with an older version of our instructions has two lines; if it
   was pasted back as one line it no longer works. Use the file itself.
   *Tested (20; a wrong key, 27).*

   **Only when you have seen that number**, remove the two packed files. The
   unpacked folder stays:

   ```bash
   NAME=upgrade-YYYYMMDD-HHMMSS
   cd "$PORTAL/_backups" && rm "$NAME.tar.gz" "$NAME.tar.gz.enc"
   ```

   *Tested (27).* It prints nothing. If `PORTAL` is not set it prints
   `No such file or directory` and removes nothing. This used to be the last
   line of the box above, where it deleted the file you had fetched even when
   unlocking it had failed.
5. Carry on with [8.5](#85-the-database-is-empty-or-gone), or with 8.3 if the
   database still has its tables.

*Tested (20): database, snapshots and key file all lost, then rebuilt this
way: identical to the original.*

Step 4 can be done on your own computer, then the unpacked folder uploaded
into `_backups/`.

**If `enc.key` is lost**, the database comes back but every stored secret is
unreadable. Type each integration's secret in again. People who use a
two-factor code will not be able to sign in; that needs a developer.
*Not tested.*

**Scheduled jobs need doing twice.** Each job's token is one of those stored
secrets, and the token is part of the address your host's scheduler calls.
So for every scheduled job (reminders, the health check, the backup check,
calendar imports and the rest):

1. Give the job a new token: on the job's own page where it has one,
   otherwise by entering a new value for its setting on the Settings page.
2. Put that new token into the job's address in your hosting panel's
   scheduler (often called "cron jobs"). *Your host.*

Until step 2 is done the scheduler goes on calling the old address, the
portal refuses it, and the job does not run. The health page names the token
among the secrets it cannot read, but nothing tells you that the scheduler is
being turned away: the reminders simply stop. *Refusing the old token with a
different key file in place, and with none: run by
`tools/settings-secrets-selftest.php`. The scheduler itself: not tested, it
is your host's.*

The bot check's keys are among those secrets. Until they are typed in again
at Admin, Captcha, sign-in and password reset run **without** the bot check
(their own limits on wrong tries still apply), and every other form it
protects is refused. Admin, Captcha, the Settings page and the health page
each say so, and the error log sends the alert e-mail where one is set up.
*Run by `tools/settings-secrets-selftest.php`, with keys encrypted by a
different key file.*

**If `offsite.key` is lost**, the off-site copies cannot be opened by anybody,
ever.

---

## 9. The second kind of backup: a full dump

A dump is one file holding the whole database, structure and rows, made by the
database's own tool. It copes with everything in
[2.1](#21-what-the-portals-own-backup-still-cannot-do), and it holds the
tables' structure, which the portal's own snapshot does not. Make one
before every upgrade and on a regular day.

### 9.1 Make one

```bash
mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p --single-transaction --no-tablespaces --hex-blob "$DB_NAME" > ~/portal-dump.sql
tail -1 ~/portal-dump.sql
```

*Tested (21).* The last line must read `-- Dump completed on` and a date. If
it does not, the dump is incomplete: do not rely on it.

The file contains everything, password fingerprints included. Keep it out of
any folder a browser can reach, copy it off the server, then delete it there.

Without a command line: your hosting panel's own database backup, or
phpMyAdmin's "Export". *Your host.*

### 9.2 Put one back

Close the portal first ([section 4](#4-close-the-portal)).

```bash
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p "$DB_NAME" < ~/portal-dump.sql
```

*Tested (21): database dropped, dump put back, every table and every row
identical, including imported calendar events and records that point at a
newer record of the same kind.* It prints nothing when it works.

**This is not all or nothing.** If it stops half way, the database is part
old and part new. Fix what stopped it and run the same command again from the
top; running it twice is safe. *Tested (21).*

It replaces every table that is in the dump. Everything entered since the dump
was made is lost.

Afterwards the portal is open or closed as it was **when the dump was made**,
whatever you did before putting it back. *Tested (26).* Check with the second
line of the SQL in [4.2](#42-with-sql-when-the-pages-will-not-load), and close
it again if it should be closed. After a break-in, a dump from before your
steps undoes section 7 exactly as a snapshot does
([8.1](#81-what-a-restore-does)).

Without a command line: phpMyAdmin's "Import". *Your host.*

---

## 10. Tell people

Send this once the portal is closed.

> **Subject:** Portal temporarily unavailable
>
> The portal is closed while we look into a technical problem. Signing in and
> every page are affected.
>
> We expect it back by **[time and time zone]**, and will write again by
> **[time]** either way.
>
> We will tell you if anything you entered needs entering again.
>
> Sorry for the trouble.
>
> [Name]

Do not promise that nothing was lost. A restore loses whatever was entered
after the snapshot.

---

## 11. Afterwards

Within a week:

1. **What happened**, in two sentences.
2. **When**: each step and its time, from `/admin/activity`.
3. **What broke**, exactly.
4. **What helped.**
5. **Where this page was wrong or unclear.** Correct it.
6. **What will change**: one to three things, each with a name and a date.
7. **Was personal information exposed?** Many countries set a deadline for
   telling the regulator and the people affected (in the United Kingdom, 72
   hours to the Information Commissioner's Office). Check your own country's
   rule the same day.

---

## What was tested

All on 12 October 2026: release 1.4.0, MySQL 8.0.36 (and, where a row says so,
MySQL 8.4.11 as well), PHP 8.5.10, a database user with rights to one database
only, and a throwaway copy of the portal.
"Identical" means every one of 224 tables had the same number of rows and the
same fingerprint of its contents before and after.

| # | What we did | Result |
| --- | --- | --- |
| 11 | Snapshot made with "Run snapshot now"; data damaged; **Restore ALL tables** pressed | Identical |
| 12 | Data damaged; the two commands of 8.3 | Check changed nothing; restore: identical |
| 13 | Whole database dropped; the steps of 8.5 | Identical, structure included |
| 14 | Three faulty snapshots (a file cut short; a row the database refuses, half way through writing; no manifest) | Each refused; database unchanged |
| 15 | The three faults found on 12 October 2026, **before** they were fixed; tables changed shape four ways; a restore killed half way | As 2.1 described them then; database unchanged after every failure |
| 16 | Portal closed and opened, by SQL and from the page; the errors query | As described |
| 17 | Every step of section 7 except sending email. (7.7's sentence about an open session was true when this ran and has since changed: see 28) | As described |
| 18 | `enc.key` replaced, then put back | Secret unreadable, then readable again |
| 19 | The example off-site script: whole runs; every message in its table except the `aws` and `scp` ones; a failed upload and a failed encryption; stopped by a signal (interrupt, terminate, hang-up, quit); a keep number that is not a whole number; old copies deleted, with the real rclone 1.75.2 and a folder on the test machine. In bash 5.3, 3.2 and (on Linux) 4.4 | As `docs/offsite-backup-setup.md` describes |
| 20 | Database, snapshots and key file lost; the steps of 8.6 then 8.5 | Identical |
| 21 | Full dump made; database dropped; dump put back, twice | Identical both times |
| 22 | PHP check; database unreachable; missing `auth_creds.php`; the two disk-usage commands; release check | As described |
| 23 | Every address on this page opened; snapshot Delete button; the memory option | As described |
| 24 | The two commands of 6.2 step 3: finished and half-made folders mixed; `PORTAL` not set; a folder name with a space; a folder named by hand; a link named like a snapshot; a name holding a line break; four or fewer; no finished snapshot; run twice | Only the oldest finished snapshots were deleted; in every other case nothing was |
| 25 | The three queries of 7.3; the count of 7.5 step 2 with the right folder and a wrong one; the three lines of 7.7 | As described |
| 26 | A snapshot made; 4.2, 7.3, 7.7 and 7.8 step 3 done by SQL; the snapshot put back with 8.3. Then a dump made with the portal open, the portal closed, the dump put back with 9.2 | Every old password matched its stored fingerprint again, the locked account was switched on, the new password no longer matched, the portal was open. After the dump: the portal open |
| 27 | A copy made by the example script fetched back; 8.6 step 4 with a wrong key, then the right one; the box that removes the packed files, with `PORTAL` set and not set; the check of 8.3 step 2 | Wrong key: `bad decrypt`, the fetched file untouched. Right key: 225 files, identical to the original snapshot; the check passed |
| 28 | Switching an account off, and erasing one, while that person is signed in: the portal's own page test (`tools/access-pages-selftest.php`, part K: real sign-ins and real cookies), run on 12 October 2026 on the code that holds this change | The next request is signed out; a public page treats them as signed out; switching the account back on does not revive the old session |
| 29 | `tools/backup-restore-selftest.php` on MySQL 8.0.36 and 8.4.11, a database user with rights on one database: 23 proofs (L01, L02 give the number of checks). Imported events; all nine self-pointing tables; a rehearsal that says no and yes; 60,000 rows under a 32 MB limit; an old-form snapshot; a snapshot and a restore each killed half way; a file cut short; moments across a change of time zone and the night the clocks go back; a record saved while a snapshot ran; the clean-up after an upgrade; a link added after the snapshot; a column changed after the snapshot, on a loosely run connection and on one with the database's notes switched off; odd names and links; a 50 MB record, and a record larger than the database's limit; a table that cannot undo; tables that are not in the snapshot; an undo the database does not confirm, and settings that cannot be put back; a value that is not on its column's list | Every value of every table identical after every restore; database unchanged after every refusal and every kill |
| 30 | `tools/backup-page-selftest.php` on both versions (L03, L04 give the number of checks): Run snapshot now; Check this snapshot (good, and with a row the database refuses); who may press it; Restore ALL tables; a portal that was already closed; an unfinished folder on the backup, freshness and health pages; seventeen odd names sent to every button; Delete, Inspect and a link; the page when the database does not confirm an undo, and when the connection's settings cannot be put back; the upgrade page's messages, the upgrade page with an imported event, and the upgrade page, the backup page and the health page when a snapshot cannot be put back whole | As described |

**Not tested:** MariaDB; any real hosting company, including how loosely it
runs its database; real email;
rclone with anything but a folder on the test machine; S3 or SFTP; a real
failed upgrade; a host ending a long restore; a snapshot made by an older
release whose tables have since changed shape; upgrading with the backup
switched off; signing in with a
passkey, Microsoft or Google. MySQL 8.4 is tested for the portal's own
snapshot and restore (29, 30) and for nothing else on this page.

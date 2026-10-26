# Installing and upgrading the portal

This guide is for somebody who has a hosting company's control panel and its file manager, and nothing else. No
command line is needed. It comes inside every package as `INSTALL.md`.

- **Installing for the first time:** read "Before you start", then steps 1 to 11.
- **Upgrading a portal that is already installed:** go to "Upgrading to a newer package", further down. Do not run
  the installer again.

Addresses in this guide that contain `portal.example.org` are examples. Use your own.

**The installer's own numbers are not this guide's.** The installer shows a row of seven numbers at the top of its
pages and calls them stages. They cover steps 3 to 10 of this guide: steps 1 and 2 come before the installer has
anything to show, and step 11 after it has finished. Each heading below says which stage it is.

## Before you start

You need the package, a file named like `webms-intra-1.5.0.zip`. Beside it on the download page is a small file
with the same name and `.sha256` on the end. It holds the package's checksum: a long line of letters and digits
worked out from the whole zip, which comes out differently if anything at all in the zip has changed. Comparing it
is optional. It shows that your download is complete and is the file that was published. To work out the checksum
of your own download (SHA-256 is the name of the method):

- on Windows, open "Command Prompt" and type `certutil -hashfile`, a space, the zip's name, a space, and `SHA256`;
- on a Mac, open "Terminal" and type `shasum -a 256`, a space, and the zip's name.

Compare the result with the line in the `.sha256` file. If they differ, download the zip again and do not use the
first one.

You also need, from your hosting company's control panel:

- **A website address** for the portal, for example `https://portal.example.org`. Use an address of its own: the
  portal has to be at the top of its address, not in a folder such as `example.org/portal`.
- **PHP 8.4 or newer** for that website.
- **A MySQL database**, a database user with full rights to it, and that user's password. The panel has a page for
  this, usually called "MySQL Databases". Create a new, empty database for the portal alone. Write down what the
  panel shows: the database's host name, its name, the user's name and the password. If the panel also shows a
  **port** (a number, usually 3306), write that down too.
- **The panel's file manager.** Switch on "show hidden files" in its settings before you upload anything: several
  of the portal's files have names that begin with a full stop, and many file managers hide them.

## 1. Put the files in place

1. In the file manager, make a new folder for the portal, for example `portal`. Make it beside the folders your
   websites are served from, not inside one of them: whatever is inside a website's folder can be asked for
   through that website.
2. Open that folder and upload the zip into it.
3. Select the zip and choose **Extract** (some panels say "Unzip" or "Extract here"). Extract it into the folder it
   is in.
4. Delete the zip from the hosting account. It is no longer needed there.

You then have:

    portal/
        _apps/  _core/  _install/  _lang/  _libraries/  _sql/  _vendor/     private: never reachable from the web
        admin_html/                                                          the web folder
        VERSION.txt   CHANGELOG.md   INSTALL.md

Some file managers put what they extract into one more folder, named after the zip. That is harmless, but
remember it: **the folder that holds `_core` and `admin_html` is the one this guide calls `portal`**, whatever it
is really called and wherever it is.

**Point the website's address at the web folder, `portal/admin_html`, and at nothing else.** Hosting panels call
this setting the "web directory", the "document root" or the "site folder".

**Never point an address at the folder that holds `_core`** (here, `portal`). Everything private would then be in
reach of a browser. Step 2 says how to tell that this has happened.

Check that one file arrived, because it is the one a file manager most often hides or drops:
`portal/admin_html/.htaccess`. Without it no page of the portal works except the first.

A package does not bring a file named `.channel`. That file holds one word saying which copy of the portal this
is (`live` for the real one), and it belongs to the installation: the installer writes it when it finishes
(step 10), and unpacking a newer package never replaces it.

### If your hosting will not let you do what step 1 asks

**The file manager refuses the upload.** The zip is about 11 MB, and some file managers stop at less. Use FTP
(or SFTP) instead: a way of copying files to the hosting account with a program on your own computer. The hosting
panel has a page, usually called "FTP accounts", with the details to type in; FileZilla and WinSCP are two free
programs for it.

- Upload the zip into `portal` by FTP, then Extract it with the file manager as above.
- If the panel has no Extract at all: unpack the zip on your own computer and upload what is inside it into
  `portal` by FTP. That is about 1,600 files and takes a while. Tell the FTP program to show hidden files first
  (in FileZilla: the "Server" menu, "Force showing hidden files"), and check afterwards that
  `portal/admin_html/.htaccess` arrived.

**The panel fixes the folder of your main address** (usually to `public_html`), and you cannot choose another.
The simplest answer is to give the portal an address of its own, such as `portal.example.org`: panels let you
choose the folder for an added address (a "subdomain"). If the portal has to be at the main address:

1. Unpack the package into the folder that **holds** `public_html` (your account's home folder), so that `_core`
   and `admin_html` sit beside `public_html`.
2. Make sure `public_html` holds nothing you need; move anything of yours out of it.
3. Open `admin_html`, select everything in it (hidden files too, so that `.htaccess` is among them), copy it all
   into `public_html`, and then delete `admin_html`.

From then on, where this guide says `portal`, read your home folder, and where it says `admin_html`, read
`public_html`. At every upgrade, do the same copy again: see "If your portal's web folder has another name".

**The panel only allows web folders inside `public_html`.** Ask the hosting company first: many will point an
added address at a folder outside `public_html` if asked. If they will not, the portal's private folders have to
sit inside a folder the web can reach, kept private only by a small rules file in each of them. Some web servers
obey those files and some do not, so **test before you install**:

1. Unpack the package into a folder inside `public_html`, for example `public_html/portal`, and point the
   portal's address at `public_html/portal/admin_html`.
2. In a browser, open your **main** website's address followed by `/portal/_core/version.php`.
3. It must answer **Forbidden** (error 403). If you see a blank page, or anything else, this web server does not
   obey the rules files. Delete `public_html/portal` and do not install the portal on this hosting: once
   installed, its database backups and everything people upload could be fetched by anybody.
4. If it answers Forbidden, carry on from step 2 of this guide. When the installation is finished, test once more:
   your main address followed by `/portal/_auth_keys/enc.key` must answer Forbidden too.

This is second best. The files `VERSION.txt`, `CHANGELOG.md` and `INSTALL.md` can be read through the main
address in this layout; they hold nothing private, but they say which version you run.

## 2. Open the address

Open the portal's **plain address** in a browser, with nothing after it: `https://portal.example.org`. The
installer starts by itself and asks for a code (step 3).

**If you see anything else, the website is pointing at the wrong folder.** Go back to step 1 and change the web
directory to `portal/admin_html`. The signs are:

- **a list of folders** (`_apps`, `_core`, `admin_html` …), or **"Forbidden"**, or **"Not found"**. This is the
  usual sign. It comes from the web server, not from the portal, so it says nothing about what to do;
- a page headed **"The website is pointing at the wrong folder"**: you opened the folder `_install` itself;
- a page headed **"The installer will not run at this address"**: the address has `admin_html` in it. Do not add
  the web folder's name to the address to get round a folder list. An installation made that way would leave the
  database login and the key file where anybody could ask for them.

Hosting panels can take a few minutes to act on a changed web directory. Then open the plain address again.

## 3. Show that this is your hosting (the installer's stage 1, "Your code")

The installer asks for an **installation code**. Anybody on the internet can open the installer's first page, so
it will do nothing until you type a code that only you can read. It has just written that code into a file on
your hosting account:

1. In the file manager, open the folder you unpacked the package into (`portal`).
2. Open the folder `_auth_keys`. The installer has just made it; press Refresh if you do not see it.
3. Open the file `install-code.php` with View or Edit. Do not change it.
4. The code is on the line that begins **YOUR CODE IS**: sixteen letters in four groups, for example
   `ABCD-EFGH-JKMN-PQRS`.
5. Type it into the installer. Capitals or small letters, with or without the dashes. There are no digits in it.

If the installer answers **"That is not the code in the file"**, open the file again and compare. After ten wrong
codes in a row, typed by anybody, the installer writes a **new** code into the same file, and the old one stops
working.

**If the code keeps changing** although you typed it correctly, somebody else is sending wrong codes. Read the
code, then set the file `install-code.php` to read-only in the file manager (usually under "Permissions": untick
"Write"). The code then stays as it is, and the installer still deletes the file when the installation is
finished.

If the installer says it **could not write its code file**: in the file manager, create a folder named
`_auth_keys` inside `portal` if there is none, and allow the website to write to it, then press "Try again". In
the file manager that is the folder's "Permissions": the owner may read, write and open the folder; everybody
else may read and open it but not write. Panels that show permissions as a number call that 755. Your host's
help pages say if it needs anything else.

**If you leave the installer open for a long time** (about twenty minutes on most hosting) it forgets where it was
and asks for the code again. Type the code again, and go through the pages again: the kind of organisation
(step 5) and the **database details** (step 6) have to be given again. What was already built in the database is
kept: at step 6 choose "Carry on with what is there".

## 4. Checks (stage 2)

The installer lists what it needs and whether it is there. When every line is in order, press "Continue". A line
that needs attention says what to do: put it right in the hosting panel, then press "Check again".

The last line says **which copy of the portal this is**. For a real installation it should say "This is the live
portal".

- It also says this is **ASSUMED**, because nothing on the server says which copy this is yet. That is as it
  should be at this step. The line goes on to say what the package is (the live portal, or a beta or alpha test
  copy) and that the installer will write that word into the file `.channel` when the installation finishes.
  There is nothing for you to create.
- If it says ASSUMED and tells **you** to create the file `.channel`, the files you uploaded did not come from a
  package (there is no `VERSION.txt` beside `_core`). The installation will work; create the file as the line
  says.
- If it says there is a file named `.channel` **but it was not used**, the file's first line is not a word the
  portal knows. Open the file and put one word on its first line, alone: `live` for the real portal; `beta`,
  `alpha` or `dev` for a test copy.

## 5. Kind of organisation (still stage 2)

Choose what kind of organisation the portal is for. This decides the name it goes by (for a place of worship, for
example, it is called ChurchMS). Every feature is there whichever you choose.

## 6. The database (stage 3)

Type what you wrote down and press "Test the connection". The page has five boxes: the host, the user name, the
port, the password and the database's name. **Leave the port at 3306 unless your hosting panel gives another
number.**

The button tests the connection and looks at what the database holds. If there is no database of that name and
the user is allowed to create one, it creates it, empty. On most shared hosting the user is not allowed to, which
is why "Before you start" has you create the database in the panel. No table is built yet, and nothing in a
database that is already there is changed.

- **"The database refused that user name and password"**: check both against the panel.
- **"No database server answered at that address"**: check the host name and the port. Many hosts use
  `localhost`; others give the database a host name of its own.
- **"The database … could not be used"**: there is no database of that name, or this user may not open it. Create
  it in the hosting panel, give the user full rights to it, and try again.
- **"This database is not empty"**: the database already holds tables. The page names the database and tells you
  **how many accounts it holds**. Read that number before you choose.
  - If an earlier try at installing was interrupted (it holds no accounts, or only the administrator you had just
    made), choose **"Carry on with what is there"**. Nothing is removed.
  - Choose **"Wipe it and start again from nothing"** only if you are certain nothing in that database is needed.
    It deletes every table of the portal and every uploaded file, and cannot be undone. So that it cannot be
    pressed by accident, the page makes you type a short text it shows you. If the page shows more accounts than
    you expect, this may be a working portal: stop, and check the database's name.
  - "No finished installation is recorded in it" does not mean the database is unused. A portal installed before
    mid 2026 looks the same.
  - Your choice is for **this database only**. If you go back and type database details again, the installer
    asks again.
- **"This database is not empty, and not everything in it is this portal's"**: the database also holds another
  program's tables. The portal needs a database of its own. Create a new, empty one in the hosting panel and type
  its name.
- **"There is already a portal installed on this database"**: the database holds a finished portal. Sign in to
  that portal as its global administrator first, in the same browser, then come back. (The message says
  "umbrella administrator". It means the same.)

## 7. Build the tables (stage 4)

The page names the database it is about to build into, and says which database product and version the server
runs and whether the portal supports it. **If you chose "Wipe it and start again", an amber box says "This will
WIPE the database …" and names it.** If that is not the database you mean, press Back: nothing has been removed
yet.

Press "Build the tables" and wait for the next page. It usually takes a few seconds and can take a minute or
two. Do not press it twice.

If the installer answers that the button "was on a page drawn for a different database", you had it open in two
tabs, or used the browser's Back button. Nothing was built or removed. Carry on from the page it shows.

## 8. The first administrator (stage 5)

Your name, your e-mail address and a password, typed twice. This is the account that can change everything in the
portal and add everybody else. You sign in with the e-mail address, so it can be 50 characters at most. The
password needs at least 12 characters, with a capital letter, a small letter, a digit and one other character.

## 9. Web addresses (stage 6)

- **The address of this portal.** The installer fills in the address you are using. Check it, and make sure it
  begins with `https://` if your website has a security certificate. Every link in an e-mail the portal sends
  begins with this address.
- **Support e-mail address.** Where people should write when they need help. You may leave it empty.

## 10. Finish (stage 7)

The page lists what is about to be set up. Read it, in particular the name of the database. Then type the
installation code once more (the same code, from the same file) and press "Finish the installation".

If the finish stops with a message (for example, a folder it may not write to), nothing is locked yet. Put right
what the message names and press Finish again. You can also go Back and correct an address first: the corrected
one is stored.

The last page is shown **once**. It tells you:

- the address the portal will use in e-mails. If the database already held an address before you started, that one
  is kept, and the page says so;
- which copy of the portal this is, and what the installer did about the file `.channel`: it writes the
  package's word into it (`live` for a released package) unless a `.channel` file was already there, which it
  leaves exactly as it is;
- to **back up the key file with the database** (see below);
- which of the private folders hold the file that refuses every request. If one of them held a rules file of
  somebody's own, the installer left it alone and the page names the folder;
- that the installation code has been deleted. On a few hosts the file cannot be deleted; the page then says so,
  and you delete `_auth_keys/install-code.php` yourself. Its code opens nothing by then;
- what to do next. That list is short; step 11 below is the full one.

## 11. Straight afterwards

Some of the pages below have no link leading to them yet, so this guide gives the address to type. Each is your
portal's own address with a few words after it: `/admin/settings/organisation` means, for example,
`https://portal.example.org/admin/settings/organisation`.

1. **Copy the whole `_auth_keys` folder somewhere safe and private** (in the file manager: select the folder and
   choose Download, or Compress and then download the result), and keep it with every backup of the database. It
   holds `enc.key`, which every password and key saved in the portal's Settings is encrypted with. A database
   restored without it loses every one of them.
2. **Sign in** with the administrator's e-mail address and password. The installer's last page has a button to
   the sign-in page; so does the portal's plain address.
3. **Give the portal your organisation's name and time zone.** Until they are set, the portal calls itself
   "Portal" and keeps its clock on UTC (the world's standard time, which has no summer time).
   - Open **Admin, then Sites**. Press "Edit" on the one organisation listed. Change "Site Name" and the
     copyright name below it (printed at the foot of every page), choose your "Time zone" from the list, and
     save. This is the name on every page once somebody has signed in, and the time zone the portal's clock runs
     on. This page is the only place the time zone is set.
   - The name is kept in a second place as well, and both have to be set. Open **Admin, then Settings**. The page
     is one long list of named settings. Find `site.name`, press the pencil button on its line, type your
     organisation's name and press "Save Changes". This is the name on the sign-in page.
4. **Check the portal's own address and the support address** at `/admin/settings/organisation`, under "Web
   addresses". They are the two you typed at step 9.
5. **Tell the portal how to send e-mail** at `/admin/integrations/email`, and send yourself a test from that page.
   The dashboard's set-up list has a line that leads to the same page. Until this is done the portal cannot send
   a password-reset link.
6. **Add the scheduled jobs.** A few of the portal's tasks have to be started from outside, every few minutes:
   for example sending e-mail that could not be sent at once. Hosting panels call the page for this "Cron jobs"
   or "Scheduled tasks". What to add there, line for line, is in the release notes: open Admin, then Release
   Notes, and use your browser's "Find" to look for **what an administrator must do after upgrading**. That
   entry, about the mail queue, applies to a new installation too. The portal's Help has the same, under Admin
   Guide, "The mail queue: mail that cannot go at once".
   **The `/cron/mail-queue` job also sends text messages that were held because the person was inside their
   Sabbath quiet hours. Without it, a held text is never sent** — its record is corrected to "not sent" instead.
7. **Add the people** who will use the portal: Admin, then User Management.

The installer has locked itself. It cannot be run again by anybody who only has the portal's address.

## What the installer leaves on the hosting account

| In `portal/` | What it is |
| --- | --- |
| `_auth_keys/auth_creds.php` | The database login. |
| `_auth_keys/enc.key` | The key file. Back it up with the database. |
| `_auth_keys/.installed` | The lock that stops the installer running again. |
| `_uploads/` | Everything people upload. |
| `_backups/` | The snapshots the portal takes of its database, by itself before an upgrade and whenever an administrator asks. |
| `.htaccess` in each of those three folders | Tells the web server to refuse every request for anything inside. A second lock, and only on a web server that obeys such files (see below). What really keeps these folders private is step 1. |
| `.channel` | One word saying which copy of the portal this is. Written by the installer from the package's own word, unless the file was already there. |

None of these is in a package, so unpacking a newer package never replaces them.

**Which web server you have.** The `.htaccess` files are obeyed by the web servers called Apache and LiteSpeed,
and ignored by the one called nginx. To see which yours is: sign in, open Admin, then Server Information, and
read the line "Web server". Your hosting company's help pages say it too.

## Upgrading to a newer package

An upgrade is: read the new version's notes, make a backup, unpack the new package over the old one, and open
the portal's Upgrade page once. Your database, your key file, your uploads, your backups and the file that says
which copy this is (`.channel`) stay as they are: no package holds any of them.

**Whether the portal closes during an upgrade depends on what the new version brings.**

- If it brings a **database upgrade**, everybody except a global administrator sees a holding page that says the
  portal is being upgraded. That begins when the new files are in place and ends when the Upgrade page's button
  has been pressed.
- If it brings **none**, the portal does not close and people can go on using it. The new version is still not
  recorded until you press **"Finish the upgrade"** on the Upgrade page, and until you do, the portal asks its
  database one small question on every request. So open the Upgrade page all the same.

The notes do not always say which it will be, so plan for the first: choose a quiet time, and do the steps from
"Unpack" to "Open the Upgrade page" in one sitting.

These steps are for a portal whose web folder is called `admin_html`, as a package makes it. **If yours has
another name** (a portal first put in place by FTP usually has `public_html`), read "If your portal's web folder
has another name" below before you unpack: there is one more thing to do.

### Before you unpack anything

1. **Read the new version's notes first.** They are in the new zip, in the file `CHANGELOG.md`: open the zip on
   your own computer and open that file with any text editor, such as Notepad. It is long, and the newest changes
   are at the top. Look for the entries whose headings mention **upgrading**. Some things have to be done
   **before** the new files are uploaded, and those entries are where they are written. For a portal installed
   before October 2026, read these five entries in full. Each is given here by words from its heading, so that
   the text editor's "Find" takes you to it:
   - **what somebody upgrading must know** (about secret settings). Its part 1, "What STARTS the moment the new
     files are uploaded", lists what begins working by itself (text messages, which cost money, among them) and
     what to switch off first if you do not want it yet. Its part 2, "What can STOP", says which scheduled job can
     stop, and how to put it right. Its part 3, "Back up the key file with the database", is why the two are
     backed up together.
   - **After upgrading: three things to do, and one thing to know**. The first of the three is to set the
     portal's web address, which closes a way of sending somebody a misleading password-reset link.
   - **what an administrator must do after upgrading** (about the mail queue). One scheduled job has to be added
     in the hosting panel, and the entry gives the line to add.
   - **Upgrading — nothing has to be done** (about the installer and the private folders).
   - **What an administrator must tell people after upgrading** (about backup codes for two-factor
     authentication). Everybody who switched it on before has no backup codes and must make a set.

   After the upgrade the same notes are in the portal itself, at Admin, Release Notes.
2. **Back up the database and the `_auth_keys` folder, together.** The database: your hosting panel has a backup
   or "Export" for it (often inside a tool called phpMyAdmin: open the database, choose "Export", keep the
   format "SQL", and press the button). Export the **whole** database, every table. The folder: download it with
   the file manager, as under "Straight afterwards" above. Keep the two side by side. If you can, download the
   `_uploads` folder as well. **Keep the zip of the version you are running now**, too: the way back, at the end
   of this guide, needs it.
3. **Look at the web folder's rules file, `portal/admin_html/.htaccess`.** Unpacking replaces it with the
   package's own. Some hosting panels write lines of their own into that file, most often the lines that choose
   the PHP version. Open the file with View. The portal's own part begins with a line saying "Portal Front
   Controller" and ends with the line `</IfModule>` that follows "Header always unset Server". If there are
   lines before or after that part (a panel usually marks its own with comment lines that name it), copy them
   into a text file on your own computer.

### Unpack the new package over the old one

4. In the file manager, open the folder that holds `_core` and `admin_html` (the one this guide calls `portal`)
   and upload the new zip into it.
5. Select the zip and choose **Extract**, into that same folder. When the file manager asks whether to
   **replace** or **overwrite** the files that are already there, say yes to all.
6. Look at what you have. If there is now a **new folder** inside `portal`, named after the zip, the file manager
   extracted into a folder of its own and **nothing has been upgraded**. Delete that new folder and extract
   again, choosing the option that extracts into the folder you are in.
7. Delete the zip from the hosting account.

Do not delete anything else first, and never delete or empty `_auth_keys`, `_uploads` or `_backups`.

What unpacking replaces: the portal's own files (`_apps`, `_core`, `_install`, `_lang`, `_libraries`, `_sql`,
`_vendor`, `admin_html`) and the three files beside them (`VERSION.txt`, `CHANGELOG.md`, `INSTALL.md`). That
includes the web folder's rules file, `admin_html/.htaccess` (step 3). What it cannot touch, because no package
holds them: `_auth_keys`, `_uploads`, `_backups`, the file `.channel`, anything of your own that you put inside
`admin_html`, and everything in the database.

8. **Only if you copied lines at step 3:** put them back. Either choose the PHP version again in the hosting
   panel, which then writes its lines again by itself, or open `portal/admin_html/.htaccess` and paste them
   where they were (before the portal's part, or after it) and save. Do this straight away: without the panel's
   PHP line the website may run an older PHP, and every page then answers with an error.

**If you upload the unpacked files by FTP instead of using Extract**, the upload takes minutes, not seconds,
and for that time some folders hold the new version and others the old. Choose a quiet time: a page opened in
those minutes can show an error. The folders may be uploaded in any order. While the files are arriving the
portal records nothing about the new version; if the new version brings a database upgrade, the holding page
goes up by itself on the first request after that file has arrived in `_sql`.

### If your portal's web folder has another name

A package's web folder is called `admin_html`. A portal that was first put in place by FTP, or by a hosting
company, usually has one called `public_html`, beside `_core`. Unpacked over such a portal, a package upgrades
everything **except** the web folder: a new folder `admin_html` appears beside yours, and the folder your
website really uses is left as it was. Straight after step 7, before you open the Upgrade page:

1. Open the new folder `admin_html`. With "show hidden files" switched on, select everything in it, including
   `.htaccess`.
2. **Copy** it all into your own web folder (for example `public_html`), and say yes when asked whether to
   replace what is there. (Step 3 above applies to your web folder's `.htaccess`: this replaces it.)
3. Delete the folder `admin_html`. Nothing uses it.

Keep your web folder's name. If it is `public_html`, `public_html_beta` or `public_html_dev` and there is no
`.channel` file, that name is what tells the portal which copy it is.

**If your file manager will not copy a folder over one that already exists**, turn it round and make
`admin_html` the web folder:

1. If your old web folder is called `public_html_beta` or `public_html_dev`, first create a plain text file named
   `.channel` beside `_core`, holding the one word `beta` or `dev`. Without it the portal would take itself for
   the live one, and a test copy would be open to anybody.
2. Copy anything of your own from the old web folder into `admin_html` (for example a folder named
   `.well-known`, or a file some service asked you to put there to show the website is yours).
3. In the hosting panel, change the website's web directory to `portal/admin_html`.
4. When the portal works at its address, delete the old web folder.

If your web folder is not beside `_core` at all, stop and ask whoever supports your portal.

### Open the Upgrade page

9. Open the portal's address followed by `/admin/upgrade`, for example
   `https://portal.example.org/admin/upgrade`. No page links to it yet, so type it. It asks you to sign in if you
   are not signed in; signing in still works while the holding page is up. Only a global administrator can open
   it.
10. Read **"Current Version"**. It shows the version of the files you have just unpacked and how many database
    upgrades are waiting. The line **"Which copy this is"** should say what it said before the upgrade. If it
    does not, the package did not change it (no package holds a `.channel` file): the line itself says what
    decided, which is the file `.channel`, the server's `PORTAL_ENV` setting, or the name of the web folder. Put
    that right before you go on.
11. What the page offers next depends on what it found:
    - **A card headed "Pending Migrations"** (a migration is one step of a database upgrade). Press its button,
      **"Run … Pending Migration"**, once, and wait. The portal first takes a snapshot of the database into
      `_backups`, then runs each step, and then shows a card headed **"Migration Results"** with a line for
      every step, each of which should say OK, and a green box saying **"The upgrade is finished"**. The portal
      is open again from that moment.
    - **A card headed "Finish the upgrade".** The new version brought no database upgrade, so the portal was
      never closed. The new version still has to be recorded, and only this button does that: press
      **"Finish the upgrade"**. It takes no snapshot, because no database upgrade runs. It does two things: it
      stores encrypted any saved password or key that was still kept as readable text (the secret itself stays
      the same, and the card in step 12 names each one), and it records the version. A green box then says
      **"The upgrade is finished"**. Until it is pressed the portal works, but asks its database one small
      question on every request; the health page (Admin, Maintenance, Health, the line "Migrations") says so
      until it is done.
    - **"Pending migrations: None — schema is up to date", and no button.** The upgrade is already finished:
      the files and the database are at the same version. There is nothing to press.
    - **A yellow box saying the portal "could not tell whether a database upgrade is waiting".** The box says
      why, and what to do about that cause. Most often the folder `_sql` did not arrive whole: unpack the
      package again and open the page again. If the box says the database's list of upgrades (the table
      `tblMigrations`) is missing, the page has made it again and offers a button under the box: press it. If it
      says that table is there but could not be read, there is no button: in the hosting panel, check that the
      portal's database user still has full rights to its database, or put the table back from a backup. The
      portal stays closed until it can tell.

    **Do not use Admin, Migrations for an upgrade.** The Admin dashboard's "Pending Migrations" card and its
    "Run Migrations" button lead there. While an upgrade is waiting, that page runs nothing and points you to
    the Upgrade page, because only the Upgrade page takes the snapshot first and records the new version
    afterwards.
12. Read the rest of the page. A card headed **"Stored secrets"** names any saved password or key it has just
    encrypted, and anything it could not. If a box says **"A missing safety file has been put back"**, one of the
    three private folders had lost the file that refuses every request, and the page has written it again. A
    rules file of your own in one of those folders is left as it is.

### Afterwards

13. Open the portal's address followed by `/health`. It should say `"status":"ok"` and the new version.
14. Do what the notes you read at step 1 asked for after the upgrade.
15. If a page looks wrong, reload it once while holding Shift: the browser may still be showing the old version's
    styles.

### If the upgrade goes wrong

Unpacking changes nothing in `_auth_keys`, `_uploads` or `_backups`, so there is a way back to the version you
had, with the database as it was when you backed it up. Take care with step 2 of it: it deletes folders, and it
must be these eight and no others.

1. **Copy out anything of your own that is inside `admin_html`**, because the next step deletes that folder
   whole. Look for a folder named `.well-known` (hosting panels and security certificates put files there), for
   any file a service asked you to put there to show the website is yours, and for lines your hosting panel
   added to `admin_html/.htaccess` (step 3 of the upgrade). Download them to your own computer.
2. In the file manager, inside `portal`, delete these eight folders, which every package brings whole: `_apps`,
   `_core`, `_install`, `_lang`, `_libraries`, `_sql`, `_vendor` and `admin_html`. **Do not delete `_auth_keys`,
   `_uploads` or `_backups`**, nor the file `.channel`. They are yours, no package holds them, and nothing can
   bring them back.
3. Upload the **previous** package into `portal` and extract it there, as in steps 4 to 8 of the upgrade. Put
   back into `admin_html` what you copied out at step 1.
4. Put back the database backup you made at step 2 of the upgrade. **Empty the database first, then import.**
   In phpMyAdmin: open the database, tick "Check all" under the list of tables, choose "Drop" in the list
   beside it, untick "Enable foreign key checks" on the page that asks you to confirm, and confirm. Then choose
   "Import" and give it your backup file. Why empty it first: an import into a database that still holds its
   tables either stops at once with the error `#1050 - Table 'tbl…' already exists` (nothing has been changed;
   empty the database and import again), or, if your backup was made with "Add DROP TABLE" switched on, puts
   your tables back and leaves behind the tables the newer version had added.
5. Open `/admin/upgrade`. It should show the previous version and no upgrade waiting.

The folders are deleted first because unpacking never **removes** a file. Left in place, the newer version's
database upgrade files would stay beside the older version's, and its Upgrade page would offer to run them. For
the same reason an ordinary upgrade leaves behind any file that the newer version no longer has. Two kinds of
left-behind file still matter: one in the web folder can be fetched by somebody who knows its old address, and
a numbered file in `_sql` is still read by the Upgrade page, which lists every numbered file it finds there.

Then tell whoever supports your portal what the Upgrade page said, word for word.

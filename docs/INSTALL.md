# Installing the portal

## Before you start

You need, from your hosting company's control panel:

- **A website address** for the portal, for example `https://portal.example.org`. Use an address of its own: the
  portal has to be at the top of its address, not in a folder such as `example.org/portal`.
- **PHP 8.4 or newer** for that website.
- **A MySQL database**, a database user with full rights to it, and that user's password. The panel has a page for
  this, usually called "MySQL Databases". Create a new, empty database for the portal alone. Write down four
  things: the database's host name, its name, the user's name and the password.
- **The panel's file manager.** Switch on "show hidden files" in its settings before you upload anything: several
  of the portal's files have names that begin with a full stop, and many file managers hide them or leave them
  behind.

## 1. Put the files in place

Unpack the package into a folder of its own on the hosting account, for example a folder named `portal`. You then
have:

    portal/
        _apps/  _core/  _install/  _lang/  _libraries/  _sql/  _vendor/     private: never reachable from the web
        admin_html/                                                          the web folder
        .channel   VERSION.txt   CHANGELOG.md   INSTALL.md

**Point the website's address at the web folder, `portal/admin_html`, and at nothing else.** Hosting panels call
this setting the "web directory", the "document root" or the "site folder".

**Never point an address at the folder that holds `_core`** (here, `portal`). Everything private would then be in
reach of a browser. Step 2 says how to tell that this has happened.

Check two files arrived, because they are the ones a file manager most often drops:

- `portal/admin_html/.htaccess`. Without it no page of the portal works except the first.
- `portal/.channel`. It holds one word, `live`, and tells the portal that this is the real one and not a test copy.
  If it is missing the portal still installs and assumes it is live; the installer tells you so and tells you how to
  create the file.

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

## 3. Show that this is your hosting

The installer asks for an **installation code**. Anybody on the internet can open the installer's first page, so
it will do nothing until you type a code that only you can read. It has just written that code into a file on
your hosting account:

1. In the file manager, open the folder you unpacked the package into (`portal`).
2. Open the folder `_auth_keys`. The installer has just made it; press Refresh if you do not see it.
3. Open the file `install-code.php` with View or Edit. Do not change it.
4. The code is on the line that begins **YOUR CODE IS**: sixteen letters in four groups, for example
   `ABCD-EFGH-JKMN-PQRS`.
5. Type it into the installer. Capitals or small letters, with or without the dashes. There are no digits in it.

If the code is refused, open the file again and compare. After ten wrong codes in a row, typed by anybody, the
installer writes a **new** code into the same file, and the old one stops working.

**If the code keeps changing** although you typed it correctly, somebody else is sending wrong codes. Read the
code, then set the file `install-code.php` to read-only in the file manager (usually under "Permissions": untick
"Write"). The code then stays as it is, and the installer still deletes the file when the installation is
finished.

If the installer says it **could not write its code file**: in the file manager, create a folder named
`_auth_keys` inside `portal` if there is none, allow the website to write to it (your host's help pages say which
permissions that needs; 755 is the usual answer), then press "Try again".

**If you leave the installer open for a long time** (about twenty minutes on most hosting) it forgets where it was
and asks for the code again. Type the code again. You will also have to **type the database details again** (step
6). What was already built in the database is kept: at step 6 choose "Carry on with what is there".

## 4. Checks

The installer lists what it needs and whether it is there. Each line that needs attention says what to do. Put
those right in the hosting panel, then press "Check again".

The last line says **which copy of the portal this is**. For a real installation it should say "the live portal".

- If it says this was **ASSUMED** because nothing says which copy this is, the file `.channel` did not arrive. The
  installation will work, and the line tells you the file to create and the word to put in it.
- If it says there is a file named `.channel` **but it was not used**, the file's first line is not a word the
  portal knows. Open the file and put one word on its first line, alone: `live` for the real portal; `beta`,
  `alpha` or `dev` for a test copy.

## 5. Kind of organisation

Choose what kind of organisation the portal is for. This decides the name it goes by. Every feature is there
whichever you choose.

## 6. The database

Type the four things you wrote down and press the button. It tests the connection and looks at what the database
holds. If there is no database of that name and the user is allowed to create one, the button creates it, empty.
No table is built yet, and nothing in a database that is already there is changed.

- **"The database refused that user name and password"**: check both against the panel.
- **"No database server answered at that address"**: check the host name. Many hosts use `localhost`; others give
  the database a host name of its own.
- **"This database is not empty"**: the database already holds tables. The page names the database and tells you
  **how many accounts it holds**. Read that number before you choose.
  - If an earlier try at installing was interrupted (it holds no accounts, or only the administrator you had just
    made), choose **"Carry on with what is there"**. Nothing is removed.
  - Choose **"Wipe it and start again"** only if you are certain nothing in that database is needed. It deletes
    every table of the portal and every uploaded file, and cannot be undone. If the page shows more accounts than
    you expect, this may be a working portal: stop, and check the database's name.
  - "No finished installation is recorded in it" does not mean the database is unused. A portal installed before
    mid 2026 looks the same.
  - Your choice is for **this database only**. If you go back and type database details again, the installer
    asks again.
- **"Not everything in it is this portal's"**: the database also holds another program's tables. The portal needs a
  database of its own. Create a new, empty one in the hosting panel and type its name.
- **"There is already a portal installed on this database"**: the database holds a finished portal. Sign in to
  that portal as its global administrator first, in the same browser, then come back.

## 7. Build the tables

The page names the database it is about to build into. **If you chose "Wipe it and start again", an amber box
says "This will WIPE the database …" and names it.** If that is not the database you mean, press Back: nothing has
been removed yet.

Press the button and wait. It can take a minute or two. Do not press it twice.

If the installer answers that the button "was on a page drawn for a different database", you had it open in two
tabs, or used the browser's Back button. Nothing was built or removed. Carry on from the page it shows.

## 8. The first administrator

Your name, your e-mail address and a password. This is the account that can change everything in the portal and
add everybody else. You sign in with the e-mail address, so it can be 50 characters at most. The password needs at
least 12 characters, with a capital letter, a small letter, a digit and one other character.

## 9. Web addresses

- **The address of this portal.** The installer fills in the address you are using. Check it, and make sure it
  begins with `https://` if your website has a security certificate. Every link in an e-mail the portal sends
  begins with this address.
- **Support e-mail address.** Where people should write when they need help. You may leave it empty.

## 10. Finish

The page lists what is about to be set up. Read it, in particular the name of the database. Then type the
installation code once more (the same code, from the same file) and press "Finish the installation".

If the finish stops with a message (for example, a folder it may not write to), nothing is locked yet. Put right
what the message names and press Finish again. You can also go Back and correct an address first: the corrected
one is stored.

The last page is shown **once**. It tells you:

- the address the portal will use in e-mails. If the database already held an address before you started, that one
  is kept, and the page says so;
- which copy of the portal this is;
- to **back up the key file with the database** (see below);
- which of the private folders hold the file that refuses every request. If one of them held a rules file of
  somebody's own, the installer left it alone and the page names the folder;
- that the installation code has been deleted. On a few hosts the file cannot be deleted; the page then says so,
  and you delete `_auth_keys/install-code.php` yourself. Its code opens nothing by then;
- what to do next.

## 11. Straight afterwards

1. **Copy the whole `_auth_keys` folder somewhere safe and private**, and keep it with every backup of the
   database. It holds `enc.key`, which every password and key saved in the portal's Settings is encrypted with. A
   database restored without it loses every one of them.
2. Sign in with the administrator's e-mail address and password.
3. In Admin, Settings, set your organisation's name, time zone and artwork.
4. In Admin, Integrations, Email, tell the portal how to send e-mail, and send yourself a test.
5. Add the people who will use the portal.

The installer has locked itself. It cannot be run again by anybody who only has the portal's address.

## What the installer leaves on the hosting account

| In `portal/` | What it is |
| --- | --- |
| `_auth_keys/auth_creds.php` | The database login. |
| `_auth_keys/enc.key` | The key file. Back it up with the database. |
| `_auth_keys/.installed` | The lock that stops the installer running again. |
| `_uploads/` | Everything people upload. |
| `_backups/` | The snapshots the portal takes before it upgrades its database. |
| `.htaccess` in each of those three folders | Tells the web server to refuse every request for anything inside. A second lock, for Apache web servers only. What really keeps these folders private is step 1. |

When you later upgrade by unpacking a newer package over this one, none of these is in the package, so none is
replaced.

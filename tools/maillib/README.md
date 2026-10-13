# The mail library (`MWBM\Mail`)

A small library for sending e-mail from a PHP application on shared hosting: no Composer, no command line, PHP 8.4 or later with the OpenSSL extension (and `intl`, only if mail must go to domains written in non-English letters). It was written for the portal and is shaped so that it can be lifted out and used in other applications.

**What is here today:** the core (a message, a result, the interface every route implements) and one route, our own SMTP client. Routes for Microsoft 365, Google and PHPMailer are planned as further classes implementing the same `Transport` interface; they are not built yet.

The portal sends through it by way of one file, `web/_core/MailRoutes.php`, which is the only file of the portal that names the library.

## Where it lives, and why in two places

| Folder | Holds |
| --- | --- |
| `web/_core/maillib/` | The library: `autoload.php` and the classes under `src/`. Only PHP files. |
| `tools/maillib/` | This README and the library's own tests, with everything those tests need. |

The split exists because only `web/` is deployed, and the zip package allows documents in one named place only. **Lifting the library out means copying those two folders**, keeping their places (`web/_core/maillib/` and `tools/maillib/`): from such a copy the whole test below passes. Nothing under `web/_core/maillib/` reaches for anything of the portal: settings, the clock, a log and the password are all handed in.

**How that is checked.** `tools/maillib/tests/stands-alone.php` reads every file of the library as PHP's own tokens and works from a list of what the library MAY use: each function it calls, each class it names and each bare name must be accounted for, and nothing may be reached by a name worked out while the program runs (no call through a variable, no `new $class`, no `$$name`). It proves itself first on 44 planted files. **What it cannot see:** what a value is (it allows reading a file, so it cannot judge which file), and what an object handed in by the application does. It trusts its two lists; the functions that must never go on them are named in the check and it fails if one does. The portal runs the same check through `tools/audit-checks/check_maillib_stands_alone.py`, which only starts it.

A `composer.json` can replace `autoload.php` later without moving a file: the folder is already laid out one class per file, with the path matching the name (PSR-4).

## The rules it keeps

- **Always encrypted.** `starttls` or `tls` only, TLS 1.2 or later. There is no unencrypted choice in any mode. A server that offers no encryption is told nothing: no sign-in, no address, no message.
- **The certificate is checked in full unless the caller asks otherwise for that one send.** There are three ways to send (`SendOptions`): checked (the default), "this certificate only", and, since 13 October 2026, "certificate not checked". The last two are for an application whose administrator has decided to send past a failed check; the library never chooses them itself.
- **When the certificate check fails, nothing is sent, and the result says why**: expired, not valid yet, issued for another name, signed by itself, a key too short, an old signature method, not marked for a server; or, when the certificate itself shows none of those, "most often not traceable to a trusted authority".
- **The certificate is blamed only when it was the certificate.** That means: PHP said it refused the certificate, or the library's own name rule did. A handshake that was cut off, answered with an alert, or too slow is `try-later`, with no certificate offered for approval. An application may hold all mail on a certificate fault, so a passing fault on the line must not look like one.
- **The server name is checked twice**, by PHP and by the library's own stricter rule (PHP 8.5 accepts names that PHP 8.4 refuses). Only the certificate's list of names counts; the old "common name" counts only when there is no list at all; a star may stand only as the whole first label (`*.example.org`) and covers one label; an address matches only an address.
- **A narrow way through, for a person to use**: `SendOptions::onlyThisCertificate($fingerprint)` sends ONE message accepting exactly the certificate with that SHA-256 fingerprint. It cannot mean "accept anything": anything but a whole fingerprint is refused, and it still requires encryption (TLS 1.2 or later). It skips the name, the dates and the key strength by design.
- **A wide way through, and what it costs**: `SendOptions::certificateNotChecked()` does not judge the certificate at all. **With it the library cannot tell the mail server from an impostor: somebody on the network path can read the mailbox's user name and password and every message sent.** It is still not a connection with the check switched off. On every new connection the certificate is first READ on a connection that can write nothing once encryption is on, and the sending connection then accepts exactly the certificate that was read, the same way as "this certificate only". So encryption, TLS 1.2 or later and "no sign-in before encryption" all still hold, and there is still one mode only in which the library writes without the full check. If the server shows one certificate when read and another a moment later, nothing is sent (`try-later`).
- **"Would the certificate pass now?"**: `CertificateReader::wouldPass()` makes one fully checked connection as far as the handshake, says nothing private, and answers yes, no, or "cannot tell" (null: the server could not be reached, or the handshake failed in a way that is not the certificate's doing). An application that has stopped checking asks this now and then to find out when it can start again. Treat null as "nothing has changed".
- **Every wait is on a clock**: connecting, the encryption handshake, each reply, each write, the verdict after the message, and the message as a whole.
- **A line break smuggled into a subject, a name, an address or a file name refuses the message.** It is not cleaned and sent.
- **The password is never printed, logged, put in an error text, or left in the transcript.**
- **A message goes whole or not at all.** A plain-text part and an HTML part, always. An attachment is never left out quietly. If the server refuses one recipient of several, nobody is sent to.
- **Refuse when unsure.** A reply the client does not expect is a failure carrying the server's own words, never a guessed success. A server that speaks when it should be listening is out of step: the connection is closed, and whatever it said is never taken as "accepted".

## Using it

The server name below is an example only.

```php
require __DIR__ . '/web/_core/maillib/autoload.php';

use MWBM\Mail\{Client, Message, MessageRefused, Secret, SendResult, SystemClock};
use MWBM\Mail\Smtp\{SmtpConfig, SmtpTransport};

$clock  = new SystemClock();
$config = new SmtpConfig('mail.example.org', 587, SmtpConfig::STARTTLS, 'mailbox-user', new Secret($password));
$client = new Client(new SmtpTransport($config, $clock));

try {
    $message = new Message(
        $clock->now(), 'office@example.org', 'The Office',
        ['reader@example.org' => 'A Reader'],
        'The subject', "The plain-text version.\n", '<p>The HTML version.</p>',
    );
} catch (MessageRefused $refusal) {
    // $refusal->field names what was wrong; the text never repeats the bad value.
}

$result = $client->send($message);   // never throws
$client->close();
```

`$config->missing()` lists what is not filled in, in plain sentences. An attachment is `['name' => ..., 'content' => ..., 'type' => ...]`, or `Message::fileAttachment($path)`, which refuses a file it cannot read.

## What a send returns

`SendResult::$outcome` is always one of four:

| Outcome | Meaning | What the caller should do |
| --- | --- | --- |
| `accepted` | The mail server took the message. Not the same as delivered. | Nothing. |
| `try-later` | Nothing was sent; trying again may work (unreachable, too slow, the server said "later"). | Keep it and retry. |
| `refused` | Nothing was sent; the same attempt will fail again (a reply beginning 5, or too big). | Look at `$stage` first: see below. |
| `needs-person` | The certificate failed (`$certificate` holds its facts; it is empty in one case, when the refused certificate could not be read a second time), or something failed after the whole message was handed over, so nobody can tell whether it went. | Hold it for a person. Do not resend automatically. |

Beside it: `$stage` (where the conversation ended), `$reason`, `$code` and `$serverWords` (the server's reply), `$transcript` (the conversation step by step, sign-in lines replaced, for a "send a test" page), and `$recipientReplies`.

**A refused sign-in is `refused` at stage `AUTH`.** That is about the mailbox password, not the message. `isAboutTheMessage()` tells the two apart, so a caller does not throw messages away because a password expired.

`Client` adds two rules: a second route is tried only when the first could not be reached (never after a refusal), and after two connection failures a route is left alone for the rest of the run, so a dead server cannot stall a batch.

## Time limits (seconds; all can be set on `SmtpConfig`)

| Connect | Handshake | Each reply | A write making no progress | The verdict after the message | One message, up to handing it over |
| --- | --- | --- | --- | --- | --- |
| 8 | 8 | 10 | 10 | 20 | 30 |

**The longest one send can take is about 50 seconds with these defaults, not 30.** The 30 covers everything up to the moment the whole message has been handed to the server. After that, one of three things can add to it, and never two of them:

| What follows | Adds | Longest send |
| --- | --- | --- |
| Waiting for the server's verdict on the message | 20 | 50 |
| A refused certificate, read on a second connection to say why (connect + handshake) | 16 | 46 |
| Clearing the connection after a refused recipient or sender | 10 | 40 |

The verdict has its own full limit on purpose: cutting it short would turn a slow "accepted" into "nobody knows". With a second route in a `Client`, a send can take twice as long. A page a person is waiting on should set smaller limits.

## Memory

Building a message and writing it to a server takes several copies of it. **Allow about five times the size of the content** (the attachments and both versions of the text) on top of the content itself and of whatever the application already uses: one 10 MB attachment was measured at 53 MB in all. A message there is no room for is refused in words (`MessageRefused`, or `refused` / `too-big` from a send) before the file is read or the message is built, so it does not end in PHP's "allowed memory size exhausted", which nothing can catch. The check cannot be made on a PHP with no memory limit or one that hides it. Build, send and let go of one message at a time: every built message keeps its bytes.

## Running the tests

```bash
php tools/maillib/tests/run.php
```

It needs PHP with OpenSSL, and Python 3 for one independent check (reading each received message back with Python's own e-mail parser; without Python that one proof is reported as not run, and the run fails saying so). It needs no database and loads no file of the portal. It starts stand-in mail servers on 127.0.0.1, makes its own certificates for the run (no key is committed), runs the library with `proc_open`, `popen`, `exec`, `shell_exec`, `system`, `passthru`, `mail` and `fsockopen` switched off, and removes everything afterwards. If the test is killed part way, its stand-in servers notice within about a second, delete the test's private keys and scratch folder, and stop. A good run ends with `the run reached its end: N of N proofs passed` and exit code 0. Nothing real is contacted.

## What it cannot do

- **It cannot make a receiving system trust the message.** That is decided by the sender's DNS records (SPF, DKIM, DMARC), set where the domain is managed.
- It cannot rule out a second copy if a server accepts a message and its "accepted" reply is lost. The message keeps one identifier across retries, which lets many mail systems drop the duplicate.
- It signs in with PLAIN or LOGIN only. A server needing no sign-in, or another method, cannot be used.
- The wait to look up a server's name (DNS) is PHP's own and is not on the library's clock.
- A very large message over a slow line may not fit the 30-second limit; raise `messageSeconds` for routes that carry big attachments.
- It reads one certificate, not the chain behind it. So the three weaknesses it can name (short key, old signature, not for a server) are what the certificate shows, and "not traceable to a trusted authority" is what is left over when it shows nothing: a certificate with a short key AND an unknown issuer is reported for its key alone.
- If a future PHP words its refusal of a certificate in a way the library does not know, a refused certificate is reported as `try-later` / `encryption-failed` until the list in `Connection.php` is brought up to date. Nothing is sent either way, and the library's test fails on such a PHP.
- One message to several people shows each of them the others' addresses (they share the To line). To avoid that, send one message per person.
- Addresses with non-English letters before the "@" are refused. A non-English domain (after the "@") is converted to its ASCII form, which needs PHP's `intl` extension; on a PHP without it, such an address is refused, not guessed.
- No real mail server has been tried. The tests use stand-ins on 127.0.0.1.

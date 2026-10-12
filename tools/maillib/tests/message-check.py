#!/usr/bin/env python3
"""
An independent reading of every message the stand-in mail servers received.

WHY THIS IS IN PYTHON. The mail library is PHP. If its own code were used to
check what it wrote, a mistake made on the way out would be made again on the
way in and the two would agree. So the bytes each stand-in server saved are
read here by Python's own e-mail parser, which shares no code with the
library, and compared with what the test meant to send.

It is started by `run.php`, which passes the scratch folder of one test run:

    python3 tools/maillib/tests/message-check.py <scratch folder>

For each folder under `<scratch>/expect/`, `run.php` has written plain files
saying what the message should hold (`subject.txt`, `text.bin`,
`attachment-0.bin` and so on) and `wire-file.txt`, naming the file in which a
stand-in server kept the message exactly as it arrived.

WHAT IS CHECKED, for every message:
  1. On the wire: it ends with the "end of message" line; every line ends
     CR LF with no stray CR or LF; and every line that begins with a full stop
     begins with TWO (the doubling that stops content ending a message early).
  2. After removing that doubling the way a mail server does: every byte is
     plain ASCII, no header line is longer than 78 characters and no body line
     longer than 76.
  3. Every "encoded word" in the headers (how a name or subject with
     non-English letters is written) is decoded BY ITSELF and must be whole
     characters, and no longer than 75. See the note below on why.
  4. Read back: the subject, names and addresses come back exactly, in whatever
     alphabet; the date is the moment sent, in UTC; there is a plain-text part
     AND an HTML part, in that order, each byte for byte; every attachment is
     there, whole, under its right name and type.
A folder marked `raw` (the full-stop test) gets check 1 and a byte-for-byte
comparison only.

WHY CHECK 3 EXISTS. Python's parser is forgiving: when a letter of several
bytes is cut in half across two encoded words, it joins the halves back
together and shows the right letter. Many mail programs do not; they decode
each word separately, as the standard says they may, and show two broken
characters. So "the name came back right through Python" does NOT show the
words were cut properly. This was found by planting that very fault: the check
below is what now fails on it. (The first version of this file had no check 3
and passed a library that cut letters in half.)

WHY NAMES ARE NOT READ THROUGH THE PARSER when they are written as encoded
words. The standard says the white space between two encoded words that stand
side by side is not part of the text (RFC 2047 section 6.2). Python 3.14 reads
a long name that way. Python 3.13.5 does not: it puts a space into the middle
of the name, where the two words met. Measured on the same 307 bytes, which
PHP 8.4 and PHP 8.5 both write identically. So a name comparison made through
the parser passed on one machine and failed on another, with nothing wrong in
the message. Such names are therefore read here from the header itself: each
word decoded BY ITSELF, then joined. That is the standard's own rule, it gives
the same answer on every Python, and it is stricter than the parser, not
looser: a letter cut across two words comes out as two broken characters and
the comparison fails. Names written plainly or in quote marks, and every
address, are still read through the parser, where the versions agree.

WHAT THIS CANNOT SHOW: how a particular mail program displays the message, or
whether a receiving system would trust it.

Exit code 0 when every check passed and at least one message was read; 1
otherwise. The last line says how many messages and checks there were, so a
run that stopped early cannot be mistaken for a pass.
"""

from __future__ import annotations

import base64
import email
import email.policy
import email.utils
import re
import sys
from pathlib import Path

checks = 0
failed = 0


def check(ok: bool, where: str, what: str) -> None:
    """Count one check and print it only when it fails."""
    global checks, failed
    checks += 1
    if not ok:
        failed += 1
        print(f"FAIL  {where}: {what}")


def unstuff(wire: bytes, where: str) -> bytes:
    """Check the wire form (check 1 above) and return the message as a server would store it."""
    check(wire.endswith(b"\r\n.\r\n") or wire == b".\r\n", where, "the message does not end with the end-of-message line")
    body = wire[:-3]  # drop the final ".\r\n"; what is left ends with the CR LF before it
    # Every LF must follow a CR and every CR must be followed by an LF.
    check(body.count(b"\n") == body.count(b"\r\n") and body.count(b"\r") == body.count(b"\r\n"), where,
          "there is a bare CR or a bare LF on the wire")
    lines = body.split(b"\r\n")
    stored = []
    for line in lines:
        if line.startswith(b"."):
            # A single leading full stop means the doubling was not done.
            check(line.startswith(b".."), where, f"a line begins with one full stop, not two: {line[:40]!r}")
            line = line[1:]
        stored.append(line)
    return b"\r\n".join(stored)


def read(folder: Path, name: str) -> bytes:
    return (folder / name).read_bytes()


# One piece of an address header: a quoted string, an encoded word, an address in angle brackets,
# the comma between two mailboxes, or a plain word. A comma inside quote marks stays inside its
# quoted string, so it is never mistaken for the comma between two people.
PIECE = re.compile(rb'"(?:[^"\\]|\\.)*"|=\?UTF-8\?B\?[A-Za-z0-9+/=]*\?=|<[^>]*>|,|[^\s,<>"]+')
ENCODED_WORD = re.compile(rb"=\?UTF-8\?B\?([A-Za-z0-9+/=]*)\?=")


def encoded_names(head: bytes, label: bytes) -> list:
    """
    For each mailbox of one address header (From, To), in order: its display name if that name was
    written as encoded words, or None if it was written plainly, in quote marks, or not at all.

    A name is each of its words decoded BY ITSELF and the results joined, with nothing between
    them. A word that is not whole characters comes out with replacement marks, so it cannot
    equal the name that was sent. A mailbox that mixes encoded words with other name text is a
    shape the library never writes; it gets a name no real name can equal.
    """
    value = None
    for line in head.split(b"\r\n"):
        if value is None:
            if line.lower().startswith(label.lower() + b":"):
                value = line[len(label) + 1:]
        elif line[:1] in (b" ", b"\t"):
            value += line  # a folded line belongs to the header above it
        else:
            break
    names = []
    words, other, anything = [], False, False
    for piece in PIECE.findall(value or b"") + [b","]:
        if piece != b",":
            anything = True
            found = ENCODED_WORD.fullmatch(piece)
            if found:
                try:
                    words.append(base64.b64decode(found.group(1), validate=True).decode("utf-8", errors="replace"))
                except ValueError:
                    words.append("\ufffd")
            elif not piece.startswith(b"<"):
                other = True
            continue
        if anything:
            names.append(None if not words else ("\x00mixed" if other else "".join(words)))
        words, other, anything = [], False, False
    return names


def check_message(folder: Path, scratch: Path) -> None:
    where = folder.name
    wire = (scratch / read(folder, "wire-file.txt").decode().strip()).read_bytes()
    message_bytes = unstuff(wire, where)

    if read(folder, "kind.txt").decode().strip() == "raw":
        check(message_bytes == read(folder, "payload.bin"), where,
              "after removing the doubled full stops, the bytes are not the bytes that were sent")
        return

    # Check 2: plain ASCII, and line lengths.
    check(all(byte < 128 for byte in message_bytes), where, "the message is not plain ASCII")
    head, _, body = message_bytes.partition(b"\r\n\r\n")
    for line in head.split(b"\r\n"):
        check(len(line) <= 78, where, f"a header line is {len(line)} characters: {line[:50]!r}")
    in_part_headers = False
    for line in body.split(b"\r\n"):
        # The few header lines at the top of each part may be up to 78; content lines up to 76.
        if line.startswith(b"--"):
            in_part_headers = True
        elif line == b"":
            in_part_headers = False
        check(len(line) <= (78 if in_part_headers else 76), where, f"a body line is {len(line)} characters")

    # Check 3: every encoded word, decoded by itself.
    for word, payload in re.findall(rb"(=\?UTF-8\?B\?([A-Za-z0-9+/=]*)\?=)", head):
        check(len(word) <= 75, where, f"an encoded word is {len(word)} characters long (the limit is 75)")
        try:
            base64.b64decode(payload, validate=True).decode("utf-8")
        except (ValueError, UnicodeDecodeError):
            check(False, where, f"an encoded word does not hold whole characters by itself: {word[:40]!r}")
        else:
            check(True, where, "")

    # Check 4: what comes back. Addresses, and names written plainly, through Python's parser;
    # names written as encoded words from the header itself (see the note at the top for why).
    message = email.message_from_bytes(message_bytes, policy=email.policy.default)
    check(not message.defects, where, f"the parser reports defects: {message.defects}")

    check(str(message["Subject"]) == read(folder, "subject.txt").decode(), where,
          f"the subject came back as {str(message['Subject'])!r}")

    def compare_mailboxes(label: str, expected: list) -> None:
        parsed = [(a.addr_spec, a.display_name) for a in message[label].addresses]
        on_the_wire = encoded_names(head, label.encode())
        check([address for address, _ in parsed] == [address for address, _ in expected], where,
              f"the {label} addresses came back as {[address for address, _ in parsed]!r}")
        check(len(on_the_wire) == len(expected), where,
              f"the {label} line holds {len(on_the_wire)} mailboxes, not {len(expected)}")
        for (address, name), (_, parsed_name), wire_name in zip(expected, parsed, on_the_wire):
            got = parsed_name if wire_name is None else wire_name
            check(got == name, where, f"the {label} name of {address} came back as {got!r}, not {name!r}")

    compare_mailboxes("From", [(read(folder, "from-address.txt").decode(), read(folder, "from-name.txt").decode())])
    compare_mailboxes("To", [tuple(line.split("\t")) for line in read(folder, "to.txt").decode().splitlines()])

    reply_to = read(folder, "reply-to.txt").decode()
    if reply_to == "":
        check(message["Reply-To"] is None, where, "there is a Reply-To line though none was asked for")
    else:
        check(message["Reply-To"] is not None and [a.addr_spec for a in message["Reply-To"].addresses] == [reply_to],
              where, "the Reply-To address is wrong or missing")

    check(str(message["Message-ID"]) == read(folder, "message-id.txt").decode(), where, "the Message-ID is not the one the library reported")
    check(str(message["MIME-Version"]) == "1.0", where, "MIME-Version is not 1.0")
    for forbidden in ("X-Mailer", "User-Agent", "Bcc"):
        check(message[forbidden] is None, where, f"there is a {forbidden} line")

    sent_at = int(read(folder, "date.txt").decode())
    date = email.utils.parsedate_to_datetime(str(message["Date"]))
    check(int(date.timestamp()) == sent_at and date.utcoffset() is not None and date.utcoffset().total_seconds() == 0,
          where, f"the Date line is {message['Date']!r}, not the moment sent in UTC")

    # The parts: plain text first, then HTML, then the attachments.
    count = int(read(folder, "attachments.txt").decode())
    if count == 0:
        check(message.get_content_type() == "multipart/alternative", where, f"the message is {message.get_content_type()}")
        alternatives = message
        attachments = []
    else:
        check(message.get_content_type() == "multipart/mixed", where, f"the message is {message.get_content_type()}")
        parts = list(message.iter_parts())
        alternatives = parts[0] if parts else message
        attachments = parts[1:]
        check(alternatives.get_content_type() == "multipart/alternative", where, "the first part is not the two versions of the content")

    versions = list(alternatives.iter_parts())
    check([part.get_content_type() for part in versions] == ["text/plain", "text/html"], where,
          f"the versions are {[part.get_content_type() for part in versions]!r}, not plain text then HTML")
    for part, file_name in zip(versions, ("text.bin", "html.bin")):
        check(part.get_content_charset() == "utf-8", where, f"{file_name}: the part does not say it is UTF-8")
        check(part.get_payload(decode=True) == read(folder, file_name), where, f"{file_name}: the content is not byte for byte what was sent")

    check(len(attachments) == count, where, f"{len(attachments)} attachments arrived, {count} were sent")
    for number, part in enumerate(attachments):
        if not (folder / f"attachment-{number}.bin").exists():
            break
        check(part.get_content_disposition() == "attachment", where, f"attachment {number} is not marked as an attachment")
        check(part.get_filename() == read(folder, f"attachment-{number}.name").decode(), where,
              f"attachment {number} is named {part.get_filename()!r}")
        check(part.get_content_type() == read(folder, f"attachment-{number}.type").decode(), where,
              f"attachment {number} is of type {part.get_content_type()}")
        check(part.get_payload(decode=True) == read(folder, f"attachment-{number}.bin"), where,
              f"attachment {number} is not byte for byte what was sent")


def main() -> int:
    if len(sys.argv) != 2 or not (Path(sys.argv[1]) / "expect").is_dir():
        print("usage: message-check.py <scratch folder made by run.php>")
        return 2
    scratch = Path(sys.argv[1])
    folders = sorted(path for path in (scratch / "expect").iterdir() if path.is_dir())
    for folder in folders:
        before = failed
        try:
            check_message(folder, scratch)
        except Exception as fault:  # a message this check cannot even read is a failure, not a crash
            check(False, folder.name, f"could not be read at all: {fault!r}")
        print(f"{'PASS' if failed == before else 'FAIL'}  message {folder.name}")
    print(f"the message check reached its end: {len(folders)} messages, {checks} checks, {failed} failed")
    return 0 if failed == 0 and folders else 1


if __name__ == "__main__":
    sys.exit(main())

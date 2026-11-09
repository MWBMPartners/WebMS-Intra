#!/usr/bin/env python3
# Path: tools/expense-receipts-commit-proxy.py
"""
A MySQL connection that is lost AT the commit, for one test (#649).

Used ONLY by `tools/expense-receipts-selftest.php`, section K. It sits between
the portal and the test's MySQL server and passes everything through, except
when an "arm" file exists. Then, the next time a client sends COMMIT, it does
one of three things, chosen by what the arm file says:

  forward         send COMMIT on to the server, wait, then cut the connection
                  BEFORE the server's reply reaches the portal. The server has
                  committed; the portal does not know it. (The case round 2 of
                  the independent check measured: the receipt of a SAVED claim
                  was deleted and the claimant told to submit it again.)
  forward-refuse  the same, and it then creates the "refuse" file: while that
                  exists every NEW connection is cut at once, so the portal
                  cannot even ask what happened. The test removes it.
  swallow         never send COMMIT on; cut both sides. The server throws the
                  unfinished claim away.

The arm file is removed when it fires, so it acts on one COMMIT only.

Adapted from the proxy the independent checker wrote for round 2. It reads
MySQL's packet framing (a three-byte length, a sequence number, then the
payload) and looks only at command packets whose first byte is 0x03 — a plain
text query — because that is how mysqli sends COMMIT.

Usage: expense-receipts-commit-proxy.py LISTEN_PORT UPSTREAM_PORT ARM_FILE REFUSE_FILE LOG_FILE

@package   Portal\\Tools
@author    MWBM Partners Ltd (t/a MWservices)
@copyright 2026-present MWBM Partners Ltd (t/a MWservices)
@license   All Rights Reserved
@version   1.0.0
"""

import asyncio
import os
import sys

LISTEN = int(sys.argv[1])
UPSTREAM = int(sys.argv[2])
ARM = sys.argv[3]
REFUSE = sys.argv[4]
LOG = open(sys.argv[5], 'a', buffering=1)


def arm_mode():
    """What the arm file asks for, or '' when it is not there."""
    try:
        with open(ARM, 'r') as handle:
            return handle.read().strip() or 'forward'
    except OSError:
        return ''


async def client_to_server(reader, writer, close_both, state):
    buffered = b''
    try:
        while True:
            data = await reader.read(65536)
            if not data:
                break
            buffered += data
            while len(buffered) >= 4:
                length = int.from_bytes(buffered[:3], 'little')
                if len(buffered) < 4 + length:
                    break
                packet, buffered = buffered[:4 + length], buffered[4 + length:]
                payload = packet[4:]
                is_commit = payload[:1] == b'\x03' and payload[1:].strip().upper().startswith(b'COMMIT')
                mode = arm_mode() if is_commit else ''
                if mode == '':
                    writer.write(packet)
                    await writer.drain()
                    continue
                try:
                    os.unlink(ARM)
                except OSError:
                    pass
                state['dead'] = True
                if mode == 'swallow':
                    LOG.write('armed COMMIT seen; NOT forwarded; dropping\n')
                    close_both()
                    return
                if mode == 'forward-refuse':
                    # Created HERE, at the commit, and not by the test before
                    # the request: the portal's own connection opens at the
                    # start of the request and must not be refused.
                    open(REFUSE, 'w').close()
                writer.write(packet)
                await writer.drain()
                LOG.write('armed COMMIT seen; forwarded; dropping before the reply (%s)\n' % mode)
                await asyncio.sleep(0.5)
                close_both()
                return
    except Exception as error:  # noqa: BLE001 - a test tool reports and carries on
        LOG.write('client side error %r\n' % error)
    close_both()


async def server_to_client(reader, writer, close_both, state):
    try:
        while True:
            data = await reader.read(65536)
            if not data or state['dead']:
                break
            writer.write(data)
            await writer.drain()
    except Exception:  # noqa: BLE001
        pass
    close_both()


async def handle(client_reader, client_writer):
    if os.path.exists(REFUSE):
        LOG.write('new connection refused (refuse file present)\n')
        client_writer.close()
        return
    server_reader, server_writer = await asyncio.open_connection('127.0.0.1', UPSTREAM)
    state = {'dead': False}

    def close_both():
        state['dead'] = True
        for side in (client_writer, server_writer):
            try:
                side.close()
            except Exception:  # noqa: BLE001
                pass

    await asyncio.gather(
        client_to_server(client_reader, server_writer, close_both, state),
        server_to_client(server_reader, client_writer, close_both, state),
    )


async def main():
    server = await asyncio.start_server(handle, '127.0.0.1', LISTEN)
    LOG.write('listening on %d\n' % LISTEN)
    async with server:
        await server.serve_forever()


asyncio.run(main())

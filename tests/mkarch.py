# Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Test archives for the upload check. Usage: mkarch.py DIR"""
import io, os, sys, zipfile

d = sys.argv[1]
os.makedirs(d, exist_ok=True)
DIZ = 'TESTTOOL v1.0\r\nEin kleines Werkzeug\r\nfür Tests \x84\r\n'.encode('cp437', 'replace')


def zbytes(entries, method=zipfile.ZIP_DEFLATED):
    b = io.BytesIO()
    with zipfile.ZipFile(b, 'w', method) as z:
        for name, data in entries:
            z.writestr(name, data)
    return b.getvalue()


def w(name, data):
    open(os.path.join(d, name), 'wb').write(data)


valid = zbytes([('FILE_ID.DIZ', DIZ), ('README.TXT', b'Hallo Welt\r\n' * 50), ('docs/info.txt', b'Info\r\n')])
w('valid.zip', valid)
w('valid2.zip', zbytes([('FILE_ID.DIZ', DIZ), ('PROG.EXE', os.urandom(3000))]))
w('valid3.zip', zbytes([('FILE_ID.DIZ', DIZ), ('DATA.BIN', os.urandom(2000))]))
w('valid4.zip', zbytes([('FILE_ID.DIZ', DIZ), ('MORE.BIN', os.urandom(2500))]))
w('withad.zip', zbytes([('FILE_ID.DIZ', DIZ), ('dieoelka.txt', b'alte Werbung einer anderen Box\r\n'), ('GAME.EXE', os.urandom(1500))]))
# stored entry with one flipped byte in the data: the CRC no longer matches
stored = bytearray(zbytes([('FILE_ID.DIZ', DIZ), ('DATA.TXT', b'A' * 4000)], zipfile.ZIP_STORED))
pos = stored.find(b'A' * 100)
stored[pos + 50] = ord('B')
w('badcrc.zip', bytes(stored))
w('truncated.zip', valid[:len(valid) - 60])
w('many.zip', zbytes([('f%03d.txt' % i, b'x') for i in range(30)]))
w('big.zip', zbytes([('zeros.bin', b'\0' * (3 * 1024 * 1024))]))
RAR = b'Rar!\x1a\x07\x01\x00' + bytes([0x33, 0x92, 0xb5, 0xe5, 0x0a, 0x01, 0x05, 0x06, 0x00, 0x05, 0x01, 0x01, 0x80, 0x80, 0x00]) + os.urandom(64)
w('rarinside.zip', RAR)
w('good.rar', RAR)
w('fake.rar', valid)
w('text.arj', b'Das ist kein ARJ-Archiv\r\n')
w('good.arj', b'\x60\xea' + os.urandom(100))
w('good.lzh', b'\x1f\x00-lh5-' + os.urandom(100))
w('good.7z', b'7z\xbc\xaf\x27\x1c' + os.urandom(100))
w('good.tar.gz', b'\x1f\x8b\x08\x00' + os.urandom(100))
w('bad.tar.gz', b'PK\x03\x04' + os.urandom(100))
w('notes.txt', b'Einfacher Text, wird nicht geprueft.\r\n')
print('archives in', d)

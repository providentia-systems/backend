"""Loopback-only SMTP sink for the real notification delivery smoke test.

Retains only the numeric code for the explicitly selected synthetic recipient.
No message body or code is printed; the private temporary file is removed by
its caller. Production code never imports this fixture.
"""

import email
import os
import pathlib
import re
import socketserver
import sys

root = pathlib.Path(sys.argv[1])
recipient = sys.argv[2]


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        self.connection.settimeout(10)
        self.wfile.write(b"220 localhost test SMTP\r\n")
        accepted = False
        while True:
            line = self.rfile.readline(8192)
            if not line:
                return
            command = line.decode("ascii", errors="replace").strip()
            if command.upper().startswith("EHLO"):
                self.wfile.write(b"250 localhost\r\n")
            elif command.upper().startswith("MAIL FROM:"):
                accepted = False
                self.wfile.write(b"250 Sender accepted\r\n")
            elif command.upper().startswith("RCPT TO:"):
                accepted = command.partition(":")[2].strip(" <>").lower() == recipient
                self.wfile.write(b"250 Recipient accepted\r\n")
            elif command.upper() == "DATA":
                self.wfile.write(b"354 End with dot\r\n")
                body = bytearray()
                while True:
                    line = self.rfile.readline(8192)
                    if line == b".\r\n":
                        break
                    if not line or len(body) + len(line) > 1_048_576:
                        return
                    body.extend(line[1:] if line.startswith(b"..") else line)
                message = email.message_from_bytes(bytes(body))
                if accepted and message["Subject"] == "Your Providentia verification code":
                    text = message.get_payload(decode=True).decode("utf-8")
                    codes = re.findall(r"(?m)^([0-9]{8})\r?$", text)
                    if len(codes) == 1 and "http://" not in text and "https://" not in text:
                        temporary = root / "code.pending"
                        temporary.write_text(codes[0], encoding="ascii")
                        os.chmod(temporary, 0o600)
                        temporary.replace(root / "code")
                self.wfile.write(b"250 Message accepted\r\n")
            elif command.upper() == "QUIT":
                self.wfile.write(b"221 Bye\r\n")
                return
            elif command.upper() in {"NOOP", "RSET"}:
                self.wfile.write(b"250 OK\r\n")
            else:
                self.wfile.write(b"500 Unsupported command\r\n")


with socketserver.TCPServer(("127.0.0.1", 0), Handler) as server:
    (root / "port").write_text(str(server.server_address[1]), encoding="ascii")
    server.serve_forever()

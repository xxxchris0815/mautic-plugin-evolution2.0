#!/usr/bin/env python3
"""Minimal Evolution API v2 mock for Mautic plugin smoke tests."""

from __future__ import annotations

import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


API_KEY = "test-evolution-key"


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:  # quieter logs
        print("[%s] %s" % (self.log_date_time_string(), fmt % args))

    def _unauthorized(self) -> None:
        self.send_response(401)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"error":"Unauthorized"}')

    def _json(self, status: int, payload) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _read_json(self):
        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0:
            return {}
        raw = self.rfile.read(length)
        try:
            return json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError:
            return {"raw": raw.decode("utf-8", errors="replace")}

    def _check_auth(self) -> bool:
        key = self.headers.get("apikey") or self.headers.get("Apikey") or ""
        if key != API_KEY:
            self._unauthorized()
            return False
        return True

    def do_GET(self) -> None:  # noqa: N802
        if self.path in ("/", "/health"):
            self._json(200, {"status": "ok", "service": "mock-evolution-v2"})
            return
        if not self._check_auth():
            return

        if self.path.rstrip("/") == "/instance/fetchInstances":
            self._json(
                200,
                [
                    {
                        "name": "cloud-instance",
                        "connectionStatus": "open",
                        "integration": "WHATSAPP-BUSINESS",
                    },
                    {
                        "name": "baileys-instance",
                        "connectionStatus": "open",
                        "integration": "WHATSAPP-BAILEYS",
                    },
                ],
            )
            return

        if self.path.startswith("/template/find/"):
            instance = self.path.split("/template/find/", 1)[1].strip("/")
            if instance != "cloud-instance":
                self._json(400, {"error": "Templates only for cloud instance in mock"})
                return
            self._json(
                200,
                [
                    {
                        "name": "welcome",
                        "language": "en",
                        "status": "APPROVED",
                        "category": "MARKETING",
                        "components": [
                            {
                                "type": "BODY",
                                "text": "Hello {{1}}, welcome to {{2}}!",
                                "example": {"body_text": [["Chris", "Acme"]]},
                            }
                        ],
                    },
                    {
                        "name": "order_update",
                        "language": "de",
                        "status": "APPROVED",
                        "category": "UTILITY",
                        "components": [
                            {
                                "type": "BODY",
                                "text": "Bestellung {{1}} ist {{2}}.",
                            }
                        ],
                    },
                ],
            )
            return

        self._json(404, {"error": "not found", "path": self.path})

    def do_POST(self) -> None:  # noqa: N802
        if not self._check_auth():
            return
        payload = self._read_json()

        if self.path.startswith("/message/sendText/"):
            self._json(
                201,
                {
                    "key": {"id": "mock-text-1"},
                    "status": "PENDING",
                    "message": {"conversation": payload.get("text")},
                    "echo": payload,
                },
            )
            return

        if self.path.startswith("/message/sendTemplate/"):
            instance = self.path.split("/message/sendTemplate/", 1)[1].strip("/")
            if instance != "cloud-instance":
                self._json(400, {"error": "Baileys cannot send templates"})
                return
            self._json(
                201,
                {
                    "key": {"id": "mock-template-1"},
                    "status": "PENDING",
                    "echo": payload,
                },
            )
            return

        if self.path.startswith("/message/sendMedia/"):
            self._json(201, {"key": {"id": "mock-media-1"}, "echo": payload})
            return

        if self.path.startswith("/chat/whatsappNumbers/"):
            numbers = payload.get("numbers") or []
            self._json(
                200,
                [{"number": n, "exists": True, "jid": f"{n}@s.whatsapp.net"} for n in numbers],
            )
            return

        if self.path.startswith("/webhook/set/"):
            self._json(200, {"webhook": {"enabled": True, "echo": payload}})
            return

        self._json(404, {"error": "not found", "path": self.path, "echo": payload})


def main() -> None:
    server = ThreadingHTTPServer(("0.0.0.0", 8081), Handler)
    print("Mock Evolution API v2 listening on :8081")
    server.serve_forever()


if __name__ == "__main__":
    main()

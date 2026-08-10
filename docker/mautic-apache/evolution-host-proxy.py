#!/usr/bin/env python3
"""Host-side reverse proxy so Dockerized Mautic can reach Evolution API.

Containers in this VM have no outbound HTTPS, but the host does.
Point Mautic API URL to http://host.docker.internal:8082
"""

from __future__ import annotations

import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

UPSTREAM = os.environ.get("EVOLUTION_UPSTREAM", "https://example.invalid").rstrip("/")
LISTEN_PORT = int(os.environ.get("EVOLUTION_PROXY_PORT", "8082"))
HOP_BY_HOP = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailers",
    "transfer-encoding",
    "upgrade",
    "host",
    "content-length",
}


class ProxyHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args) -> None:
        print("[%s] %s" % (self.log_date_time_string(), fmt % args))

    def do_GET(self) -> None:  # noqa: N802
        self._proxy()

    def do_POST(self) -> None:  # noqa: N802
        self._proxy()

    def do_PUT(self) -> None:  # noqa: N802
        self._proxy()

    def do_DELETE(self) -> None:  # noqa: N802
        self._proxy()

    def do_OPTIONS(self) -> None:  # noqa: N802
        self._proxy()

    def _proxy(self) -> None:
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else None
        url = UPSTREAM.rstrip("/") + self.path
        req = urllib.request.Request(url, data=body, method=self.command)
        for key, value in self.headers.items():
            if key.lower() in HOP_BY_HOP:
                continue
            req.add_header(key, value)

        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                payload = resp.read()
                self.send_response(resp.status)
                for key, value in resp.headers.items():
                    if key.lower() in HOP_BY_HOP:
                        continue
                    self.send_header(key, value)
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                if self.command != "HEAD":
                    self.wfile.write(payload)
        except urllib.error.HTTPError as e:
            payload = e.read()
            self.send_response(e.code)
            for key, value in e.headers.items():
                if key.lower() in HOP_BY_HOP:
                    continue
                self.send_header(key, value)
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        except Exception as e:  # noqa: BLE001
            payload = ('{"error":"proxy_failed","detail":"%s"}' % str(e).replace('"', "'")).encode()
            self.send_response(502)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)


def main() -> None:
    server = ThreadingHTTPServer(("0.0.0.0", LISTEN_PORT), ProxyHandler)
    print(f"Evolution host proxy :{LISTEN_PORT} -> {UPSTREAM}")
    server.serve_forever()


if __name__ == "__main__":
    main()

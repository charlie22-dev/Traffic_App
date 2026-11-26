"""Check the HTTP client against a real, local HTTP server."""

import json
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from healthcheck import check_health


class Handler(BaseHTTPRequestHandler):
    payload = {"status": "ok", "service": "CommuteLive API"}

    def do_GET(self):
        self.send_response(200 if self.path == "/api/health" else 404)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(self.payload).encode())

    def log_message(self, *_args):
        pass


class HealthcheckTest(unittest.TestCase):
    def test_health_contract_over_http(self):
        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            url = f"http://127.0.0.1:{server.server_port}/"
            self.assertEqual(check_health(url), Handler.payload)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_unexpected_response_is_rejected(self):
        class WrongHandler(Handler):
            payload = {"status": "error"}

        server = ThreadingHTTPServer(("127.0.0.1", 0), WrongHandler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with self.assertRaisesRegex(ValueError, "Unexpected health"):
                check_health(f"http://127.0.0.1:{server.server_port}")
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

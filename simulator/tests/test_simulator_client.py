import json
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from commutelive_sim.client import Api, ApiError, DriverClient
from sim_fixtures import parameters


class ClientTest(unittest.TestCase):
    def test_transient_http_failure_retries_the_identical_payload(self):
        bodies = []
        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                bodies.append(self.rfile.read(int(self.headers["Content-Length"])))
                self.send_response(503 if len(bodies) == 1 else 200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(b'{"ok":true}')
            def log_message(self, *_args):
                pass
        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            api = Api(f"http://127.0.0.1:{server.server_port}", parameters(), "test-token-not-real", sleep=lambda _: None)
            self.assertTrue(api.request("/driver/pings", "POST", {"trip_id": 1, "pings": [{"client_ping_uuid": "fixed-test-uuid"}]})["ok"])
            self.assertEqual(bodies[0], bodies[1])
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_lost_trip_start_response_is_recovered_before_cleanup(self):
        class FakeApi:
            started = False
            ended = False
            def request(self, path, method="GET", body=None, **kwargs):
                if path == "/driver/vehicles":
                    return {"data": [{"id": 1, "route_id": 1, "is_simulated": True}]}
                if path == "/driver/trips/active":
                    return {"data": {"id": 7, "vehicle_id": 1, "is_simulated": True} if self.started else None}
                if path == "/driver/trips/start":
                    self.started = True
                    raise ApiError("lost response")
                if path == "/driver/trips/7/end":
                    self.ended = True
                    return {}
                raise AssertionError(path)
        api = FakeApi()
        client = DriverClient(api, {"vehicle_id": 1, "route_id": 1})
        client.start("test-notice")
        self.assertEqual(client.trip_id, 7)
        client.close()
        self.assertTrue(api.ended)

    def test_an_existing_trip_is_not_taken_over_or_ended(self):
        class FakeApi:
            def request(self, path, **kwargs):
                if path == "/driver/vehicles":
                    return {"data": [{"id": 1, "route_id": 1, "is_simulated": True}]}
                if path == "/driver/trips/active":
                    return {"data": {"id": 8}}
                raise AssertionError("Must not touch the existing trip")
        client = DriverClient(FakeApi(), {"vehicle_id": 1, "route_id": 1})
        with self.assertRaises(ApiError):
            client.start("notice")
        client.close()

#!/usr/bin/env python3
"""Simulador mínimo de Wompi El Salvador para pruebas de humo (NO es para producción).

  python3 tests/mock_wompi.py 9090

Emula:  POST /connect/token   (client_credentials)  -> access_token
        POST /EnlacePago      (Bearer)              -> idEnlace + urlEnlace
Y expone utilidades de inspección para los scripts de humo:
        GET /_last/<campo>    último valor recibido: identificador | monto | urlwebhook | urlredirect
        GET /_stats/tokens    nº de tokens emitidos (comprueba que el token se cachea)
"""
import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs

TOKEN = "mock-token-abc123"
lock = threading.Lock()
state = {"tokens": 0, "links": 0, "last": {}}


class Handler(BaseHTTPRequestHandler):
    def _send(self, status, body, ctype="application/json"):
        data = body if isinstance(body, bytes) else (body if isinstance(body, str) else json.dumps(body)).encode()
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _body(self):
        return self.rfile.read(int(self.headers.get("Content-Length", 0) or 0)).decode()

    def do_POST(self):
        body = self._body()
        if self.path == "/connect/token":
            form = {k: v[0] for k, v in parse_qs(body).items()}
            ok = (form.get("grant_type") == "client_credentials" and form.get("audience") == "wompi_api"
                  and form.get("client_id") and form.get("client_secret")
                  and "x-www-form-urlencoded" in self.headers.get("Content-Type", ""))
            if not ok:
                return self._send(400, {"error": "invalid_request"})
            with lock:
                state["tokens"] += 1
            return self._send(200, {"access_token": TOKEN, "expires_in": 3600, "token_type": "Bearer", "scope": "wompi_api"})

        if self.path == "/EnlacePago":
            if self.headers.get("Authorization") != f"Bearer {TOKEN}":
                return self._send(401, {"mensaje": "no autorizado"})
            try:
                p = json.loads(body)
                monto = p["monto"]
                assert isinstance(monto, (int, float)) and monto >= 0.01
                ident = p["identificadorEnlaceComercio"]
                assert ident and p["configuracion"]["urlWebhook"] and p["configuracion"]["urlRedirect"]
            except Exception:
                return self._send(422, {"mensaje": "payload inválido"})
            with lock:
                state["links"] += 1
                state["last"] = {"identificador": ident, "monto": monto,
                                 "urlwebhook": p["configuracion"]["urlWebhook"], "urlredirect": p["configuracion"]["urlRedirect"]}
                n = state["links"]
            return self._send(200, {"idEnlace": n, "urlQrCodeEnlace": "https://lk.wompi.sv/qr", "urlEnlace": f"https://lk.wompi.sv/mock{n}", "estaProductivo": False})
        self._send(404, {"mensaje": "no existe"})

    def do_GET(self):
        if self.path == "/_stats/tokens":
            return self._send(200, str(state["tokens"]), "text/plain")
        if self.path.startswith("/_last/"):
            v = state["last"].get(self.path.split("/")[-1])
            return self._send(200 if v is not None else 404, "" if v is None else str(v), "text/plain")
        self._send(200, "wompi-mock", "text/plain")

    def log_message(self, *a):  # silencio
        pass


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 9090
    ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()

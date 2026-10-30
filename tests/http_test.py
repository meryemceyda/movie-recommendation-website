"""Exercise real sessions/POSTs using a temporary database and PHP's dev server."""
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, query="", data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            response = self.opener.open(self.base + "/index.php" + query, body, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode(), response.headers

    def token(self, query="?page=login"):
        status, html, _ = self.request(query)
        assert status == 200, (status, html)
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html)[1]


def run(base):
    alice, bob, guest = Client(base), Client(base), Client(base)
    status, html, _ = guest.request("?all=1")
    assert status == 200 and "63 film" in html
    assert html.count('movie-poster-card') == 63, 'Every film needs a poster element'
    assert 'posters.js' in html and 'assets/tmdb-logo.svg' in html
    assert 'This product uses the TMDB API but is not endorsed or certified by TMDB.' in html
    assert 'movie-poster-detail' in guest.request('?id=1')[1]
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    images = urllib.request.build_opener(NoRedirect())
    for movie_id in range(1, 64):
        try:
            images.open(base + f'/poster.php?id={movie_id}', timeout=10)
            raise AssertionError('Poster endpoint must redirect')
        except urllib.error.HTTPError as response:
            assert response.code == 302 and response.headers['Location'] == 'assets/poster-placeholder.svg'
            assert 'ncfilm_session' not in response.headers.get('Set-Cookie', '')
    assert guest.opener.open(base + '/assets/poster-placeholder.svg').headers['Content-Type'].startswith('image/svg+xml')
    assert "6 film" in guest.request()[1]
    assert guest.request("?id=99999")[0] == 404
    assert guest.request("?id[]=1")[0] == 404
    assert guest.request("?page=lists")[1].find('id="password"') >= 0
    assert guest.request(data={"action": "register", "email": "forged@example.com"})[0] == 403

    for client, email in [(alice, "alice@example.com"), (bob, "bob@example.com")]:
        old_token = client.token()
        status, html, headers = client.request(data={
            "action": "register", "csrf": old_token, "name": "<script>alert(1)</script>",
            "email": email, "password": "secure-password-123",
        })
        assert status == 200 and "Hesabınız oluşturuldu" in html
        assert "&lt;script&gt;alert(1)&lt;/script&gt;" in html
        assert "<script>alert(1)</script>" not in html
        assert headers["Content-Security-Policy"]
        assert client.request(data={"action": "logout", "csrf": old_token})[0] == 403
        cookie = next(iter(client.cookies))
        assert cookie.has_nonstandard_attr("HttpOnly") and cookie.get_nonstandard_attr("SameSite") == "Lax"

    token = alice.token("?id=1")
    status, html, _ = alice.request(data={
        "action": "save", "csrf": token, "movie_id": 1, "list": "watchlist", "operation": "add",
        "user_id": 999,  # Client-supplied owners must be ignored.
    })
    assert status == 200 and "Film listeniz güncellendi" in html
    assert "1 film" in alice.request("?page=lists")[1]
    assert "0 film" in bob.request("?page=lists")[1]
    assert "Matrix" not in bob.request("?page=lists")[1]
    # Posting with Bob's session cannot remove Alice's entry.
    bob.request(data={"action": "save", "csrf": bob.token("?id=1"), "movie_id": 1,
                      "list": "watchlist", "operation": "remove", "user_id": 1})
    assert "1 film" in alice.request("?page=lists")[1]
    assert alice.request(data={"action": "save", "csrf": token, "movie_id": 99999,
                               "list": "watchlist", "operation": "add"})[0] == 400
    assert alice.request(data={"action": "save", "csrf": token, "movie_id": 1,
                               "list": "unknown", "operation": "add"})[0] == 400
    alice.request(data={"action": "logout", "csrf": token})
    assert 'id="password"' in alice.request("?page=lists")[1]
    status, html, _ = alice.request(data={"action": "login", "csrf": alice.token(),
        "email": "alice@example.com", "password": "secure-password-123"})
    assert status == 200 and "1 film" in html
    # The configured public root must not expose credentials or SQLite files.
    for path in ["/var/app.sqlite", "/app/schema.sql", "/../var/app.sqlite"]:
        try:
            response = guest.opener.open(base + path, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        assert response.status == 404, path
    print("HTTP integration tests passed.")


if __name__ == "__main__":
    with tempfile.TemporaryDirectory() as temp:
        environment = dict(os.environ, APP_DB_PATH=str(Path(temp) / "test.sqlite"))
        environment.pop('TMDB_READ_ACCESS_TOKEN', None)
        environment.pop('TMDB_API_KEY', None)
        subprocess.run(["php", "bin/init-db.php"], cwd=ROOT, env=environment, check=True)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        with open(Path(temp) / "server.log", "w+") as log:
            server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "movie-recommendation-website"], cwd=ROOT, env=environment, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                            break
                    except OSError:
                        if server.poll() is not None:
                            raise RuntimeError("PHP server exited")
                        time.sleep(0.05)
                run(f"http://127.0.0.1:{port}")
            except Exception:
                log.seek(0)
                print(log.read())
                raise
            finally:
                server.terminate()
                server.wait(timeout=10)

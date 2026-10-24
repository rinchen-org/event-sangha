"""Isolated HTTP authentication tests. No Composer, SQLite extension, or app data needed."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class AuthHTTPTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which('php'):
            raise RuntimeError('Install a separate PHP CLI runtime before running auth tests.')
        cls.temp = tempfile.TemporaryDirectory(prefix='rinchen-auth-test-')
        cls.work = Path(cls.temp.name)
        cls.web = cls.work / 'web'
        cls.web.mkdir()
        cls.sessions = cls.work / 'sessions'
        cls.sessions.mkdir(mode=0o700)
        cls.throttle = cls.work / 'throttle'
        cls.throttle.mkdir(mode=0o700)
        # Copy code only, never real configuration, databases, or participant QR files.
        for base in ('', 'event-retiro'):
            dest = cls.web / base
            for source in (ROOT / 'src').rglob('*'):
                relative = source.relative_to(ROOT / 'src')
                if source.is_file() and (source.suffix == '.php' or relative.as_posix() in ('.htaccess', 'static/auth.css', 'static/test-lab.css', 'static/qr-checkin.js', 'static/auth-navigation.js', 'templates/test-email.html', 'templates/test-lab-email.html')):
                    target = dest / relative
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(source, target)
            (dest / '.env').write_text('HOST_ADDRESS=http://localhost\nSEND_EMAIL=0\n')
            (dest / 'db.sqlite').write_bytes(b'PRIVATE DATABASE SENTINEL')
            (dest / 'static/qr').mkdir(parents=True, exist_ok=True)
            (dest / 'static/qr' / ('a' * 64 + '.jpg')).write_bytes(b'PRIVATE QR SENTINEL')
            (dest / 'static/app').mkdir(parents=True, exist_ok=True)
            (dest / 'static/app/index.html').write_text('PRIVATE HTML SENTINEL')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{port}'
        cls.log = (cls.work / 'server.log').open('w+')
        command = [
            'php', '-d', f'session.save_path={cls.sessions}', '-d', 'display_errors=0',
            '-S', f'127.0.0.1:{port}', '-t', str(cls.web), str(ROOT / 'scripts/dev-router.php'),
        ]
        if getattr(cls, 'fake_mail', False):
            cls.mail_capture = cls.work / 'mail.txt'
            cls.mail_failure = cls.work / 'mail-failure'
            transport = cls.work / 'fake-sendmail'
            transport.write_text(f'#!/bin/sh\ncat > "{cls.mail_capture}"\n[ ! -f "{cls.mail_failure}" ]\n')
            transport.chmod(0o700)
            command[1:1] = ['-d', f'sendmail_path={transport}']
        if os.environ.get('RINCHEN_TEST_SERVER') == 'apache':
            if getattr(cls, 'fake_mail', False):
                raise RuntimeError('Mail fake harness supports the PHP built-in server only.')
            modules = Path('/usr/lib/apache2/modules')
            php_modules = sorted(modules.glob('libphp*.so'))
            if not shutil.which('apache2') or not php_modules:
                raise RuntimeError('Apache with mod_php is required for Apache integration tests.')
            config = cls.work / 'apache.conf'
            config.write_text(f"""ServerRoot "{cls.work}"
Listen 127.0.0.1:{port}
ServerName localhost
PidFile "{cls.work}/apache.pid"
ErrorLog "{cls.work}/apache-errors.log"
LoadModule mpm_prefork_module {modules}/mod_mpm_prefork.so
LoadModule authz_core_module {modules}/mod_authz_core.so
LoadModule dir_module {modules}/mod_dir.so
LoadModule mime_module {modules}/mod_mime.so
LoadModule rewrite_module {modules}/mod_rewrite.so
LoadModule headers_module {modules}/mod_headers.so
LoadModule php_module {php_modules[-1]}
TypesConfig /etc/mime.types
DocumentRoot "{cls.web}"
DirectoryIndex index.php
<Directory "{cls.web}">
  Require all granted
  AllowOverride All
  Options FollowSymLinks
</Directory>
<FilesMatch "\\.php$">
  SetHandler application/x-httpd-php
</FilesMatch>
php_value session.save_path "{cls.sessions}"
php_flag display_errors Off
""")
            command = ['apache2', '-f', str(config), '-DFOREGROUND']
        cls.process = subprocess.Popen(command, stdout=cls.log, stderr=cls.log, start_new_session=True, env={
            **os.environ, 'RINCHEN_AUTH_STATE_DIR': str(cls.throttle), 'RINCHEN_HTTPS': '0',
        })
        for _ in range(100):
            if cls.process.poll() is not None:
                cls.log.seek(0)
                raise RuntimeError(cls.log.read())
            try:
                urllib.request.urlopen(cls.origin + '/templates/login.php', timeout=1).close()
                break
            except (urllib.error.URLError, TimeoutError):
                time.sleep(.05)
        else:
            raise RuntimeError('PHP HTTP test server did not start.')

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.log.close()
        cls.temp.cleanup()

    def setUp(self):
        for file in self.throttle.glob('*.json'):
            file.unlink()
        self.jar = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, path, data=None, method=None, headers=None):
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(self.origin + path, data=payload, method=method, headers=headers or {})
        try:
            response = self.client.open(req, timeout=10)
        except urllib.error.HTTPError as response:
            with response:
                return response.code, response.headers, response.read().decode(errors='replace')
        with response:
            return response.status, response.headers, response.read().decode(errors='replace')

    def login(self, base='', password='LoveAndPeace2026', next_page='templates/index.php'):
        status, headers, body = self.request(base + '/templates/login.php?next=' + urllib.parse.quote(next_page))
        self.assertEqual(status, 200)
        csrf = re.search(r'name="csrf_token" value="([a-f0-9]+)"', body)[1]
        return self.request(base + '/templates/login.php', {
            'username': 'admin', 'password': password, 'csrf_token': csrf, 'next': next_page,
        })

    def csrf(self, base=''):
        status, _, body = self.request(base + '/templates/session.php')
        self.assertEqual(status, 200)
        return json.loads(body)['csrfToken']

    def test_all_existing_php_entrypoints_require_login_before_work(self):
        for source in (ROOT / 'src').rglob('*.php'):
            if not source.is_file():
                continue
            relative = source.relative_to(ROOT / 'src').as_posix()
            if relative in ('templates/login.php',):
                continue
            with self.subTest(route=relative):
                before = (self.web / 'db.sqlite').read_bytes()
                status, headers, body = self.request('/' + relative)
                if relative.startswith(('lib/', 'migrations/')):
                    self.assertIn(status, (403, 404))
                elif relative.startswith('api/') or relative == 'templates/session.php':
                    self.assertEqual(status, 401)
                    self.assertFalse(json.loads(body)['success'])
                else:
                    self.assertEqual(status, 303)
                    self.assertTrue(headers['Location'].startswith('/templates/login.php'))
                self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)

    def test_json_and_posts_do_not_redirect_or_mutate_before_auth(self):
        for route in ('event', 'event-session', 'subscription', 'attendance'):
            status, _, body = self.request(f'/templates/{route}/list.php?format=json')
            self.assertEqual(status, 401)
            self.assertFalse(json.loads(body)['success'])
        status, headers, _ = self.request('/templates/subscription/activation.php', {'id': 1, 'active': 1})
        self.assertEqual(status, 303)
        self.assertIn('templates%2Findex.php', headers['Location'])

    def test_login_csrf_invalid_credentials_and_shared_throttle(self):
        status, _, _ = self.request('/templates/login.php', {'username': 'admin', 'password': 'LoveAndPeace2026'})
        self.assertEqual(status, 403)
        for _ in range(5):
            self.assertEqual(self.login(password='incorrect')[0], 401)
        self.jar.clear()  # A fresh cookie cannot bypass the per-IP rate limit.
        self.assertEqual(self.login()[0], 429)

    def test_login_regenerates_cookie_and_logout_revokes_it(self):
        self.request('/templates/login.php')
        old_id = next(iter(self.jar)).value
        status, headers, _ = self.login()
        self.assertEqual(status, 303)
        self.assertEqual(headers['Location'], '/templates/index.php')
        cookie = next(iter(self.jar))
        self.assertNotEqual(cookie.value, old_id)
        self.assertTrue(cookie.has_nonstandard_attr('HttpOnly'))
        self.assertEqual(cookie.get_nonstandard_attr('SameSite'), 'Lax')
        sid = cookie.value
        token = self.csrf()
        self.assertEqual(self.request('/templates/logout.php')[0], 405)
        self.assertEqual(self.request('/templates/logout.php', {'csrf_token': 'wrong'})[0], 403)
        self.assertEqual(self.request('/templates/logout.php', {'csrf_token': token})[0], 303)
        self.assertEqual(self.request('/templates/session.php')[0], 401)
        self.jar.clear()
        self.assertEqual(self.request('/templates/session.php', headers={'Cookie': f'rinchen_session={sid}'})[0], 401)

    def test_post_actions_reject_missing_tokens_before_database_access(self):
        self.login()
        for route in ('event/form.php', 'event-session/form.php', 'subscription/activation.php',
                      'subscription/resend_email.php', 'subscription/log_attendance.php',
                      'subscription/log_attendance_force.php', 'subscription/upload.php',
                      'subscription/form.php', 'attendance/form.php', 'attendance/log.php'):
            self.assertEqual(self.request('/templates/' + route, {'id': 1})[0], 403, route)
        self.assertEqual(self.request('/generate_qr.php', {})[0], 403)

    def test_safe_return_paths_and_subdirectory_cookie_scope(self):
        destination = 'attendance_log.php/?fullname=Ana%20Flores&email=test%2Btag%40example.com&phone=%2B591123'
        status, headers, _ = self.request('/event-retiro/' + destination)
        self.assertEqual(status, 303)
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(headers['Location']).query)['next'][0], destination)
        status, headers, _ = self.login('/event-retiro', next_page=destination)
        self.assertEqual(headers['Location'], '/event-retiro/' + destination)
        self.assertEqual(next(c for c in self.jar if c.name == 'rinchen_session').path, '/event-retiro/')
        self.assertEqual(self.request('/templates/session.php')[0], 401)
        # Malicious destinations must never become Location targets.
        for next_page in ('https://example.com', '//example.com', '../.env', '%2F%2Fevil.test', 'templates/login.php', 'templates/index.php%0d%0aX:bad'):
            self.jar.clear()
            self.assertEqual(self.login(next_page=next_page)[1]['Location'], '/templates/index.php')

    def test_qr_reads_require_auth_and_attendance_get_only_confirms(self):
        qr = '/static/qr/' + 'a' * 64 + '.jpg'
        self.assertEqual(self.request(qr)[0], 303)
        self.login()
        self.assertEqual(self.request(qr)[2], 'PRIVATE QR SENTINEL')
        self.assertEqual(self.request('/media.php?file=../db.sqlite')[0], 404)
        status, _, body = self.request('/templates/attendance/log.php?fullname=Ana&email=test%40example.com&phone=000')
        self.assertEqual(status, 503)  # Synthetic sentinel is deliberately not a database.
        self.assertIn('No se pudo verificar o guardar', body)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), b'PRIVATE DATABASE SENTINEL')

    def test_private_files_are_never_served_even_after_login(self):
        paths = ('/.env', '/db.sqlite', '/db.sqlite-wal', '/lib/auth.php', '/migrations/all.php', '/static/app/index.html')
        for authenticated in (False, True):
            if authenticated:
                self.login()
            for path in paths:
                self.assertIn(self.request(path)[0], (403, 404), path)

    def test_idle_and_absolute_expiration(self):
        for key, age in (('auth_seen', 1801), ('auth_created', 43201)):
            self.jar.clear()
            self.login()
            sid = next(c for c in self.jar if c.name == 'rinchen_session').value
            file = self.sessions / ('sess_' + sid)
            state = file.read_text()
            file.write_text(re.sub(rf'{key}\|i:\d+;', f'{key}|i:{int(time.time()) - age};', state))
            self.assertEqual(self.request('/templates/session.php')[0], 401)

    def test_https_cookie_policy(self):
        code = "require 'src/lib/auth.php'; auth_start(); echo json_encode(session_get_cookie_params());"
        result = subprocess.run(['php', '-d', f'session.save_path={self.sessions}', '-r', code],
                                cwd=ROOT, env={**os.environ, 'RINCHEN_HTTPS': '1'},
                                text=True, capture_output=True, check=True)
        policy = json.loads(result.stdout)
        self.assertTrue(policy['secure'])
        self.assertTrue(policy['httponly'])
        self.assertEqual(policy['samesite'], 'Lax')

    def test_authenticated_menu_has_logout_and_no_session_header_warnings(self):
        self.login()
        status, headers, body = self.request('/templates/index.php?legacy=1')
        self.assertEqual(status, 200)
        self.assertIn('Cerrar sesión', body)
        self.assertIn('no-store', headers['Cache-Control'])
        self.log.flush()
        log = (self.work / 'server.log').read_text()
        self.assertNotIn('headers already sent', log)
        self.assertNotIn('session is already active', log)


if __name__ == '__main__':
    unittest.main(verbosity=2)

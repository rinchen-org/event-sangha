"""Isolated session-storage and HTTP auth tests; no Composer, SQLite, or app data."""
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


def write_frontend_fixture(web):
    """Synthetic build in a disposable web root, never copy generated app assets."""
    build = web / 'static/app'
    (build / '.vite').mkdir(parents=True, exist_ok=True)
    (build / 'assets').mkdir(exist_ok=True)
    (build / 'assets/test-entry.js').write_text('// SYNTHETIC FRONTEND FIXTURE\n')
    (build / 'assets/test-entry.css').write_text('/* SYNTHETIC FRONTEND FIXTURE */\n')
    (build / '.vite/manifest.json').write_text(json.dumps({
        'index.html': {'file': 'assets/test-entry.js', 'css': ['assets/test-entry.css']},
    }))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class AuthSessionTest(unittest.TestCase):
    """Real PHP file sessions in disposable copies, without sockets or real cookies."""

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='rinchen-session-test-')
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        self.web = self.work / 'web'
        for relative in ('lib/auth.php', 'templates/logout.php', 'templates/index.php', 'templates/menu.php'):
            target = self.web / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / 'src' / relative, target)
        self.state = self.work / 'auth-state'
        self.shared = self.work / 'shared-php-sessions'
        self.shared.mkdir()

    def request(self, *, state=None, https='0', **payload):
        code = r'''
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$web = getenv('AUTH_TEST_WEB');
$_SERVER['DOCUMENT_ROOT'] = $web;
$_SERVER['SCRIPT_FILENAME'] = $web . '/' . ($input['route'] ?? 'templates/logout.php');
$_SERVER['SCRIPT_NAME'] = ($input['base'] ?? '') . '/' . ($input['route'] ?? 'templates/logout.php');
$_SERVER['REQUEST_METHOD'] = $input['method'] ?? (isset($input['route']) ? 'GET' : 'POST');
$_SERVER['HTTP_ACCEPT'] = $input['accept'] ?? 'application/json';
$_GET = $input['get'] ?? [];
$_POST = $input['post'] ?? [];
ob_start();
register_shutdown_function(static function (): void {
    $body = ob_get_clean();
    $sid = session_id();
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body, 'sid' => $sid], JSON_THROW_ON_ERROR);
});
if (isset($input['sid'])) { session_id($input['sid']); }
require $web . '/lib/auth.php';
if (($input['action'] ?? '') === 'logout') { require $web . '/templates/logout.php'; }
auth_start();
if (isset($input['seed'])) {
    $_SESSION = ['auth_user' => AUTH_ADMIN_USER, 'auth_created' => time() - $input['seed']['created'],
        'auth_seen' => time() - $input['seed']['seen']];
    auth_csrf_token();
}
if (isset($input['route'])) { require $web . '/' . $input['route']; exit; }
if (($input['action'] ?? '') === 'collect') { session_gc(); }
$loggedIn = auth_logged_in();
echo json_encode(['logged_in' => $loggedIn, 'session' => $_SESSION, 'cookie' => session_get_cookie_params(),
    'save_path' => session_save_path(), 'handler' => ini_get('session.save_handler'),
    'gc_lifetime' => (int) ini_get('session.gc_maxlifetime'), 'gc_probability' => (int) ini_get('session.gc_probability'),
    'strict' => (int) ini_get('session.use_strict_mode'), 'only_cookies' => (int) ini_get('session.use_only_cookies')], JSON_THROW_ON_ERROR);
'''
        result = subprocess.run([
            'php', '-d', 'display_errors=0', '-d', f'sys_temp_dir={self.work}',
            '-d', f'session.save_path={self.shared}', '-d', 'session.gc_maxlifetime=1440',
            '-d', 'session.gc_probability=0', '-r', code,
        ], input=json.dumps(payload), text=True, capture_output=True, check=True, env={
            **os.environ, 'AUTH_TEST_WEB': str(self.web), 'RINCHEN_HTTPS': https,
            'RINCHEN_AUTH_STATE_DIR': str(self.state if state is None else state),
        })
        response = json.loads(result.stdout)
        try:
            response['body'] = json.loads(response['body']) if response['body'] else None
        except json.JSONDecodeError:
            pass  # HTML entrypoint responses are intentionally not JSON.
        return response

    def test_frontend_and_old_menu_routes_keep_authentication_and_csrf(self):
        for base in ('', '/event-retiro'):
            for route, query in (('templates/index.php', {}), ('templates/index.php', {'legacy': '1'}),
                                 ('templates/menu.php', {})):
                with self.subTest(base=base, route=route, query=query):
                    self.assertEqual(self.request(base=base, route=route, get=query)['status'], 401)
                    self.assertEqual(self.request(base=base, route=route, get=query, method='POST',
                                                  seed={'created': 0, 'seen': 0})['status'], 403)

    def test_old_menu_routes_only_redirect_even_without_a_build(self):
        for base in ('', '/event-retiro'):
            for route, query in (('templates/menu.php', {}), ('templates/index.php', {'legacy': '1'})):
                response = self.request(base=base, route=route, get=query, seed={'created': 0, 'seen': 0})
                self.assertEqual(response['status'], 303)
                self.assertIsNone(response['body'])
        # The HTTP suite checks the actual Location headers, unavailable in CLI.

    def test_frontend_build_errors_are_explicit_and_never_show_a_legacy_menu(self):
        for base in ('', '/event-retiro'):
            write_frontend_fixture(self.web)
            manifest = self.web / 'static/app/.vite/manifest.json'
            for content in (None, '{broken json', 'null', '[]', '{"index.html":false}',
                            '{"index.html":{"file":[]}}', '{"index.html":{"file":"missing.js"}}',
                            '{"index.html":{"file":"assets/test-entry.js","css":"invalid"}}',
                            '{"index.html":{"file":"assets/test-entry.js","css":[[]]}}',
                            '{"index.html":{"file":"assets/test-entry.js","css":["missing.css"]}}',
                            '{"index.html":{"file":"../../templates/index.php"}}'):
                with self.subTest(base=base, manifest=content):
                    if content is None:
                        manifest.unlink()
                    else:
                        manifest.write_text(content)
                    response = self.request(base=base, route='templates/index.php', accept='text/html',
                                            seed={'created': 0, 'seen': 0})
                    self.assertEqual(response['status'], 503)
                    self.assertIn('makim frontend.build', response['body'])
                    self.assertIn('.vite/manifest.json', response['body'])
                    self.assertNotIn('<div id="root">', response['body'])
                    self.assertNotIn('Menu Suscripciones', response['body'])
                    self.assertNotIn('legacy=1', response['body'])
                    self.assertNotIn(str(self.work), response['body'])
        self.assertFalse((self.web / 'db.sqlite').exists())
        self.assertFalse((self.web / '.env').exists())

    def test_frontend_build_renders_react_and_javascript_guidance(self):
        write_frontend_fixture(self.web)
        for base in ('', '/event-retiro'):
            response = self.request(base=base, route='templates/index.php', accept='text/html',
                                    seed={'created': 0, 'seen': 0})
            self.assertEqual(response['status'], 200)
            self.assertIn('<div id="root"></div>', response['body'])
            self.assertIn('../static/app/assets/test-entry.js', response['body'])
            self.assertIn('../static/app/assets/test-entry.css', response['body'])
            self.assertIn('<noscript>Activa JavaScript', response['body'])
            self.assertNotIn('legacy=1', response['body'])
            self.assertNotIn('menú clásico', response['body'])
        self.assertFalse((self.web / 'db.sqlite').exists())
        self.assertFalse((self.web / '.env').exists())

    def test_private_storage_and_browser_session_cookie_policy(self):
        for base in ('', '/event-retiro'):
            for https in ('0', '1'):
                with self.subTest(base=base, https=https):
                    response = self.request(base=base, https=https)
                    self.assertEqual(response['status'], 200)
                    config = response['body']
                    directory = self.state / 'sessions'
                    self.assertEqual(config['save_path'], str(directory))
                    self.assertEqual(config['handler'], 'files')
                    self.assertEqual(config['gc_lifetime'], 86400)
                    self.assertGreater(config['gc_probability'], 0)
                    self.assertEqual(config['strict'], 1)
                    self.assertEqual(config['only_cookies'], 1)
                    self.assertEqual(config['cookie']['lifetime'], 0)
                    self.assertEqual(config['cookie']['path'], base + '/')
                    self.assertEqual(config['cookie']['secure'], https == '1')
                    self.assertTrue(config['cookie']['httponly'])
                    self.assertEqual(config['cookie']['samesite'], 'Lax')
                    self.assertEqual(self.state.stat().st_mode & 0o777, 0o700)
                    self.assertEqual(directory.stat().st_mode & 0o777, 0o700)
                    self.assertEqual((directory / ('sess_' + response['sid'])).stat().st_mode & 0o777, 0o600)
        self.assertEqual(list(self.shared.iterdir()), [])

    def test_default_state_directory_is_application_specific_and_private(self):
        response = self.request(state='')
        directory = Path(response['body']['save_path'])
        self.assertEqual(directory.parent.parent, self.work)
        self.assertTrue(directory.parent.name.startswith('rinchen-auth-'))
        self.assertEqual(directory.name, 'sessions')
        self.assertEqual(directory.stat().st_mode & 0o777, 0o700)

    def test_idle_limit_is_eight_hours_and_absolute_limit_is_twenty_four(self):
        for created, seen, expected in (
            (3600, 3600, True), (28740, 28740, True), (28800, 28800, False),
            (28801, 28801, False), (50000, 60, True), (86340, 60, True),
            (86400, 0, False), (86401, 0, False),
        ):
            with self.subTest(created=created, seen=seen):
                response = self.request(seed={'created': created, 'seen': seen})
                self.assertEqual(response['body']['logged_in'], expected)
                if not expected:
                    self.assertEqual(response['body']['session'], [])

    def test_activity_refreshes_idle_time_but_never_login_time_or_csrf(self):
        before = int(time.time())
        first = self.request(seed={'created': 80000, 'seen': 28000})
        first_state = first['body']['session']
        self.assertGreaterEqual(first_state['auth_seen'], before)
        second = self.request(sid=first['sid'])
        self.assertTrue(second['body']['logged_in'])
        self.assertEqual(second['body']['session']['auth_created'], first_state['auth_created'])
        self.assertEqual(second['body']['session']['csrf_token'], first_state['csrf_token'])

    def test_garbage_collection_keeps_long_sessions_and_removes_old_files(self):
        response = self.request(seed={'created': 7200, 'seen': 7200})
        directory = Path(response['body']['save_path'])
        current = directory / ('sess_' + response['sid'])
        expired = directory / 'sess_expired'
        expired.write_bytes(current.read_bytes())
        now = time.time()
        os.utime(current, (now - 7200, now - 7200))
        os.utime(expired, (now - 86520, now - 86520))
        sentinel = self.shared / 'sess_shared'
        sentinel.write_text('UNRELATED APPLICATION SESSION')
        response = self.request(action='collect')
        self.assertEqual(response['status'], 200)
        self.assertTrue(current.exists())
        self.assertFalse(expired.exists())
        self.assertTrue(self.request(sid=current.name[5:])['body']['logged_in'])
        self.assertEqual(sentinel.read_text(), 'UNRELATED APPLICATION SESSION')

    def test_unsafe_or_unavailable_state_storage_fails_closed(self):
        public = self.web / 'auth-state'
        insecure = self.work / 'insecure'
        insecure.mkdir(mode=0o700)
        insecure.chmod(0o755)
        not_directory = self.work / 'file'
        not_directory.write_text('NOT A DIRECTORY')
        target = self.work / 'symlink-target'
        target.mkdir(mode=0o700)
        link = self.work / 'link'
        link.symlink_to(target, target_is_directory=True)
        for directory in (public, insecure, not_directory, link):
            with self.subTest(directory=directory.name):
                response = self.request(state=directory)
                self.assertEqual(response['status'], 503)
                self.assertFalse(response['body']['success'])
                self.assertNotIn(str(self.work), response['body']['message'])
        self.assertEqual(list(self.shared.iterdir()), [])
        self.assertEqual(list(self.web.rglob('sess_*')), [])

    def test_session_subdirectory_cannot_be_a_symlink_or_world_readable(self):
        self.state.mkdir(mode=0o700)
        directory = self.state / 'sessions'
        directory.symlink_to(self.shared, target_is_directory=True)
        self.assertEqual(self.request()['status'], 503)
        directory.unlink()
        directory.mkdir(mode=0o700)
        directory.chmod(0o755)
        self.assertEqual(self.request()['status'], 503)

    def test_unknown_session_id_is_not_adopted(self):
        response = self.request(sid='unknown-session-id')
        self.assertNotEqual(response['sid'], 'unknown-session-id')
        self.assertFalse(response['body']['logged_in'])

    def test_logout_still_requires_csrf_and_removes_the_session(self):
        for base in ('', '/event-retiro'):
            response = self.request(base=base, seed={'created': 50000, 'seen': 3600})
            sid = response['sid']
            csrf = response['body']['session']['csrf_token']
            self.assertEqual(self.request(base=base, sid=sid, action='logout')['status'], 403)
            self.assertTrue(self.request(base=base, sid=sid)['body']['logged_in'])
            self.assertEqual(self.request(base=base, sid=sid, action='logout', post={'csrf_token': csrf})['status'], 303)
            self.assertFalse((self.state / 'sessions' / ('sess_' + sid)).exists())
            self.assertFalse(self.request(base=base, sid=sid)['body']['logged_in'])


class AuthHTTPTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which('php'):
            raise RuntimeError('Install a separate PHP CLI runtime before running auth tests.')
        cls.temp = tempfile.TemporaryDirectory(prefix='rinchen-auth-test-')
        cls.work = Path(cls.temp.name)
        cls.web = cls.work / 'web'
        cls.web.mkdir()
        cls.throttle = cls.work / 'throttle'
        cls.throttle.mkdir(mode=0o700)
        cls.sessions = cls.throttle / 'sessions'
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
            write_frontend_fixture(dest)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{port}'
        cls.log = (cls.work / 'server.log').open('w+')
        command = [
            'php', '-d', 'display_errors=0',
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

    def test_automatic_checkin_script_is_public_but_qr_directory_stays_private(self):
        for base in ('', '/event-retiro'):
            with self.subTest(base=base):
                status, _, body = self.request(base + '/static/qr-checkin.js?version=regression')
                self.assertEqual(status, 200)
                self.assertIn('form.requestSubmit', body)
                for suffix in ('/static/qr', '/static/qr/', '/static/qr/unknown.jpg'):
                    self.assertIn(self.request(base + suffix)[0], (403, 404))
                status, _, body = self.request(base + '/static/qr/' + 'a' * 64 + '.jpg')
                self.assertEqual(status, 303)
                self.assertNotIn('PRIVATE QR SENTINEL', body)

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
        for base in ('', '/event-retiro'):
            for key, age, expected in (
                ('auth_seen', 3600, 200), ('auth_seen', 28740, 200), ('auth_seen', 28801, 401),
                ('auth_created', 50000, 200), ('auth_created', 86340, 200), ('auth_created', 86401, 401),
            ):
                with self.subTest(base=base, key=key, age=age):
                    self.jar.clear()
                    self.login(base)
                    sid = next(c for c in self.jar if c.name == 'rinchen_session').value
                    file = self.sessions / ('sess_' + sid)
                    state = file.read_text()
                    file.write_text(re.sub(rf'{key}\|i:\d+;', f'{key}|i:{int(time.time()) - age};', state))
                    self.assertEqual(self.request(base + '/templates/session.php')[0], expected)

    def test_https_cookie_policy(self):
        code = "require 'src/lib/auth.php'; auth_start(); echo json_encode(session_get_cookie_params());"
        result = subprocess.run(['php', '-r', code],
                                cwd=ROOT, env={**os.environ, 'RINCHEN_HTTPS': '1',
                                               'RINCHEN_AUTH_STATE_DIR': str(self.throttle)},
                                text=True, capture_output=True, check=True)
        policy = json.loads(result.stdout)
        self.assertTrue(policy['secure'])
        self.assertTrue(policy['httponly'])
        self.assertEqual(policy['samesite'], 'Lax')

    def test_authenticated_home_loads_react_without_session_header_warnings(self):
        self.login()
        status, headers, body = self.request('/templates/index.php')
        self.assertEqual(status, 200)
        self.assertIn('<div id="root"></div>', body)
        self.assertIn('../static/app/assets/test-entry.js', body)
        self.assertNotIn('legacy=1', body)
        self.assertIn('no-store', headers['Cache-Control'])
        self.log.flush()
        log = (self.work / 'server.log').read_text()
        self.assertNotIn('headers already sent', log)
        self.assertNotIn('session is already active', log)

    def test_old_menu_bookmarks_redirect_to_the_authenticated_react_home(self):
        for base in ('', '/event-retiro'):
            self.jar.clear()
            for route in ('/templates/menu.php', '/templates/index.php?legacy=1'):
                self.assertTrue(self.request(base + route)[1]['Location'].startswith(base + '/templates/login.php'))
            self.login(base)
            for route in ('/templates/menu.php', '/templates/index.php?legacy=1'):
                for method in ('GET', 'HEAD'):
                    status, headers, body = self.request(base + route, method=method)
                    self.assertEqual(status, 303)
                    self.assertEqual(headers['Location'], base + '/templates/index.php')
                    self.assertEqual(body, '')
                    self.assertIn('no-store', headers['Cache-Control'])
                self.assertEqual(self.request(base + route, {})[0], 403)
            self.assertEqual(self.request(base + '/templates/index.php')[0], 200)

    def test_missing_frontend_build_returns_503_after_auth_not_a_menu(self):
        for base in ('', '/event-retiro'):
            dest = self.web / base.lstrip('/')
            manifest = dest / 'static/app/.vite/manifest.json'
            original = manifest.read_bytes()
            manifest.unlink()
            try:
                self.jar.clear()
                self.assertEqual(self.request(base + '/templates/index.php')[0], 303)
                self.login(base)
                status, headers, body = self.request(base + '/templates/index.php')
                self.assertEqual(status, 503)
                self.assertIn('makim frontend.build', body)
                self.assertNotIn('Menu Suscripciones', body)
                self.assertIn('no-store', headers['Cache-Control'])
                self.assertEqual((dest / 'db.sqlite').read_bytes(), b'PRIVATE DATABASE SENTINEL')
            finally:
                manifest.write_bytes(original)


if __name__ == '__main__':
    unittest.main(verbosity=2)

"""Synthetic lab route tests. HTTP by default; explicit CLI mode is not HTTP coverage."""
from contextlib import closing
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import struct
import subprocess
import tempfile
import unittest
import urllib.parse
import zlib

import test_auth

LAB = '/templates/testing/index.php'
CHECKIN = '/templates/testing/check-in.php'
IMAGE = '/templates/testing/qr.php'


def fake_png():
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 300, 300, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress((b'\x00' + b'\xff' * 900) * 300)) + chunk(b'IEND', b''))


class LabTest(unittest.TestCase):
    fake_mail = True
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf

    @classmethod
    def setUpClass(cls):
        cls.cli = os.environ.get('RINCHEN_TEST_SERVER') == 'cli'
        if not cls.cli:
            test_auth.AuthHTTPTest.setUpClass.__func__(cls)
        else:
            # Code-only disposable copy; exercising the real controllers without sockets.
            cls.temp = tempfile.TemporaryDirectory(prefix='rinchen-lab-cli-')
            cls.work = Path(cls.temp.name)
            cls.web = cls.work / 'web'
            cls.sessions = cls.work / 'sessions'
            cls.throttle = cls.work / 'throttle'
            cls.sessions.mkdir(mode=0o700)
            cls.throttle.mkdir(mode=0o700)
            cls.origin = 'http://localhost'
            for base in ('', 'event-retiro'):
                for source in (test_auth.ROOT / 'src').rglob('*'):
                    relative = source.relative_to(test_auth.ROOT / 'src')
                    if any(part.startswith('.') for part in relative.parts):
                        continue
                    if source.is_file() and source.suffix in ('.php', '.html', '.css', '.js') and 'static/app' not in relative.as_posix():
                        target = cls.web / base / relative
                        target.parent.mkdir(parents=True, exist_ok=True)
                        shutil.copyfile(source, target)
            cls.mail_capture = cls.work / 'mail.txt'
            cls.mail_failure = cls.work / 'mail-failure'
            cls.transport = cls.work / 'fake-sendmail'
            cls.transport.write_text(f'#!/bin/sh\ncat > "{cls.mail_capture}"\n[ ! -f "{cls.mail_failure}" ]\n')
            cls.transport.chmod(0o700)
            cls.runner = cls.work / 'request.php'
            cls.runner.write_text('''<?php
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$_SERVER['DOCUMENT_ROOT']=__DIR__.'/web';
$_SERVER['REQUEST_URI']=$input['uri'];
$_SERVER['REQUEST_METHOD']=$input['method'];
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$_SERVER['HTTP_ACCEPT']=$input['accept'];
$_GET=$input['get']; $_POST=$input['post'];
if ($input['sid'] !== '') { session_id($input['sid']); }
ob_start();
register_shutdown_function(static function (): void {
    $body=ob_get_clean(); $sid=session_id();
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    echo json_encode(['status'=>http_response_code() ?: 200,'body'=>base64_encode($body),'sid'=>$sid],JSON_THROW_ON_ERROR);
});
require ''' + json.dumps(str(test_auth.ROOT / 'scripts/dev-router.php')) + ';\n')
        cls.qr_capture = cls.work / 'qr-request.txt'
        # Replace ONLY the transport in the disposable copies, never application code/config.
        for base in ('', 'event-retiro'):
            (cls.web / base / 'lib/test_lab_qr.php').write_text('<?php\nfunction test_lab_fetch_qr(string $url): string {\n'
                + 'file_put_contents(' + json.dumps(str(cls.qr_capture)) + ', $url);\n'
                + 'return base64_decode(' + json.dumps(base64.b64encode(fake_png()).decode()) + ');\n}\n')

    @classmethod
    def tearDownClass(cls):
        if cls.cli:
            cls.temp.cleanup()
        else:
            test_auth.AuthHTTPTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_auth.AuthHTTPTest.setUp(self)
        for base in ('', '/event-retiro'):
            shutil.rmtree(self.web / base.lstrip('/') / '.test-lab', ignore_errors=True)
            (self.web / base.lstrip('/') / 'db.sqlite').write_bytes(b'PRIVATE LIVE DATABASE SENTINEL')
            self.configure(base)
        self.mail_capture.unlink(missing_ok=True)
        self.mail_failure.unlink(missing_ok=True)
        self.qr_capture.unlink(missing_ok=True)
        self.login()

    def request(self, path, data=None, method=None, headers=None):
        if not self.cli:
            return test_auth.AuthHTTPTest.request(self, path, data, method, headers)
        parsed = urllib.parse.urlsplit(path)
        cookies = sorted((c for c in self.jar if parsed.path.startswith(c.path)), key=lambda c: len(c.path), reverse=True)
        payload = {'uri': path, 'method': method or ('POST' if data is not None else 'GET'),
                   'accept': (headers or {}).get('Accept', ''), 'sid': cookies[0].value if cookies else '',
                   'get': {key: values[-1] for key, values in urllib.parse.parse_qs(parsed.query).items()},
                   'post': {key: str(value) for key, value in (data or {}).items()}}
        result = subprocess.run(['php', '-d', f'session.save_path={self.sessions}', '-d', f'sendmail_path={self.transport}',
                                 str(self.runner)], input=json.dumps(payload), capture_output=True, text=True,
                                env={**os.environ, 'RINCHEN_AUTH_STATE_DIR': str(self.throttle), 'RINCHEN_HTTPS': '0'}, check=True)
        response = json.loads(result.stdout)
        if response['sid']:
            cookie_path = '/event-retiro/' if path.startswith('/event-retiro/') else '/'
            self.jar.set_cookie(http.cookiejar.Cookie(0, 'rinchen_session', response['sid'], None, False,
                'localhost', False, False, cookie_path, True, False, None, True, None, None, {}))
        return response['status'], {}, base64.b64decode(response['body']).decode(errors='replace')

    def configure(self, base='', **changes):
        config = dict(TEST_ENV='1', TEST_EMAIL_TO='staff@example.test', SEND_EMAIL='1',
                      HOST_ADDRESS=self.origin + base, EMAIL_FROM='sender@example.test',
                      EMAIL_REPLY_TO='reply@example.test', EMAIL_CC='never-production@example.test')
        config.update(changes)
        (self.web / base.lstrip('/') / '.env').write_text(''.join(f'{k}={v}\n' for k, v in config.items()))

    def query(self, sql, base=''):
        with closing(sqlite3.connect(self.web / base.lstrip('/') / '.test-lab/lab.sqlite')) as db:
            rows = db.execute(sql).fetchall()
            db.commit()
            return rows

    def action(self, action, body=None, base='', **fields):
        if body is None:
            status, _, body = self.request(base + LAB)
            self.assertEqual(status, 200, body)
        form = next(form for form in re.findall(r'<form\b.*?</form>', body, re.S)
                    if f'name="action" value="{action}"' in form)
        data = dict(re.findall(r'name="(csrf_token|action|action_token)" value="([^"]*)"', form))
        return self.request(base + LAB, {**data, **fields}), data

    def prepare(self, base=''):
        response, _ = self.action('prepare', base=base, start='00:00', end='23:59')
        self.assertEqual(response[0], 200, response[2])

    def generate(self, base='', count='10', profile='ready'):
        response, data = self.action('generate', base=base, count=count, recipient='staff@example.test', profile=profile)
        self.assertEqual(response[0], 200, response[2])
        return response, data

    def assert_live_untouched(self, base=''):
        self.assertEqual((self.web / base.lstrip('/') / 'db.sqlite').read_bytes(), b'PRIVATE LIVE DATABASE SENTINEL')

    def test_flag_is_strict_server_side_and_get_never_creates_lab(self):
        for flag in ('0', '', 'true', '01'):
            self.configure(TEST_ENV=flag)
            self.assertFalse(json.loads(self.request('/templates/session.php')[2])['testMode'])
            for route in (LAB, IMAGE, CHECKIN):
                self.assertEqual(self.request(route)[0], 404)
                self.assertEqual(self.request(route, {'csrf_token': self.csrf()})[0], 404)
            self.assertNotIn('Pruebas · Laboratorio QR', self.request('/templates/menu.php')[2])
        self.configure()
        self.assertTrue(json.loads(self.request('/templates/session.php')[2])['testMode'])
        self.assertEqual(self.request(LAB)[0], 200)
        self.assertIn('Pruebas · Laboratorio QR', self.request('/templates/menu.php')[2])
        self.assertFalse((self.web / '.test-lab').exists())
        self.assert_live_untouched()
        # Absent configuration stays absent when checking availability/session metadata.
        (self.web / '.env').unlink()
        self.assertFalse(json.loads(self.request('/templates/session.php')[2])['testMode'])
        self.assertEqual(self.request(LAB)[0], 404)
        self.assertFalse((self.web / '.env').exists())

    def test_auth_csrf_cancel_and_replay_protection(self):
        _, _, body = self.request(LAB)
        self.request('/templates/index.php?legacy=1')  # cancellation
        self.assertFalse((self.web / '.test-lab').exists())
        self.assertEqual(self.request(LAB, {'action':'prepare'})[0], 403)
        response, data = self.action('prepare', body, start='00:00', end='23:59')
        self.assertEqual(response[0], 200, response[2])
        self.assertEqual(self.request(LAB, data)[0], 409)
        self.assertEqual(len(self.query('SELECT id FROM event')), 1)
        self.jar.clear()
        for route in (LAB, IMAGE, CHECKIN):
            self.assertEqual(self.request(route)[0], 303)
        self.assert_live_untouched()

    def test_generation_default_uuid_aliases_limits_and_no_side_effect_delivery(self):
        self.prepare()
        self.assertIn('name="count" type="number" min="1" max="100" step="1" value="10"', self.request(LAB)[2])
        for count in ('0', '-1', '1.5', '101', '1e1'):
            self.assertEqual(self.action('generate', count=count, recipient='staff@example.test', profile='ready')[0][0], 409)
        response, data = self.generate()
        self.assertEqual(len(self.query('SELECT uuid FROM test_lab_attendee')), 10)
        self.assertEqual(self.request(LAB, data)[0], 409)
        self.assertFalse(self.mail_capture.exists())
        self.assertFalse(self.qr_capture.exists())
        uuid, name, email = self.query('SELECT a.uuid,p.fullname,p.email FROM test_lab_attendee a JOIN person p ON p.id=a.person_id LIMIT 1')[0]
        self.assertIn(uuid, name)
        self.assertEqual(email, f'staff+{uuid}@example.test')
        self.assert_live_untouched()

    def test_explicit_qr_is_private_opaque_cached_and_disabled_with_flag(self):
        self.prepare(); self.generate(count='1')
        response, _ = self.action('qr')
        self.assertEqual(response[0], 200, response[2])
        uuid = self.query('SELECT uuid FROM test_lab_attendee')[0][0]
        target = self.qr_capture.read_text()
        self.assertEqual(target, self.origin + CHECKIN + '?uuid=' + uuid)
        self.assertNotIn('staff@', target)
        self.assertEqual(self.request(IMAGE + '?uuid=' + uuid)[0], 200)
        for path in ('/.test-lab/lab.sqlite', '/.test-lab/lab.sqlite-journal', '/.test-lab/'):
            self.assertIn(self.request(path)[0], (403, 404))
        self.qr_capture.unlink()
        self.assertEqual(self.action('qr')[0][0], 200)
        self.assertFalse(self.qr_capture.exists())
        self.configure(TEST_ENV='0')
        for route in (LAB, IMAGE, CHECKIN):
            self.assertEqual(self.request(route + '?uuid=' + uuid)[0], 404)
        self.assert_live_untouched()

    def test_scan_reuses_automatic_and_warning_flow_without_live_writes(self):
        self.prepare()
        self.query("UPDATE event_session SET start_date=datetime('now','-2 days'),end_date=datetime('now','-1 day') WHERE id!=1")
        self.query("UPDATE event_session SET start_date=datetime('now','-1 hour'),end_date=datetime('now','+1 hour') WHERE id=1")
        self.generate(count='1')
        uuid = self.query('SELECT uuid FROM test_lab_attendee')[0][0]
        route = CHECKIN + '?uuid=' + uuid
        status, _, body = self.request(route)
        self.assertEqual(status, 200, body)
        self.assertIn('data-auto-checkin="1"', body)
        self.assertIn('SOLO PRUEBAS', body)
        self.assertEqual(self.query('SELECT id FROM attendance WHERE event_session_id=1'), [])
        token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1]
        data = {'csrf_token':self.csrf(),'review_token':token,'decision':'automatic'}
        self.assertEqual(self.request(CHECKIN, data)[0], 200)
        self.assertEqual(self.request(CHECKIN, data)[0], 409)
        self.assertEqual(len(self.query('SELECT id FROM attendance WHERE event_session_id=1')), 1)
        self.generate(count='1', profile='missing')
        uuid = self.query('SELECT uuid FROM test_lab_attendee ORDER BY person_id DESC LIMIT 1')[0][0]
        status, _, body = self.request(CHECKIN + '?uuid=' + uuid)
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertIn('Faltan asistencias anteriores', body)
        token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1]
        self.assertEqual(self.request(CHECKIN, {'csrf_token':self.csrf(),'review_token':token,'decision':'allow'})[0], 200)
        self.assert_live_untouched()

    def test_lab_and_live_review_tokens_cannot_cross_contexts(self):
        self.prepare(); self.generate(count='1')
        # A second, synthetic "live" fixture with overlapping IDs, never the working DB.
        live = self.web / 'db.sqlite'; live.unlink()
        with closing(sqlite3.connect(live)) as db:
            db.executescript("""CREATE TABLE person(id INTEGER PRIMARY KEY,fullname TEXT,email TEXT,phone TEXT);
                CREATE TABLE event_session(id INTEGER PRIMARY KEY,event_id INTEGER,name TEXT,start_date TEXT,end_date TEXT);
                CREATE TABLE attendance(id INTEGER PRIMARY KEY,person_id INTEGER,event_session_id INTEGER,log_time TEXT);
                CREATE TABLE qr_admission_decision(id INTEGER PRIMARY KEY,person_id INTEGER,event_session_id INTEGER,decision TEXT,warnings_json TEXT,actor TEXT,decided_at TEXT);
                INSERT INTO person VALUES(1,'Synthetic live','live@example.test','123');
                INSERT INTO event_session VALUES(1,1,'Synthetic live',datetime('now','-1 hour'),datetime('now','+1 hour'));""")
        before = live.read_bytes()
        uuid = self.query('SELECT uuid FROM test_lab_attendee')[0][0]
        for scan, post in ((CHECKIN + '?uuid=' + uuid, '/templates/attendance/log.php'),
                           ('/templates/attendance/log.php?fullname=Synthetic%20live&email=live%40example.test&phone=123', CHECKIN)):
            body = self.request(scan)[2]
            token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1]
            self.assertEqual(self.request(post, {'csrf_token':self.csrf(),'review_token':token,'decision':'allow'})[0], 409)
        self.assertEqual(live.read_bytes(), before)
        self.assertEqual(self.query('SELECT id FROM qr_admission_decision'), [])

    def test_email_is_opt_in_alias_only_without_cc_and_turning_off_blocks_pending_actions(self):
        self.prepare(); self.generate(count='1')
        response, data = self.action('mail')
        self.assertEqual(response[0], 200, response[2])
        self.assertIn('Esto no confirma la entrega', response[2])
        mail = self.mail_capture.read_text()
        uuid = self.query('SELECT uuid FROM test_lab_attendee')[0][0]
        self.assertIn(f'staff+{uuid}@example.test', mail)
        self.assertNotIn('never-production', mail)
        self.assertEqual(self.request(LAB, data)[0], 409)
        self.assertEqual(self.mail_capture.read_text(), mail)
        _, _, body = self.request(LAB)
        self.configure(TEST_ENV='0')
        self.assertEqual(self.action('generate', body, count='1', recipient='staff@example.test', profile='ready')[0][0], 404)
        self.assertEqual(len(self.query('SELECT uuid FROM test_lab_attendee')), 1)
        self.assert_live_untouched()

    def test_subdirectory_and_private_permissions(self):
        self.jar.clear(); self.login('/event-retiro')
        self.prepare('/event-retiro'); self.generate('/event-retiro', count='1')
        response, _ = self.action('qr', base='/event-retiro')
        self.assertEqual(response[0], 200, response[2])
        self.assertIn(self.origin + '/event-retiro' + CHECKIN, self.qr_capture.read_text())
        self.assertIn('href="/event-retiro/templates/testing/check-in.php', response[2])
        directory = self.web / 'event-retiro/.test-lab'
        self.assertEqual(directory.stat().st_mode & 0o777, 0o700)
        self.assertEqual((directory / 'lab.sqlite').stat().st_mode & 0o777, 0o600)
        self.assertIn(self.request('/event-retiro/.test-lab/lab.sqlite')[0], (403,404))
        self.assert_live_untouched('/event-retiro')

    def test_failed_mail_and_invalid_recipient_never_become_success_or_duplicate_records(self):
        self.prepare(); self.generate(count='1')
        self.configure(TEST_EMAIL_TO='changed@example.test')
        self.assertEqual(self.action('mail')[0][0], 409)
        self.assertFalse(self.mail_capture.exists())
        self.jar.clear(); self.login()
        self.configure()
        self.mail_failure.touch()
        response, data = self.action('mail')
        self.assertEqual(response[0], 502, response[2])
        self.assertIn('No se pudo confirmar', response[2])
        self.assertEqual(self.query('SELECT mail_state FROM test_lab_attendee'), [('unconfirmed',)])
        self.assertEqual(self.request(LAB, data)[0], 409)
        self.assertEqual(len(self.query('SELECT id FROM person')), 1)
        self.assert_live_untouched()


if __name__ == '__main__':
    if os.environ.get('RINCHEN_TEST_SERVER') == 'cli':
        print('CLI controller mode: no actual HTTP headers/cookies/socket coverage.', flush=True)
    unittest.main(verbosity=2)

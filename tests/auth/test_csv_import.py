"""Disposable CSV tests with fake QR/sendmail. No real delivery or working data writes."""
import base64
from contextlib import closing
import csv
import io
import json
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import tempfile
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid

import test_auth

ROOT = test_auth.ROOT
HEADER = ['No.', 'NOMBRE', 'APELLIDOS', 'CORREO ELECTRONICO', 'No. CELULAR', 'CIUDAD']
PEOPLE = [
    ['1', ' Ana <test> ', " O'Flores ", ' ana@example.test ', '(+591) 12345678', 'La Paz'],
    ['2', 'Bruno', 'Prueba', 'bruno@example.test', '591+23456789', 'Cochabamba'],
    ['3', 'Carla', 'Ríos', 'carla@example.test', '+591 34567890', 'Santa Cruz'],
]


def csv_text(rows=PEOPLE, header=HEADER, leading=True):
    output = io.StringIO(newline='')
    writer = csv.writer(output)
    if leading:
        writer.writerows([[''] * len(header)] * 4)
    writer.writerow(header)
    writer.writerow([''] * len(header))
    writer.writerows(rows)
    return output.getvalue()


class CsvReaderTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='rinchen-csv-reader-')
        cls.lib = Path(cls.temp.name) / 'src/lib'
        shutil.copytree(ROOT / 'src/lib', cls.lib)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def read(self, contents):
        contents = contents.encode() if isinstance(contents, str) else contents
        code = f'''require {json.dumps(str(self.lib / 'subscription_csv.php'))};
            try {{ echo json_encode(SubscriptionCsv::readContents(base64_decode(stream_get_contents(STDIN))), JSON_THROW_ON_ERROR); }}
            catch (DomainException $e) {{ echo json_encode(['error' => $e->getMessage()]); }}'''
        result = subprocess.run(['php', '-d', 'disable_functions=mail,curl_init', '-r', code],
                                input=base64.b64encode(contents), capture_output=True, check=True)
        self.assertEqual(result.stderr, b'')
        return json.loads(result.stdout)

    def test_supplied_2026_roster_read_only(self):
        fixture = ROOT / 'tests/data/2026/participantes.csv'
        if not fixture.exists():
            self.skipTest('Local participant roster is deliberately not committed.')
        report = self.read(fixture.read_bytes())
        with fixture.open(encoding='utf-8-sig', newline='') as handle:
            records = list(csv.reader(handle))
        nonempty = [row for row in records if any(value.strip() for value in row)]
        self.assertEqual(report['profile'], '2026')
        self.assertEqual(len(report['rows']) + len(report['errors']), len(nonempty) - 1)
        self.assertEqual(report['blank_rows'], len(records) - len(nonempty))
        self.assertEqual(report['warnings'], [])

    def test_historical_fixture_keeps_mapping(self):
        report = self.read((ROOT / 'tests/data/google_forms.csv').read_bytes())
        self.assertEqual(report['profile'], 'legacy')
        self.assertEqual(len(report['rows']), 6)
        self.assertEqual(len(report['warnings']), 5)
        self.assertEqual(report['errors'], [])

    def test_bom_blank_rows_whitespace_and_phone_formatting(self):
        report = self.read('\ufeff' + csv_text([PEOPLE[0], [' \t'] * 6, [], PEOPLE[2]]))
        self.assertEqual((report['profile'], report['blank_rows']), ('2026', 7))
        self.assertEqual(report['errors'], [])
        self.assertEqual(report['rows'][0]['fullname'], "Ana <test> O'Flores")
        self.assertEqual(report['rows'][0]['email'], 'ana@example.test')
        self.assertEqual(report['rows'][0]['phone'], '+59112345678')

    def test_reordered_trimmed_accent_insensitive_headers(self):
        header = ['ciudad', ' No. CELULAR ', 'Correo Electrónico', 'APELLIDOS', 'nombre', 'No.', 'Extra']
        report = self.read(csv_text([['La Paz', '+59112345678', 'ana@example.test', 'Flores', 'Ana', '1', 'ignored']], header))
        self.assertEqual(report['profile'], '2026')
        self.assertEqual(report['rows'][0]['fullname'], 'Ana Flores')

    def test_bad_width_and_invalid_values_do_not_stop_later_rows(self):
        rows = [PEOPLE[0][:-1], PEOPLE[0] + ['extra'], PEOPLE[1],
                ['4', 'Test', 'Error', 'bad-email', '12345', 'La Paz'],
                ['5', '', '', 'test@example.test', '12345', 'La Paz'], PEOPLE[2]]
        report = self.read(csv_text(rows))
        self.assertEqual(len(report['rows']), 1)
        self.assertEqual([item['row'] for item in report['errors']], [7, 8, 9, 10, 11])
        self.assertIn('signo +', report['errors'][2]['message'])

    def test_quoted_multiline_fields_and_physical_line_numbers(self):
        report = self.read(csv_text([['1', 'Ana, "María"\nTest', 'Flores', 'ana@example.test', '12345', 'La Paz'], PEOPLE[1]], leading=False))
        self.assertEqual(report['rows'][0]['fullname'], 'Ana, "María"\nTest Flores')
        self.assertEqual(report['errors'][0]['row'], 5)

    def test_bad_headers_empty_encoding_and_size_fail_closed(self):
        for contents in ('', '\n,,,,,\n', 'Name,Email\nAna,ana@example.test\n',
                         csv_text([], HEADER + [' nombre ']),
                         csv_text([], HEADER + ['Número de celular', 'valido']), b'\xff', b'x' * (5242880 + 1)):
            with self.subTest(length=len(contents)):
                self.assertIn('error', self.read(contents))

    def test_real_qr_builder_encodes_identity_without_external_transport(self):
        (self.lib.parent / '.env').write_text('HOST_ADDRESS=https://example.test/event-retiro\nSEND_EMAIL=0\n')
        (self.lib.parent / 'static/qr').mkdir(parents=True)
        # PHP -n loads no cURL extension. These fake functions capture the real builder's URL.
        code = f'''const CURLOPT_RETURNTRANSFER = 19913;
            function curl_init($url) {{ $GLOBALS['request_url'] = $url; return new stdClass(); }}
            function curl_setopt($handle, $option, $value) {{ return true; }}
            function curl_exec($handle) {{ return 'FAKE QR'; }}
            function curl_close($handle) {{}}
            require {json.dumps(str(self.lib / 'qr.php'))};
            $qr = generate_qr("Ana & Test O'Flores", 'ana+test@example.test', '+59112345678');
            echo json_encode(['request' => $GLOBALS['request_url'], 'qr' => $qr]);'''
        result = subprocess.run(['php', '-n', '-d', 'disable_functions=mail', '-r', code], capture_output=True, check=True)
        self.assertEqual(result.stderr, b'')
        data = json.loads(result.stdout)
        attendance = urllib.parse.parse_qs(urllib.parse.urlsplit(data['request']).query)['data'][0]
        self.assertEqual(urllib.parse.urlsplit(attendance).path, '/event-retiro/attendance_log.php/')
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(attendance).query), {
            'fullname': ["Ana & Test O'Flores"], 'email': ['ana+test@example.test'], 'phone': ['+59112345678'],
        })
        self.assertTrue(data['qr'].startswith('https://example.test/event-retiro/static/qr/'))


class CsvImportHTTPTest(unittest.TestCase):
    fake_mail = True
    request = test_auth.AuthHTTPTest.request
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf

    @classmethod
    def setUpClass(cls):
        if subprocess.run(['php', '-r', 'exit(class_exists("SQLite3") ? 0 : 1);']).returncode:
            raise RuntimeError('CSV integration tests require the external PHP sqlite3 extension.')
        test_auth.AuthHTTPTest.setUpClass.__func__(cls)
        cls.qr_calls = cls.work / 'qr-calls'
        cls.qr_failure = cls.work / 'qr-failure'
        cls.mail_calls = cls.work / 'mail-calls'
        for base in ('', 'event-retiro'):
            dest = cls.web / base
            # Replace only the QR transport, in the disposable code copy.
            (dest / 'lib/qr.php').write_text(f'''<?php
                require_once __DIR__ . '/auth.php'; auth_block_direct(__FILE__);
                require_once __DIR__ . '/settings.php';
                function generate_qr(string $fullname, string $email, string $phone): string {{
                    file_put_contents({json.dumps(str(cls.qr_calls))}, "qr\\n", FILE_APPEND);
                    if (file_exists({json.dumps(str(cls.qr_failure))})) throw new RuntimeException('Fake QR failure');
                    return get_env('HOST_ADDRESS') . '/static/qr/' . hash('sha256', $fullname . $email . $phone) . '.jpg';
                }}''')
            shutil.copyfile(ROOT / 'src/templates/subscription-email.html', dest / 'templates/subscription-email.html')
            (dest / '.env').write_text(f'HOST_ADDRESS={cls.origin}/{base}\nSEND_EMAIL=1\nEMAIL_FROM=sender@example.test\nEMAIL_REPLY_TO=reply@example.test\nEMAIL_CC=staff@example.test\n')
        (cls.work / 'fake-sendmail').write_text(f'#!/bin/sh\ncat >> "{cls.mail_capture}"\nprintf "mail\\n" >> "{cls.mail_calls}"\n[ ! -f "{cls.mail_failure}" ]\n')

    @classmethod
    def tearDownClass(cls):
        test_auth.AuthHTTPTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_auth.AuthHTTPTest.setUp(self)
        for file in (self.mail_capture, self.mail_calls, self.mail_failure, self.qr_calls, self.qr_failure):
            file.unlink(missing_ok=True)
        for base in ('', 'event-retiro'):
            database = self.web / base / 'db.sqlite'
            database.unlink()
            (self.web / base / '.env').write_text(f'HOST_ADDRESS={self.origin}/{base}\nSEND_EMAIL=1\nEMAIL_FROM=sender@example.test\nEMAIL_REPLY_TO=reply@example.test\nEMAIL_CC=staff@example.test\n')
            with closing(sqlite3.connect(database)) as db:
                db.executescript('''
                    CREATE TABLE person (id INTEGER PRIMARY KEY AUTOINCREMENT, fullname TEXT, email TEXT, phone TEXT, active INTEGER DEFAULT 1);
                    CREATE TABLE subscription (id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER, datetime TEXT, qr TEXT, active INTEGER DEFAULT 1);
                    CREATE TABLE attendance (id INTEGER PRIMARY KEY, person_id INTEGER, event_session_id INTEGER, log_time TEXT);
                    INSERT INTO person VALUES (50, 'Existing person', 'existing@example.test', '12345', 1);
                ''')
        self.login()

    def query(self, sql, values=(), base=''):
        with closing(sqlite3.connect(self.web / base.lstrip('/') / 'db.sqlite')) as db:
            result = db.execute(sql, values).fetchall()
            db.commit()
            return result

    def upload(self, contents=None, base='', csrf=True, event_id=None):
        contents = csv_text() if contents is None else contents
        contents = contents.encode() if isinstance(contents, str) else contents
        boundary = uuid.uuid4().hex
        payload = b''
        if csrf:
            payload += f'--{boundary}\r\nContent-Disposition: form-data; name="csrf_token"\r\n\r\n{self.csrf(base)}\r\n'.encode()
        if event_id is not None:
            payload += f'--{boundary}\r\nContent-Disposition: form-data; name="event_id"\r\n\r\n{event_id}\r\n'.encode()
        payload += f'--{boundary}\r\nContent-Disposition: form-data; name="csv_file"; filename="participantes.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode()
        payload += contents + f'\r\n--{boundary}--\r\n'.encode()
        req = urllib.request.Request(self.origin + base + '/templates/subscription/upload.php', data=payload,
                                     headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
        try:
            response = self.client.open(req, timeout=10)
        except urllib.error.HTTPError as response:
            with response:
                return response.code, response.read().decode()
        with response:
            return response.status, response.read().decode()

    def review(self, contents=None, base=''):
        status, body = self.upload(contents, base)
        self.assertEqual(status, 200, body)
        match = re.search(r'name="import_token" value="([a-f0-9]+)"', body)
        return match[1] if match else None, body

    def decide(self, token, action='confirm', base='', **extra):
        return self.request(base + '/templates/subscription/upload.php', dict(
            csrf_token=self.csrf(base), action=action, import_token=token, **extra))

    def attempts(self, path):
        return len(path.read_text().splitlines()) if path.exists() else 0

    def test_get_preview_cancel_do_not_write_or_send(self):
        before = (self.web / 'db.sqlite').read_bytes()
        self.assertEqual(self.request('/templates/subscription/upload.php')[0], 200)
        token, body = self.review()
        self.assertIn('Fila 8:', body)
        self.assertIn('Ana &lt;test&gt; O&#039;Flores', body)
        self.assertEqual(self.decide(token, 'cancel')[0], 200)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertFalse(self.qr_calls.exists())
        self.assertFalse(self.mail_capture.exists())
        self.assertEqual(self.decide(token)[0], 409)

    def test_import_and_downstream_api_person_relationships(self):
        token, _ = self.review()
        status, _, body = self.decide(token, notes='Revisión de prueba')
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 2.', body)
        self.assertIn('Filas no importadas por errores: 1.', body)
        self.assertEqual(self.query('SELECT fullname, phone FROM person WHERE id=51'), [("Ana <test> O'Flores", '+59112345678')])
        self.assertEqual(self.query('SELECT person_id, active FROM subscription ORDER BY id'), [(51, 1), (52, 1)])
        audit = self.query('SELECT profile, warnings_json, staff_confirmed, actor, notes, imported_at FROM csv_import_audit')
        self.assertEqual(len(audit), 2)
        self.assertEqual(audit[0][:5], ('2026', '[]', 1, 'admin', 'Revisión de prueba'))
        self.assertRegex(audit[0][5], r'^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$')
        self.assertEqual(self.attempts(self.mail_calls), 2)
        for route in ('/api/subscription/list.php', '/templates/subscription/list.php?format=json'):
            status, _, body = self.request(route)
            response = json.loads(body)
            self.assertEqual(status, 200, body)
            self.assertTrue(response['success'])
            self.assertEqual(len(response['data']), 2)
            first = response['data'][0]
            if 'person' in first:
                self.assertEqual((first['id'], first['person']['id']), (1, 51))
                self.assertEqual(first['person']['fullname'], "Ana <test> O'Flores")
            else:
                self.assertEqual(first['person_id'], 51)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])

    def test_replay_and_reimport_do_not_duplicate_or_resend(self):
        token, _ = self.review()
        self.assertEqual(self.decide(token)[0], 200)
        self.assertEqual(self.decide(token)[0], 409)
        second, _ = self.review()
        status, _, body = self.decide(second)
        self.assertEqual(status, 200, body)
        self.assertIn('Duplicados omitidos: 2.', body)
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(2,)])
        self.assertEqual(self.query('SELECT count(*) FROM csv_import_audit'), [(2,)])
        self.assertEqual(self.attempts(self.qr_calls), 2)
        self.assertEqual(self.attempts(self.mail_calls), 2)

    def test_imported_person_activation_and_qr_attendance(self):
        token, _ = self.review(csv_text([PEOPLE[0]]))
        self.assertEqual(self.decide(token)[0], 200)
        for active in (0, 1):
            status, _, body = self.request('/templates/subscription/activation.php', {
                'csrf_token': self.csrf(), 'id': 1, 'active': active,
            })
            self.assertEqual(status, 302, body)
            self.assertEqual(self.query('SELECT active FROM person WHERE id=51'), [(active,)])
            self.assertEqual(self.query('SELECT active FROM subscription WHERE id=1'), [(active,)])
        self.assertEqual(self.query('SELECT active FROM person WHERE id=50'), [(1,)])
        self.query('CREATE TABLE event_session (id INTEGER PRIMARY KEY, event_id INTEGER, name TEXT, start_date TEXT, end_date TEXT)')
        self.query("INSERT INTO event_session VALUES (1, 1, 'Actual', datetime('now', '-1 hour'), datetime('now', '+1 hour'))")
        subprocess.run(['php', str(self.web / 'migrations/0006_qr_admission.php')], check=True, capture_output=True)
        identity = urllib.parse.urlencode(dict(fullname="Ana <test> O'Flores", email='ana@example.test', phone='+59112345678'))
        status, _, body = self.request('/templates/attendance/log.php?' + identity)
        self.assertEqual(status, 200, body)
        review_token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1]
        status, _, body = self.request('/templates/attendance/log.php', {
            'csrf_token': self.csrf(), 'review_token': review_token, 'decision': 'allow',
        })
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT person_id, event_session_id FROM attendance'), [(51, 1)])
        self.assertEqual(self.attempts(self.mail_calls), 1)

    def test_duplicate_rows_within_one_csv_are_written_once(self):
        token, _ = self.review(csv_text([PEOPLE[0], PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 1.', body)
        self.assertIn('Duplicados omitidos: 1.', body)
        self.assertEqual(self.attempts(self.mail_calls), 1)

    def test_ambiguous_existing_people_are_not_arbitrarily_merged(self):
        for _ in range(2):
            self.query('INSERT INTO person (fullname, email, phone, active) VALUES (?, ?, ?, 1)',
                       ("Ana <test> O'Flores", 'ana@example.test', '+59112345678'))
        token, _ = self.review(csv_text([PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Hay varias personas con estos mismos datos', body)
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(0,)])
        self.assertFalse(self.qr_calls.exists())
        self.assertFalse(self.mail_capture.exists())

    def test_library_upload_entrypoint_requires_legacy_warning_confirmation(self):
        file = self.work / 'legacy.csv'
        file.write_text(csv_text([['Ana', 'Flores', 'ana@example.test', '12345', '0']],
                                 ['Nombre', 'Apellidos', 'Correo electrónico', 'Número de celular', 'valido']))
        require = f'require {json.dumps(str(self.web / "lib/subscription.php"))};'
        call = f'Subscription::upload_csv({json.dumps(str(file))})'
        code = require + f'try {{ {call}; }} catch (DomainException $e) {{ echo "review_required"; }}'
        result = subprocess.run(['php', '-d', f'sendmail_path={self.work / "fake-sendmail"}', '-r', code], check=True, capture_output=True)
        self.assertEqual(result.stdout, b'review_required')
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(0,)])
        self.assertFalse(self.mail_capture.exists())
        code = require + f'echo json_encode(Subscription::upload_csv({json.dumps(str(file))}, true, "admin"));'
        result = subprocess.run(['php', '-d', f'sendmail_path={self.work / "fake-sendmail"}', '-r', code], check=True, capture_output=True)
        self.assertEqual(json.loads(result.stdout)['imported'], 1)
        self.assertEqual(self.attempts(self.mail_calls), 1)

    def test_legacy_warning_cancel_and_confirmed_continuation(self):
        legacy = csv_text([['Ana', 'Flores', 'ana@example.test', '12345', '0']],
                          ['Nombre', 'Apellidos', 'Correo electrónico', 'Número de celular', 'valido'])
        token, body = self.review(legacy)
        self.assertIn('aporte puede estar pendiente', body)
        self.assertIn('Continuar de todos modos e importar', body)
        self.assertEqual(self.decide(token, 'cancel')[0], 200)
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(0,)])
        self.assertFalse(self.mail_capture.exists())
        token, _ = self.review(legacy)
        status, _, body = self.decide(token, warnings='[]', fullname='Forged identity')
        self.assertEqual(status, 200, body)
        audit = self.query('SELECT warnings_json, actor, staff_confirmed FROM csv_import_audit')[0]
        self.assertEqual(json.loads(audit[0])[0]['code'], 'payment_unverified')
        self.assertEqual(audit[1:], ('admin', 1))
        self.assertEqual(self.query('SELECT fullname FROM person WHERE id=51'), [('Ana Flores',)])

    def test_auth_csrf_expired_tokens_cannot_write(self):
        token, _ = self.review()
        self.assertEqual(self.request('/templates/subscription/upload.php', {'action': 'confirm', 'import_token': token})[0], 403)
        self.assertEqual(self.upload(csrf=False)[0], 403)
        sid = next(iter(self.jar)).value
        session = self.sessions / ('sess_' + sid)
        # Preserve CRLF inside PHP's byte-counted serialized CSV string.
        session.write_bytes(re.sub(rb'("expires";i:)\d+', rb'\g<1>1', session.read_bytes()))
        self.assertEqual(self.decide(token)[0], 409)
        self.jar.clear()
        self.assertEqual(self.upload(csrf=False)[0], 303)
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(0,)])
        self.assertFalse(self.mail_capture.exists())

    def test_unknown_headers_invalid_rows_and_forged_token(self):
        status, body = self.upload('Name,Email\nAna,ana@example.test\n')
        self.assertEqual(status, 409, body)
        token, body = self.review(csv_text([PEOPLE[1]]))
        self.assertIsNone(token)
        self.assertIn('Filas que no se importarán', body)
        self.assertEqual(self.decide('forged-token')[0], 409)
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(0,)])

    def test_qr_and_database_failures_roll_back_not_overridable(self):
        self.qr_failure.touch()
        token, _ = self.review(csv_text([PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 0.', body)
        self.assertIn('fallo técnico', body)
        self.assertEqual(self.query('SELECT count(*) FROM person'), [(1,)])
        self.assertFalse(self.mail_capture.exists())
        self.qr_failure.unlink()
        self.query("CREATE TRIGGER fail_import BEFORE INSERT ON subscription BEGIN SELECT RAISE(ABORT, 'fake storage failure'); END")
        token, _ = self.review(csv_text([PEOPLE[0]]))
        self.decide(token)
        self.assertEqual(self.query('SELECT count(*) FROM person'), [(1,)])
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(0,)])
        self.assertFalse(self.mail_capture.exists())

    def test_mail_failure_keeps_saved_rows_without_automatic_retry(self):
        self.mail_failure.touch()
        token, _ = self.review(csv_text([PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 1.', body)
        self.assertIn('La inscripción quedó guardada', body)
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(1,)])
        token, _ = self.review(csv_text([PEOPLE[0]]))
        self.decide(token)
        self.assertEqual(self.attempts(self.mail_calls), 1)

    def test_audit_failure_rolls_back_only_the_new_row(self):
        token, _ = self.review(csv_text([PEOPLE[0]]))
        self.decide(token)
        self.query("CREATE TRIGGER fail_audit BEFORE INSERT ON csv_import_audit BEGIN SELECT RAISE(ABORT, 'fake audit failure'); END")
        token, _ = self.review(csv_text([PEOPLE[2]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 0.', body)
        self.assertEqual(self.query('SELECT count(*) FROM person'), [(2,)])
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(1,)])
        self.assertEqual(self.query('SELECT count(*) FROM csv_import_audit'), [(1,)])
        self.assertEqual(self.attempts(self.mail_calls), 1)

    def test_disabled_email_is_not_a_successful_mock(self):
        config = self.web / '.env'
        config.write_text(config.read_text().replace('SEND_EMAIL=1', 'SEND_EMAIL=0'))
        token, _ = self.review(csv_text([PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 1.', body)
        self.assertIn('deshabilitado (SEND_EMAIL=0)', body)
        self.assertFalse(self.mail_capture.exists())

    def test_subdirectory_and_existing_person_reuse(self):
        self.jar.clear()
        self.login('/event-retiro')
        self.query("INSERT INTO person VALUES (80, 'Ana Flores', 'ana@example.test', '+59112345678', 1)", base='/event-retiro')
        token, body = self.review(csv_text([['1', 'Ana', 'Flores', 'ana@example.test', '(+591) 12345678', 'La Paz']]), '/event-retiro')
        self.assertIn('action="/event-retiro/templates/subscription/upload.php"', body)
        self.assertEqual(self.decide(token, base='/event-retiro')[0], 200)
        self.assertEqual(self.query('SELECT person_id FROM subscription', base='/event-retiro'), [(80,)])
        self.assertEqual(self.query('SELECT count(*) FROM person', base='/event-retiro'), [(2,)])
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(0,)])


if __name__ == '__main__':
    unittest.main(verbosity=2)

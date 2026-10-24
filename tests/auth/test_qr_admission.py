"""Staff QR workflow against disposable SQLite fixtures, never application data."""
from contextlib import closing
import json
import re
import sqlite3
import subprocess
import unittest
import urllib.parse

import test_auth

QR = '/templates/attendance/log.php?' + urllib.parse.urlencode({
    'fullname': 'Ana <test>', 'email': 'ana@example.test', 'phone': '+591 123',
}, quote_via=urllib.parse.quote)


class QrAdmissionTest(unittest.TestCase):
    request = test_auth.AuthHTTPTest.request
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf

    @classmethod
    def setUpClass(cls):
        check = subprocess.run(['php', '-r', 'exit(class_exists("SQLite3") ? 0 : 1);'])
        if check.returncode:
            raise RuntimeError('QR tests require the external PHP sqlite3 extension.')
        test_auth.AuthHTTPTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        test_auth.AuthHTTPTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_auth.AuthHTTPTest.setUp(self)
        for base in ('', 'event-retiro'):
            database = self.web / base / 'db.sqlite'
            database.unlink()
            with closing(sqlite3.connect(database)) as db:
                db.executescript("""
                    CREATE TABLE person (id INTEGER PRIMARY KEY, fullname TEXT, email TEXT, phone TEXT);
                    CREATE TABLE event_session (id INTEGER PRIMARY KEY, event_id INTEGER, name TEXT, start_date TEXT, end_date TEXT);
                    CREATE TABLE attendance (id INTEGER PRIMARY KEY, person_id INTEGER, event_session_id INTEGER, log_time TEXT);
                    INSERT INTO person VALUES (1, 'Ana <test>', 'ana@example.test', '+591 123');
                    INSERT INTO event_session VALUES
                        (1, 1, 'Primera', datetime('now', '-3 hours'), datetime('now', '-2 hours')),
                        (2, 1, 'Segunda', datetime('now', '-2 hours'), datetime('now', '-1 hour')),
                        (3, 1, 'Actual', datetime('now', '-30 minutes'), datetime('now', '+1 hour')),
                        (4, 2, 'Otro evento', datetime('now', '-3 hours'), datetime('now', '-2 hours'));
                """)
            # Run only the additive migration, twice to check safe repeatability.
            migration = self.web / base / 'migrations/0006_qr_admission.php'
            subprocess.run(['php', '-r', f'require {json.dumps(str(migration))}; migrate_0006(); migrate_0006();'],
                           check=True, capture_output=True)
        self.login()

    def query(self, sql, base=''):
        with closing(sqlite3.connect(self.web / base.lstrip('/') / 'db.sqlite')) as db:
            result = db.execute(sql).fetchall()
            db.commit()
            return result

    def review(self, base=''):
        status, _, body = self.request(base + QR)
        self.assertEqual(status, 200, body)
        token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)
        return token[1] if token else None, body

    def decide(self, token, decision, base='', **extra):
        return self.request(base + '/templates/attendance/log.php', {
            'csrf_token': self.csrf(base), 'review_token': token, 'decision': decision, **extra,
        })

    def test_scan_warns_without_writing_and_cancel_is_safe(self):
        token, body = self.review()
        self.assertIn('Ana &lt;test&gt;', body)
        for text in ('Primera', 'Segunda', 'Permitir participación', 'Denegar participación'):
            self.assertIn(text, body)
        self.assertNotIn('Otro evento', body)
        self.assertNotIn('data-auto-checkin="1"', body)
        self.request('/templates/index.php?legacy=1')
        self.assertEqual(self.query('SELECT * FROM attendance'), [])
        self.assertEqual(self.query('SELECT * FROM qr_admission_decision'), [])

    def test_allow_records_attendance_and_warning_audit_once(self):
        token, _ = self.review()
        status, _, body = self.decide(token, 'allow')
        self.assertEqual(status, 200, body)
        self.assertIn('Participación permitida', body)
        self.assertEqual(self.query('SELECT person_id, event_session_id FROM attendance'), [(1, 3)])
        audit = self.query('SELECT decision, warnings_json, actor, decided_at FROM qr_admission_decision')[0]
        self.assertEqual((audit[0], audit[2]), ('allow', 'admin'))
        snapshot = json.loads(audit[1])
        self.assertEqual([s['id'] for s in snapshot['missing_sessions']], [1, 2])
        self.assertEqual((snapshot['version'], snapshot['mode'], snapshot['staff_confirmed']), (2, 'staff', True))
        self.assertTrue(audit[3])
        self.assertEqual(self.decide(token, 'allow')[0], 409)
        self.assertEqual(len(self.query('SELECT * FROM attendance')), 1)
        self.assertIn('Asistencia ya registrada', self.review()[1])

    def test_deny_persists_on_rescan_and_staff_can_revise(self):
        token, _ = self.review()
        self.assertEqual(self.decide(token, 'deny')[0], 200)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])
        token, body = self.review()
        self.assertIn('Participación denegada para esta sesión', body)
        self.assertIn('Cambiar decisión y permitir participación', body)
        self.assertEqual(self.decide(token, 'allow')[0], 200)
        self.assertEqual(self.query('SELECT decision FROM qr_admission_decision ORDER BY id'), [('deny',), ('allow',)])
        self.assertEqual(len(self.query('SELECT * FROM attendance')), 1)

    def test_clear_active_session_offers_automatic_post_but_get_never_writes(self):
        self.query("INSERT INTO attendance VALUES (1, 1, 1, datetime('now'))")
        self.query("INSERT INTO attendance VALUES (2, 1, 2, datetime('now'))")
        token, body = self.review()
        self.assertIn('Registrando asistencia automáticamente', body)
        self.assertIn('data-auto-checkin="1"', body)
        self.assertNotIn('<select', body)
        self.assertNotIn('Permitir participación', body)
        self.assertNotIn('Denegar participación', body)
        self.assertNotIn('id="qr-notes"', body)
        self.assertRegex(body, r'<details class="qr-secondary" id="qr-auto-fallback">')
        self.assertEqual(self.query('SELECT * FROM attendance WHERE event_session_id=3'), [])
        self.assertEqual(self.query('SELECT * FROM qr_admission_decision'), [])
        self.assertEqual(self.request('/static/qr-checkin.js')[0], 200)
        status, _, body = self.decide(token, 'automatic')
        self.assertEqual(status, 200, body)
        self.assertIn('Asistencia registrada automáticamente', body)
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertIn('id="qr-result"', body)
        self.assertNotIn('<form', body)
        self.assertNotIn('Asistencia ya registrada', body)
        _, rescan = self.review()
        self.assertIn('Asistencia ya registrada', rescan)
        self.assertNotIn('<form', rescan)
        snapshot = json.loads(self.query('SELECT warnings_json FROM qr_admission_decision')[0][0])
        self.assertEqual(snapshot['warnings'], [])
        self.assertEqual((snapshot['mode'], snapshot['staff_confirmed']), ('automatic', False))
        self.assertEqual(self.decide(token, 'automatic')[0], 409)
        self.assertEqual(len(self.query('SELECT * FROM attendance WHERE event_session_id=3')), 1)

    def test_result_can_explicitly_review_another_period_without_duplicate_writes(self):
        self.query("UPDATE event_session SET end_date=datetime('now','-10 minutes') WHERE id=3")
        self.query("INSERT INTO attendance VALUES (1,1,3,datetime('now'))")
        token, body = self.review()
        self.assertIn('Asistencia ya registrada', body)
        self.assertNotIn('<select', body)
        self.assertRegex(body, r'<details class="qr-secondary" id="qr-other-session">')
        status, _, body = self.decide(token, 'review')
        self.assertEqual(status, 200, body)
        self.assertIn('<select', body)
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertNotIn('value="allow"', body)
        self.assertEqual(len(self.query('SELECT * FROM attendance')), 1)
        self.assertEqual(self.query('SELECT * FROM qr_admission_decision'), [])

    def test_outside_times_suggests_nearest_session_and_manual_confirmation_works(self):
        self.query("UPDATE event_session SET end_date=datetime('now', '-10 minutes'), start_date=datetime('now', '-50 minutes') WHERE id=3")
        token, body = self.review()
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertIn('fuera del horario orientativo', body)
        self.assertRegex(body, r'<option value="3" selected>')
        self.assertEqual(self.query('SELECT * FROM attendance'), [])
        self.assertEqual(self.decide(token, 'allow', notes='Ingreso autorizado')[0], 200)
        snapshot = json.loads(self.query('SELECT warnings_json FROM qr_admission_decision')[0][0])
        self.assertIn('outside_session_time', [w['code'] for w in snapshot['warnings']])
        self.assertEqual(snapshot['notes'], 'Ingreso autorizado')
        self.assertTrue(snapshot['staff_confirmed'])

    def test_selection_refreshes_review_without_writing_and_never_auto_posts(self):
        token, _ = self.review()
        status, _, body = self.decide(token, 'review', selected_session_id='1')
        self.assertEqual(status, 200, body)
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])
        self.assertEqual(self.query('SELECT * FROM qr_admission_decision'), [])
        next_token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1]
        self.assertEqual(self.decide(next_token, 'allow', selected_session_id='1')[0], 200)
        self.assertEqual(self.query('SELECT event_session_id FROM attendance'), [(1,)])

    def test_changed_dropdown_cannot_commit_an_unreviewed_session(self):
        token, _ = self.review()
        status, _, body = self.decide(token, 'allow', selected_session_id='1')
        self.assertEqual(status, 409)
        self.assertIn('todavía no se registró asistencia', body)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])
        next_token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1]
        self.assertEqual(self.decide(next_token, 'allow', selected_session_id='1')[0], 200)

    def test_other_event_and_forged_automatic_actions_cannot_bypass_review(self):
        token, _ = self.review()
        self.assertEqual(self.decide(token, 'review', selected_session_id='4')[0], 409)
        token, _ = self.review()
        self.assertEqual(self.decide(token, 'automatic')[0], 409)
        token, _ = self.review()
        self.decide(token, 'deny')
        token, body = self.review()
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertEqual(self.decide(token, 'automatic')[0], 409)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])

    def test_automatic_conditions_are_rechecked_and_failure_never_auto_retries(self):
        self.query("INSERT INTO attendance VALUES (1, 1, 1, datetime('now')), (2, 1, 2, datetime('now'))")
        token, body = self.review()
        self.assertIn('data-auto-checkin="1"', body)
        self.query("UPDATE event_session SET end_date=datetime('now', '-1 second') WHERE id=3")
        status, _, body = self.decide(token, 'automatic')
        self.assertEqual(status, 409)
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertEqual(self.query('SELECT * FROM attendance WHERE event_session_id=3'), [])

    def test_overlapping_sessions_require_review(self):
        self.query("INSERT INTO attendance VALUES (1, 1, 1, datetime('now')), (2, 1, 2, datetime('now'))")
        self.query("INSERT INTO event_session VALUES (5, 1, 'Solapada', datetime('now', '-30 minutes'), datetime('now', '+1 hour'))")
        token, body = self.review()
        self.assertIn('Varios períodos coinciden', body)
        self.assertNotIn('data-auto-checkin="1"', body)
        self.assertEqual(self.decide(token, 'allow')[0], 200)

    def test_changed_qr_event_rejects_selection_and_confirmation(self):
        token, _ = self.review()
        self.query("CREATE TABLE app_setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)")
        self.query("INSERT INTO app_setting VALUES ('qr_event_id', '2')")
        self.assertEqual(self.decide(token, 'review', selected_session_id='4')[0], 409)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])

    def test_concurrent_reviews_cannot_overwrite_a_new_decision(self):
        first, _ = self.review()
        second, _ = self.review()
        self.decide(first, 'deny')
        status, _, body = self.decide(second, 'allow')
        self.assertEqual(status, 409)
        self.assertIn('La información cambió', body)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])
        self.assertEqual(len(self.query('SELECT * FROM qr_admission_decision')), 1)

    def test_changed_prerequisites_and_session_rollover_require_fresh_review(self):
        token, _ = self.review()
        self.query("INSERT INTO attendance VALUES (1, 1, 1, datetime('now'))")
        self.assertEqual(self.decide(token, 'allow')[0], 409)
        token, _ = self.review()
        self.query("UPDATE event_session SET end_date = datetime('now', '-1 second') WHERE id=3")
        self.assertEqual(self.decide(token, 'allow')[0], 409)
        self.assertEqual(self.query('SELECT * FROM attendance WHERE event_session_id=3'), [])

    def test_csrf_and_forged_or_expired_decisions_fail(self):
        token, _ = self.review()
        self.assertEqual(self.request('/templates/attendance/log.php', {'review_token': token, 'decision': 'allow'})[0], 403)
        self.assertEqual(self.decide('forged', 'allow')[0], 409)
        self.assertEqual(self.decide(token, 'unexpected')[0], 409)
        token, _ = self.review()
        sid = next(c.value for c in self.jar if c.name == 'rinchen_session')
        path = self.sessions / ('sess_' + sid)
        path.write_text(re.sub(r'("expires";i:)\d+', r'\g<1>1', path.read_text()))
        self.assertEqual(self.decide(token, 'allow')[0], 409)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])

    def test_browser_cannot_change_bound_person_or_session(self):
        token, _ = self.review()
        self.assertEqual(self.decide(token, 'allow', person_id='999', event_session_id='4')[0], 200)
        self.assertEqual(self.query('SELECT person_id, event_session_id FROM attendance'), [(1, 3)])

    def test_audit_failure_rolls_back_attendance(self):
        token, _ = self.review()
        self.query("""CREATE TRIGGER fail_audit BEFORE INSERT ON qr_admission_decision
            BEGIN SELECT RAISE(ABORT, 'test failure'); END""")
        self.assertEqual(self.decide(token, 'allow')[0], 503)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])

    def test_denial_does_not_ban_future_sessions(self):
        token, _ = self.review()
        self.decide(token, 'deny')
        self.query("UPDATE event_session SET end_date = datetime('now', '-1 second') WHERE id=3")
        self.query("""INSERT INTO event_session VALUES
            (5, 1, 'Siguiente', datetime('now', '-1 second'), datetime('now', '+1 hour'))""")
        token, body = self.review()
        self.assertNotIn('Participación denegada para esta sesión', body)
        self.assertEqual(self.decide(token, 'allow')[0], 200)
        self.assertEqual(self.query('SELECT event_session_id FROM attendance'), [(5,)])

    def test_missing_migration_fails_closed(self):
        self.query('DROP TABLE qr_admission_decision')
        self.assertEqual(self.request(QR)[0], 503)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])

    def test_subdirectory_and_issued_qr_redirect(self):
        self.jar.clear()
        self.login('/event-retiro')
        old = '/event-retiro/attendance_log.php/?' + QR.split('?', 1)[1].replace('%2B', '+')
        status, headers, _ = self.request(old)
        self.assertEqual(status, 302)
        self.assertEqual(self.request(headers['Location'])[0], 200)
        token, _ = self.review('/event-retiro')
        self.assertEqual(self.decide(token, 'deny', '/event-retiro')[0], 200)
        self.assertEqual(self.query('SELECT decision FROM qr_admission_decision', '/event-retiro'), [('deny',)])


if __name__ == '__main__':
    unittest.main(verbosity=2)

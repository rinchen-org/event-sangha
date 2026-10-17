"""Authenticated web setup tests; only temporary copies and SQLite fixtures."""
from contextlib import closing
import re
import shutil
import sqlite3
import subprocess
import unittest

import test_auth


class EventSetupTest(unittest.TestCase):
    request = test_auth.AuthHTTPTest.request
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf

    @classmethod
    def setUpClass(cls):
        if subprocess.run(['php', '-r', 'exit(class_exists("SQLite3") ? 0 : 1);']).returncode:
            raise RuntimeError('Setup tests require the external PHP sqlite3 extension.')
        test_auth.AuthHTTPTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        test_auth.AuthHTTPTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_auth.AuthHTTPTest.setUp(self)
        for base in ('', 'event-retiro'):
            database = self.web / base / 'db.sqlite'
            if database.exists():
                database.unlink()
            backup = self.web / base / '.backups'
            if backup.exists():
                shutil.rmtree(backup)
        self.values = dict(name='Retiro <noviembre>', description='Encuentro', year='2026',
                           morning_start='09:00', morning_end='12:00',
                           afternoon_start='14:00', afternoon_end='18:00', event_id='', qr_event='1')
        self.login()

    def query(self, sql, base='', path=None):
        path = path or self.web / base.lstrip('/') / 'db.sqlite'
        with closing(sqlite3.connect(path)) as db:
            result = db.execute(sql).fetchall()
            db.commit()
            return result

    def form(self, base=''):
        status, _, body = self.request(base + '/scripts/setup.php')
        self.assertEqual(status, 200, body)
        token = re.search(r'name="setup_token" value="([a-f0-9]+)"', body)[1]
        return token, body

    def save(self, base='', token=None, **values):
        token = token or self.form(base)[0]
        return self.request(base + '/scripts/setup.php', dict(self.values, **values,
            csrf_token=self.csrf(base), setup_token=token))

    def test_get_does_not_create_database_and_auth_is_required(self):
        self.form()
        self.assertFalse((self.web / 'db.sqlite').exists())
        self.jar.clear()
        status, headers, _ = self.request('/scripts/setup.php')
        self.assertEqual(status, 303)
        self.assertIn('scripts%2Fsetup.php', headers['Location'])
        self.assertFalse((self.web / 'db.sqlite').exists())

    def test_csrf_and_invalid_schedule_cannot_write(self):
        token, _ = self.form()
        self.assertEqual(self.request('/scripts/setup.php', dict(self.values, setup_token=token))[0], 403)
        self.assertEqual(self.save(morning_end='08:00')[0], 409)
        self.assertEqual(self.save(year='no-year')[0], 409)
        self.assertFalse((self.web / 'db.sqlite').exists())

    def test_fresh_database_has_six_sessions_utc_and_no_seed_people(self):
        status, _, body = self.save()
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT count(*) FROM event'), [(1,)])
        self.assertEqual(self.query('SELECT count(*) FROM event_session'), [(6,)])
        sessions = self.query('SELECT start_date, end_date FROM event_session ORDER BY start_date')
        self.assertEqual(sessions[0], ('2026-11-12 13:00:00', '2026-11-12 16:00:00'))
        self.assertEqual([start for start, end in sessions], [
            '2026-11-12 13:00:00', '2026-11-12 18:00:00',
            '2026-11-13 18:00:00', '2026-11-14 18:00:00',
            '2026-11-15 13:00:00', '2026-11-15 18:00:00',
        ])
        self.assertEqual(sessions[-1], ('2026-11-15 18:00:00', '2026-11-15 22:00:00'))
        for table in ('person', 'subscription', 'attendance', 'qr_admission_decision'):
            self.assertEqual(self.query(f'SELECT count(*) FROM {table}'), [(0,)])
        self.assertEqual(self.query("SELECT value FROM app_setting WHERE name='qr_event_id'"), [('1',)])

    def test_replay_rejected_and_repeat_updates_preserve_ids(self):
        token, _ = self.form()
        self.save(token=token)
        ids = self.query('SELECT id FROM event_session ORDER BY id')
        self.assertEqual(self.save(token=token)[0], 409)
        self.assertEqual(self.save(name='Actualizado', morning_start='08:00')[0], 200)
        self.assertEqual(self.query('SELECT id FROM event_session ORDER BY id'), ids)
        self.assertEqual(self.query('SELECT name FROM event'), [('Actualizado',)])
        self.assertEqual(self.query('SELECT start_date FROM event_session ORDER BY id LIMIT 1'), [('2026-11-12 12:00:00',)])

    def test_backup_and_update_preserve_attendance_and_decisions(self):
        self.save()
        self.query("INSERT INTO person VALUES (1, 'Ana', 'a@test', '123', 1)")
        self.query("INSERT INTO attendance VALUES (1, 1, 1, '2026-11-12 13:01:00')")
        self.query("INSERT INTO qr_admission_decision VALUES (1, 1, 1, 'allow', '[]', 'admin', '2026-11-12 13:01:00')")
        self.assertEqual(self.save(name='Cambio')[0], 200)
        self.assertEqual(self.query('SELECT count(*) FROM attendance'), [(1,)])
        self.assertEqual(self.query('SELECT count(*) FROM qr_admission_decision'), [(1,)])
        snapshots = list((self.web / '.backups').glob('*.sqlite'))
        self.assertEqual(len(snapshots), 1)
        self.assertEqual(self.query('SELECT name FROM event', path=snapshots[0]), [('Retiro <noviembre>',)])
        self.assertEqual(self.query('SELECT count(*) FROM attendance', path=snapshots[0]), [(1,)])
        self.assertEqual(snapshots[0].stat().st_mode & 0o777, 0o600)
        self.assertIn(self.request('/.backups/' + snapshots[0].name)[0], (403, 404))

    def test_existing_event_selection_adopts_matching_sessions(self):
        self.save()
        self.query("INSERT INTO event VALUES (20, 'Existente', '', '2027-11-12', '2027-11-15')")
        self.query("INSERT INTO event_session VALUES (50, 20, 'Mañana', '2027-11-12 13:00:00', '2027-11-12 16:00:00')")
        self.assertEqual(self.save(year='2027', event_id='20')[0], 200)
        self.assertEqual(self.query('SELECT count(*) FROM event'), [(2,)])
        self.assertEqual(self.query('SELECT count(*) FROM event_session WHERE event_id=20'), [(6,)])
        self.assertEqual(self.query("SELECT session_id FROM setup_session WHERE event_id=20 AND slot='11-12-am'"), [(50,)])

    def test_qr_uses_selected_event_not_hardcoded_one(self):
        self.save()
        self.save(year='2027')
        self.query("INSERT INTO person VALUES (1, 'Ana', 'a@test', '123', 1)")
        self.query("UPDATE event_session SET start_date=datetime('now','-1 hour'), end_date=datetime('now','+1 hour') WHERE id=7")
        status, _, body = self.request('/templates/attendance/log.php?fullname=Ana&email=a%40test&phone=123')
        self.assertEqual(status, 200, body)
        self.assertIn('Permitir participación', body)
        # Deselecting QR update retains the chosen second event.
        self.save(year='2026', qr_event='0')
        self.assertEqual(self.query("SELECT value FROM app_setting WHERE name='qr_event_id'"), [('2',)])

    def test_old_attendance_schema_is_not_destroyed(self):
        self.query('CREATE TABLE attendance (id INTEGER PRIMARY KEY, person_id INTEGER, datetime TEXT)')
        self.query("INSERT INTO attendance VALUES (1, 5, '2023-11-24')")
        status, _, body = self.save()
        self.assertEqual(status, 409)
        self.assertIn('esquema antiguo', body)
        self.assertEqual(self.query('SELECT * FROM attendance'), [(1, 5, '2023-11-24')])
        self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='event'"), [])

    def test_subdirectory_route_and_escaped_values(self):
        self.jar.clear()
        self.login('/event-retiro')
        status, _, body = self.save('/event-retiro')
        self.assertEqual(status, 200, body)
        self.assertIn('Retiro &lt;noviembre&gt;', body)
        self.assertEqual(self.query('SELECT count(*) FROM event_session', '/event-retiro'), [(6,)])

    def test_transaction_rolls_back_on_session_write_failure(self):
        self.save()
        before = self.query('SELECT * FROM event')
        self.query("""CREATE TRIGGER fail_session BEFORE UPDATE ON event_session
            BEGIN SELECT RAISE(ABORT, 'test failure'); END""")
        self.assertEqual(self.save(name='Must roll back')[0], 503)
        self.assertEqual(self.query('SELECT * FROM event'), before)

    def add_old_morning_slots(self):
        self.query("""INSERT INTO event_session VALUES
            (30, 1, '13 mañana', '2026-11-13 13:00:00', '2026-11-13 16:00:00'),
            (40, 1, '14 mañana', '2026-11-14 13:00:00', '2026-11-14 16:00:00')""")
        self.query("INSERT INTO setup_session VALUES (1, '11-13-am', 30), (1, '11-14-am', 40)")

    def test_previous_eight_session_setup_is_reconciled_without_touching_other_sessions(self):
        self.save()
        original_ids = self.query('SELECT id FROM event_session ORDER BY id')
        self.add_old_morning_slots()
        self.query("INSERT INTO event_session VALUES (99, 1, 'Otra', '2026-11-13 10:00:00', '2026-11-13 11:00:00')")
        self.assertEqual(self.save()[0], 200)
        self.assertEqual(self.query('SELECT id FROM event_session ORDER BY id'), original_ids + [(99,)])
        self.assertEqual(self.query('SELECT count(*) FROM setup_session'), [(6,)])

    def test_old_morning_history_blocks_cleanup_and_rolls_back_whole_update(self):
        self.save()
        self.add_old_morning_slots()
        self.query("INSERT INTO person VALUES (1, 'Ana', 'a@test', '123', 1)")
        self.query("INSERT INTO attendance VALUES (1, 1, 40, '2026-11-14 13:01:00')")
        self.assertEqual(self.save(name='Must not change')[0], 409)
        self.assertEqual(self.query('SELECT name FROM event'), [('Retiro <noviembre>',)])
        self.assertEqual(self.query('SELECT count(*) FROM event_session'), [(8,)])
        self.query('DELETE FROM attendance')
        self.query("INSERT INTO qr_admission_decision VALUES (1, 1, 30, 'deny', '[]', 'admin', '2026-11-13 13:01:00')")
        self.assertEqual(self.save()[0], 409)
        self.assertEqual(self.query('SELECT count(*) FROM qr_admission_decision'), [(1,)])
        self.assertEqual(self.query('SELECT count(*) FROM event_session'), [(8,)])

    def test_new_year_creates_separate_event_without_overwriting_history(self):
        self.save()
        self.assertEqual(self.save(year='2027')[0], 200)
        self.assertEqual(self.query('SELECT count(*) FROM event'), [(2,)])
        self.assertEqual(self.query('SELECT count(*) FROM event_session'), [(12,)])
        self.assertEqual(self.query('SELECT start_date FROM event WHERE id=1'), [('2026-11-12 13:00:00',)])


if __name__ == '__main__':
    unittest.main(verbosity=2)

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

    def preparation(self, base='', selection=''):
        path = base + '/scripts/setup.php?mode=prepare2026'
        if selection:
            path += '&event_id=' + selection
        status, _, body = self.request(path)
        self.assertEqual(status, 200, body)
        token = re.search(r'name="prepare_token" value="([a-f0-9]+)"', body)
        return token[1] if token else None, body

    def prepare(self, token, base='', **extra):
        return self.request(base + '/scripts/setup.php?mode=prepare2026', dict(
            csrf_token=self.csrf(base), prepare_token=token, **extra))

    def dump(self):
        with closing(sqlite3.connect(self.web / 'db.sqlite')) as db:
            return '\n'.join(db.iterdump())

    def test_preparation_review_and_cancel_do_not_create_database(self):
        token, body = self.preparation()
        self.assertTrue(token)
        self.assertIn('Pasos pendientes', body)
        self.assertIn('12/11/2026 09:00', body)
        self.assertIn('Cancelar y volver', body)
        status, _, menu = self.request('/templates/index.php?legacy=1')
        self.assertEqual(status, 200)
        self.assertIn('scripts/setup.php?mode=prepare2026', menu)
        self.assertFalse((self.web / 'db.sqlite').exists())
        self.assertFalse((self.web / '.backups').exists())

    def test_preparation_first_run_and_repeat_are_noops_when_ready(self):
        first, _ = self.preparation()
        second, _ = self.preparation()
        status, _, body = self.prepare(first)
        self.assertEqual(status, 200, body)
        self.assertIn('Preparación completada', body)
        self.assertEqual(self.query('SELECT count(*) FROM event_session'), [(6,)])
        self.assertEqual(self.query('SELECT * FROM setup_event'), [('november-2026', 1)])
        self.assertEqual(self.query('SELECT count(*) FROM setup_session'), [(6,)])
        for table in ('person', 'subscription', 'attendance', 'qr_admission_decision', 'app_setting'):
            self.assertEqual(self.query(f'SELECT count(*) FROM {table}'), [(0,)])
        before = (self.web / 'db.sqlite').read_bytes()
        token, body = self.preparation()
        self.assertIsNone(token)
        self.assertIn('Todo está listo', body)
        self.assertEqual(self.prepare(second)[0], 200)  # another reviewed request now has nothing to do
        self.assertEqual(self.prepare(first)[0], 409)  # single-use token still rejects replay
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertFalse((self.web / '.backups').exists())

    def test_preparation_preserves_staff_edits_history_extra_sessions_and_qr(self):
        self.save(name='Personalizado', morning_start='08:00')
        self.add_old_morning_slots()
        self.query("UPDATE event_session SET name='Nombre editado' WHERE id=1")
        self.query("INSERT INTO person VALUES (1, 'Ana', 'a@test', '123', 1)")
        self.query("INSERT INTO attendance VALUES (1, 1, 1, '2026-11-12 12:01:00')")
        self.query("INSERT INTO qr_admission_decision VALUES (1, 1, 30, 'deny', '[]', 'admin', '2026-11-13 13:01:00')")
        self.query("INSERT INTO event VALUES (20, 'Otro QR', '', '2026-01-01', '2026-12-31')")
        self.query("UPDATE app_setting SET value='20' WHERE name='qr_event_id'")
        before = self.dump()
        token, body = self.preparation()
        self.assertIsNone(token)
        self.assertIn('Nombre editado', body)
        self.assertIn('12/11/2026 08:00', body)
        self.assertIn('evento #20', body)
        self.assertEqual(self.dump(), before)
        self.assertFalse((self.web / '.backups').exists())

    def test_preparation_applies_only_missing_schema_and_backs_up(self):
        self.save(name='No cambiar', morning_start='08:00')
        self.query('DROP TABLE qr_admission_decision')
        self.query('ALTER TABLE subscription DROP COLUMN active')
        self.query("CREATE TRIGGER no_event_update BEFORE UPDATE ON event BEGIN SELECT RAISE(ABORT, 'must skip'); END")
        self.query("CREATE TRIGGER no_session_update BEFORE UPDATE ON event_session BEGIN SELECT RAISE(ABORT, 'must skip'); END")
        event, sessions = self.query('SELECT * FROM event'), self.query('SELECT * FROM event_session')
        token, body = self.preparation()
        self.assertIn('Crear tabla qr_admission_decision', body)
        self.assertIn('Evento 2026 ya preparado', body)
        status, _, body = self.prepare(token)
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT * FROM event'), event)
        self.assertEqual(self.query('SELECT * FROM event_session'), sessions)
        self.assertEqual(self.query('SELECT count(*) FROM qr_admission_decision'), [(0,)])
        backups = list((self.web / '.backups').glob('*.sqlite'))
        self.assertEqual(len(backups), 1)
        self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='qr_admission_decision'", path=backups[0]), [])
        self.assertEqual(backups[0].stat().st_mode & 0o777, 0o600)
        self.assertIn(self.request('/.backups/' + backups[0].name)[0], (403, 404))
        self.assertIsNone(self.preparation()[0])
        self.assertEqual(len(list((self.web / '.backups').glob('*.sqlite'))), 1)

    def test_preparation_fills_missing_session_and_adopts_without_updates(self):
        self.save()
        self.query("DELETE FROM setup_session WHERE slot IN ('11-12-am', '11-15-pm')")
        self.query('DELETE FROM event_session WHERE id=6')
        self.query("UPDATE event_session SET name='Conservar' WHERE id=1")
        preserved = self.query('SELECT * FROM event_session ORDER BY id')
        token, body = self.preparation()
        self.assertIn('Vincular sin modificar', body)
        status, _, body = self.prepare(token)
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT * FROM event_session WHERE id <= 5 ORDER BY id'), preserved)
        self.assertEqual(self.query('SELECT count(*) FROM event_session'), [(6,)])
        self.assertEqual(self.query('SELECT count(*) FROM setup_session'), [(6,)])

    def test_preparation_requires_explicit_adoption_and_ignores_posted_ids(self):
        self.save(name='Evento manual')
        self.query('DELETE FROM setup_event')
        self.query('DELETE FROM setup_session')
        self.query("INSERT INTO event VALUES (20, 'Otro', '', '2026-01-01', '2026-12-31')")
        token, body = self.preparation()
        self.assertIsNone(token)
        self.assertIn('Selecciona uno explícitamente', body)
        before = self.query('SELECT * FROM event_session')
        token, _ = self.preparation(selection='1')
        status, _, body = self.prepare(token, event_id='20', year='2027', schema_complete='1')
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT * FROM setup_event'), [('november-2026', 1)])
        self.assertEqual(self.query('SELECT * FROM event_session'), before)
        self.assertEqual(self.query('SELECT name FROM event WHERE id=1'), [('Evento manual',)])

    def test_preparation_explicit_new_event_keeps_other_year_and_qr(self):
        self.save(year='2027')
        token, _ = self.preparation(selection='new')
        status, _, body = self.prepare(token)
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT count(*) FROM event'), [(2,)])
        self.assertEqual(self.query('SELECT count(*) FROM event_session'), [(12,)])
        self.assertEqual(self.query("SELECT value FROM app_setting WHERE name='qr_event_id'"), [('1',)])

    def test_preparation_stale_review_fails_before_backup_or_writes(self):
        token, _ = self.preparation()
        self.save()
        self.query('DROP INDEX qr_admission_lookup')
        before = self.dump()
        status, _, body = self.prepare(token)
        self.assertEqual(status, 409, body)
        self.assertIn('cambió desde la revisión', body)
        self.assertEqual(self.dump(), before)
        self.assertFalse((self.web / '.backups').exists())
        token, body = self.preparation()
        self.assertIn('Crear índice qr_admission_lookup', body)
        self.assertEqual(self.prepare(token)[0], 200)

    def test_preparation_security_expiry_and_method_checks(self):
        path = '/scripts/setup.php?mode=prepare2026'
        token, _ = self.preparation()
        self.assertEqual(self.request(path, {'prepare_token': token})[0], 403)
        self.assertEqual(self.request(path, method='PUT')[0], 405)
        for file in self.sessions.glob('sess_*'):
            file.write_text(re.sub(r'"expires";i:\d+', '"expires";i:1', file.read_text()))
        self.assertEqual(self.prepare(token)[0], 409)
        self.jar.clear()
        self.assertEqual(self.request(path)[0], 303)
        self.assertEqual(self.request('/templates/event/prepare-2026.php')[0], 303)
        self.assertFalse((self.web / 'db.sqlite').exists())

    def test_preparation_subdirectory_and_invalid_schema(self):
        self.jar.clear()
        self.login('/event-retiro')
        token, body = self.preparation('/event-retiro')
        self.assertIn('action="/event-retiro/scripts/setup.php?mode=prepare2026"', body)
        status, _, body = self.prepare(token, '/event-retiro')
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT count(*) FROM event_session', '/event-retiro'), [(6,)])
        self.login()
        self.query('CREATE TABLE attendance (id INTEGER PRIMARY KEY, person_id INTEGER, datetime TEXT)')
        before = self.dump()
        self.assertEqual(self.request('/scripts/setup.php?mode=prepare2026')[0], 409)
        self.assertEqual(self.dump(), before)

    def test_preparation_refuses_broken_links_and_unrecognized_sessions(self):
        self.save(morning_start='08:00')
        self.query('DELETE FROM setup_session WHERE session_id=1')
        before = self.dump()
        status, _, body = self.request('/scripts/setup.php?mode=prepare2026')
        self.assertEqual(status, 409)
        self.assertIn('horarios distintos', body)
        self.assertEqual(self.dump(), before)
        self.query("INSERT INTO setup_session VALUES (1, '11-12-am', 999)")
        self.assertEqual(self.request('/scripts/setup.php?mode=prepare2026')[0], 409)

    def test_preparation_failure_rolls_back_schema_and_event_writes(self):
        self.save()
        self.query("DELETE FROM setup_session WHERE slot='11-15-pm'")
        self.query('DELETE FROM event_session WHERE id=6')
        self.query('DROP TABLE qr_admission_decision')
        self.query("CREATE TRIGGER fail_insert BEFORE INSERT ON event_session BEGIN SELECT RAISE(ABORT, 'test failure'); END")
        before = self.dump()
        token, _ = self.preparation()
        status, _, body = self.prepare(token)
        self.assertEqual(status, 503, body)
        self.assertNotIn('Preparación completada', body)
        self.assertEqual(self.dump(), before)

    def test_preparation_backup_failure_does_not_migrate(self):
        self.save()
        self.query('DROP INDEX qr_admission_lookup')
        backup = self.web / '.backups'
        backup.mkdir(mode=0o755)
        backup.chmod(0o755)
        before = self.dump()
        token, _ = self.preparation()
        status, _, body = self.prepare(token)
        self.assertEqual(status, 503, body)
        self.assertNotIn('Preparación completada', body)
        self.assertEqual(self.dump(), before)


if __name__ == '__main__':
    unittest.main(verbosity=2)

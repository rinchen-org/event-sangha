"""Event membership tests: synthetic identities, temporary code/data, fake QR/mail only."""
from contextlib import closing
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import tempfile
import unittest

import test_auth
import test_csv_import
import test_lab

ROOT = test_auth.ROOT


class EventRegistrationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='rinchen-event-domain-')
        self.addCleanup(self.temp.cleanup)
        self.web = Path(self.temp.name) / 'src'
        shutil.copytree(ROOT / 'src/lib', self.web / 'lib')
        (self.web / '.env').write_text('HOST_ADDRESS=http://localhost\nSEND_EMAIL=1\nEMAIL_FROM=sender@example.test\nEMAIL_REPLY_TO=reply@example.test\nEMAIL_CC=\n')
        (self.web / 'lib/qr.php').write_text('''<?php
function generate_qr(string $fullname, string $email, string $phone): string {
    $GLOBALS['qr_calls']++;
    if ($GLOBALS['qr_fail']) { throw new RuntimeException('Synthetic QR failure'); }
    return 'http://localhost/static/qr/' . hash('sha256', $email) . '.jpg';
}
''')
        (self.web / 'lib/email.php').write_text('''<?php
function send_email(...$args): bool { $GLOBALS['mail_calls']++; return !$GLOBALS['mail_fail']; }
''')

    def check(self, code):
        prefix = '''
require getenv('EVENT_TEST_WEB') . '/lib/subscription.php';
$db = new SQLite3(':memory:'); $db->enableExceptions(true); $db->exec('PRAGMA foreign_keys=ON');
create_setup_schema($db);
$db->exec("INSERT INTO event VALUES (1,'Event A','','2026-11-01','2026-11-02'),(2,'Event B','','2026-12-01','2026-12-02');
    INSERT INTO person VALUES (50,'Existing Person','existing@example.test','12345',1),(60,'Inactive Person','inactive@example.test','23456',0);
    INSERT INTO subscription VALUES (10,50,'2026-01-01','KEEP-QR',1),(20,60,'2026-01-01','INACTIVE-QR',0);
    INSERT INTO event_session VALUES (100,1,'Session A','2026-11-01','2026-11-02'),(200,2,'Session B','2026-12-01','2026-12-02');
    INSERT INTO attendance VALUES (1,50,100,'2026-11-01'),(2,50,200,'2026-12-01');
    INSERT INTO app_setting VALUES ('qr_event_id','2');");
$r = new EventRegistration($db); $import = new SubscriptionCsvImport($db);
$GLOBALS['qr_calls']=0; $GLOBALS['mail_calls']=0; $GLOBALS['qr_fail']=false; $GLOBALS['mail_fail']=false;
function check($ok, $message='Assertion failed') { if (!$ok) throw new RuntimeException($message); }
function fails($callback) { try { $callback(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected failure'); }
function csvReview($event, $csv=null) {
    global $r;
    return $r->scopeCsv(SubscriptionCsv::readContents($csv ?? "No.,NOMBRE,APELLIDOS,CORREO ELECTRONICO,No. CELULAR,CIUDAD\\n1,New,Person,new@example.test,+59134567,La Paz\\n"),$event);
}
'''
        result = subprocess.run(['php', '-d', 'disable_functions=mail,curl_init,curl_exec', '-r', prefix + code + '\necho "OK";'],
            env={**os.environ, 'EVENT_TEST_WEB': str(self.web)}, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stdout, 'OK')
        self.assertFalse((self.web / 'db.sqlite').exists())

    def test_schema_is_additive_repeatable_and_does_not_assign_old_people(self):
        self.check('''
$db->exec('DROP TABLE event_registration; DROP INDEX subscription_id_person_unique');
check($r->detail(1)['subscriptions']===[]);
check(!$r->hasSchema());
create_event_registration_schema($db); create_event_registration_schema($db);
check((int)$db->querySingle('SELECT count(*) FROM event_registration')===0);
check((int)$db->querySingle('SELECT count(*) FROM subscription')===2);
check($db->querySingle('SELECT qr FROM subscription WHERE id=10')==='KEEP-QR');
check($db->querySingle("SELECT value FROM app_setting WHERE name='qr_event_id'")==='2');
''')

    def test_link_reuses_subscription_and_is_idempotent_per_event(self):
        self.check('''
$review=$r->reviewExisting(1,10);
check($r->confirmExisting($review,'admin','explicit'));
check(!$r->confirmExisting($review,'admin','repeat'));
check($r->confirmExisting($r->reviewExisting(2,10),'admin','second event'));
check(count($r->detail(1)['subscriptions'])===1 && count($r->detail(2)['subscriptions'])===1);
check($r->detail(1)['subscriptions'][0]['person_id']===50);
$audit=$r->rows('SELECT * FROM event_registration WHERE event_id=1')[0];
check($audit['actor']==='admin' && $audit['staff_confirmed']===1 && $audit['notes']==='explicit');
check($GLOBALS['qr_calls']===0 && $GLOBALS['mail_calls']===0);
''')

    def test_inactive_warning_is_reviewed_audited_and_not_a_gate(self):
        self.check('''
$review=$r->reviewExisting(1,20); check($review['warnings'][0]['code']==='inactive_registration');
check($r->confirmExisting($review,'admin','continue'));
check(str_contains($db->querySingle('SELECT warnings_json FROM event_registration'),'inactive_registration'));
check((int)$db->querySingle('SELECT active FROM subscription WHERE id=20')===0);
check((int)$db->querySingle('SELECT active FROM person WHERE id=60')===0);
''')

    def test_stale_review_and_invalid_identifiers_fail_without_writes(self):
        self.check('''
$review=$r->reviewExisting(1,10); $db->exec('UPDATE subscription SET active=0 WHERE id=10');
fails(fn()=>$r->confirmExisting($review,'admin',''));
$review=$r->reviewExisting(1,10); $db->exec("UPDATE event SET name='Changed' WHERE id=1");
fails(fn()=>$r->confirmExisting($review,'admin',''));
fails(fn()=>$r->reviewExisting(999,10)); fails(fn()=>$r->reviewExisting(1,999));
foreach (['0','01','-1','1 OR 1=1',[],null] as $id) { fails(fn()=>EventRegistration::id($id)); }
check((int)$db->querySingle('SELECT count(*) FROM event_registration')===0);
''')

    def test_relational_integrity_and_one_membership_per_person(self):
        self.check('''
$r->confirmExisting($r->reviewExisting(1,10),'admin','');
$db->exec("INSERT INTO subscription VALUES (11,50,'2026-01-02','OTHER-QR',1)");
fails(fn()=>$r->confirmExisting($r->reviewExisting(1,11),'admin',''));
fails(fn()=>$r->insert(2,10,60,'admin','staff',[]));
fails(fn()=>$r->insert(999,10,50,'admin','staff',[]));
fails(fn()=>$db->exec('DELETE FROM subscription WHERE id=10'));
check((int)$db->querySingle('SELECT count(*) FROM event_registration')===1);
''')

    def test_detail_filters_by_ids_and_keeps_unlinked_historical_attendance(self):
        self.check('''
$db->exec("UPDATE event SET name='Same name'");
$r->confirmExisting($r->reviewExisting(2,10),'admin','');
$a=$r->detail(1); $b=$r->detail(2);
check($a['subscriptions']===[] && count($b['subscriptions'])===1);
check(array_column($a['sessions'],'id')===[100] && array_column($b['sessions'],'id')===[200]);
check(array_column($a['attendance'],'id')===[1] && array_column($b['attendance'],'id')===[2]);
''')

    def test_scoped_csv_creates_then_reuses_people_across_events_without_mail_duplication(self):
        self.check('''
$first=$import->run(csvReview(1),true,'admin','first',1);
check($first['imported']===1 && $first['linked']===0 && $first['errors']===[]);
$repeat=$import->run(csvReview(1),true,'admin','repeat',1); check($repeat['duplicates']===1);
$second=$import->run(csvReview(2),true,'admin','second',2); check($second['linked']===1 && $second['imported']===0);
check($GLOBALS['qr_calls']===1 && $GLOBALS['mail_calls']===1);
check((int)$db->querySingle('SELECT count(*) FROM person')===3);
check((int)$db->querySingle('SELECT count(*) FROM event_registration')===2);
check($db->querySingle("SELECT value FROM app_setting WHERE name='qr_event_id'")==='2');
''')

    def test_csv_duplicate_rows_and_existing_unassigned_registration(self):
        self.check('''
$csv="No.,NOMBRE,APELLIDOS,CORREO ELECTRONICO,No. CELULAR,CIUDAD\\n1,Existing,Person,existing@example.test,12345,La Paz\\n1,Existing,Person,existing@example.test,12345,La Paz\\n";
$report=$import->run(csvReview(1,$csv),true,'admin','',1);
check($report['linked']===1 && $report['duplicates']===1);
check($GLOBALS['qr_calls']===0 && $GLOBALS['mail_calls']===0);
check($db->querySingle('SELECT qr FROM subscription WHERE id=10')==='KEEP-QR');
''')

    def test_csv_warning_confirmation_stale_state_and_ambiguity(self):
        self.check('''
$csv="Nombre,Apellidos,Correo electrónico,Número de celular,valido\\nInactive,Person,inactive@example.test,23456,0\\n";
$review=csvReview(1,$csv); check(count($review['rows'][0]['warnings'])===2);
fails(fn()=>$import->run($review,false,'admin','',1));
$db->exec('UPDATE subscription SET active=1 WHERE id=20');
$stale=$import->run($review,true,'admin','',1); check(count($stale['errors'])===1 && !$stale['linked']);
$report=$import->run(csvReview(1,$csv),true,'admin','confirmed',1); check($report['linked']===1);
$audit=json_decode($db->querySingle('SELECT warnings_json FROM event_registration'),true);
check(array_column($audit,'code')===['payment_unverified','inactive_registration']);
$db->exec("INSERT INTO person VALUES (70,'New Person','new@example.test','+59134567',1),(71,'New Person','new@example.test','+59134567',1)");
check(count(csvReview(2)['errors'])===1 && csvReview(2)['rows']===[]);
$db->exec("INSERT INTO subscription VALUES (11,50,'2026-01-02','OTHER-QR',1)");
$existing="No.,NOMBRE,APELLIDOS,CORREO ELECTRONICO,No. CELULAR,CIUDAD\\n1,Existing,Person,existing@example.test,12345,La Paz\\n";
check(count(csvReview(2,$existing)['errors'])===1);
''')

    def test_csv_database_failure_rolls_back_person_subscription_and_membership(self):
        self.check('''
$db->exec("CREATE TRIGGER fail_membership BEFORE INSERT ON event_registration BEGIN SELECT RAISE(ABORT,'synthetic'); END");
$report=$import->run(csvReview(1),true,'admin','',1);
check(count($report['errors'])===1 && $report['imported']===0);
check((int)$db->querySingle('SELECT count(*) FROM person')===2);
check((int)$db->querySingle('SELECT count(*) FROM subscription')===2);
check((int)$db->querySingle('SELECT count(*) FROM event_registration')===0 && $GLOBALS['mail_calls']===0);
''')

    def test_mail_failure_is_saved_membership_not_a_retry_and_qr_failure_saves_nothing(self):
        self.check('''
$GLOBALS['qr_fail']=true; $failed=$import->run(csvReview(1),true,'admin','',1);
check(count($failed['errors'])===1 && (int)$db->querySingle('SELECT count(*) FROM person')===2);
$GLOBALS['qr_fail']=false; $GLOBALS['mail_fail']=true;
$saved=$import->run(csvReview(1),true,'admin','',1);
check($saved['imported']===1 && count($saved['mail_errors'])===1);
check((int)$db->querySingle('SELECT count(*) FROM event_registration')===1);
$repeat=$import->run(csvReview(1),true,'admin','',1); check($repeat['duplicates']===1 && $GLOBALS['mail_calls']===1);
''')

    def test_general_import_remains_unassigned_and_unrelated_records_unchanged(self):
        self.check('''
$review=SubscriptionCsv::readContents("No.,NOMBRE,APELLIDOS,CORREO ELECTRONICO,No. CELULAR,CIUDAD\\n1,New,Person,new@example.test,+59134567,La Paz\\n");
check($import->run($review,true,'admin')['imported']===1);
check((int)$db->querySingle('SELECT count(*) FROM event_registration')===0);
check((int)$db->querySingle('SELECT count(*) FROM attendance')===2);
''')


class EventRegistrationRoutesTest(unittest.TestCase):
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf
    request = test_lab.LabTest.request
    query = test_csv_import.CsvImportHTTPTest.query
    upload = test_csv_import.CsvImportHTTPTest.upload

    @classmethod
    def setUpClass(cls):
        # The explicit CLI option exercises controllers, not real HTTP/cookie/upload headers.
        test_lab.LabTest.setUpClass.__func__(cls)
        for base in ('', 'event-retiro'):
            (cls.web / base / 'lib/qr.php').write_text('<?php function generate_qr(...$args): string { throw new RuntimeException("QR must not be called by link tests"); }')

    @classmethod
    def tearDownClass(cls):
        test_lab.LabTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_auth.AuthHTTPTest.setUp(self)
        for base in ('', 'event-retiro'):
            database = self.web / base / 'db.sqlite'
            database.unlink(missing_ok=True)
            with closing(sqlite3.connect(database)) as db:
                db.executescript('''
CREATE TABLE person (id INTEGER PRIMARY KEY,fullname TEXT,email TEXT,phone TEXT,active INTEGER);
CREATE TABLE subscription (id INTEGER PRIMARY KEY,person_id INTEGER,datetime TEXT,qr TEXT,active INTEGER);
CREATE TABLE event (id INTEGER PRIMARY KEY,name TEXT,description TEXT,start_date TEXT,end_date TEXT);
CREATE TABLE event_session (id INTEGER PRIMARY KEY,event_id INTEGER,name TEXT,start_date TEXT,end_date TEXT);
CREATE TABLE attendance (id INTEGER PRIMARY KEY,person_id INTEGER,event_session_id INTEGER,log_time TEXT);
INSERT INTO person VALUES (50,'Existing Test','existing@example.test','12345',1);
INSERT INTO subscription VALUES (10,50,'2026-01-01','UNCHANGED QR',1);
INSERT INTO event VALUES (1,'First <test>','','2026-11-01','2026-11-02'),(2,'Second','','2026-12-01','2026-12-02');
INSERT INTO event_session VALUES (100,1,'First session','2026-11-01','2026-11-02'),(200,2,'Second session','2026-12-01','2026-12-02');
INSERT INTO attendance VALUES (1,50,100,'2026-11-01'),(2,50,200,'2026-12-01');
''')
            (self.web / base / '.env').write_text('HOST_ADDRESS=http://localhost\nSEND_EMAIL=0\n')
        self.login()

    def review(self, event='1', base=''):
        status, _, body = self.request(base + '/templates/event/register.php', {
            'csrf_token': self.csrf(base), 'event_id': event, 'subscription_id': '10', 'action': 'review',
        })
        self.assertEqual(status, 200, body)
        return re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1], body

    def confirm(self, token, event='1', base='', action='confirm'):
        return self.request(base + '/templates/event/register.php', {
            'csrf_token': self.csrf(base), 'event_id': event, 'review_token': token, 'action': action,
        })

    def csv_review(self, event='1'):
        contents = test_csv_import.csv_text([['1','Existing','Test','existing@example.test','12345','La Paz']])
        if not self.cli:
            status, body = self.upload(contents, event_id=event)
            self.assertEqual(status, 200, body)
            return re.search(r'name="import_token" value="([a-f0-9]+)"', body)[1], body
        # CLI cannot emulate is_uploaded_file(). Seed only the server-held review
        # via the real PHP parser/evaluator; POST confirmation still uses the real route.
        sid = next(c.value for c in self.jar if c.name == 'rinchen_session')
        code = '''require getenv('EVENT_TEST_WEB').'/lib/subscription.php';
session_id(getenv('EVENT_TEST_SID')); auth_start();
$db=new SQLite3(getenv('EVENT_TEST_WEB').'/db.sqlite',SQLITE3_OPEN_READONLY);
$review=(new EventRegistration($db))->scopeCsv(SubscriptionCsv::readContents(stream_get_contents(STDIN)),(int)getenv('EVENT_TEST_ID'));
$token=bin2hex(random_bytes(24));
$_SESSION['csv_import_review']=['token'=>$token,'expires'=>time()+600,'contents'=>getenv('EVENT_TEST_CSV'),'event_id'=>(int)getenv('EVENT_TEST_ID'),'scoped_review'=>$review];
session_write_close(); echo json_encode(['token'=>$token,'review'=>$review]);'''
        result = subprocess.run(['php','-r',code],input=contents,text=True,capture_output=True,check=True,
            env={**os.environ,'EVENT_TEST_WEB':str(self.web),'EVENT_TEST_SID':sid,'EVENT_TEST_ID':event,
                 'EVENT_TEST_CSV':contents,'RINCHEN_AUTH_STATE_DIR':str(self.throttle),'RINCHEN_HTTPS':'0'})
        response = json.loads(result.stdout)
        return response['token'], json.dumps(response['review'], ensure_ascii=False)

    def csv_confirm(self, token, event='1', action='confirm'):
        return self.request('/templates/subscription/upload.php', {
            'csrf_token':self.csrf(),'import_token':token,'event_id':event,'action':action,
        })

    def test_get_detail_review_cancel_do_not_create_schema_or_assign_old_records(self):
        before = (self.web / 'db.sqlite').read_bytes()
        status, _, body = self.request('/templates/event/detail.php?format=json&event_id=1')
        self.assertEqual(status, 200)
        data = json.loads(body)['data']
        self.assertEqual(data['subscriptions'], [])
        self.assertEqual([s['id'] for s in data['sessions']], [100])
        self.assertEqual([a['id'] for a in data['attendance']], [1])
        self.assertEqual(self.request('/templates/event/register.php?event_id=1')[0], 200)
        token, body = self.review()
        self.assertIn('First &lt;test&gt;', body)
        self.assertEqual(self.confirm(token, action='cancel')[0], 200)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertEqual(self.confirm(token)[0], 409)

    def test_link_audit_duplicate_and_multiple_events(self):
        for event in ('1', '1', '2'):
            token, _ = self.review(event)
            self.assertEqual(self.confirm(token, event)[0], 200)
            self.assertEqual(self.confirm(token, event)[0], 409)
        self.assertEqual(self.query('SELECT event_id,person_id,subscription_id,actor,staff_confirmed FROM event_registration ORDER BY event_id'),
                         [(1,50,10,'admin',1),(2,50,10,'admin',1)])
        self.assertEqual(self.query('SELECT qr FROM subscription'), [('UNCHANGED QR',)])

    def test_inactive_warning_and_changed_review_require_staff_confirmation(self):
        self.query('UPDATE subscription SET active=0')
        token, body = self.review()
        self.assertIn('inactiva', body)
        self.assertIn('Continuar de todos modos y vincular', body)
        self.query('UPDATE subscription SET active=1')
        self.assertEqual(self.confirm(token)[0], 409)
        self.query('UPDATE subscription SET active=0')
        token, _ = self.review()
        self.assertEqual(self.confirm(token)[0], 200)
        self.assertIn('inactive_registration', self.query('SELECT warnings_json FROM event_registration')[0][0])
        self.assertEqual(self.query('SELECT active FROM subscription'), [(0,)])

    def test_auth_csrf_invalid_ids_and_cross_event_tokens_fail_closed(self):
        token, _ = self.review()
        self.assertEqual(self.confirm(token, event='2')[0], 409)
        self.assertEqual(self.request('/templates/event/register.php', {'event_id':'1','action':'confirm'})[0], 403)
        self.assertEqual(self.request('/templates/event/detail.php?format=json&event_id=1', {'csrf_token':self.csrf()})[0], 405)
        for event in ('0', '999', '1%20OR%201=1'):
            self.assertEqual(self.request('/templates/event/detail.php?format=json&event_id=' + event)[0], 404)
        self.jar.clear()
        self.assertEqual(self.request('/templates/event/detail.php?format=json&event_id=1')[0], 401)
        self.assertEqual(self.request('/templates/event/register.php?event_id=1')[0], 303)
        self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='event_registration'"), [])

    def test_expired_token_and_subdirectory_scope(self):
        self.jar.clear()
        self.login('/event-retiro')
        token, _ = self.review(base='/event-retiro')
        for path in self.sessions.glob('sess_*'):
            path.write_bytes(re.sub(rb'"expires";i:\d+;', b'"expires";i:1;', path.read_bytes()))
        self.assertEqual(self.confirm(token, base='/event-retiro')[0], 409)
        token, _ = self.review(base='/event-retiro')
        status, _, body = self.confirm(token, base='/event-retiro')
        self.assertEqual(status, 200, body)
        self.assertIn('/event-retiro/templates/index.php#eventos/1/participantes', body)
        self.assertEqual(self.query('SELECT count(*) FROM event_registration', base='/event-retiro'), [(1,)])
        self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='event_registration'"), [])

    def test_csv_review_cancel_and_cross_event_tampering_never_write(self):
        before = (self.web / 'db.sqlite').read_bytes()
        token, _ = self.csv_review()
        self.assertEqual(self.csv_confirm(token, action='cancel')[0], 200)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        token, _ = self.csv_review()
        self.assertEqual(self.csv_confirm(token, event='2')[0], 409)
        self.assertEqual(self.csv_confirm(token)[0], 409)
        token, _ = self.csv_review()
        self.assertEqual(self.request('/templates/subscription/upload.php', {
            'csrf_token':self.csrf(),'import_token':token,'action':'confirm',
        })[0], 409)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)

    def test_scoped_csv_links_existing_audits_warning_and_skips_repeated_imports(self):
        self.query('UPDATE subscription SET active=0')
        for event in ('1','1','2'):
            token, body = self.csv_review(event)
            self.assertIn('inactiva', body)
            status, _, result = self.csv_confirm(token, event)
            self.assertEqual(status, 200, result)
            self.assertIn('Volver al evento', result)
        self.assertEqual(self.query('SELECT event_id,person_id,subscription_id,actor,staff_confirmed FROM event_registration ORDER BY event_id'),
                         [(1,50,10,'admin',1),(2,50,10,'admin',1)])
        self.assertIn('inactive_registration', self.query('SELECT warnings_json FROM event_registration LIMIT 1')[0][0])
        self.assertEqual(self.query('SELECT active,qr FROM subscription'), [(0,'UNCHANGED QR')])

    def test_csv_confirmation_rechecks_status_and_token_expiry(self):
        token, _ = self.csv_review()
        self.query('UPDATE subscription SET active=0')
        self.assertEqual(self.csv_confirm(token)[0], 409)
        token, _ = self.csv_review()
        for path in self.sessions.glob('sess_*'):
            path.write_bytes(re.sub(rb'"expires";i:\d+;', b'"expires";i:1;', path.read_bytes()))
        self.assertEqual(self.csv_confirm(token)[0], 409)
        self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='event_registration'"), [])


if __name__ == '__main__':
    unittest.main(verbosity=2)

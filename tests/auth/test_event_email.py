"""Two-phase CSV/event mail: disposable code/SQLite, synthetic QR, fake sendmail only."""
from contextlib import closing
import email
import email.policy
import json
import re
import shutil
import sqlite3
import subprocess
import unittest

import test_auth
import test_csv_import
import test_event_registration
import test_lab

ROOT = test_auth.ROOT
PNG = test_lab.fake_png()


class EventEmailDomainTest(unittest.TestCase):
    def setUp(self):
        test_event_registration.EventRegistrationTest.setUp(self)
        (self.web / 'templates').mkdir()
        shutil.copyfile(ROOT / 'src/templates/subscription-email.html', self.web / 'templates/subscription-email.html')
        (self.web / 'static/qr').mkdir(parents=True)
        (self.web / 'static/qr' / ('b' * 64 + '.jpg')).write_bytes(PNG)

    def check(self, code):
        prefix = '''
require getenv('EVENT_TEST_WEB') . '/lib/event_email.php';
$db->exec("UPDATE subscription SET qr='http://localhost/static/qr/" . str_repeat('b',64) . ".jpg'");
$r->confirmExisting($r->reviewExisting(1,10),'admin','');
$r->confirmExisting($r->reviewExisting(1,20),'admin','');
$m = new EventEmail($db);
$sender = function(...$args) {
    $GLOBALS['mail_calls']++;
    check($args[6]==='', 'Unexpected CC');
    check($args[1]['<QR>']==='<img src="cid:participant-qr" alt="Tu código QR personal" />');
    check($args[1]['{{HOST_ADDRESS}}']===auth_escape(app_base_url()));
    check($args[7]['participant-qr']['mime']==='image/png');
    check($args[7]['participant-qr']['data']!== '');
    return !$GLOBALS['mail_fail'];
};
'''
        test_event_registration.EventRegistrationTest.check(self, prefix + code)

    def test_review_is_read_only_selected_and_all_are_event_scoped(self):
        self.check('''
check(count($m->review(1,'all')['recipients'])===2);
check(array_column($m->review(1,'selected',[10])['recipients'],'id')===[10]);
check($m->history(1)===[] && $GLOBALS['mail_calls']===0);
check($m->review(1,'selected',[20])['recipients'][0]['warnings'][0]['code']==='inactive_registration');
check($db->querySingle("SELECT count(*) FROM sqlite_master WHERE name='event_email_attempt'")===0);
fails(fn()=>$m->review(2,'selected',[10])); fails(fn()=>$m->review(1,'selected',[]));
fails(fn()=>$m->review(2,'all')); fails(fn()=>$m->review(999,'all')); fails(fn()=>$m->review(1,'bad'));
check(EventEmail::selectedIds('20,10,20')===[10,20]);
foreach (['','01','10 OR 1=1','10,',[],null] as $ids) { fails(fn()=>EventEmail::selectedIds($ids)); }
''')

    def test_send_claims_before_transport_and_audits_each_person(self):
        self.check('''
$review=$m->review(1,'all');
$send=function(...$args) use ($sender,$db) {
    check($db->querySingle('SELECT count(*) FROM event_email_attempt')===2);
    return $sender(...$args);
};
$result=$m->send($review,str_repeat('a',48),'admin','Optional note',$send);
check(array_column($result,'status')===['accepted','accepted'] && $GLOBALS['mail_calls']===2);
check($result[1]['staff_confirmed']===1 && $result[1]['actor']==='admin' && $result[1]['notes']==='Optional note');
check(json_decode($result[1]['warnings_json'],true)[0]['code']==='inactive_registration');
check($result[0]['completed_at']!==null && $result[0]['template_hash']===$review['template_hash']);
check($db->querySingle('SELECT active FROM subscription WHERE id=20')===0);
check($db->querySingle('SELECT count(*) FROM attendance')===2);
fails(fn()=>$db->exec('UPDATE event_email_attempt SET person_id=60 WHERE subscription_id=10'));
check($db->querySingle('PRAGMA foreign_key_check')===null);
fails(fn()=>$m->send($review,str_repeat('a',48),'admin','',$sender));
check($GLOBALS['mail_calls']===2);
''')

    def test_stale_reviews_include_roster_identity_status_and_attempts(self):
        self.check('''
$old=$m->review(1,'all');
$db->exec("UPDATE person SET email='changed@example.test' WHERE id=50");
fails(fn()=>$m->send($old,str_repeat('a',48),'admin','',$sender));
$old=$m->review(1,'all'); $db->exec('UPDATE subscription SET active=0 WHERE id=10');
fails(fn()=>$m->send($old,str_repeat('a',48),'admin','',$sender));
$old=$m->review(1,'all'); $db->exec('DELETE FROM event_registration WHERE person_id=60');
fails(fn()=>$m->send($old,str_repeat('a',48),'admin','',$sender));
$old=$m->review(1,'selected',[10]); $m->send($old,str_repeat('b',48),'admin','',$sender);
fails(fn()=>$m->send($old,str_repeat('c',48),'admin','',$sender));
check($GLOBALS['mail_calls']===1);
$review=$m->review(1,'selected',[10]);
check(in_array('previous_email_attempt',array_column($review['recipients'][0]['warnings'],'code'),true));
$m->send($review,str_repeat('d',48),'admin','Deliberate resend',$sender);
check($GLOBALS['mail_calls']===2);
''')

    def test_template_config_and_qr_changes_require_new_review(self):
        self.check('''
$review=$m->review(1,'selected',[10]);
$file=getenv('EVENT_TEST_WEB').'/templates/subscription-email.html';
file_put_contents($file,EventEmail::template().'changed');
fails(fn()=>$m->send($review,str_repeat('a',48),'admin','',$sender));
$review=$m->review(1,'selected',[10]);
$config=getenv('EVENT_TEST_WEB').'/.env'; file_put_contents($config,"HOST_ADDRESS=http://localhost\nEMAIL_FROM=other@example.test\n");
fails(fn()=>$m->send($review,str_repeat('a',48),'admin','',$sender));
check($GLOBALS['mail_calls']===0 && $m->history(1)===[]);
''')
        self.check('''
$review=$m->review(1,'selected',[10]);
file_put_contents(getenv('EVENT_TEST_WEB').'/static/qr/'.str_repeat('b',64).'.jpg','not an image');
fails(fn()=>$m->send($review,str_repeat('a',48),'admin','',$sender));
check($GLOBALS['mail_calls']===0);
''')

    def test_invalid_address_or_private_qr_fail_before_any_send(self):
        self.check('''
$db->exec("UPDATE person SET email='bad-address' WHERE id=60");
fails(fn()=>$m->review(1,'all')); check($GLOBALS['mail_calls']===0);
$db->exec("UPDATE person SET email='inactive@example.test' WHERE id=60; UPDATE subscription SET qr='https://evil.test/../../.env' WHERE id=20");
fails(fn()=>$m->review(1,'all')); check($m->history(1)===[]);
''')

    def test_host_address_change_requires_review_and_keeps_original_qr(self):
        self.check('''
$review=$m->review(1,'selected',[10]);
$old=$db->querySingle('SELECT qr FROM subscription WHERE id=10');
$config=getenv('EVENT_TEST_WEB').'/.env';
file_put_contents($config,str_replace('HOST_ADDRESS=http://localhost','HOST_ADDRESS=https://production.example.test/event-retiro/',file_get_contents($config)));
fails(fn()=>$m->send($review,str_repeat('a',48),'admin','',$sender));
check($GLOBALS['mail_calls']===0 && $m->history(1)===[]);
$current=$m->review(1,'selected',[10]);
check($current['recipients'][0]['warnings'][0]['code']==='qr_base_changed');
check(str_contains(EventEmail::template(),'https://production.example.test/event-retiro/static/images/logo-head.png'));
$m->send($current,str_repeat('b',48),'admin','Reviewed old code',$sender);
check($GLOBALS['mail_calls']===1 && $db->querySingle('SELECT qr FROM subscription WHERE id=10')===$old);
check(str_contains($db->querySingle('SELECT warnings_json FROM event_email_attempt'),'qr_base_changed'));
''')

    def test_qr_symlinks_hardlinks_and_invalid_stored_identifiers_fail_closed(self):
        self.check('''
$path=getenv('EVENT_TEST_WEB').'/static/qr/'.str_repeat('b',64).'.jpg';
rename($path,$path.'.original'); symlink($path.'.original',$path);
fails(fn()=>$m->review(1,'all')); unlink($path);
link($path.'.original',$path); clearstatcache(); fails(fn()=>$m->review(1,'all'));
unlink($path); rename($path.'.original',$path); clearstatcache();
$db->exec("INSERT INTO subscription VALUES (-1,50,'2026-01-01','unused',1)");
$db->exec("UPDATE event_registration SET subscription_id=-1 WHERE person_id=50");
fails(fn()=>$m->review(1,'all'));
check($GLOBALS['mail_calls']===0 && $m->history(1)===[]);
''')

    def test_transport_failure_is_durable_and_never_automatically_retried(self):
        self.check('''
$GLOBALS['mail_fail']=true; $review=$m->review(1,'all');
$result=$m->send($review,str_repeat('a',48),'admin','',$sender);
check(array_column($result,'status')===['unconfirmed','unconfirmed']);
fails(fn()=>$m->send($review,str_repeat('b',48),'admin','',$sender));
check($GLOBALS['mail_calls']===2 && count($m->history(1))===2);
check(count($r->detail(1)['subscriptions'])===2);
''')

    def test_claim_failure_rolls_back_without_mail_and_pending_blocks_stale_review(self):
        self.check('''
$old=$m->review(1,'all');
$m->send($m->review(1,'selected',[10]),str_repeat('a',48),'admin','',$sender);
$db->exec("CREATE TRIGGER fail_claim BEFORE INSERT ON event_email_attempt BEGIN SELECT RAISE(ABORT,'fake'); END");
fails(fn()=>$m->send($m->review(1,'all'),str_repeat('b',48),'admin','',$sender));
check(count($m->history(1))===1 && $GLOBALS['mail_calls']===1);
$db->exec('DROP TRIGGER fail_claim');
$db->exec("CREATE TRIGGER fail_result BEFORE UPDATE ON event_email_attempt BEGIN SELECT RAISE(ABORT,'fake'); END");
fails(fn()=>$m->send($m->review(1,'all'),str_repeat('c',48),'admin','',$sender));
check($GLOBALS['mail_calls']===2);
check(array_column(array_slice($m->history(1),0,2),'status')===['pending','pending']);
fails(fn()=>$m->send($old,str_repeat('d',48),'admin','',$sender));
check($GLOBALS['mail_calls']===2);
''')


class EventEmailRoutesTest(unittest.TestCase):
    fake_mail = True
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf
    request = test_lab.LabTest.request
    upload = test_csv_import.CsvImportHTTPTest.upload
    csv_review = test_event_registration.EventRegistrationRoutesTest.csv_review
    csv_confirm = test_event_registration.EventRegistrationRoutesTest.csv_confirm

    @classmethod
    def setUpClass(cls):
        test_lab.LabTest.setUpClass.__func__(cls)
        cls.mail_calls = cls.work / 'mail-calls'
        cls.transport = cls.work / 'fake-sendmail'
        cls.transport.write_text(f'#!/bin/sh\ncat >> "{cls.mail_capture}"\nprintf "mail\\n" >> "{cls.mail_calls}"\n[ ! -f "{cls.mail_failure}" ]\n')
        cls.transport.chmod(0o700)
        for base in ('', 'event-retiro'):
            shutil.copyfile(ROOT / 'src/templates/subscription-email.html', cls.web / base / 'templates/subscription-email.html')
            (cls.web / base / 'lib/qr.php').write_text("<?php function generate_qr(...$args): string { return 'http://localhost/static/qr/" + 'b' * 64 + ".jpg'; }")

    @classmethod
    def tearDownClass(cls):
        test_lab.LabTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_auth.AuthHTTPTest.setUp(self)
        for path in (self.mail_capture, self.mail_calls, self.mail_failure):
            path.unlink(missing_ok=True)
        for base in ('', 'event-retiro'):
            web = self.web / base
            database = web / 'db.sqlite'
            database.unlink(missing_ok=True)
            code = "require $argv[1].'/lib/schema.php'; $db=new SQLite3($argv[1].'/db.sqlite'); create_setup_schema($db);"
            subprocess.run(['php', '-r', code, str(web)], check=True, capture_output=True)
            with closing(sqlite3.connect(database)) as db:
                db.executescript('''
INSERT INTO event VALUES (1,'Event <A>','','2026-11-12','2026-11-15'),(2,'Event B','','2026-11-12','2026-11-15');
INSERT INTO person VALUES (50,'Test <One>','one@example.test','12345',1),(60,'Test Two','two@example.test','23456',0),(70,'Other event','other@example.test','34567',1);
INSERT INTO subscription VALUES (10,50,'2026-01-01','QR',1),(20,60,'2026-01-01','QR',0),(30,70,'2026-01-01','QR',1);
INSERT INTO event_registration (event_id,person_id,subscription_id,registered_at,actor,source,source_row,warnings_json,staff_confirmed,notes)
VALUES (1,50,10,'2026-01-01','admin','staff',0,'[]',1,''),(1,60,20,'2026-01-01','admin','staff',0,'[]',1,''),(2,70,30,'2026-01-01','admin','staff',0,'[]',1,'');
''')
                db.execute('UPDATE subscription SET qr=?', ('http://localhost/static/qr/' + 'b' * 64 + '.jpg',))
                db.commit()
            (web / 'static/qr').mkdir(parents=True, exist_ok=True)
            (web / 'static/qr' / ('b' * 64 + '.jpg')).write_bytes(PNG)
            (web / '.env').write_text('HOST_ADDRESS=http://localhost\nSEND_EMAIL=1\nEMAIL_FROM=sender@example.test\nEMAIL_REPLY_TO=reply@example.test\nEMAIL_CC=never-copy@example.test\n')
        self.login()

    def query(self, sql, base=''):
        with closing(sqlite3.connect(self.web / base.lstrip('/') / 'db.sqlite')) as db:
            result = db.execute(sql).fetchall()
            db.commit()
            return result

    def review(self, mode='selected', ids='10', event='1', base=''):
        status, _, body = self.request(base + '/templates/event/email.php', {
            'csrf_token': self.csrf(base), 'event_id': event, 'mode': mode, 'subscription_ids': ids,
        })
        self.assertEqual(status, 200, body)
        return re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1], body

    def confirm(self, token, action='confirm', event='1', base='', **extra):
        return self.request(base + '/templates/event/email.php', dict(
            csrf_token=self.csrf(base), event_id=event, review_token=token, action=action, **extra))

    def count_mail(self):
        return len(self.mail_calls.read_text().splitlines()) if self.mail_calls.exists() else 0

    def test_get_review_and_cancel_are_read_only_and_replay_fails(self):
        before = (self.web / 'db.sqlite').read_bytes()
        self.assertEqual(self.request('/templates/event/email.php?event_id=1')[0], 200)
        token, body = self.review()
        self.assertIn('Test &lt;One&gt;', body)
        self.assertIn('Vista previa de la plantilla', body)
        self.assertEqual(self.confirm(token, action='cancel')[0], 200)
        self.assertEqual(self.confirm(token)[0], 409)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertEqual(self.count_mail(), 0)

    def test_csv_confirmation_then_selected_email_are_two_explicit_phases(self):
        contents = test_csv_import.csv_text([['1','New','Attendee','new@example.test','12345','La Paz']])
        token, _ = self.csv_review(contents=contents)
        status, _, body = self.csv_confirm(token)
        self.assertEqual(status, 200, body)
        self.assertIn('No se enviaron correos.', body)
        self.assertEqual(self.count_mail(), 0)
        subscriptions = self.query("SELECT s.id FROM subscription s JOIN person p ON p.id=s.person_id WHERE p.email='new@example.test'")
        self.assertEqual(len(subscriptions), 1)
        token, _ = self.review(ids=str(subscriptions[0][0]))
        self.assertEqual(self.count_mail(), 0)
        status, _, body = self.confirm(token)
        self.assertEqual(status, 200, body)
        self.assertEqual(self.count_mail(), 1)
        self.assertEqual(self.query('SELECT email,status FROM event_email_attempt'), [('new@example.test','accepted')])

    def test_selected_send_embeds_qr_excludes_cc_and_retargeting_then_replay_fail(self):
        token, _ = self.review()
        status, _, body = self.confirm(token, subscription_ids='30', mode='all', recipient='forged@example.test')
        self.assertEqual(status, 200, body)
        self.assertEqual(self.count_mail(), 1)
        self.assertEqual(self.query('SELECT event_id,person_id,subscription_id,email,status,actor,staff_confirmed FROM event_email_attempt'),
            [(1,50,10,'one@example.test','accepted','admin',1)])
        self.assertIn('Aceptado por el transporte (entrega no confirmada)', body)
        message = email.message_from_bytes(self.mail_capture.read_bytes(), policy=email.policy.default)
        self.assertEqual(message['To'], 'one@example.test')
        self.assertIsNone(message['Cc'])
        self.assertIsNone(message['Bcc'])
        parts = list(message.iter_parts())
        self.assertIn('cid:participant-qr', parts[0].get_content())
        self.assertIn('http://localhost/static/images/logo-head.png', parts[0].get_content())
        self.assertNotIn('{{HOST_ADDRESS}}', parts[0].get_content())
        self.assertEqual(parts[1].get_payload(decode=True), PNG)
        self.assertEqual(parts[1]['Content-ID'], '<participant-qr>')
        self.assertEqual(self.confirm(token)[0], 409)
        self.assertEqual(self.count_mail(), 1)

    def test_all_includes_inactive_after_visible_warning_and_not_other_events(self):
        token, body = self.review(mode='all')
        self.assertIn('2 destinatario(s)', body)
        self.assertIn('inactiva', body)
        status, _, body = self.confirm(token, notes='Reviewed')
        self.assertEqual(status, 200, body)
        self.assertEqual(self.count_mail(), 2)
        self.assertEqual(self.query('SELECT subscription_id FROM event_email_attempt ORDER BY id'), [(10,),(20,)])
        self.assertEqual(self.query('SELECT active FROM subscription WHERE id=20'), [(0,)])
        self.assertEqual(self.query('SELECT count(*) FROM event_registration'), [(3,)])

    def test_changed_recipient_all_roster_or_template_requires_another_review(self):
        token, _ = self.review()
        self.query("UPDATE person SET email='changed@example.test' WHERE id=50")
        self.assertEqual(self.confirm(token)[0], 409)
        token, _ = self.review(mode='all')
        self.query('DELETE FROM event_registration WHERE person_id=60')
        self.assertEqual(self.confirm(token)[0], 409)
        token, _ = self.review()
        template = self.web / 'templates/subscription-email.html'
        original = template.read_text()
        try:
            template.write_text(original + '<p>Changed</p>')
            self.assertEqual(self.confirm(token)[0], 409)
        finally:
            template.write_text(original)
        self.assertEqual(self.count_mail(), 0)

    def test_auth_csrf_cross_event_invalid_ids_and_expiry_fail(self):
        token, _ = self.review()
        self.assertEqual(self.confirm(token, event='2')[0], 409)
        self.assertEqual(self.request('/templates/event/email.php', {'event_id':'1','mode':'all'})[0], 403)
        for ids in ('30','999','01','10 OR 1=1',''):
            status, _, _ = self.request('/templates/event/email.php', {
                'csrf_token':self.csrf(),'event_id':'1','mode':'selected','subscription_ids':ids,
            })
            self.assertEqual(status, 409)
        token, _ = self.review()
        for path in self.sessions.glob('sess_*'):
            path.write_bytes(re.sub(rb'"expires";i:\d+;', b'"expires";i:1;', path.read_bytes()))
        self.assertEqual(self.confirm(token)[0], 409)
        self.jar.clear()
        self.assertEqual(self.request('/templates/event/email.php?event_id=1')[0], 303)
        self.assertEqual(self.count_mail(), 0)

    def test_transport_failure_does_not_retry_and_new_review_warns(self):
        self.mail_failure.touch()
        token, _ = self.review()
        self.assertEqual(self.confirm(token)[0], 200)
        self.assertEqual(self.query('SELECT status FROM event_email_attempt'), [('unconfirmed',)])
        self.assertEqual(self.confirm(token)[0], 409)
        self.assertEqual(self.count_mail(), 1)
        token, body = self.review()
        self.assertIn('Ya hay intentos de envío', body)
        self.assertEqual(self.confirm(token, action='cancel')[0], 200)
        self.assertEqual(self.count_mail(), 1)

    def test_changed_qr_base_warning_can_be_cancelled_or_explicitly_confirmed(self):
        config = self.web / '.env'
        config.write_text(config.read_text().replace('HOST_ADDRESS=http://localhost', 'HOST_ADDRESS=https://production.example.test/event-retiro'))
        before = (self.web / 'db.sqlite').read_bytes()
        token, body = self.review()
        self.assertIn('dirección base diferente de HOST_ADDRESS', body)
        self.assertEqual(self.confirm(token, action='cancel')[0], 200)
        self.assertEqual(self.count_mail(), 0)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        token, _ = self.review()
        status, _, body = self.confirm(token)
        self.assertEqual(status, 200, body)
        self.assertEqual(self.count_mail(), 1)
        self.assertIn('qr_base_changed', self.query('SELECT warnings_json FROM event_email_attempt')[0][0])

    def test_disabled_config_and_invalid_qr_or_address_send_nothing(self):
        config = self.web / '.env'
        original = config.read_text()
        token, _ = self.review()
        config.write_text(original.replace('SEND_EMAIL=1', 'SEND_EMAIL=0'))
        self.assertEqual(self.confirm(token)[0], 409)
        config.write_text(original)
        self.query("UPDATE person SET email='bad-address' WHERE id=60")
        status, _, _ = self.request('/templates/event/email.php', {'csrf_token':self.csrf(),'event_id':'1','mode':'all'})
        self.assertEqual(status, 409)
        (self.web / 'static/qr' / ('b' * 64 + '.jpg')).write_bytes(b'not an image')
        status, _, _ = self.request('/templates/event/email.php', {'csrf_token':self.csrf(),'event_id':'1','mode':'selected','subscription_ids':'10'})
        self.assertEqual(status, 409)
        self.assertEqual(self.count_mail(), 0)

    def test_subdirectory_urls_and_separate_event_scope(self):
        self.jar.clear()
        self.login('/event-retiro')
        token, body = self.review(event='2', ids='30', base='/event-retiro')
        self.assertIn('action="/event-retiro/templates/event/email.php"', body)
        status, _, body = self.confirm(token, event='2', base='/event-retiro')
        self.assertEqual(status, 200, body)
        self.assertIn('/event-retiro/templates/index.php#eventos/2/participantes', body)
        self.assertEqual(self.query('SELECT event_id,subscription_id FROM event_email_attempt', base='/event-retiro'), [(2,30)])
        self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='event_email_attempt'"), [])


if __name__ == '__main__':
    unittest.main(verbosity=2)

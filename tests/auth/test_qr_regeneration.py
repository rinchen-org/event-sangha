"""Explicit QR repair: disposable SQLite/config/files, fake QR and mail. HTTP by default."""
import base64
import json
import os
import re
import subprocess
import unittest

import test_auth
import test_event_email
import test_event_registration
import test_lab

ROOT = test_auth.ROOT
PNG = test_lab.fake_png()
ROUTE = '/templates/subscription/regenerate_qr.php'


class QrRegenerationDomainTest(unittest.TestCase):
    def setUp(self):
        test_event_registration.EventRegistrationTest.setUp(self)
        (self.web / 'static/qr').mkdir(parents=True)
        (self.web / 'static/qr' / ('b' * 64 + '.jpg')).write_bytes(PNG)
        (self.web / 'fake.png').write_bytes(PNG)

    def check(self, code):
        prefix = '''
require getenv('EVENT_TEST_WEB').'/lib/qr_regeneration.php';
$db->exec("UPDATE subscription SET qr='http://localhost/static/qr/".str_repeat('b',64).".jpg'");
$r->confirmExisting($r->reviewExisting(1,10),'admin','');
$r->confirmExisting($r->reviewExisting(2,10),'admin','');
$r->confirmExisting($r->reviewExisting(1,20),'admin','');
$g=new QrRegeneration($db);
$root=getenv('EVENT_TEST_WEB'); $png=file_get_contents($root.'/fake.png');
$fetch=function($url) use ($png,$db) {
    $GLOBALS['qr_calls']++;
    check($db->querySingle("SELECT count(*) FROM qr_regeneration WHERE status='pending'")>=1);
    check(strpos($url,'/attendance_log.php/?fullname=')!==false);
    return $png;
};
function unchanged($db) {
    check($GLOBALS['mail_calls']===0);
    check($db->querySingle('SELECT count(*) FROM person')===2);
    check($db->querySingle('SELECT count(*) FROM subscription')===2);
    check($db->querySingle('SELECT count(*) FROM attendance')===2);
    check($db->querySingle('SELECT count(*) FROM event_registration')===3);
    check($db->querySingle("SELECT value FROM app_setting WHERE name='qr_event_id'")==='2');
    check($db->querySingle('SELECT active FROM subscription WHERE id=20')===0);
}
'''
        test_event_registration.EventRegistrationTest.check(self, prefix + code)

    def test_review_is_read_only_scoped_and_allows_repair_of_missing_old_images(self):
        self.check('''
$review=$g->review(10,1);
check($review['person']['person_id']===50 && $review['scope']['event']['id']===1);
check($g->review(10)['scope']===null && $g->history(10)===[]);
check($g->review(20,1)['warnings'][0]['code']==='inactive_registration');
fails(fn()=>$g->review(20,2)); fails(fn()=>$g->review(999)); fails(fn()=>$g->review(10,999));
check($db->querySingle("SELECT count(*) FROM sqlite_master WHERE name='qr_regeneration'")===0);
$db->exec("UPDATE subscription SET qr='broken/missing' WHERE id=10");
check($g->review(10)['person']['qr']==='broken/missing');
check($GLOBALS['qr_calls']===0); unchanged($db);
''')

    def test_confirm_replaces_only_qr_preserves_old_files_and_audits_warning_confirmation(self):
        self.check('''
$old=$g->review(20,1)['person']['qr'];
$url=$g->regenerate($g->review(20,1),str_repeat('a',48),'admin','Reviewed inactive',$fetch);
check($url!==$old && $db->querySingle('SELECT qr FROM subscription WHERE id=20')===$url);
check($db->querySingle('SELECT qr FROM subscription WHERE id=10')===$old);
check(qr_inline_image($old)['data']===$png && qr_inline_image($url)['data']===$png);
$audit=$g->history(20)[0];
check($audit['status']==='completed' && $audit['old_qr']===$old && $audit['new_qr']===$url);
check($audit['actor']==='admin' && $audit['staff_confirmed']===1 && $audit['notes']==='Reviewed inactive');
check($audit['person_id']===60 && $audit['subscription_id']===20 && $audit['event_id']===1);
check(json_decode($audit['warnings_json'],true)[0]['code']==='inactive_registration');
check($audit['completed_at']!==null && $audit['requested_at']!==null);
check($db->querySingle('PRAGMA foreign_key_check')===null);
fails(fn()=>$db->exec('UPDATE qr_regeneration SET person_id=50 WHERE subscription_id=20'));
$next=$g->regenerate($g->review(20),str_repeat('b',48),'admin','',$fetch);
check($g->history(20)[0]['event_id']===null && $next!==$url);
check(count(glob($root.'/static/qr/*.jpg'))===3);
check(qr_inline_image($url)['data']===$png && $GLOBALS['qr_calls']===2); unchanged($db);
''')

    def test_host_identity_encoding_and_empty_phone_are_preserved(self):
        self.check('''
file_put_contents($root.'/.env',"HOST_ADDRESS=https://production.example.test/event-retiro/\nSEND_EMAIL=1\n");
$db->exec("UPDATE person SET fullname='Test & Ñ +', phone='' WHERE id=50");
$review=$g->review(10,1);
check($review['base']==='https://production.example.test/event-retiro');
parse_str(parse_url($review['target'],PHP_URL_QUERY),$query);
check($query===['fullname'=>'Test & Ñ +','email'=>'existing@example.test','phone'=>'']);
check(parse_url($review['target'],PHP_URL_PATH)==='/event-retiro/attendance_log.php/');
check($review['warnings'][0]['code']==='qr_base_changed');
$url=$g->regenerate($review,str_repeat('a',48),'admin','',$fetch);
check(strpos($url,$review['base'].'/static/qr/')===0); unchanged($db);
''')

    def test_stale_identity_qr_status_host_and_membership_fail_before_network_or_schema(self):
        self.check('''
foreach (["UPDATE person SET email='changed@example.test' WHERE id=50",
    "UPDATE person SET phone='' WHERE id=50", "UPDATE person SET active=0 WHERE id=50",
    "UPDATE subscription SET qr='CHANGED' WHERE id=10", "UPDATE subscription SET active=0 WHERE id=10",
    "UPDATE event_registration SET notes='changed' WHERE event_id=1 AND person_id=50"] as $sql) {
    $review=$g->review(10,1); $db->exec($sql);
    fails(fn()=>$g->regenerate($review,str_repeat('a',48),'admin','',$fetch));
}
$review=$g->review(10,1); file_put_contents($root.'/.env',"HOST_ADDRESS=https://changed.example.test\n");
fails(fn()=>$g->regenerate($review,str_repeat('a',48),'admin','',$fetch));
check($GLOBALS['qr_calls']===0 && $g->history(10)===[]);
check($db->querySingle("SELECT count(*) FROM sqlite_master WHERE name='qr_regeneration'")===0);
''')

    def test_invalid_identity_and_ambiguous_people_are_integrity_failures(self):
        self.check('''
$db->exec("INSERT INTO person (id,fullname,email,phone,active) SELECT 99,fullname,email,phone,active FROM person WHERE id=50");
fails(fn()=>$g->review(10)); $db->exec('DELETE FROM person WHERE id=99');
$db->exec("UPDATE person SET email='invalid' WHERE id=50"); fails(fn()=>$g->review(10));
$db->exec("UPDATE person SET fullname='' WHERE id=60"); fails(fn()=>$g->review(20));
check($GLOBALS['qr_calls']===0 && $g->history(10)===[]);
''')

    def test_durable_claims_network_failures_and_replay_never_replace_or_retry(self):
        self.check('''
$review=$g->review(10); $old=$review['person']['qr'];
$bad=function($url) { $GLOBALS['qr_calls']++; throw new RuntimeException('Synthetic network failure'); };
fails(fn()=>$g->regenerate($review,str_repeat('a',48),'admin','',$bad));
check($g->history(10)[0]['status']==='failed');
fails(fn()=>$g->regenerate($g->review(10),str_repeat('a',48),'admin','',$fetch));
check($GLOBALS['qr_calls']===1 && $db->querySingle('SELECT qr FROM subscription WHERE id=10')===$old);
foreach (['not png',str_repeat('x',2097153),substr($png,0,8),substr($png,0,-12)] as $bytes) {
    fails(fn()=>$g->regenerate($g->review(10),bin2hex(random_bytes(24)),'admin','',fn($url)=>$bytes));
}
check(count(glob($root.'/static/qr/*.jpg'))===1); unchanged($db);
''')

    def test_changes_during_network_are_rechecked_without_replacing_the_saved_qr(self):
        self.check('''
$review=$g->review(10,1); $old=$review['person']['qr'];
$change=function($url) use ($db,$png) { $db->exec("UPDATE person SET email='changed@example.test' WHERE id=50"); return $png; };
fails(fn()=>$g->regenerate($review,str_repeat('a',48),'admin','',$change));
check($g->history(10)[0]['status']==='failed');
check($db->querySingle('SELECT qr FROM subscription WHERE id=10')===$old);
check(count(glob($root.'/static/qr/*.jpg'))===1); unchanged($db);
''')

    def test_write_and_audit_failures_rollback_reference_and_remove_only_the_new_file(self):
        self.check('''
$old=$g->review(10)['person']['qr'];
$g->regenerate($g->review(10),str_repeat('a',48),'admin','',$fetch);
$saved=$db->querySingle('SELECT qr FROM subscription WHERE id=10');
foreach ([['subscription',"ABORT,'Synthetic DB failure'"],['qr_regeneration','IGNORE'],['qr_regeneration',"ABORT,'Synthetic DB failure'"]] as [$table,$failure]) {
    $db->exec("CREATE TRIGGER reject_result BEFORE UPDATE ON $table BEGIN SELECT RAISE($failure); END");
    fails(fn()=>$g->regenerate($g->review(10),bin2hex(random_bytes(24)),'admin','',$fetch));
    $db->exec('DROP TRIGGER reject_result');
    check($db->querySingle('SELECT qr FROM subscription WHERE id=10')===$saved);
    check(count(glob($root.'/static/qr/*.jpg'))===2);
}
check($g->history(10)[0]['status']==='pending');
check(in_array('unfinished_regeneration',array_column($g->review(10)['warnings'],'code'),true));
check(qr_inline_image($old)['data']===$png && qr_inline_image($saved)['data']===$png); unchanged($db);
$g->regenerate($g->review(10),str_repeat('c',48),'admin','Explicit continuation',$fetch);
check($g->history(10)[0]['status']==='completed');
check(in_array('unfinished_regeneration',array_column(json_decode($g->history(10)[0]['warnings_json'],true),'code'),true));
''')

    def test_symlink_directory_or_non_directory_fails_without_touching_other_files(self):
        self.check('''
$old=$g->review(10)['person']['qr'];
rename($root.'/static/qr',$root.'/kept'); symlink($root.'/kept',$root.'/static/qr');
fails(fn()=>$g->regenerate($g->review(10),str_repeat('a',48),'admin','',$fetch));
check(count(glob($root.'/kept/*.jpg'))===1); unlink($root.'/static/qr');
file_put_contents($root.'/static/qr','sentinel');
fails(fn()=>$g->regenerate($g->review(10),str_repeat('b',48),'admin','',$fetch));
check(file_get_contents($root.'/static/qr')==='sentinel');
check($db->querySingle('SELECT qr FROM subscription WHERE id=10')===$old); unchanged($db);
''')


class QrRegenerationTransportTest(unittest.TestCase):
    setUp = QrRegenerationDomainTest.setUp

    def test_fixed_service_time_size_status_and_no_redirects_without_real_network(self):
        code = '''
const CURLOPT_CONNECTTIMEOUT=1, CURLOPT_TIMEOUT=2, CURLOPT_FOLLOWLOCATION=3, CURLOPT_PROTOCOLS=4,
    CURLOPT_WRITEFUNCTION=5, CURLPROTO_HTTPS=6, CURLINFO_HTTP_CODE=7;
function curl_init($url) { $GLOBALS['url']=$url; return new stdClass(); }
function curl_setopt_array($ch,$options) { $GLOBALS['options']=$options; return true; }
function curl_exec($ch) {
    $write=$GLOBALS['options'][CURLOPT_WRITEFUNCTION];
    if ($GLOBALS['mode']==='fail') return false;
    if ($GLOBALS['mode']==='size') {
        $write($ch,str_repeat('x',2097152));
        if ($write($ch,'x')===0) return false;
        throw new RuntimeException('Expected bounded callback');
    }
    $write($ch,'synthetic bytes'); return true;
}
function curl_getinfo($ch,$option) { return $GLOBALS['mode']==='redirect' ? 302 : 200; }
function curl_close($ch) { $GLOBALS['closed']++; }
require ''' + json.dumps(str(self.web / 'lib/qr_regeneration.php')) + ''';
$target='https://production.example.test/event-retiro/attendance_log.php/?fullname=Test&email=test%40example.test&phone=';
$GLOBALS['closed']=0; $results=[];
foreach (['ok','fail','size','redirect'] as $mode) {
    $GLOBALS['mode']=$mode;
    try { $results[$mode]=QrRegeneration::fetch($target); }
    catch (RuntimeException $e) { $results[$mode]=false; }
}
$o=$GLOBALS['options'];
echo json_encode(['results'=>$results,'closed'=>$GLOBALS['closed'], 'url'=>$GLOBALS['url'],
    'connect'=>$o[CURLOPT_CONNECTTIMEOUT],'timeout'=>$o[CURLOPT_TIMEOUT],
    'redirect'=>$o[CURLOPT_FOLLOWLOCATION],'protocol'=>$o[CURLOPT_PROTOCOLS]]);
'''
        result = subprocess.run(['php', '-n', '-d', 'disable_functions=mail,fsockopen,stream_socket_client', '-r', code],
            capture_output=True, text=True, check=True)
        data = json.loads(result.stdout)
        self.assertEqual(data['results'], {'ok':'synthetic bytes','fail':False,'size':False,'redirect':False})
        self.assertEqual(data['closed'], 4)
        self.assertEqual((data['connect'], data['timeout'], data['redirect'], data['protocol']), (5,20,False,6))
        self.assertTrue(data['url'].startswith('https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&data=https%3A%2F%2Fproduction.example.test%2Fevent-retiro%2Fattendance_log.php%2F%3F'))


class QrRegenerationRoutesTest(unittest.TestCase):
    fake_mail = True
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf
    request = test_lab.LabTest.request
    query = test_event_email.EventEmailRoutesTest.query
    count_mail = test_event_email.EventEmailRoutesTest.count_mail

    @classmethod
    def setUpClass(cls):
        test_event_email.EventEmailRoutesTest.setUpClass.__func__(cls)
        for base in ('', 'event-retiro'):
            # Replace only transport in disposable copies. Production domain/controller stays intact.
            path = cls.web / base / 'lib/qr_regeneration.php'
            source = path.read_text()
            start = source.index('        $handle = curl_init(')
            end = source.index('\n    /** @param array<string, mixed> $review', start)
            path.write_text(source[:start] + '        file_put_contents(' + json.dumps(str(cls.qr_capture)) + ', $target . "\\n", FILE_APPEND);\n'
                + '        return base64_decode(' + json.dumps(base64.b64encode(PNG).decode()) + ');\n    }\n' + source[end:])

    @classmethod
    def tearDownClass(cls):
        test_lab.LabTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_event_email.EventEmailRoutesTest.setUp(self)
        self.qr_capture.unlink(missing_ok=True)

    def review(self, subscription='10', event='1', base=''):
        data = {'csrf_token': self.csrf(base), 'subscription_id': subscription}
        if event is not None:
            data['event_id'] = event
        status, _, body = self.request(base + ROUTE, data)
        self.assertEqual(status, 200, body)
        return re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1], body

    def confirm(self, token, action='confirm', subscription='10', event='1', base='', **extra):
        data = dict(csrf_token=self.csrf(base), subscription_id=subscription, review_token=token, action=action, **extra)
        if event is not None:
            data['event_id'] = event
        return self.request(base + ROUTE, data)

    def test_get_review_cancel_are_read_only_even_when_email_is_enabled(self):
        before = (self.web / 'db.sqlite').read_bytes()
        files = sorted((self.web / 'static/qr').glob('*.jpg'))
        self.assertEqual(self.request(ROUTE + '?action=confirm&subscription_id=10')[0], 200)
        token, body = self.review()
        self.assertIn('Test &lt;One&gt;', body)
        self.assertIn('api.qrserver.com', body)
        self.assertIn('compartido entre los eventos', body)
        self.assertIn('HOST_ADDRESS', body)
        self.assertEqual(self.confirm(token, action='cancel')[0], 200)
        self.assertEqual(self.confirm(token)[0], 409)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertEqual(sorted((self.web / 'static/qr').glob('*.jpg')), files)
        self.assertFalse(self.qr_capture.exists())
        self.assertEqual(self.count_mail(), 0)

    def test_classic_list_redirects_to_the_single_react_list_without_side_effects(self):
        before = (self.web / 'db.sqlite').read_bytes()
        for base in ('', '/event-retiro'):
            if base:
                self.jar.clear()
                self.login(base)
            for suffix, target in (('', '#participantes'), ('?event_id=1', '#participantes/1')):
                status, headers, body = self.request(base + '/templates/subscription/list.php' + suffix)
                self.assertEqual(status, 303, body)
                if not self.cli:
                    self.assertEqual(headers['Location'], base + '/templates/index.php' + target)
            self.assertEqual(self.request(base + '/templates/subscription/list.php?event_id=01')[0], 400)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertFalse(self.qr_capture.exists())
        self.assertEqual(self.count_mail(), 0)

    def test_confirm_inactive_updates_only_qr_never_sends_mail_and_is_single_use(self):
        token, body = self.review(subscription='20')
        self.assertIn('inactiva', body)
        before = self.query('SELECT id,person_id,datetime,active FROM subscription ORDER BY id')
        self.assertEqual(self.confirm(token, subscription='20', action='cancel')[0], 200)
        self.assertFalse(self.qr_capture.exists())
        token, _ = self.review(subscription='20')
        status, _, body = self.confirm(token, subscription='20', notes='Staff note', recipient='forged@example.test')
        self.assertEqual(status, 200, body)
        self.assertIn('QR actualizado. No se envió ningún correo.', body)
        self.assertEqual(self.query('SELECT id,person_id,datetime,active FROM subscription ORDER BY id'), before)
        self.assertEqual(self.query('SELECT subscription_id,person_id,event_id,status,notes FROM qr_regeneration'), [(20,60,1,'completed','Staff note')])
        new_url = self.query('SELECT qr FROM subscription WHERE id=20')[0][0]
        for route in ('/templates/subscription/list.php?format=json', '/templates/event/detail.php?format=json&event_id=1'):
            status, _, body = self.request(route)
            self.assertEqual(status, 200, body)
            data = json.loads(body)['data']
            rows = data['subscriptions'] if 'event/detail.php' in route else data
            row = next(row for row in rows if row['id'] == 20)
            self.assertEqual(row['qr_preview']['url'], new_url)
            self.assertIsNone(row['qr_preview']['error'])
        self.assertEqual(self.query('SELECT count(*) FROM event_registration'), [(3,)])
        self.assertEqual(self.confirm(token, subscription='20')[0], 409)
        self.assertEqual(len(self.qr_capture.read_text().splitlines()), 1)
        self.assertEqual(self.count_mail(), 0)

    def test_missing_host_is_read_only_and_does_not_create_configuration(self):
        before = (self.web / 'db.sqlite').read_bytes()
        config = self.web / '.env'
        config.unlink()
        self.assertEqual(self.request(ROUTE)[0], 200)
        self.assertFalse(config.exists())
        status, _, body = self.request(ROUTE, {'csrf_token':self.csrf(),'subscription_id':'10'})
        self.assertEqual(status, 409, body)
        self.assertIn('HOST_ADDRESS', body)
        self.assertFalse(config.exists())
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertFalse(self.qr_capture.exists())
        self.assertEqual(self.count_mail(), 0)

    def test_auth_csrf_ids_expiry_and_retargeting_fail_without_network(self):
        self.assertEqual(self.request(ROUTE, {'subscription_id':'10'})[0], 403)
        for data in ({'subscription_id':'01'}, {'subscription_id':'999'}, {'subscription_id':'30','event_id':'1'},
                     {'subscription_id':'10','event_id':'999'}):
            self.assertEqual(self.request(ROUTE, dict(csrf_token=self.csrf(), **data))[0], 409)
        token, _ = self.review()
        self.assertEqual(self.confirm(token, event=None)[0], 409)
        token, _ = self.review()
        self.assertEqual(self.confirm(token, subscription='20')[0], 409)
        token, _ = self.review()
        for path in self.sessions.glob('sess_*'):
            path.write_bytes(re.sub(rb'"expires";i:\d+;', b'"expires";i:1;', path.read_bytes()))
        self.assertEqual(self.confirm(token)[0], 409)
        self.jar.clear()
        self.assertEqual(self.request(ROUTE)[0], 303)
        self.assertIn(self.request('/lib/qr_regeneration.php')[0], (403,404))
        self.assertFalse(self.qr_capture.exists())
        self.assertEqual(self.count_mail(), 0)

    def test_host_and_saved_qr_changes_require_new_review_and_global_action_uses_host(self):
        token, _ = self.review(event=None)
        env = self.web / '.env'
        env.write_text('HOST_ADDRESS=https://production.example.test/event-retiro\nSEND_EMAIL=1\n')
        self.assertEqual(self.confirm(token, event=None)[0], 409)
        token, _ = self.review(event=None)
        self.query("UPDATE subscription SET qr='changed' WHERE id=10")
        self.assertEqual(self.confirm(token, event=None)[0], 409)
        token, _ = self.review(event=None)
        status, _, body = self.confirm(token, event=None, target='https://forged.example.test')
        self.assertEqual(status, 200, body)
        self.assertTrue(self.qr_capture.read_text().startswith('https://production.example.test/event-retiro/attendance_log.php/?'))
        self.assertEqual(self.query('SELECT event_id FROM qr_regeneration'), [(None,)])
        self.assertEqual(self.count_mail(), 0)

    def test_regeneration_invalidates_pending_email_review_but_never_sends_it(self):
        email_token, _ = test_event_email.EventEmailRoutesTest.review(self)
        token, _ = self.review()
        self.assertEqual(self.confirm(token)[0], 200)
        self.assertEqual(test_event_email.EventEmailRoutesTest.confirm(self, email_token)[0], 409)
        self.assertEqual(self.count_mail(), 0)

    def test_failed_write_is_an_error_not_success_or_retry(self):
        token, _ = self.review()
        old = self.query('SELECT qr FROM subscription WHERE id=10')
        self.query("CREATE TRIGGER reject_qr BEFORE UPDATE ON subscription BEGIN SELECT RAISE(ABORT,'synthetic'); END")
        status, _, body = self.confirm(token)
        self.assertEqual(status, 503, body)
        self.assertNotIn('QR actualizado. No se envió', body)
        self.assertIn('Fallo técnico', body)
        self.assertEqual(self.query('SELECT qr FROM subscription WHERE id=10'), old)
        self.assertEqual(self.query('SELECT status FROM qr_regeneration'), [('failed',)])
        self.assertEqual(self.confirm(token)[0], 409)
        self.assertEqual(len(self.qr_capture.read_text().splitlines()), 1)
        self.assertEqual(self.count_mail(), 0)

    def test_subdirectory_actions_back_link_and_new_image_stay_protected(self):
        self.jar.clear()
        self.login('/event-retiro')
        (self.web / 'event-retiro/.env').write_text('HOST_ADDRESS=http://localhost/event-retiro\nSEND_EMAIL=1\n')
        token, body = self.review(base='/event-retiro')
        self.assertIn('action="/event-retiro' + ROUTE + '"', body)
        self.assertIn('/event-retiro/templates/index.php#participantes/1', body)
        status, _, body = self.confirm(token, base='/event-retiro')
        self.assertEqual(status, 200, body)
        url = self.query('SELECT qr FROM subscription WHERE id=10', base='/event-retiro')[0][0]
        path = url.removeprefix('http://localhost')
        self.assertEqual(self.request(path)[0], 200)
        self.jar.clear()
        self.assertEqual(self.request(path)[0], 303)
        self.assertEqual(self.count_mail(), 0)


if __name__ == '__main__':
    unittest.main(verbosity=2)

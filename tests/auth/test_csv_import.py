"""Disposable CSV tests with fake QR/sendmail. No real delivery or working data writes."""
import base64
from contextlib import closing
import csv
import io
import json
import os
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
    ['2', 'Bruno', 'Prueba', 'not-an-email', '+59123456789', 'Cochabamba'],
    ['3', 'Carla', 'Ríos', 'carla@example.test', '+591 34567890', 'Santa Cruz'],
]
LOCAL_ROSTERS = [ROOT / 'tests/data/2026/participantes.csv',
                 ROOT / 'tests/data/Lista_de_inscritos_Evento_SS_43_2026 .csv']


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
        fixtures = [path for path in LOCAL_ROSTERS if path.exists()]
        if not fixtures:
            self.skipTest('Local participant roster is deliberately not committed.')
        for fixture in fixtures:
            with self.subTest(fixture=fixture.name):
                report = self.read(fixture.read_bytes())
                with fixture.open(encoding='utf-8-sig', newline='') as handle:
                    records = list(csv.reader(handle))
                nonempty = [row for row in records if any(value.strip() for value in row)]
                self.assertEqual(report['profile'], '2026')
                self.assertEqual(len(report['rows']) + len(report['errors']), len(nonempty) - 1)
                self.assertEqual(report['blank_rows'], len(records) - len(nonempty))
                self.assertEqual(report['errors'], [])

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
        self.assertIn('correo', report['errors'][2]['message'])

    def test_quoted_multiline_fields_and_physical_line_numbers(self):
        report = self.read(csv_text([['1', 'Ana, "María"\nTest', 'Flores', 'ana@example.test', '12345', 'La Paz'], PEOPLE[1]], leading=False))
        self.assertEqual(report['rows'][0]['fullname'], 'Ana, "María"\nTest Flores')
        self.assertEqual(report['errors'][0]['row'], 5)

    def test_phone_issues_warn_preserve_original_and_never_guess(self):
        phones = ['', '   ', '591+23456789', ' 591+ 234-56789 ', '(+591) 123-45678',
                  '123 ext. 45', '++123', '---', 'sin celular']
        report = self.read(csv_text([
            [str(index), 'Test', str(index), f'test{index}@example.test', phone, 'La Paz']
            for index, phone in enumerate(phones)]))
        self.assertEqual(report['errors'], [])
        self.assertEqual([row['phone'] for row in report['rows']],
                         ['', '', '591+23456789', '591+ 234-56789', '+59112345678',
                          '123 ext. 45', '++123', '---', 'sin celular'])
        self.assertEqual([row['warnings'][0]['code'] if row['warnings'] else None for row in report['rows']],
                         ['phone_missing', 'phone_missing', 'phone_unverified', 'phone_unverified',
                          None, 'phone_unverified', 'phone_unverified', 'phone_unverified', 'phone_unverified'])
        self.assertEqual([warning['row'] for warning in report['warnings']], [7, 8, 9, 10, 12, 13, 14, 15])
        self.assertTrue(all('cancelar o continuar' in warning['message'] for warning in report['warnings']))

    def test_legacy_phone_and_payment_warnings_are_both_visible(self):
        report = self.read(csv_text([['Ana', 'Test', 'ana@example.test', '591+ 123', '0'],
                                    ['Bruno', 'Test', 'bruno@example.test', '', '1']],
                                   ['Nombre', 'Apellidos', 'Correo electrónico', 'Número de celular', 'valido']))
        self.assertEqual(report['errors'], [])
        self.assertEqual(report['rows'][0]['phone'], '591+ 123')
        self.assertEqual([warning['code'] for warning in report['rows'][0]['warnings']],
                         ['phone_unverified', 'payment_unverified'])
        self.assertEqual([warning['code'] for warning in report['rows'][1]['warnings']], ['phone_missing'])
        self.assertEqual(len(report['warnings']), 3)

    def test_name_and_email_remain_required_with_phone_warnings(self):
        report = self.read(csv_text([['1', '', '', 'test@example.test', '', 'La Paz'],
                                    ['2', 'Test', '', '', 'bad phone', 'La Paz'],
                                    ['3', 'Test', '', 'bad-email', '', 'La Paz']]))
        self.assertEqual(report['rows'], [])
        self.assertEqual([error['row'] for error in report['errors']], [7, 8, 9])
        self.assertEqual(report['warnings'], [])

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
            $request = $GLOBALS['request_url'];
            generate_qr('No Phone', 'no-phone@example.test', '');
            echo json_encode(['request' => $request, 'qr' => $qr, 'blank_request' => $GLOBALS['request_url']]);'''
        result = subprocess.run(['php', '-n', '-d', 'disable_functions=mail', '-r', code], capture_output=True, check=True)
        self.assertEqual(result.stderr, b'')
        data = json.loads(result.stdout)
        attendance = urllib.parse.parse_qs(urllib.parse.urlsplit(data['request']).query)['data'][0]
        self.assertEqual(urllib.parse.urlsplit(attendance).path, '/event-retiro/attendance_log.php/')
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(attendance).query), {
            'fullname': ["Ana & Test O'Flores"], 'email': ['ana+test@example.test'], 'phone': ['+59112345678'],
        })
        self.assertTrue(data['qr'].startswith('https://example.test/event-retiro/static/qr/'))
        blank = urllib.parse.parse_qs(urllib.parse.urlsplit(data['blank_request']).query)['data'][0]
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(blank).query, keep_blank_values=True), {
            'fullname': ['No Phone'], 'email': ['no-phone@example.test'], 'phone': [''],
        })


class CsvImportDomainTest(unittest.TestCase):
    """Real persistence in memory, mail disabled and QR transport replaced only in a copy."""

    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='rinchen-csv-domain-')
        cls.web = Path(cls.temp.name) / 'src'
        shutil.copytree(ROOT / 'src/lib', cls.web / 'lib')
        for relative in ('attendance/log.php', 'subscription/upload.php', 'subscription/activation.php',
                         'header.php', 'footer.php', 'config.php'):
            target = cls.web / 'templates' / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / 'src/templates' / relative, target)
        (cls.web / '.env').write_text('HOST_ADDRESS=http://localhost\nSEND_EMAIL=0\n')
        (cls.web / 'lib/qr.php').write_text('''<?php
require_once __DIR__ . '/settings.php';
function generate_qr(string $fullname, string $email, string $phone): string {
    if ($GLOBALS['qr_fail'] ?? false) { throw new RuntimeException('Synthetic QR failure'); }
    $GLOBALS['qr_calls']++;
    return 'http://localhost/static/qr/' . hash('sha256', json_encode([$fullname, $email, $phone])) . '.jpg';
}
''')

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def check(self, code, contents=None):
        prefix = f'''require {json.dumps(str(self.web / 'lib/subscription.php'))};
require {json.dumps(str(self.web / 'lib/qr_admission.php'))};
$contents = stream_get_contents(STDIN); $review = SubscriptionCsv::readContents($contents);
$db = new SQLite3(':memory:'); $db->enableExceptions(true); create_setup_schema($db);
$db->exec("INSERT INTO event VALUES (1,'Test A','','2026-11-12','2026-11-15'),(2,'Test B','','2026-11-12','2026-11-15')");
$import = new SubscriptionCsvImport($db); $registration = new EventRegistration($db);
$GLOBALS['qr_calls'] = 0;
function check($ok, $message='Assertion failed') {{ if (!$ok) throw new RuntimeException($message); }}
function rejected($action) {{
    try {{ $action(); }} catch (DomainException $e) {{ return; }}
    throw new RuntimeException('Expected rejection');
}}
'''
        result = subprocess.run(['php', '-d', 'disable_functions=mail,curl_init,curl_exec,fsockopen,stream_socket_client',
                                 '-d', 'allow_url_fopen=0', '-d', f'error_log={self.web.parent / "errors.log"}',
                                 '-r', prefix + code + '\necho "OK";'],
                                input=csv_text() if contents is None else contents,
                                capture_output=True, text=True, env={**os.environ,
                                    'RINCHEN_AUTH_STATE_DIR': str(self.web.parent / 'auth-state'), 'RINCHEN_HTTPS': '0'})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stdout, 'OK')
        self.assertEqual(result.stderr, '')
        self.assertFalse((self.web / 'db.sqlite').exists())

    def test_phone_warning_requires_confirmation_before_any_side_effect(self):
        self.check('''
check($review['rows'][0]['warnings'][0]['code']==='phone_unverified');
rejected(fn()=>$import->run($review,false));
check((int)$db->querySingle('SELECT count(*) FROM person')===0);
check((int)$db->querySingle("SELECT count(*) FROM sqlite_master WHERE name='csv_import_audit'")===0);
check($GLOBALS['qr_calls']===0);
// The ordinary manual registration validator remains strict.
try { (new Person('Test','test@example.test',''))->validate(); throw new RuntimeException('Phone policy leaked'); }
catch (Exception $e) { check($e->getMessage()==='Phone is required.'); }
''', csv_text([['1', 'Test', 'Person', 'test@example.test', '591+ 123', 'La Paz']]))

    def test_phone_warning_controller_cancellation_and_confirmed_continuation(self):
        for action in ('cancel', 'confirm'):
            with self.subTest(action=action):
                self.check('''
$path=''' + json.dumps(str(self.web / 'db.sqlite')) + ''';
$copy=new SQLite3($path); check($db->backup($copy)); $copy->close(); $before=file_get_contents($path);
$_SERVER['REQUEST_METHOD']='POST'; $_SERVER['HTTP_HOST']='localhost';
$_SERVER['SCRIPT_NAME']='/templates/subscription/upload.php';
$_SERVER['SCRIPT_FILENAME']=''' + json.dumps(str(self.web / 'templates/subscription/upload.php')) + ''';
$_SERVER['DOCUMENT_ROOT']=''' + json.dumps(str(self.web)) + ''';
auth_start(); $_SESSION['auth_user']='admin'; $_SESSION['auth_created']=time(); $_SESSION['auth_seen']=time();
$_SESSION['csv_import_review']=['token'=>'server-review','expires'=>time()+600,'contents'=>$contents,
    'event_id'=>1,'scoped_review'=>$registration->scopeCsv($review,1)];
$_POST=['csrf_token'=>auth_csrf_token(),'event_id'=>'1','import_token'=>'server-review','action'=>''' + json.dumps(action) + ''',
    'warnings'=>'[]','phone'=>'Forged phone'];
ob_start(); require $_SERVER['SCRIPT_FILENAME']; $html=ob_get_clean();
check((http_response_code() ?: 200)===200,'Wrong upload controller status');
check(!isset($_SESSION['csv_import_review']),'Review token not consumed'); session_write_close();
$saved=new SQLite3($path,SQLITE3_OPEN_READONLY);
if (''' + json.dumps(action) + '''==='cancel') {
    check(file_get_contents($path)===$before && $GLOBALS['qr_calls']===0,'Cancellation had side effects');
    check(str_contains($html,'Importación cancelada'));
} else {
    check((int)$saved->querySingle('SELECT count(*) FROM subscription')===1);
    check($saved->querySingle('SELECT phone FROM person')==='591+ 123','Client phone replaced reviewed original');
    $audit=$saved->querySingle('SELECT * FROM csv_import_audit',true);
    check(json_decode($audit['warnings_json'],true)[0]['code']==='phone_unverified','Warning not re-evaluated');
    check($audit['staff_confirmed']===1 && $audit['actor']==='admin');
    check(str_contains($html,'No se enviaron correos.'));
}
$saved->close(); unlink($path);
''', csv_text([['1', 'Test', 'Person', 'test@example.test', '591+ 123', 'La Paz']]))

    def test_confirmed_phone_warnings_persist_audit_and_reimport_once(self):
        self.check('''
$report=$import->run($review,true,'admin','Reviewed phone');
check($report['imported']===2 && $report['errors']===[] && $report['mail_errors']===[]);
$people=$registration->rows('SELECT * FROM person ORDER BY id');
check(array_column($people,'phone')===['591+ 123','']);
$audits=$registration->rows('SELECT * FROM csv_import_audit ORDER BY id');
foreach ($audits as $i=>$audit) {
    check($audit['actor']==='admin' && $audit['staff_confirmed']===1 && $audit['notes']==='Reviewed phone');
    check(json_decode($audit['warnings_json'],true)===$review['rows'][$i]['warnings']);
    check((bool)preg_match('/^\\d{4}-\\d{2}-\\d{2} /',$audit['imported_at']));
}
$again=$import->run($review,true,'admin');
check($again['imported']===0 && $again['duplicates']===2 && $again['mail_errors']===[] && $GLOBALS['qr_calls']===2);
check((int)$db->querySingle('SELECT count(*) FROM csv_import_audit')===2);
check($db->querySingle('PRAGMA integrity_check')==='ok');
''', csv_text([['1', 'Test', 'One', 'one@example.test', '591+ 123', 'La Paz'],
               ['2', 'Test', 'Two', 'two@example.test', '', 'La Paz']]))

    def test_activation_preserves_phone_warnings_and_is_atomic(self):
        self.check('''
$import->run($review,true,'admin');
$before=$registration->rows('SELECT id,person_id,datetime,qr FROM subscription ORDER BY id');
foreach ([0,1] as $active) {
    foreach ([1,2] as $id) { Subscription::setActive($db,$id,$active); }
    check(array_column($registration->rows('SELECT active FROM person ORDER BY id'),'active')===[$active,$active]);
    check(array_column($registration->rows('SELECT active FROM subscription ORDER BY id'),'active')===[$active,$active]);
}
check($registration->rows('SELECT id,person_id,datetime,qr FROM subscription ORDER BY id')===$before);
check(array_column($registration->rows('SELECT phone FROM person ORDER BY id'),'phone')===['591+ 123','']);
foreach ([[0,0],[999,0],[1,2]] as [$id,$active]) { rejected(fn()=>Subscription::setActive($db,$id,$active)); }
$db->exec("CREATE TRIGGER fail_activation BEFORE UPDATE ON subscription BEGIN SELECT RAISE(ABORT,'Synthetic status failure'); END");
try { Subscription::setActive($db,1,0); throw new RuntimeException('Failure was ignored'); }
catch (Exception $e) { check(str_contains($e->getMessage(),'Synthetic status failure')); }
check((int)$db->querySingle('SELECT active FROM person WHERE id=1')===1);
check((int)$db->querySingle('SELECT active FROM subscription WHERE id=1')===1);
$db->exec('DROP TRIGGER fail_activation');
foreach ([999,'invalid',0] as $personId) {
    $statement=$db->prepare('UPDATE subscription SET person_id=:person WHERE id=1');
    $statement->bindValue(':person',$personId,is_int($personId) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    $statement->execute(); $statement->close();
    try { Subscription::setActive($db,1,0); throw new RuntimeException('Broken identity accepted'); }
    catch (Exception $e) { check(str_contains($e->getMessage(),'persona válida')); }
    check((int)$db->querySingle('SELECT active FROM subscription WHERE id=1')===1);
}
check($GLOBALS['qr_calls']===2);
''', csv_text([['1', 'Test', 'One', 'one@example.test', '591+ 123', 'La Paz'],
               ['2', 'Test', 'Two', 'two@example.test', '', 'La Paz']]))

    def test_activation_controller_keeps_auth_csrf_and_identifier_checks(self):
        cases = [('1', '0', True, True, 302), ('1', '1', True, True, 302),
                 ('1', '0', False, True, 403), ('1', '0', True, False, 401),
                 ('0', '0', True, True, 409), ('999', '0', True, True, 409),
                 ('1 OR 1=1', '0', True, True, 409), ('1', '2', True, True, 409),
                 ('1', [], True, True, 409)]
        for record, active, csrf, authenticated, status in cases:
            with self.subTest(record=record, active=active, csrf=csrf, authenticated=authenticated):
                self.check('''
$import->run($review,true,'admin');
$path=''' + json.dumps(str(self.web / 'db.sqlite')) + ''';
$copy=new SQLite3($path); check($db->backup($copy)); $copy->close(); $before=file_get_contents($path);
$_SERVER['REQUEST_METHOD']='POST'; $_SERVER['HTTP_ACCEPT']='application/json';
$_SERVER['SCRIPT_NAME']='/templates/subscription/activation.php';
$_SERVER['SCRIPT_FILENAME']=''' + json.dumps(str(self.web / 'templates/subscription/activation.php')) + ''';
$_SERVER['DOCUMENT_ROOT']=''' + json.dumps(str(self.web)) + ''';
auth_start();
if (''' + json.dumps(authenticated) + ''') {
    $_SESSION['auth_user']='admin'; $_SESSION['auth_created']=time(); $_SESSION['auth_seen']=time();
}
$_POST=json_decode(''' + json.dumps(json.dumps({'id': record, 'active': active})) + ''',true);
if (''' + json.dumps(csrf) + ''') { $_POST['csrf_token']=auth_csrf_token(); }
ob_start();
register_shutdown_function(static function () use ($path,$before): void {
    ob_end_clean(); session_write_close();
    check(http_response_code()===''' + str(status) + ''','Wrong activation controller status');
    $saved=new SQLite3($path,SQLITE3_OPEN_READONLY);
    check($saved->querySingle('SELECT phone FROM person')==='591+ 123','Activation altered phone');
    foreach (['person','subscription'] as $table) {
        check((int)$saved->querySingle("SELECT active FROM $table")===''' + (active if status == 302 else '1') + ''');
    }
    if (''' + str(status) + '''!==302) { check(file_get_contents($path)===$before,'Rejected request wrote data'); }
    check($GLOBALS['qr_calls']===1,'Activation regenerated QR');
    $saved->close(); unlink($path); echo 'OK';
});
require $_SERVER['SCRIPT_FILENAME'];
''', csv_text([['1', 'Test', 'Person', 'test@example.test', '591+ 123', 'La Paz']]))

    def test_phone_warning_scoped_membership_is_audited_and_reused(self):
        self.check('''
$scoped=$registration->scopeCsv($review,1);
rejected(fn()=>$import->run($scoped,false,'admin','',1));
$report=$import->run($scoped,true,'admin','',1);
check($report['imported']===1 && $report['errors']===[]);
$membership=$registration->rows('SELECT * FROM event_registration')[0];
check(json_decode($membership['warnings_json'],true)===$review['rows'][0]['warnings']);
check($membership['staff_confirmed']===1 && $membership['actor']==='admin');
$second=$import->run($registration->scopeCsv($review,2),true,'admin','',2);
check($second['linked']===1 && $second['imported']===0 && $second['mail_errors']===[] && $GLOBALS['qr_calls']===1);
check($db->query('PRAGMA foreign_key_check')->fetchArray(SQLITE3_ASSOC)===false);
''', csv_text([['1', 'Test', 'Person', 'test@example.test', '', 'La Paz']]))

    def test_blank_and_uncertain_phones_use_exact_qr_identity_without_merging(self):
        self.check('''
$report=$import->run($review,true,'admin'); check($report['imported']===2);
$qr=new QrAdmission($db);
check($qr->findPerson('Same Person','same@example.test','')===1);
check($qr->findPerson('Same Person','same@example.test','591+ 123')===2);
rejected(fn()=>$qr->findPerson('Same Person','same@example.test','+591123'));
$db->exec("INSERT INTO person (fullname,email,phone,active) VALUES ('Same Person','same@example.test','',1)");
rejected(fn()=>$qr->findPerson('Same Person','same@example.test',''));
$again=$import->run($review,true,'admin');
check(count($again['errors'])===1 && $again['duplicates']===1 && $GLOBALS['qr_calls']===2);
''', csv_text([['1', 'Same', 'Person', 'same@example.test', '', 'La Paz'],
               ['2', 'Same', 'Person', 'same@example.test', '591+ 123', 'La Paz']]))

    def test_confirmed_phone_warning_cannot_override_technical_failure(self):
        self.check('''
$GLOBALS['qr_fail']=true;
$report=$import->run($review,true,'admin');
check($report['imported']===0 && count($report['errors'])===1 && $report['mail_errors']===[]);
check((int)$db->querySingle('SELECT count(*) FROM person')===0);
$GLOBALS['qr_fail']=false;
$db->exec("CREATE TRIGGER fail_write BEFORE INSERT ON subscription BEGIN SELECT RAISE(ABORT,'Synthetic write failure'); END");
$report=$import->run($review,true,'admin');
check($report['imported']===0 && count($report['errors'])===1 && $report['mail_errors']===[]);
check((int)$db->querySingle('SELECT count(*) FROM person')===0);
''', csv_text([['1', 'Test', 'Person', 'test@example.test', '', 'La Paz']]))

    def test_qr_controller_blank_phone_is_present_typed_exact_and_unambiguous(self):
        identity = {'fullname': 'Test Person', 'email': 'test@example.test', 'phone': ''}
        cases = [(identity, False, 200),
                 ({**identity, 'phone': '591+ 123'}, False, 200),
                 ({key: value for key, value in identity.items() if key != 'phone'}, False, 409),
                 ({**identity, 'phone': []}, False, 409),
                 ({**identity, 'phone': '123'}, False, 409),
                 ({**identity, 'fullname': ''}, False, 409),
                 ({**identity, 'email': ''}, False, 409),
                 (identity, True, 409)]
        for query, duplicate, expected in cases:
            with self.subTest(query=query, duplicate=duplicate):
                self.check('''
$import->run($review,true,'admin');
if (''' + json.dumps(duplicate) + ''') {
    $db->exec("INSERT INTO person (fullname,email,phone,active) VALUES ('Test Person','test@example.test','',1)");
}
$db->exec("INSERT INTO event_session VALUES (1,1,'Test session',datetime('now','-1 hour'),datetime('now','+1 hour'))");
$path=''' + json.dumps(str(self.web / 'db.sqlite')) + ''';
$copy=new SQLite3($path); check($db->backup($copy)); $copy->close();
$before=file_get_contents($path);
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/templates/attendance/log.php';
$_SERVER['SCRIPT_FILENAME']=''' + json.dumps(str(self.web / 'templates/attendance/log.php')) + ''';
$_SERVER['DOCUMENT_ROOT']=''' + json.dumps(str(self.web)) + ''';
$_GET=json_decode(''' + json.dumps(json.dumps(query)) + ''',true);
auth_start(); $_SESSION['auth_user']='admin'; $_SESSION['auth_created']=time(); $_SESSION['auth_seen']=time();
ob_start(); require $_SERVER['SCRIPT_FILENAME']; $html=ob_get_clean(); session_write_close();
check((http_response_code() ?: 200)===''' + str(expected) + ''','Wrong QR controller status');
check(file_get_contents($path)===$before,'GET wrote attendance or schema');
unlink($path);
''', csv_text([['1', 'Test', 'Person', 'test@example.test', '', 'La Paz'],
               ['2', 'Test', 'Person', 'test@example.test', '591+ 123', 'La Paz']]))

    def test_optional_local_rosters_import_in_memory_without_mail_or_network(self):
        fixtures = [path for path in LOCAL_ROSTERS if path.exists()]
        if not fixtures:
            self.skipTest('Local participant roster is deliberately not committed.')
        for fixture in fixtures:
            with self.subTest(fixture=fixture.name):
                self.check('''
check($review['errors']===[]); $expected=count($review['rows']);
foreach ([null,1] as $event) {
    $scoped=$event===null ? $review : $registration->scopeCsv($review,$event);
    $report=$import->run($scoped,true,'admin','Isolated roster check',$event);
    check($report['errors']===[]);
    check($event===null ? $report['imported']===$expected : $report['linked']===$expected);
    check($report['mail_errors']===[]);
}
$again=$import->run($registration->scopeCsv($review,1),true,'admin','',1);
check($again['duplicates']===$expected && $again['imported']===0 && $again['mail_errors']===[]);
check($GLOBALS['qr_calls']===$expected);
foreach (['person','subscription','csv_import_audit','event_registration'] as $table) {
    check((int)$db->querySingle("SELECT count(*) FROM $table")===$expected);
}
$qr=new QrAdmission($db);
foreach ($review['rows'] as $row) { check($qr->findPerson($row['fullname'],$row['email'],$row['phone'])>0); }
check($db->query('PRAGMA foreign_key_check')->fetchArray(SQLITE3_ASSOC)===false);
check($db->querySingle('PRAGMA integrity_check')==='ok');
''', fixture.read_text(encoding='utf-8-sig'))


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
                    CREATE TABLE event (id INTEGER PRIMARY KEY, name TEXT, description TEXT, start_date TEXT, end_date TEXT);
                    INSERT INTO event VALUES (1,'Test A','','2026-11-12','2026-11-15'),(2,'Test B','','2026-11-12','2026-11-15');
                    INSERT INTO person VALUES (50, 'Existing person', 'existing@example.test', '12345', 1);
                ''')
        self.login()

    def query(self, sql, values=(), base=''):
        with closing(sqlite3.connect(self.web / base.lstrip('/') / 'db.sqlite')) as db:
            result = db.execute(sql, values).fetchall()
            db.commit()
            return result

    def upload(self, contents=None, base='', csrf=True, event_id='1'):
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
            csrf_token=self.csrf(base), action=action, import_token=token, event_id='1', **extra))

    def attempts(self, path):
        return len(path.read_text().splitlines()) if path.exists() else 0

    def test_get_preview_cancel_do_not_write_or_send(self):
        before = (self.web / 'db.sqlite').read_bytes()
        self.assertEqual(self.request('/templates/subscription/upload.php')[0], 303)
        self.assertEqual(self.request('/templates/subscription/upload.php?event_id=1')[0], 200)
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
        self.assertEqual(self.attempts(self.mail_calls), 0)
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
        self.assertEqual(self.attempts(self.mail_calls), 0)

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
        self.assertEqual(self.attempts(self.mail_calls), 0)

    def test_duplicate_rows_within_one_csv_are_written_once(self):
        token, _ = self.review(csv_text([PEOPLE[0], PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 1.', body)
        self.assertIn('Duplicados omitidos: 1.', body)
        self.assertEqual(self.attempts(self.mail_calls), 0)

    def test_ambiguous_existing_people_are_not_arbitrarily_merged(self):
        for _ in range(2):
            self.query('INSERT INTO person (fullname, email, phone, active) VALUES (?, ?, ?, 1)',
                       ("Ana <test> O'Flores", 'ana@example.test', '+59112345678'))
        token, body = self.review(csv_text([PEOPLE[0]]))
        self.assertIsNone(token)  # scoped review rejects ambiguous identity before confirmation
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
        self.assertEqual(self.attempts(self.mail_calls), 0)

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

    def test_phone_warnings_cancel_and_confirm_without_email(self):
        config = self.web / '.env'
        config.write_text(config.read_text().replace('SEND_EMAIL=1', 'SEND_EMAIL=0'))
        contents = csv_text([['1', 'Test', 'One', 'one@example.test', '', 'La Paz'],
                             ['2', 'Test', 'Two', 'two@example.test', '591+ <test>', 'La Paz']])
        before = (self.web / 'db.sqlite').read_bytes()
        token, body = self.review(contents)
        self.assertIn('sin celular', body)
        self.assertIn('Se conservará el valor original', body)
        self.assertIn('591+ &lt;test&gt;', body)
        self.assertIn('Continuar de todos modos e importar', body)
        self.assertEqual(self.request('/templates/subscription/upload.php', {
            'action': 'confirm', 'import_token': token})[0], 403)
        self.assertEqual(self.decide(token, 'cancel')[0], 200)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertFalse(self.qr_calls.exists())
        self.assertFalse(self.mail_capture.exists())
        token, _ = self.review(contents)
        status, _, body = self.decide(token, warnings='[]', phone='Forged phone')
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT phone FROM person WHERE id>50 ORDER BY id'), [('',), ('591+ <test>',)])
        audits = self.query('SELECT warnings_json,staff_confirmed,actor FROM csv_import_audit ORDER BY id')
        self.assertEqual([json.loads(row[0])[0]['code'] for row in audits], ['phone_missing', 'phone_unverified'])
        self.assertTrue(all(row[1:] == (1, 'admin') for row in audits))
        self.assertFalse(self.mail_capture.exists())
        self.assertEqual(self.decide(token)[0], 409)
        for active in (0, 1):
            for subscription_id in (1, 2):
                status, _, body = self.request('/templates/subscription/activation.php', {
                    'csrf_token': self.csrf(), 'id': subscription_id, 'active': active,
                })
                self.assertEqual(status, 302, body)
            self.assertEqual(self.query('SELECT active FROM person WHERE id>50 ORDER BY id'), [(active,), (active,)])
            self.assertEqual(self.query('SELECT active FROM subscription ORDER BY id'), [(active,), (active,)])
        self.assertEqual(self.query('SELECT phone FROM person WHERE id>50 ORDER BY id'), [('',), ('591+ <test>',)])
        token, _ = self.review(contents)
        self.assertIn('Duplicados omitidos: 2.', self.decide(token)[2])
        self.assertEqual(self.attempts(self.qr_calls), 2)

    def test_empty_phone_qr_get_is_read_only_and_post_retains_csrf(self):
        config = self.web / '.env'
        config.write_text(config.read_text().replace('SEND_EMAIL=1', 'SEND_EMAIL=0'))
        token, _ = self.review(csv_text([['1', 'Test', 'Person', 'test@example.test', '', 'La Paz']]))
        self.assertEqual(self.decide(token)[0], 200)
        self.query('CREATE TABLE event_session (id INTEGER PRIMARY KEY,event_id INTEGER,name TEXT,start_date TEXT,end_date TEXT)')
        self.query("INSERT INTO event_session VALUES (1,1,'Test',datetime('now','-1 hour'),datetime('now','+1 hour'))")
        subprocess.run(['php', str(self.web / 'migrations/0006_qr_admission.php')], check=True, capture_output=True)
        identity = urllib.parse.urlencode(dict(fullname='Test Person', email='test@example.test', phone=''))
        status, _, body = self.request('/templates/attendance/log.php?' + identity)
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT * FROM attendance'), [])
        token = re.search(r'name="review_token" value="([a-f0-9]+)"', body)[1]
        self.assertEqual(self.request('/templates/attendance/log.php', {'review_token': token, 'decision': 'allow'})[0], 403)
        status, _, body = self.request('/templates/attendance/log.php', {
            'csrf_token': self.csrf(), 'review_token': token, 'decision': 'allow',
        })
        self.assertEqual(status, 200, body)
        self.assertEqual(self.query('SELECT person_id,event_session_id FROM attendance'), [(51, 1)])
        self.assertFalse(self.mail_capture.exists())

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

    def test_import_never_calls_even_a_failing_mail_transport(self):
        self.mail_failure.touch()
        token, _ = self.review(csv_text([PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 1.', body)
        self.assertIn('No se enviaron correos.', body)
        self.assertEqual(self.query('SELECT count(*) FROM subscription'), [(1,)])
        token, _ = self.review(csv_text([PEOPLE[0]]))
        self.decide(token)
        self.assertEqual(self.attempts(self.mail_calls), 0)

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
        self.assertEqual(self.attempts(self.mail_calls), 0)

    def test_disabled_email_does_not_affect_mail_free_import(self):
        config = self.web / '.env'
        config.write_text(config.read_text().replace('SEND_EMAIL=1', 'SEND_EMAIL=0'))
        token, _ = self.review(csv_text([PEOPLE[0]]))
        status, _, body = self.decide(token)
        self.assertEqual(status, 200, body)
        self.assertIn('Inscripciones guardadas: 1.', body)
        self.assertIn('No se enviaron correos.', body)
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

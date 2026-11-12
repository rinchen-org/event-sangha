"""HOST_ADDRESS regression checks: disposable code/config/SQLite, fake mail/QR only."""
from contextlib import closing
import base64
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest
from urllib.parse import parse_qs, urlsplit

import test_auth
import test_lab
import test_layout

ROOT = test_auth.ROOT
FILENAME = 'a' * 64 + '.jpg'


class AppUrlTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='rinchen-urls-')
        self.addCleanup(self.temp.cleanup)
        self.web = Path(self.temp.name) / 'src'
        shutil.copytree(ROOT / 'src/lib', self.web / 'lib')
        (self.web / 'static/qr').mkdir(parents=True)

    def config(self, base):
        (self.web / '.env').write_text('HOST_ADDRESS=' + base + '\nSEND_EMAIL=0\n')

    def run_php(self, code, *, qr=False):
        prefix = 'require ' + json.dumps(str(self.web / 'lib/qr_assets.php')) + ';\n'
        command = ['php', '-d', 'disable_functions=mail,curl_init,curl_exec,fsockopen,stream_socket_client',
                   '-d', 'allow_url_fopen=0', '-r', prefix + code]
        if qr:
            # PHP -n has no real cURL extension; capture the real generator's request.
            fake = '''const CURLOPT_RETURNTRANSFER=19913;
function curl_init($url) { $GLOBALS['request']=$url; return new stdClass(); }
function curl_setopt(...$args) { return true; }
function curl_exec($handle) { return base64_decode(''' + json.dumps(base64.b64encode(test_lab.fake_png()).decode()) + '''); }
function curl_close($handle) {}
'''
            command = ['php', '-n', '-d', 'disable_functions=mail,fsockopen,stream_socket_client',
                       '-d', 'allow_url_fopen=0', '-r', fake + prefix + code]
        result = subprocess.run(command, capture_output=True, text=True, check=True,
            env={**os.environ, 'HOST_ADDRESS':'https://ignored-process-env.example.test'})
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_urls_follow_each_environment_not_saved_origin_or_request_host(self):
        for base in ('http://localhost:8081', 'http://localhost:8081/event-retiro/',
                     'https://production.example.test', 'https://production.example.test/event-retiro///',
                     'http://192.168.1.20:8081/event-retiro', 'http://[::1]:8081'):
            with self.subTest(base=base):
                self.config(base)
                result = self.run_php('''$_SERVER['HTTP_HOST']='forged.example.test';
echo json_encode(['base'=>app_base_url(),'logo'=>app_url('static/images/logo-head.png'),
    'qr'=>qr_image_url('http://old.example.test/old/static/qr/''' + FILENAME + '''?ignored=1'),
    'changed'=>qr_base_changed('http://old.example.test/old/static/qr/''' + FILENAME + '''')]);''')
                self.assertEqual(result['base'], base.rstrip('/'))
                self.assertEqual(result['logo'], base.rstrip('/') + '/static/images/logo-head.png')
                self.assertEqual(result['qr'], base.rstrip('/') + '/static/qr/' + FILENAME)
                self.assertTrue(result['changed'])
                self.assertFalse((self.web / 'db.sqlite').exists())

    def test_missing_configuration_never_falls_back_to_a_hardcoded_domain_or_creates_env(self):
        result = self.run_php('''try { app_url('static/images/logo-head.png'); echo 'false'; }
catch (DomainException $e) { echo json_encode($e->getMessage()); }''')
        self.assertIn('HOST_ADDRESS', result)
        self.assertFalse((self.web / '.env').exists())

    def test_invalid_base_urls_and_external_or_traversing_paths_are_rejected(self):
        for base in ('', 'localhost:8081', '//other.example.test', 'ftp://other.example.test',
                     'https://user:password@example.test/app', 'https://example.test/app?query=1',
                     'https://example.test/app#fragment', 'https://example.test/a/../b',
                     'https://example.test/a/%2e%2e/b', 'https://bad host.test', 'https://example.test/\\private'):
            with self.subTest(base=base):
                self.config(base)
                self.assertTrue(self.run_php("try { app_base_url(); echo 'false'; } catch (DomainException $e) { echo 'true'; }"))
        self.config('https://example.test/event-retiro')
        for path in ('https://other.example.test/x', '//other.example.test/x', '../.env', '%2e%2e/.env', 'static/../.env', 'a\\b'):
            with self.subTest(path=path):
                self.assertTrue(self.run_php('try { app_url(' + json.dumps(path) + "); echo 'false'; } catch (InvalidArgumentException $e) { echo 'true'; }"))

    def test_private_qr_bytes_are_not_regenerated_or_changed_when_base_changes(self):
        png = test_lab.fake_png()
        image = self.web / 'static/qr' / FILENAME
        image.write_bytes(png)
        self.config('https://new.example.test/event-retiro')
        result = self.run_php('''$image=qr_inline_image('http://localhost:8081/static/qr/''' + FILENAME + '''');
echo json_encode(['data'=>base64_encode($image['data']),'mime'=>$image['mime']]);''')
        self.assertEqual(result['mime'], 'image/png')
        self.assertEqual(base64.b64decode(result['data']), png)
        self.assertEqual(image.read_bytes(), png)
        self.assertEqual(len(list(image.parent.iterdir())), 1)

    def test_missing_host_rejects_manual_registration_before_any_person_or_database_write(self):
        self.config('')
        result = self.run_php('require ' + json.dumps(str(self.web / 'lib/subscription.php')) + ''';
try { Subscription::subscribe_person('Synthetic','synthetic@example.test','12345'); echo 'false'; }
catch (DomainException $e) { echo 'true'; }''')
        self.assertTrue(result)
        self.assertFalse((self.web / 'db.sqlite').exists())
        self.assertEqual(list((self.web / 'static/qr').iterdir()), [])

    def test_new_qr_generation_encodes_the_configured_base_and_preserves_identity_parameters(self):
        for base in ('http://localhost:8081/', 'https://production.example.test/event-retiro/'):
            with self.subTest(base=base):
                self.config(base)
                result = self.run_php('require ' + json.dumps(str(self.web / 'lib/qr.php')) + ''';
$qr=generate_qr("Synthetic & Test",'attendee+tag@example.test','+59112345');
echo json_encode(['qr'=>$qr,'request'=>$GLOBALS['request']]);''', qr=True)
                self.assertTrue(result['qr'].startswith(base.rstrip('/') + '/static/qr/'))
                target = parse_qs(urlsplit(result['request']).query)['data'][0]
                self.assertTrue(target.startswith(base.rstrip('/') + '/attendance_log.php/?'))
                self.assertEqual(parse_qs(urlsplit(target).query), {
                    'fullname':['Synthetic & Test'], 'email':['attendee+tag@example.test'], 'phone':['+59112345'],
                })
        self.config('')
        result = self.run_php('require ' + json.dumps(str(self.web / 'lib/qr.php')) + ''';
try { generate_qr('Synthetic','test@example.test',''); echo 'false'; }
catch (DomainException $e) { echo json_encode(!isset($GLOBALS['request'])); }''', qr=True)
        self.assertTrue(result)


class AppUrlRoutesTest(unittest.TestCase):
    request = test_auth.AuthSessionTest.request
    page = test_layout.LayoutTest.page

    def setUp(self):
        test_layout.LayoutTest.setUp(self)
        destination = self.web / 'api/subscription/list.php'
        destination.parent.mkdir(parents=True)
        shutil.copyfile(ROOT / 'src/api/subscription/list.php', destination)
        self.old = 'http://localhost:8081/static/qr/' + FILENAME
        with closing(sqlite3.connect(self.database)) as db:
            db.execute('UPDATE subscription SET qr=?', (self.old,))
            db.commit()
        (self.web / 'static/qr').mkdir(exist_ok=True)
        (self.web / 'static/qr' / FILENAME).write_bytes(test_lab.fake_png())

    def test_website_and_report_api_rebase_without_writing_data_or_loading_old_origin(self):
        before = self.database.read_bytes()
        for base in ('http://localhost:8081', 'https://production.example.test/event-retiro/'):
            self.env.write_text('HOST_ADDRESS=' + base + '\nSEND_EMAIL=0\n')
            for prefix in ('', '/event-retiro'):
                response = self.page('templates/subscription/list.php', prefix)
                self.assertEqual(response['status'], 200)
                self.assertIn('src="' + base.rstrip('/') + '/static/qr/' + FILENAME + '"', response['body'])
                if not base.startswith('http://localhost'):
                    self.assertNotIn('src="' + self.old + '"', response['body'])
                    self.assertIn('el enlace dentro del QR no se modificó', response['body'])
                api = self.request(route='api/subscription/list.php', base=prefix,
                    seed={'created':0,'seen':0}, accept='application/json')
                self.assertTrue(api['body']['success'], api['body'])
                record = api['body']['data'][0]
                self.assertEqual(record['qr'], base.rstrip('/') + '/static/qr/' + FILENAME)
                self.assertEqual((record['id'], record['person']['id']), (10,50))
        self.assertEqual(self.database.read_bytes(), before)
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute('SELECT qr FROM subscription').fetchone()[0], self.old)


if __name__ == '__main__':
    unittest.main(verbosity=2)

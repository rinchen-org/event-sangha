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

    def test_roster_preview_rebases_only_existing_images_and_returns_structured_warnings(self):
        png = test_lab.fake_png()
        image = self.web / 'static/qr' / FILENAME
        image.write_bytes(png)
        stored = 'http://localhost:8081/static/qr/' + FILENAME
        for base in ('http://localhost:8081', 'https://production.example.test/event-retiro/'):
            self.config(base)
            preview = self.run_php('echo json_encode(qr_preview(' + json.dumps(stored) + '));')
            self.assertEqual(preview['url'], base.rstrip('/') + '/static/qr/' + FILENAME)
            self.assertIsNone(preview['error'])
            self.assertEqual([w['code'] for w in preview['warnings']], [] if base.startswith('http://localhost') else ['qr_base_changed'])
        self.assertEqual(image.read_bytes(), png)
        self.assertEqual(list(image.parent.iterdir()), [image])
        self.assertFalse((self.web / 'db.sqlite').exists())

    def test_roster_preview_handles_missing_bad_metadata_files_and_configuration_without_writes(self):
        self.config('https://production.example.test/event-retiro')
        values = [None, '', 'invalid', 'https://old.example.test/static/qr/' + FILENAME, ['not a string']]
        previews = self.run_php('echo json_encode(array_map(\'qr_preview\',' + json.dumps(values) + '));')
        self.assertTrue(all(p['url'] is None and p['error'] for p in previews))
        directory = self.web / 'static/qr'
        self.assertEqual(list(directory.iterdir()), [])
        image = directory / FILENAME
        image.write_bytes(test_lab.fake_png())
        config = self.web / '.env'
        for base in ('', 'https://production.example.test/event-retiro?query=1'):
            self.config(base)
            before = config.read_bytes()
            preview = self.run_php('echo json_encode(qr_preview(' + json.dumps(FILENAME) + '));')
            self.assertIsNone(preview['url'])
            self.assertIn('HOST_ADDRESS', preview['error'])
            self.assertEqual(config.read_bytes(), before)
        config.unlink()
        preview = self.run_php('echo json_encode(qr_preview(' + json.dumps(FILENAME) + '));')
        self.assertIsNone(preview['url'])
        self.assertIn('HOST_ADDRESS', preview['error'])
        self.assertFalse(config.exists())

    def test_roster_preview_rejects_links_empty_and_oversized_files_without_reading_targets(self):
        self.config('http://localhost:8081')
        directory = self.web / 'static/qr'
        image = directory / FILENAME
        outside = self.web / 'synthetic.png'
        outside.write_bytes(test_lab.fake_png())

        def unavailable():
            preview = self.run_php('echo json_encode(qr_preview(' + json.dumps(FILENAME) + '));')
            self.assertIsNone(preview['url'])
            self.assertIn('no está disponible', preview['error'])

        image.symlink_to(outside)
        unavailable()
        image.unlink()
        os.link(outside, image)
        unavailable()
        image.unlink()
        image.touch()
        unavailable()
        with image.open('wb') as stream:
            stream.truncate(2097153)
        unavailable()
        image.write_bytes(test_lab.fake_png())
        kept = self.web / 'kept-qr'
        directory.rename(kept)
        directory.symlink_to(kept, target_is_directory=True)
        unavailable()
        self.assertEqual(outside.read_bytes(), test_lab.fake_png())
        self.assertEqual((kept / FILENAME).read_bytes(), test_lab.fake_png())

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
        # Any accidental attempt to invoke the regeneration workflow must fail before networking.
        (self.web / 'lib/qr_regeneration.php').write_text('<?php throw new RuntimeException("No regeneration in read-only URL tests");')

    def roster(self, *, event=False, base='', **options):
        return self.request(route='templates/event/detail.php' if event else 'templates/subscription/list.php',
            get={'format':'json', **({'event_id':'1'} if event else {})}, base=base,
            accept='application/json', **options)

    def link_fixture(self):
        with closing(sqlite3.connect(self.database)) as db:
            db.execute("INSERT INTO event_registration (event_id,person_id,subscription_id,registered_at,actor,source,warnings_json,staff_confirmed,notes) VALUES (1,50,10,'2026-01-01','admin','test','[]',1,'')")
            db.commit()

    def test_react_global_and_event_rosters_expose_existing_qr_without_old_origins_or_writes(self):
        self.link_fixture()
        before = self.database.read_bytes()
        for host in ('http://localhost:8081', 'https://production.example.test/event-retiro/'):
            self.env.write_text('HOST_ADDRESS=' + host + '\nSEND_EMAIL=1\n')
            before_env = self.env.read_bytes()
            for base in ('', '/event-retiro'):
                for event in (False, True):
                    with self.subTest(host=host, base=base, event=event):
                        response = self.roster(event=event, base=base, seed={'created':0,'seen':0})
                        self.assertEqual(response['status'], 200, response['body'])
                        records = response['body']['data']['subscriptions'] if event else response['body']['data']
                        self.assertEqual(len(records), 1)
                        row = records[0]
                        self.assertEqual((row['id'], row['person_id']), (10,50))
                        self.assertNotIn('qr', row)
                        self.assertEqual(row['qr_preview']['url'], host.rstrip('/') + '/static/qr/' + FILENAME)
                        self.assertIsNone(row['qr_preview']['error'])
                        self.assertEqual([w['code'] for w in row['qr_preview']['warnings']], [] if host.startswith('http://localhost') else ['qr_base_changed'])
            self.assertEqual(self.env.read_bytes(), before_env)
        self.assertEqual(self.database.read_bytes(), before)
        self.assertEqual((self.web / 'static/qr' / FILENAME).read_bytes(), test_lab.fake_png())
        self.assertEqual(len(list((self.web / 'static/qr').iterdir())), 1)

    def test_missing_images_and_host_errors_keep_the_roster_usable_without_repair_or_fake_qr(self):
        self.link_fixture()
        before = self.database.read_bytes()
        (self.web / 'static/qr' / FILENAME).unlink()
        for event in (False, True):
            response = self.roster(event=event, seed={'created':0,'seen':0})
            self.assertEqual(response['status'], 200, response['body'])
            rows = response['body']['data']['subscriptions'] if event else response['body']['data']
            self.assertEqual(len(rows), 1)
            self.assertIsNone(rows[0]['qr_preview']['url'])
            self.assertIn('no está disponible', rows[0]['qr_preview']['error'])
        self.env.unlink()
        for event in (False, True):
            response = self.roster(event=event, seed={'created':0,'seen':0})
            self.assertEqual(response['status'], 200, response['body'])
            rows = response['body']['data']['subscriptions'] if event else response['body']['data']
            self.assertIsNone(rows[0]['qr_preview']['url'])
            self.assertIn('HOST_ADDRESS', rows[0]['qr_preview']['error'])
            self.assertFalse(self.env.exists())
        self.assertEqual(list((self.web / 'static/qr').iterdir()), [])
        self.assertEqual(self.database.read_bytes(), before)

    def test_qr_roster_json_retains_authentication_and_read_only_method_boundaries(self):
        self.link_fixture()
        before = self.database.read_bytes()
        session = self.request(route='templates/session.php', seed={'created':0,'seen':0})
        token = session['body']['csrfToken']
        for event in (False, True):
            for base in ('', '/event-retiro'):
                response = self.roster(event=event, base=base)
                self.assertEqual(response['status'], 401)
                self.assertNotIn('qr_preview', json.dumps(response['body']))
            response = self.roster(event=event, method='POST', sid=session['sid'])
            self.assertEqual(response['status'], 403)
            response = self.roster(event=event, method='POST', sid=session['sid'], post={'csrf_token':token})
            self.assertEqual(response['status'], 405)
        self.assertEqual(self.database.read_bytes(), before)

    def test_classic_redirect_and_report_api_preserve_data_without_loading_old_origin(self):
        before = self.database.read_bytes()
        for base in ('http://localhost:8081', 'https://production.example.test/event-retiro/'):
            self.env.write_text('HOST_ADDRESS=' + base + '\nSEND_EMAIL=0\n')
            for prefix in ('', '/event-retiro'):
                response = self.page('templates/subscription/list.php', prefix)
                self.assertEqual(response['status'], 303)
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

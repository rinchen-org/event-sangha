"""Presentation/controller checks in disposable copies. No sockets, real mail or QR requests."""
import json
import re
import shutil
import subprocess
import unittest

import test_auth

ROOT = test_auth.ROOT


class LayoutTest(unittest.TestCase):
    request = test_auth.AuthSessionTest.request

    def setUp(self):
        test_auth.AuthSessionTest.setUp(self)
        # Copy code and public UI assets only, never configuration/participant files.
        shutil.copytree(ROOT / 'src/lib', self.web / 'lib', dirs_exist_ok=True)
        for directory in ('templates', 'scripts'):
            for source in (ROOT / 'src' / directory).rglob('*.php'):
                target = self.web / source.relative_to(ROOT / 'src')
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, target)
        test_auth.write_frontend_fixture(self.web)
        shutil.copyfile(ROOT / 'src/generate_qr.php', self.web / 'generate_qr.php')
        self.env = self.web / '.env'
        self.env.write_text('HOST_ADDRESS=http://localhost/event-retiro\nSEND_EMAIL=0\nTEST_ENV=0\n')
        (self.web / 'lib/qr.php').write_text('<?php function generate_qr(...$args): string { throw new RuntimeException("No QR in layout tests"); }')
        (self.web / 'lib/email.php').write_text('<?php function send_email(...$args): bool { throw new RuntimeException("No mail in layout tests"); }')
        code = '''require $argv[1].'/lib/schema.php'; $db=new SQLite3($argv[1].'/db.sqlite'); create_setup_schema($db);
$db->exec("INSERT INTO event VALUES(1,'Synthetic event','','2026-11-12','2026-11-15');
INSERT INTO event_session VALUES(1,1,'Synthetic session','2026-11-12 13:00:00','2026-11-12 14:00:00');
INSERT INTO person VALUES(50,'Synthetic Person','synthetic@example.test','12345',1);
INSERT INTO subscription VALUES(10,50,'2026-01-01','unchanged-qr',1);");'''
        subprocess.run(['php', '-r', code, str(self.web)], check=True, capture_output=True)
        self.database = self.web / 'db.sqlite'
        self.version = re.search(r"const APP_VERSION = '([^']+)';", (self.web / 'lib/version.php').read_text())[1]

    def page(self, route, base='', get=None):
        return self.request(base=base, route=route, get=get or {}, accept='text/html', seed={'created':0,'seen':0})

    def assert_shell(self, body, compact=False):
        self.assertIn('static/ui.css', body)
        self.assertIn('rinchen-ui', body)
        self.assertIn('Versión ' + self.version, body)
        self.assertIn('https://scistitch.com/', body)
        self.assertEqual(body.count('<main '), 1)
        self.assertEqual(body.count('</main>'), 1)
        self.assertEqual(body.count('<html '), 1)
        self.assertNotIn('head5.png', body)
        self.assertNotIn('static/style.css', body)
        if compact:
            self.assertIn('ui-compact', body)
            self.assertNotIn('id="ui-sidebar"', body)
        else:
            self.assertEqual(body.count('aria-label="Navegación principal"'), 1)
            self.assertIn('id="ui-sidebar"', body)
            self.assertIn('name="csrf_token"', body)

    def test_classic_forms_lists_reviews_and_reports_share_dashboard_shell(self):
        before = self.database.read_bytes(), self.env.read_bytes()
        routes = ['event/form.php','event/list.php','event-session/form.php','event-session/list.php',
                  'subscription/form.php','subscription/upload.php',
                  'attendance/form.php','attendance/list.php','attendance/report.php',
                  'event/register.php','event/email.php']
        for base in ('', '/event-retiro'):
            for route in routes:
                with self.subTest(route=route, base=base):
                    response = self.page('templates/' + route, base, {'event_id':'1'} if route.startswith('event/') or route == 'subscription/upload.php' else {})
                    self.assertEqual(response['status'], 200, response['body'])
                    self.assert_shell(response['body'])
                    self.assertIn('href="' + base + '/templates/index.php#eventos"', response['body'])
                    self.assertIn('href="' + base + '/static/ui.css"', response['body'])
                    if route == 'attendance/report.php':
                        self.assertIn('buttons.html5.min.js', response['body'])
                        self.assertIn('buttons.print.min.js', response['body'])
        self.assertEqual((self.database.read_bytes(), self.env.read_bytes()), before)

    def test_classic_list_and_unscoped_upload_redirect_without_data_or_configuration(self):
        self.database.unlink()
        self.env.unlink()
        for base in ('', '/event-retiro'):
            for route in ('templates/subscription/list.php', 'templates/subscription/upload.php'):
                for method in ('GET', 'HEAD'):
                    response = self.request(route=route, base=base, method=method, accept='text/html', seed={'created':0,'seen':0})
                    self.assertEqual(response['status'], 303, response['body'])
                    self.assertFalse(self.database.exists())
                    self.assertFalse(self.env.exists())
                self.assertEqual(self.request(route=route, base=base, method='POST', seed={'created':0,'seen':0})['status'], 403)
        self.assertEqual(self.request(route='templates/subscription/list.php', get={'event_id':'01'}, seed={'created':0,'seen':0})['status'], 400)

    def test_setup_lab_and_compact_screens_work_without_frontend_build_or_database(self):
        self.database.unlink()
        shutil.rmtree(self.web / 'static/app')
        for route, query in [('scripts/setup.php', {}), ('scripts/setup.php', {'mode':'prepare2026'})]:
            response = self.page(route, get=query)
            self.assertEqual(response['status'], 200, response['body'])
            self.assert_shell(response['body'])
        self.env.write_text(self.env.read_text().replace('TEST_ENV=0','TEST_ENV=1'))
        response = self.page('templates/testing/index.php')
        self.assertEqual(response['status'], 200, response['body'])
        self.assert_shell(response['body'])
        self.assertIn('Pruebas · Laboratorio QR', response['body'])
        login = self.request(route='templates/login.php', accept='text/html')
        self.assertEqual(login['status'], 200)
        self.assert_shell(login['body'], compact=True)
        qr = self.page('templates/attendance/log.php')
        self.assertEqual(qr['status'], 503)
        self.assert_shell(qr['body'], compact=True)
        self.assertIn('static/qr-checkin.js?v=2', qr['body'])
        self.assertNotIn('data-auto-checkin', qr['body'])
        self.assertFalse(self.database.exists())
        self.assertFalse((self.web / '.test-lab').exists())
        self.assertFalse((self.web / '.backups').exists())
        generated = self.page('generate_qr.php')
        self.assertEqual(generated['status'], 200)
        self.assert_shell(generated['body'], compact=True)
        self.assertIn('Confirmar y continuar', generated['body'])

    def test_bootstrap_plugins_remain_but_flash_messages_are_escaped_once(self):
        (self.web / 'templates/layout-fixture.php').write_text('''<?php
require_once __DIR__.'/../lib/auth.php'; auth_require();
$_SESSION['message']=['type'=>'success','text'=>'<script>not markup</script>'];
require __DIR__.'/header.php';
echo '<h1>Fixture</h1><form method="post">'; auth_csrf_field(); echo '<button>Guardar</button></form>';
require __DIR__.'/footer.php';''')
        response = self.page('templates/layout-fixture.php')
        self.assertEqual(response['status'], 200)
        self.assert_shell(response['body'])
        self.assertIn('bootstrap.bundle.min.js', response['body'])
        self.assertIn('jquery.dataTables.js', response['body'])
        self.assertIn('&lt;script&gt;not markup&lt;/script&gt;', response['body'])
        self.assertNotIn('<script>not markup', response['body'])

    def test_auth_csrf_json_and_lab_gating_are_not_changed_by_presentation(self):
        before = self.database.read_bytes()
        response = self.request(route='templates/event/form.php')
        self.assertEqual(response['status'], 401)
        self.assertNotIn('ui-sidebar', json.dumps(response['body']))
        response = self.request(route='templates/subscription/upload.php', method='POST', accept='text/html', seed={'created':0,'seen':0})
        self.assertEqual(response['status'], 403)
        self.assert_shell(response['body'], compact=True)
        response = self.request(route='templates/event/list.php', get={'format':'json'}, seed={'created':0,'seen':0})
        self.assertEqual(response['status'], 200)
        self.assertTrue(response['body']['success'])
        self.assertNotIn('ui-sidebar', json.dumps(response['body']))
        response = self.page('templates/testing/index.php')
        self.assertEqual(response['status'], 404)
        self.assert_shell(response['body'], compact=True)
        self.assertEqual(self.database.read_bytes(), before)

    def test_every_local_css_import_and_font_reference_exists(self):
        for name in ('theme.css','ui.css'):
            css = (ROOT / 'src/static' / name).read_text()
            for relative in re.findall(r"url\(['\"]?([^)'\"]+)", css):
                self.assertTrue((ROOT / 'src/static' / relative).is_file(), relative)
        for weight in (400,500,600,700):
            self.assertGreater((ROOT / f'src/static/fonts/manrope-{weight}.woff2').stat().st_size, 1000)

    def test_protected_form_post_keeps_its_controller_and_new_result_layout(self):
        page = self.page('templates/event/form.php', base='/event-retiro')
        csrf = re.search(r'name="csrf_token" value="([a-f0-9]+)"', page['body'])[1]
        response = self.request(base='/event-retiro', route='templates/event/form.php', sid=page['sid'],
            method='POST', accept='text/html', post={
                'csrf_token':csrf, 'name':'Synthetic created event', 'description':'Layout fixture only',
                'start_date':'2026-11-12', 'end_date':'2026-11-15',
            })
        self.assertEqual(response['status'], 200, response['body'])
        self.assert_shell(response['body'])
        self.assertIn('Evento creado correctamente.', response['body'])
        self.assertIn('href="/event-retiro/templates/index.php#eventos"', response['body'])


if __name__ == '__main__':
    unittest.main(verbosity=2)

"""Complete print rosters: disposable code/data, existing synthetic QR, no real transport."""
import unittest

import test_auth
import test_event_email
import test_lab

ROUTE = '/templates/subscription/print.php'


class ParticipantPrintTest(unittest.TestCase):
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
            # Even an accidental import of a transport must fail before networking/mail.
            for filename in ('qr.php', 'email.php', 'qr_regeneration.php'):
                (cls.web / base / 'lib' / filename).write_text('<?php throw new RuntimeException("No transport during print tests");')

    @classmethod
    def tearDownClass(cls):
        test_lab.LabTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_event_email.EventEmailRoutesTest.setUp(self)
        self.qr_capture.unlink(missing_ok=True)

    def test_complete_event_roster_ignores_screen_filters_and_pagination_without_side_effects(self):
        before = (self.web / 'db.sqlite').read_bytes(), (self.web / '.env').read_bytes()
        image = self.web / 'static/qr' / ('b' * 64 + '.jpg')
        qr = image.read_bytes()
        status, _, body = self.request(ROUTE + '?event_id=1&page=999&per_page=1&search=not-matching&active=1')
        self.assertEqual(status, 200, body)
        self.assertEqual(body.count('<tr data-participant-row>'), 2)
        self.assertIn('Test &lt;One&gt;', body)
        self.assertIn('Test Two', body)
        self.assertIn('Inactiva', body)
        self.assertNotIn('Other event', body)
        self.assertIn('2 participantes', body)
        self.assertIn('sin filtros ni paginación', body)
        self.assertEqual(body.count('data-participant-qr'), 2)
        self.assertEqual(body.count('loading="eager"'), 2)
        self.assertNotIn('id="ui-sidebar"', body)
        self.assertNotIn('<form', body)
        self.assertNotIn('Regenerar QR', body)
        self.assertIn('/templates/index.php#participantes/1', body)
        self.assertIn('(Bolivia)', body)
        self.assertEqual(((self.web / 'db.sqlite').read_bytes(), (self.web / '.env').read_bytes()), before)
        self.assertEqual(image.read_bytes(), qr)
        self.assertFalse(self.qr_capture.exists())
        self.assertEqual(self.count_mail(), 0)

    def test_other_event_and_explicit_general_scope_do_not_mix_memberships(self):
        status, _, body = self.request(ROUTE + '?event_id=2')
        self.assertEqual(status, 200, body)
        self.assertIn('Other event', body)
        self.assertNotIn('Test Two', body)
        self.assertEqual(body.count('<tr data-participant-row>'), 1)
        status, _, body = self.request(ROUTE + '?scope=general')
        self.assertEqual(status, 200, body)
        self.assertEqual(body.count('<tr data-participant-row>'), 3)
        self.assertIn('no representa la lista de un evento', body)
        self.assertIn('/templates/index.php#participantes/todos', body)

    def test_invalid_missing_or_mixed_scope_and_methods_fail_closed(self):
        before = (self.web / 'db.sqlite').read_bytes()
        for query in ('', '?event_id=0', '?event_id=01', '?event_id=invalid', '?scope=lab',
                      '?scope=general&event_id=1', '?scope=event'):
            self.assertEqual(self.request(ROUTE + query)[0], 400, query)
        self.assertEqual(self.request(ROUTE + '?event_id=999')[0], 404)
        self.assertEqual(self.request(ROUTE + '?event_id=1', {}, method='POST')[0], 403)
        self.assertEqual(self.request(ROUTE + '?event_id=1', {'csrf_token':self.csrf()}, method='POST')[0], 405)
        self.assertEqual(self.request(ROUTE + '?event_id=1', method='HEAD')[0], 200)
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertFalse(self.qr_capture.exists())
        self.assertEqual(self.count_mail(), 0)

    def test_authentication_and_public_assets_keep_qr_media_private(self):
        self.jar.clear()
        self.assertEqual(self.request(ROUTE + '?event_id=1')[0], 303)
        self.assertEqual(self.request(ROUTE + '?event_id=1', headers={'Accept':'application/json'})[0], 401)
        for filename in ('participants-print.js', 'participants-print.css'):
            self.assertEqual(self.request('/static/' + filename)[0], 200)
        self.assertEqual(self.request('/static/qr/' + 'b' * 64 + '.jpg')[0], 303)
        self.assertFalse(self.qr_capture.exists())
        self.assertEqual(self.count_mail(), 0)

    def test_missing_qr_configuration_and_old_base_warnings_never_trigger_repair(self):
        config = self.web / '.env'
        config.write_text('HOST_ADDRESS=https://configured.example.test/event-retiro\nSEND_EMAIL=1\n')
        before = (self.web / 'db.sqlite').read_bytes()
        status, _, body = self.request(ROUTE + '?event_id=1')
        self.assertEqual(status, 200, body)
        self.assertIn('dirección base diferente de HOST_ADDRESS', body)
        self.assertIn('src="https://configured.example.test/event-retiro/static/qr/', body)
        (self.web / 'static/qr' / ('b' * 64 + '.jpg')).unlink()
        status, _, body = self.request(ROUTE + '?event_id=1')
        self.assertEqual(status, 200, body)
        self.assertEqual(body.count('data-qr-missing'), 2)
        self.assertNotIn('data-participant-qr', body)
        config.unlink()
        status, _, body = self.request(ROUTE + '?event_id=1')
        self.assertEqual(status, 200, body)
        self.assertIn('HOST_ADDRESS', body)
        self.assertFalse(config.exists())
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)
        self.assertEqual(self.count_mail(), 0)
        self.assertFalse(self.qr_capture.exists())

    def test_empty_event_without_registration_schema_is_not_filled_from_global_history(self):
        self.query('DROP TABLE event_registration')
        before = (self.web / 'db.sqlite').read_bytes()
        status, _, body = self.request(ROUTE + '?event_id=1')
        self.assertEqual(status, 200, body)
        self.assertIn('0 participantes', body)
        self.assertIn('No hay participantes registrados', body)
        self.assertNotIn('<tr data-participant-row>', body)
        self.assertEqual(self.query("SELECT name FROM sqlite_master WHERE name='event_registration'"), [])
        self.assertEqual((self.web / 'db.sqlite').read_bytes(), before)

    def test_missing_database_is_a_technical_failure_not_a_successful_empty_list(self):
        (self.web / 'db.sqlite').unlink()
        status, _, body = self.request(ROUTE + '?event_id=1')
        self.assertEqual(status, 503, body)
        self.assertIn('No se pudo preparar la impresión', body)
        self.assertNotIn('id="participants-print-button"', body)
        self.assertNotIn('0 participantes', body)
        self.assertFalse((self.web / 'db.sqlite').exists())

    def test_subdirectory_links_and_assets_preserve_the_deployment_base(self):
        self.jar.clear()
        self.login('/event-retiro')
        status, _, body = self.request('/event-retiro' + ROUTE + '?event_id=1')
        self.assertEqual(status, 200, body)
        self.assertIn('href="/event-retiro/static/participants-print.css"', body)
        self.assertIn('src="/event-retiro/static/participants-print.js"', body)
        self.assertIn('/event-retiro/templates/index.php#participantes/1', body)
        self.assertEqual(self.count_mail(), 0)


if __name__ == '__main__':
    unittest.main(verbosity=2)

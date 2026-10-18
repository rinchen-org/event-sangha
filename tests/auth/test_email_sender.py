"""Email endpoint tests using a local fake sendmail; never sends real email."""
import json
import unittest

import test_auth


class EmailSenderTest(unittest.TestCase):
    fake_mail = True
    request = test_auth.AuthHTTPTest.request
    login = test_auth.AuthHTTPTest.login
    csrf = test_auth.AuthHTTPTest.csrf

    @classmethod
    def setUpClass(cls):
        test_auth.AuthHTTPTest.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        test_auth.AuthHTTPTest.tearDownClass.__func__(cls)

    def setUp(self):
        test_auth.AuthHTTPTest.setUp(self)
        self.mail_capture.unlink(missing_ok=True)
        self.mail_failure.unlink(missing_ok=True)
        self.configure()
        self.login()

    def configure(self, base='', **changes):
        settings = dict(HOST_ADDRESS='http://localhost', SEND_EMAIL='1',
                        TEST_EMAIL_TO='one@example.test, two@example.test, one@example.test',
                        EMAIL_FROM='sender@example.test', EMAIL_REPLY_TO='reply@example.test',
                        EMAIL_CC='production@example.test')
        settings.update(changes)
        (self.web / base.lstrip('/') / '.env').write_text(''.join(f'{key}={value}\n' for key, value in settings.items()))

    def send(self, base=''):
        return self.request(base + '/templates/test-email.php', {'csrf_token': self.csrf(base)},
                            headers={'Accept': 'application/json'})

    def test_multiple_recipients_reuse_mail_helper_without_production_cc(self):
        status, _, body = self.send()
        self.assertEqual(status, 200, body)
        result = json.loads(body)
        self.assertTrue(result['success'])
        self.assertIn('2 destinatario(s)', result['message'])
        self.assertIn('no confirma la entrega', result['message'])
        mail = self.mail_capture.read_text()
        self.assertIn('one@example.test, two@example.test', mail)
        self.assertIn('From: sender@example.test', mail)
        self.assertIn('Reply-To: reply@example.test', mail)
        self.assertIn('Prueba de envío de correo', mail)
        self.assertNotIn('production@example.test', mail)

    def test_missing_and_invalid_recipients_do_not_send(self):
        for recipients in ('', 'one@example.test, invalid', 'one@example.test,', 'one@example.test\rBcc:other@example.test'):
            self.jar.clear()
            self.login()
            self.configure(TEST_EMAIL_TO=recipients)
            status, _, body = self.send()
            self.assertEqual(status, 422, body)
            self.assertIn('TEST_EMAIL_TO', json.loads(body)['message'])
            self.assertFalse(self.mail_capture.exists())

    def test_disabled_sender_and_invalid_from_are_reported(self):
        self.configure(SEND_EMAIL='0')
        self.assertEqual(self.send()[0], 422)
        self.assertFalse(self.mail_capture.exists())
        self.jar.clear()
        self.login()
        self.configure(EMAIL_FROM='')
        status, _, body = self.send()
        self.assertEqual(status, 422)
        self.assertIn('EMAIL_FROM', body)
        self.assertFalse(self.mail_capture.exists())

    def test_transport_failure_and_duplicate_attempt(self):
        self.mail_failure.touch()
        status, _, body = self.send()
        self.assertEqual(status, 502, body)
        self.assertFalse(json.loads(body)['success'])
        self.assertIn('mail()/sendmail', json.loads(body)['message'])
        first = self.mail_capture.read_bytes()
        self.assertEqual(self.send()[0], 429)
        self.assertEqual(self.mail_capture.read_bytes(), first)

    def test_authenticated_post_and_csrf_required(self):
        self.assertEqual(self.request('/templates/test-email.php', headers={'Accept': 'application/json'})[0], 405)
        self.assertEqual(self.request('/templates/test-email.php', {}, headers={'Accept': 'application/json'})[0], 403)
        self.jar.clear()
        self.assertEqual(self.request('/templates/test-email.php', {}, headers={'Accept': 'application/json'})[0], 401)
        self.assertFalse(self.mail_capture.exists())

    def test_legacy_html_and_subdirectory(self):
        self.jar.clear()
        self.configure('/event-retiro', TEST_EMAIL_TO='one@example.test', EMAIL_REPLY_TO='')
        self.login('/event-retiro')
        status, _, body = self.request('/event-retiro/templates/test-email.php',
                                      {'csrf_token': self.csrf('/event-retiro')})
        self.assertEqual(status, 200, body)
        self.assertIn('role="status"', body)
        self.assertIn('1 destinatario(s)', body)
        self.assertIn('Reply-To: sender@example.test', self.mail_capture.read_text())


if __name__ == '__main__':
    unittest.main(verbosity=2)

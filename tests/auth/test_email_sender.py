"""Email endpoint tests using a local fake sendmail; never sends real email."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import xml.etree.ElementTree as ET

import test_auth


class SubscriptionEmailTemplateTest(unittest.TestCase):
    """Read-only content checks and real rendering with PHP's mail transport replaced."""

    @classmethod
    def setUpClass(cls):
        cls.template = (test_auth.ROOT / 'src/templates/subscription-email.html').read_text(encoding='utf-8')
        cls.root = ET.fromstring(cls.template.replace('<QR>', '<img src="https://example.test/qr.png" />'))
        cls.text = ' '.join(' '.join(cls.root.itertext()).split())

    def test_current_event_details_and_help_links(self):
        for phrase in ('43.º Sakya Trizin', 'Enseñanzas de Ngöndro', 'Los fundamentos del Mantrayana',
                       '12 al 15 de noviembre de 2026', 'Salón de eventos Infinity',
                       'Av. Ballivián #1578', 'entre calles 23 y 24', '+591 2 2910777',
                       'pulsera', 'modo avión', 'equipo organizador para revisar tu participación'):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, self.text)
        links = [link.attrib['href'] for link in self.root.iter('a')]
        self.assertIn('https://maps.app.goo.gl/UAb9r5a63pWdACLEA', links)
        self.assertIn('https://wa.me/59122910777', links)
        for stale in ('2023', 'Sakya Trizin 42', 'forms.gle', 'Bajo ningún concepto', 'no podrás'):
            self.assertNotIn(stale, self.template)

    def test_program_matches_document_image_as_readable_html(self):
        rows = [[' '.join(''.join(cell.itertext()).split()) for cell in row]
                for row in self.root.findall('.//table//tr')]
        self.assertEqual(rows, [
            ['Hora', 'Actividad'],
            ['Jueves 12 de noviembre'],
            ['08:00', 'Registro de ingreso (check-in)'],
            ['10:00', 'Votos de refugio y Bodhichitta'],
            ['13:00', 'Comida'],
            ['16:00', 'Enseñanza'],
            ['Viernes 13 y sábado 14 de noviembre'],
            ['15:00', 'Iniciación Mayor'],
            ['Domingo 15 de noviembre'],
            ['10:00', 'Enseñanzas'],
            ['13:00', 'Comida'],
            ['16:00', 'Enseñanzas'],
        ])

    def test_qr_branding_and_small_scistitch_footer(self):
        self.assertEqual(self.template.count('<QR>'), 1)
        images = [image.attrib['src'] for image in self.root.iter('img')]
        self.assertIn('https://rinchen.org/wp-content/uploads/2020/08/Sakya-Rinchen-Ling.png', images)
        footer = list(self.root)[-1]
        self.assertIn('font-size: 11px', footer.attrib['style'])
        self.assertEqual(footer.find('a').attrib['href'], 'https://scistitch.com/')
        self.assertEqual(footer.find('a').text, 'SciStitch')

    def test_subscription_sender_renders_template_without_real_mail(self):
        with tempfile.TemporaryDirectory(prefix='rinchen-email-template-') as directory:
            web = Path(directory) / 'src'
            shutil.copytree(test_auth.ROOT / 'src/lib', web / 'lib')
            (web / 'templates').mkdir()
            (web / 'templates/subscription-email.html').write_text(self.template, encoding='utf-8')
            (web / '.env').write_text('HOST_ADDRESS=https://example.test/event-retiro\nSEND_EMAIL=1\n'
                                    'EMAIL_FROM=sender@example.test\nEMAIL_REPLY_TO=reply@example.test\nEMAIL_CC=\n')
            # PHP 8+ allows a fake definition after disabling the real mail function.
            code = '''function mail($to, $subject, $message, $headers): bool {
                $GLOBALS['captured_mail'] = compact('to', 'subject', 'message', 'headers');
                return true;
            }
            require ''' + json.dumps(str(web / 'lib/subscription.php')) + ''';
            $person = new Person('Synthetic Participant', 'participant@example.test', '12345');
            Subscription::send_email(new Subscription($person, 'https://example.test/qr.png'));
            echo json_encode($GLOBALS['captured_mail'], JSON_THROW_ON_ERROR);'''
            result = subprocess.run(['php', '-d', 'disable_functions=mail,curl_init,curl_exec',
                                     '-d', 'allow_url_fopen=0', '-r', code],
                                    capture_output=True, text=True, check=True)
            self.assertEqual(result.stderr, '')
            mail = json.loads(result.stdout)
            self.assertEqual(mail['to'], 'participant@example.test')
            self.assertNotIn('<QR>', mail['message'])
            rendered = ET.fromstring(mail['message'])
            self.assertEqual(sum(image.attrib['src'] == 'https://example.test/qr.png'
                                 for image in rendered.iter('img')), 1)
            self.assertIn('43.º Sakya Trizin', mail['message'])
            self.assertIn('SciStitch', mail['message'])
            self.assertIn('Content-Type: text/html; charset=utf-8', mail['headers'])
            self.assertFalse((web / 'db.sqlite').exists())


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

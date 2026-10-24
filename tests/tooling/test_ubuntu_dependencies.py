"""Installer control-flow tests. Never run APT, sudo, Composer, or application code."""
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts/install-php-composer.sh'


class UbuntuInstallerTest(unittest.TestCase):
    def shell(self, code, *args):
        return subprocess.run(
            ['bash', '-c', 'source "$1"; shift; ' + code, 'installer-test', str(SCRIPT), *args],
            cwd=ROOT, capture_output=True, text=True,
        )

    def test_help_and_unknown_options_do_not_install(self):
        for option, expected in [('--help', 0), ('--unexpected', 2)]:
            result = self.shell('check_ubuntu() { exit 99; }; main "$@"', option)
            self.assertEqual(result.returncode, expected, result.stderr)
            self.assertIn('Usage:', result.stdout + result.stderr)

    def test_ubuntu_release_validation(self):
        with tempfile.TemporaryDirectory(prefix='rinchen-installer-') as directory:
            release = Path(directory) / 'os-release'
            for distro, version, expected in [
                ('ubuntu', '24.04', 0), ('ubuntu', '22.04', 0),
                ('ubuntu', '20.04', 1), ('debian', '12', 1),
                ('ubuntu', '', 1), ('ubuntu', 'invalid', 1),
            ]:
                release.write_text(f'ID={distro}\nVERSION_ID="{version}"\n')
                result = self.shell('check_ubuntu "$1"', str(release))
                self.assertEqual(result.returncode, expected, result.stderr)
            release.unlink()
            self.assertNotEqual(self.shell('check_ubuntu "$1"', str(release)).returncode, 0)

    def test_dry_run_prints_packages_without_executing_commands(self):
        # Test run() itself with a failing command: dry-run must not execute it.
        result = self.shell('dry_run=1; run /bin/false')
        self.assertEqual(result.returncode, 0, result.stderr)
        result = self.shell('''
            check_ubuntu() { :; }
            verify_runtime() { exit 99; }
            main --dry-run --yes
        ''')
        self.assertEqual(result.returncode, 0, result.stderr)
        for package in ('php-cli', 'php-sqlite3', 'php-curl', 'php-xml',
                        'php-mbstring', 'php-zip', 'composer', 'ca-certificates', 'unzip'):
            self.assertIn(package, result.stdout)
        self.assertIn('--yes', result.stdout)
        self.assertIn('--no-remove', result.stdout)
        self.assertIn('Dry run:', result.stdout)
        self.assertNotIn('verification passed', result.stdout)

    def test_success_requires_installation_and_runtime_verification(self):
        result = self.shell('''
            check_ubuntu() { :; }
            run() { printf 'MOCK %s\\n' "$*"; }
            verify_runtime() { echo VERIFIED; }
            main
        ''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertLess(result.stdout.index('apt-get update'), result.stdout.index('apt-get install'))
        self.assertLess(result.stdout.index('apt-get install'), result.stdout.index('VERIFIED'))
        self.assertIn('verification passed', result.stdout)
        self.assertNotIn('--yes', result.stdout)

    def test_failures_stop_before_reporting_success(self):
        cases = [
            ('check_ubuntu() { return 17; }', 17, 'apt-get update'),
            ('run() { return 18; }', 18, 'VERIFIED'),
            ('run() { [[ "$*" != *"apt-get install"* ]]; }', 1, 'VERIFIED'),
            ('verify_runtime() { return 19; }', 19, 'verification passed'),
        ]
        for override, status, absent in cases:
            result = self.shell('''
                check_ubuntu() { :; }
                run() { printf 'MOCK %s\\n' "$*"; }
                verify_runtime() { echo VERIFIED; }
            ''' + override + '; main')
            self.assertEqual(result.returncode, status, result.stderr)
            self.assertNotIn(absent, result.stdout)
            self.assertNotIn('verification passed', result.stdout)

    def test_runtime_failure_is_not_hidden_by_composer_version(self):
        result = self.shell('''
            system_php() {
                if [[ "$1" == -r ]]; then return 23; fi
                if [[ "$1" == /usr/bin/composer ]]; then echo UNEXPECTED_COMPOSER; fi
            }
            verify_runtime
        ''')
        self.assertEqual(result.returncode, 23, result.stderr)
        self.assertNotIn('UNEXPECTED_COMPOSER', result.stdout)


if __name__ == '__main__':
    unittest.main(verbosity=2)

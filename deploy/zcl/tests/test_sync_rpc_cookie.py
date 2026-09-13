from contextlib import redirect_stderr, redirect_stdout
import importlib.util
import io
from pathlib import Path
import runpy
import sys
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('credential_reader_test', ROOT / 'node_rpc_credentials.py')
reader = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(reader)


class CredentialSyncTests(unittest.TestCase):
    def invoke(self, credentials=('fixture-user', 'fixture-password'), *, returncode=0, failure=None):
        output, error_output = io.StringIO(), io.StringIO()

        def read():
            if failure is not None:
                raise failure
            return credentials

        with patch.dict(sys.modules, {'node_rpc_credentials': SimpleNamespace(read_credentials=read)}), \
                patch('subprocess.run', return_value=SimpleNamespace(returncode=returncode,
                      stdout='private SQL output fixture', stderr='private SQL error fixture')) as sql, \
                redirect_stdout(output), redirect_stderr(error_output):
            code = 0
            try:
                runpy.run_path(str(ROOT / 'sync-rpc-cookie.py'), run_name='__main__')
            except SystemExit as error:
                code = error.code
        return code, sql, output.getvalue(), error_output.getvalue()

    def read_fixture(self, files):
        def read(path, *_args, **_kwargs):
            if str(path) not in files:
                raise FileNotFoundError(str(path))
            return files[str(path)]

        with patch.object(Path, 'read_text', read), \
                patch.object(Path, 'exists', lambda path: str(path) in files):
            return reader.read_credentials()

    def test_only_scoped_coin_authentication_is_updated_and_secrets_use_stdin(self):
        user, password = "fixture-'user", "fixture-'; UPDATE accounts SET balance=9; --"
        code, sql, output, errors = self.invoke((user, password))
        self.assertEqual(code, 0)
        self.assertEqual(sql.call_count, 1)
        self.assertEqual(sql.call_args.args, (['mariadb', 'yiimp_zcl'],))
        self.assertEqual(sql.call_args.kwargs['input'],
                         'UPDATE coins SET rpcuser=0x%s,rpcpasswd=0x%s WHERE id=1 AND symbol=\'ZCL\';' %
                         (user.encode().hex(), password.encode().hex()))
        self.assertEqual(sql.call_args.kwargs['timeout'], 10)
        self.assertNotIn(password, str(sql.call_args.args))
        self.assertEqual(output, '')
        self.assertEqual(errors, '')

    def test_repeated_values_are_idempotent_and_rotated_cookie_changes_only_authentication(self):
        first = self.invoke(('__cookie__', 'fixture-cookie-one'))[1].call_args.kwargs['input']
        repeated = self.invoke(('__cookie__', 'fixture-cookie-one'))[1].call_args.kwargs['input']
        rotated = self.invoke(('__cookie__', 'fixture-cookie-two'))[1].call_args.kwargs['input']
        self.assertEqual(first, repeated)
        self.assertNotEqual(first, rotated)
        self.assertEqual(first.replace('fixture-cookie-one'.encode().hex(), 'fixture-cookie-two'.encode().hex()), rotated)

    def test_present_cookie_takes_precedence_and_absence_uses_config_credentials(self):
        files = {'/var/lib/zclassic/zclassic.conf':
                 '# ignored\nrpcuser=fixture-config-user\nrpcpassword=fixture-config-password\n',
                 '/var/lib/zclassic/.cookie': '__cookie__:fixture-cookie-password\n'}
        self.assertEqual(self.read_fixture(files), ['__cookie__', 'fixture-cookie-password'])
        del files['/var/lib/zclassic/.cookie']
        self.assertEqual(self.read_fixture(files), ('fixture-config-user', 'fixture-config-password'))
        files['/var/lib/zclassic/zclassic.conf'] += 'rpccookiefile=auth/fixture.cookie\n'
        files['/var/lib/zclassic/auth/fixture.cookie'] = '__cookie__:fixture-rotated'
        self.assertEqual(self.read_fixture(files), ['__cookie__', 'fixture-rotated'])

    def test_invalid_credentials_or_missing_source_never_execute_sql(self):
        invalid = [('', 'fixture'), ('fixture', ''), ('fixture', 'line\nbreak'),
                   ('fixture', 'x' * 129), ['missing-colon']]
        for credentials in invalid:
            with self.subTest(credentials=credentials):
                code, sql, output, errors = self.invoke(credentials)
                self.assertEqual(code, 1)
                self.assertEqual(sql.call_count, 0)
                self.assertEqual(output, '')
                self.assertNotIn('line\nbreak', errors)
        code, sql, output, errors = self.invoke(failure=RuntimeError('fixture-secret-must-not-print'))
        self.assertEqual(code, 1)
        self.assertEqual(sql.call_count, 0)
        self.assertNotIn('fixture-secret', output + errors)

    def test_sql_failure_is_nonzero_without_exposing_captured_output(self):
        code, sql, output, errors = self.invoke(returncode=1)
        self.assertEqual(code, 1)
        self.assertEqual(sql.call_count, 1)
        self.assertEqual(output, '')
        self.assertEqual(errors, 'ZCL loopback credential synchronization unavailable (RuntimeError)\n')
        self.assertNotIn('private SQL', errors)


if __name__ == '__main__':
    unittest.main()

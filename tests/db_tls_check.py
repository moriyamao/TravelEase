"""Explicit local database TLS check. Creates only disposable local fixtures."""
import argparse
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
SERVER_ID = 11272027
PASSWORD = 'Local-TLS-Fixture-9'
GENERIC = 'Something went wrong. Please try again later.'


def run(args, **kwargs):
    return subprocess.run([str(a) for a in args], check=True, capture_output=True,
                          timeout=60, **kwargs)


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mysql-bin', type=Path, default=Path('C:/xampp/mysql/bin'))
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--openssl', default=shutil.which('openssl'))
    parser.add_argument('--openssl-conf', type=Path)
    args = parser.parse_args()
    if not args.php or not Path(args.php).is_file():
        raise RuntimeError('PHP executable required')
    if not args.openssl or not Path(args.openssl).is_file():
        raise RuntimeError('NOT RUN: OpenSSL executable required')
    for executable in ['mysql_install_db.exe', 'mysqld.exe', 'mysql.exe']:
        if not (args.mysql_bin / executable).is_file():
            raise RuntimeError(f'Missing {executable}')

    runs = ROOT.parent / 'work' / 'local-tests'
    runs.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix='te-tls-', dir=runs)).resolve()
    data = work / 'data'
    certs = work / 'certs'
    certs.mkdir()
    app = work / 'app'
    (app / 'config').mkdir(parents=True)
    (app / 'includes').mkdir()
    for relative in ['config/database.php', 'config/database_options.php',
                     'config/env.php', 'includes/timing.php']:
        shutil.copyfile(ROOT / relative, app / relative)
    if any(path.name.startswith('.env') for path in app.rglob('*')):
        raise RuntimeError('Refusing test app snapshot containing an environment file')

    base_env = {key: value for key, value in os.environ.items() if key.upper() in {
        'PATH', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA',
        'LOCALAPPDATA', 'PROGRAMFILES', 'PROGRAMFILES(X86)', 'PROGRAMW6432',
        'PROGRAMDATA', 'COMSPEC'}}
    base_env['MYSQL_PWD'] = PASSWORD
    openssl_env = dict(base_env)
    openssl_conf = args.openssl_conf
    if openssl_conf is None:
        bundled_conf = Path(args.openssl).parent.parent / 'conf' / 'openssl.cnf'
        openssl_conf = bundled_conf if bundled_conf.is_file() else None
    if openssl_conf is not None:
        if not openssl_conf.is_file():
            raise RuntimeError('OpenSSL configuration file not found')
        openssl_env['OPENSSL_CONF'] = str(openssl_conf)
    port = free_port()
    mysql = [args.mysql_bin / 'mysql.exe', '--no-defaults', '--protocol=tcp',
             '--host=127.0.0.1', f'--port={port}', '--user=root', '--connect-timeout=2',
             '--batch', '--skip-column-names']
    processes = []
    handles = []
    results = []

    def check(label, condition):
        results.append({'check': label, 'passed': bool(condition)})
        if not condition:
            raise RuntimeError(label)
        print('PASS', label, flush=True)

    def make_ca(name):
        key = certs / f'{name}-key.pem'
        certificate = certs / f'{name}.pem'
        run([args.openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
             '-keyout', key, '-out', certificate, '-days', '1', '-sha256',
             '-subj', f'/CN=TravelEase {name}'], env=openssl_env)
        return key, certificate

    def make_server(name, ca_key, ca_certificate, names):
        key = certs / f'{name}-key.pem'
        request = certs / f'{name}.csr'
        certificate = certs / f'{name}.pem'
        extensions = certs / f'{name}.ext'
        extensions.write_text(
            'subjectAltName=' + names + '\n'
            'extendedKeyUsage=serverAuth\n'
            'keyUsage=digitalSignature,keyEncipherment\n', encoding='ascii')
        run([args.openssl, 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', key,
             '-out', request, '-sha256', '-subj', f'/CN={name}'], env=openssl_env)
        run([args.openssl, 'x509', '-req', '-in', request, '-CA', ca_certificate,
             '-CAkey', ca_key, '-CAcreateserial', '-out', certificate, '-days', '1',
             '-sha256', '-extfile', extensions], env=openssl_env)
        return key, certificate

    ca_a_key, ca_a = make_ca('ca-a')
    _, ca_b = make_ca('ca-b')
    good_key, good_cert = make_server(
        'localhost', ca_a_key, ca_a, 'DNS:localhost,IP:127.0.0.1')
    mismatch_key, mismatch_cert = make_server(
        'mismatch.invalid', ca_a_key, ca_a, 'DNS:mismatch.invalid')

    def raw(sql):
        return run(mysql, input=sql.encode(), env=base_env).stdout.decode().strip()

    def guard():
        values = raw('SELECT @@port, @@server_id, @@datadir').split('\t')
        if int(values[0]) != port or int(values[1]) != SERVER_ID:
            raise RuntimeError('Wrong disposable database server')
        if Path(values[2]).resolve() != data.resolve():
            raise RuntimeError('Wrong data directory; refusing test connection')

    current = None

    def stop_database():
        nonlocal current
        if current is not None and current.poll() is None:
            current.terminate()
            current.wait(timeout=10)
        current = None

    def start_database(certificate=None, key=None, no_tls=False):
        nonlocal current
        command = [args.mysql_bin / 'mysqld.exe', '--no-defaults',
                   f'--basedir={args.mysql_bin.parent}', f'--datadir={data}',
                   f'--port={port}', '--bind-address=127.0.0.1',
                   f'--server-id={SERVER_ID}', '--skip-log-bin', '--console']
        if no_tls:
            command.append('--skip-ssl')
        else:
            command += [f'--ssl-ca={ca_a}', f'--ssl-cert={certificate}', f'--ssl-key={key}']
        log = (work / f'database-{len(processes) + 1}.log').open('wb')
        handles.append(log)
        current = subprocess.Popen([str(arg) for arg in command], env=base_env,
                                   stdout=log, stderr=log)
        processes.append(current)
        for _ in range(150):
            try:
                guard()
                return
            except (subprocess.CalledProcessError, AssertionError):
                if current.poll() is not None:
                    raise RuntimeError('Test database stopped; inspect its log')
                time.sleep(.1)
        raise RuntimeError('Test database did not become ready')

    log_counter = 0

    def app_result(ca_file=None, host='127.0.0.1', app_env='production'):
        nonlocal log_counter
        log_counter += 1
        log = work / f'app-{log_counter}.log'
        environment = {key: value for key, value in base_env.items() if key != 'MYSQL_PWD'}
        environment.update(APP_ENV=app_env, DB_HOST=host, DB_PORT=str(port),
                           DB_NAME='mysql', DB_USER='root', DB_PASS=PASSWORD)
        if ca_file is not None:
            environment['DB_SSL_CA_FILE'] = str(ca_file)
        code = r'''
require $argv[1];
$pdo = get_db_connection();
echo json_encode([
    'cipher' => $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetchColumn(1),
    'version' => $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_version'")->fetchColumn(1),
]);
'''
        result = subprocess.run(
            [str(args.php), '-d', 'display_errors=0', '-d', 'log_errors=1',
             '-d', f'error_log="{log.as_posix()}"', '-r', code,
             str(app / 'config/database.php')],
            env=environment, capture_output=True, timeout=15)
        return result, log.read_text(encoding='utf-8', errors='replace') if log.exists() else ''

    def direct_result(ca_file, verify_server, host='127.0.0.1'):
        environment = {key: value for key, value in base_env.items() if key != 'MYSQL_PWD'}
        environment.update(DB_HOST=host, DB_PORT=str(port), DB_NAME='mysql',
                           DB_USER='root', DB_PASS=PASSWORD)
        code = r'''
require $argv[1];
$options = database_pdo_options(['APP_ENV' => 'development', 'DB_SSL_CA_FILE' => $argv[2]]);
$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $argv[3] === '1';
$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT')
    . ';dbname=mysql;charset=utf8mb4';
$pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), $options);
echo json_encode([
    'cipher' => $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetchColumn(1),
    'version' => $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_version'")->fetchColumn(1),
]);
'''
        return subprocess.run(
            [str(args.php), '-d', 'display_errors=0', '-r', code,
             str(app / 'config/database_options.php'), str(ca_file),
             '1' if verify_server else '0'],
            env=environment, capture_output=True, timeout=15)

    def refused(label, **kwargs):
        result, log = app_result(**kwargs)
        output = (result.stdout + result.stderr).decode('utf-8', errors='replace')
        check(label, GENERIC in output and PASSWORD not in output and PASSWORD not in log)
        return output + log

    print('Isolated TLS run:', work, flush=True)
    try:
        run([args.mysql_bin / 'mysql_install_db.exe', f'--datadir={data}',
             f'--password={PASSWORD}', f'--port={port}'], env=base_env)
        start_database(good_cert, good_key)
        check('TLS-capable disposable server verified', raw("SHOW VARIABLES LIKE 'have_ssl'").endswith('\tYES'))
        success, success_log = app_result(ca_a)
        if success.returncode != 0 or not success.stdout:
            detail = (success.stdout + success.stderr).decode('utf-8', errors='replace') + success_log
            raise RuntimeError('Verified TLS connection failed: ' + detail)
        payload = json.loads(success.stdout.decode())
        check('verified TLS connection reports cipher and version',
              success.returncode == 0 and bool(payload['cipher']) and bool(payload['version']))
        dns_success, _ = app_result(ca_a, host='localhost')
        dns_payload = json.loads(dns_success.stdout.decode())
        check('verified TLS connection works with a DNS hostname',
              dns_success.returncode == 0 and bool(dns_payload['cipher'])
              and bool(dns_payload['version']))
        evidence = refused('wrong CA is refused', ca_file=ca_b)
        check('wrong CA refusal reaches driver verification', '[2002]' in evidence)

        stop_database()
        start_database(mismatch_cert, mismatch_key)
        evidence = refused('certificate hostname mismatch is refused', ca_file=ca_a)
        check('hostname mismatch refusal reaches driver verification', '[2002]' in evidence)
        mismatch_control = direct_result(ca_a, verify_server=False)
        mismatch_payload = json.loads(mismatch_control.stdout.decode())
        check('mismatched certificate connects only when hostname verification is disabled',
              mismatch_control.returncode == 0 and bool(mismatch_payload['cipher'])
              and bool(mismatch_payload['version']))

        stop_database()
        start_database(no_tls=True)
        plaintext_control, _ = app_result(ca_file=None, app_env='development')
        plaintext_payload = json.loads(plaintext_control.stdout.decode())
        check('no-TLS server remains reachable without TLS requirements',
              plaintext_control.returncode == 0 and not plaintext_payload['cipher']
              and not plaintext_payload['version'])
        evidence = refused('server without TLS is refused', ca_file=ca_a)
        check('no-TLS refusal reaches the driver after the control succeeds',
              '[2002]' in evidence or '[2006]' in evidence)
        missing_log = refused('unreadable CA is refused before connecting',
                              ca_file=work / 'missing.pem')
        check('unreadable CA log is categorized', 'ca file not readable' in missing_log.lower())
        production_log = refused('production without a CA is refused', ca_file=None)
        check('missing production CA log is categorized',
              'production requires db_ssl_ca_file' in production_log.lower())
        check('test app snapshot contains no environment file',
              not any(path.name.startswith('.env') for path in app.rglob('*')))
        check('private key contents are absent from application logs',
              not any('PRIVATE KEY' in path.read_text(encoding='utf-8', errors='replace')
                      for path in work.glob('app-*.log')))
    finally:
        stop_database()
        for process in processes:
            if process.poll() is None:
                process.kill()
                process.wait()
        for handle in handles:
            handle.close()
        (work / 'results.json').write_text(json.dumps({
            'scope': 'verified locally only; not Render, Aiven or the production Docker image',
            'php': run([args.php, '-r', 'echo PHP_VERSION;'], env=base_env).stdout.decode(),
            'checks': results,
        }, indent=2), encoding='utf-8')
        print('Results and logs:', work, flush=True)
    print(f'{len(results)} local TLS checks passed; production TLS remains unverified.')


if __name__ == '__main__':
    main()

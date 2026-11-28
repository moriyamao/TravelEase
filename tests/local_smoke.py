"""Small local regression baseline. Creates its own MariaDB; never loads .env.

Run with Python 3 and PHP/MySQL on PATH (see README.md in this directory).
Every run keeps its isolated files/logs in a new temporary directory.
"""
import argparse
import concurrent.futures
import hashlib
from html.parser import HTMLParser
import http.cookiejar
import http.server
import io
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SERVER_ID = 11272026
PASSWORD = 'Local-Fixture-9'
PDF = b'%PDF-1.4\nLocal test document only\n%%EOF\n'
ROLES = ['owner', 'editor', 'commenter', 'viewer', 'outsider', 'staff', 'admin']


def run(args, **kwargs):
    return subprocess.run([str(a) for a in args], check=True, capture_output=True, timeout=60, **kwargs)


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Forms(HTMLParser):
    """Compare submitted field names/actions, permitting duplicate-control removal."""
    def __init__(self, body):
        super().__init__()
        self.current = None
        self.signatures = set()
        self.links = set()
        self.feed(body.decode('utf-8'))

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'a' and attrs.get('href'):
            self.links.add(attrs['href'])
        if tag == 'form':
            self.current = [attrs.get('method', 'get').lower(), attrs.get('action', ''), []]
        elif self.current is not None and tag in ['input', 'select', 'textarea', 'button'] and attrs.get('name'):
            value = attrs.get('value', '') if attrs.get('type') == 'hidden' and attrs['name'] != 'csrf_token' else ''
            self.current[2].append((attrs['name'], value))

    def handle_endtag(self, tag):
        if tag == 'form' and self.current is not None:
            method, action, fields = self.current
            self.signatures.add((method, action, tuple(sorted(fields))))
            self.current = None


def canonical_links(links, page):
    result = set()
    for link in links:
        url = urllib.parse.urlsplit(urllib.parse.urljoin('http://local' + page, link))
        result.add(urllib.parse.urlunsplit(('', '', url.path, url.query, url.fragment))
                   if url.netloc == 'local' else urllib.parse.urlunsplit(url))
    return result


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def request(self, path, data=None, headers=None):
        body = ((json.dumps(data).encode() if (headers or {}).get('Content-Type') == 'application/json'
                 else urllib.parse.urlencode(data).encode()) if data is not None else None)
        try:
            request = urllib.request.Request(self.base + path, data=body, headers=headers or {})
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()

    def token(self, path):
        status, _, body = self.request(path)
        assert status == 200, (path, status)
        match = re.search(rb'name="csrf_token" value="([^"]+)"', body)
        assert match, 'No CSRF token on ' + path
        return match[1].decode()

    def login(self, role):
        token = self.token('/auth/login.php')
        status, _, body = self.request('/auth/login.php', {
            'csrf_token': token, 'email': f'ui-{role}@example.test', 'password': PASSWORD})
        error = re.findall(rb'<li>(.*?)</li>', body, re.S)
        assert status == 302, ('login', role, status, error)
        return self


def snapshot(destination, ref=None):
    if ref:
        archive = run(['git', '-C', ROOT, 'archive', '--format=zip', ref]).stdout
        with zipfile.ZipFile(io.BytesIO(archive)) as z:
            for name in z.namelist():
                if name.endswith('/') or Path(name).name.startswith('.env'):
                    continue
                target = destination / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(z.read(name))
    else:
        paths = run(['git', '-C', ROOT, 'ls-files', '--cached', '--others',
                     '--exclude-standard', '-z']).stdout.decode().split('\0')
        for name in filter(None, paths):
            if Path(name).name.startswith('.env'):
                continue
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, target)
    assert not (destination / '.env').exists()


def main():
    if not __debug__:
        raise RuntimeError('Run without -O: safety and result checks require Python assertions.')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mysql-bin', type=Path, default=Path('C:/xampp/mysql/bin'))
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--baseline', default='origin/main')
    parser.add_argument('--node', help='Optional Node executable for browser checks')
    parser.add_argument('--modules', help='Bundled node_modules directory with playwright-core')
    args = parser.parse_args()
    assert args.php and Path(args.php).is_file(), 'PHP executable required'
    for exe in ['mysql_install_db.exe', 'mysqld.exe', 'mysql.exe']:
        assert (args.mysql_bin / exe).is_file(), f'Missing {exe}'
    runs = ROOT.parent / 'work' / 'local-tests'
    runs.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix='te-local-', dir=runs)).resolve()
    data = work / 'data'
    db_port = port()
    processes, handles, results = [], [], []
    # Inherit runtime essentials only. No application/provider credentials.
    env = {k: v for k, v in os.environ.items() if k.upper() in {
        'PATH', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA',
        'PROGRAMFILES', 'PROGRAMFILES(X86)', 'PROGRAMW6432', 'PROGRAMDATA', 'COMSPEC'}}
    env['MYSQL_PWD'] = PASSWORD
    mysql = [args.mysql_bin / 'mysql.exe', '--no-defaults', '--protocol=tcp',
             '--host=127.0.0.1', f'--port={db_port}', '--user=root',
             '--connect-timeout=2', '--batch', '--skip-column-names', '--default-character-set=utf8mb4']

    def raw(sql):
        return run(mysql, input=sql.encode(), env=env).stdout.decode('utf-8').strip()

    def guard():
        values = raw('SELECT @@port, @@server_id, @@datadir').split('\t')
        assert int(values[0]) == db_port and int(values[1]) == SERVER_ID, 'Wrong test server'
        assert Path(values[2]).resolve() == data.resolve(), 'Wrong data directory; refusing fixtures'

    def sql(statement):
        guard()
        return raw('USE travelease; ' + statement)

    def check(label, condition):
        results.append({'check': label, 'passed': bool(condition)})
        assert condition, label
        print('PASS', label, flush=True)

    def launch(command, label, app_env=env):
        log = (work / (label + '.log')).open('wb')
        handles.append(log)
        proc = subprocess.Popen([str(a) for a in command], env=app_env, stdout=log, stderr=log)
        processes.append(proc)
        return proc

    mail_state = {'attempts': 0, 'status': 201}
    chat_payloads = []
    class Storage(http.server.BaseHTTPRequestHandler):
        def do_POST(self):
            if self.path == '/mail':
                self.rfile.read(int(self.headers['Content-Length']))
                mail_state['attempts'] += 1
                self.send_response(mail_state['status'])
                self.end_headers()
                self.wfile.write(b'{}')
                return
            if self.path != '/openai/v1/chat/completions':
                self.send_error(404)
                return
            payload = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
            if 'response_format' not in payload:
                chat_payloads.append(payload)
                body = json.dumps({'choices': [{'message': {'content': 'Local general support reply'}}]}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(body)
                return
            context = json.loads(payload['messages'][-1]['content'])
            activity = context['activities'][0]
            suggestion = {'budget': [], 'ideas': [], 'schedule': [{
                'activity': activity['activity'], 'reason': 'Local test schedule move',
                'new_date': '2027-01-02', 'new_time': '10:00', 'new_end_time': '12:00'}]}
            body = json.dumps({'choices': [{'message': {'content': json.dumps(suggestion)}}]}).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            key = self.path.rsplit('/', 1)[-1]
            if self.path.startswith('/storage/v1/object/authenticated/travelease-documents/') and key in {str(i) * 64 for i in range(1, 5)}:
                self.send_response(200)
                self.end_headers()
                self.wfile.write(PDF)
            else:
                self.send_error(404)

        def log_message(self, *args):
            pass

    storage = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Storage)
    threading.Thread(target=storage.serve_forever, daemon=True).start()
    print('Isolated run:', work, flush=True)
    try:
        run([args.mysql_bin / 'mysql_install_db.exe', f'--datadir={data}',
             f'--password={PASSWORD}', f'--port={db_port}'], env=env)
        db = launch([args.mysql_bin / 'mysqld.exe', '--no-defaults',
                     f'--basedir={args.mysql_bin.parent}', f'--datadir={data}',
                     f'--port={db_port}', '--bind-address=127.0.0.1',
                     f'--server-id={SERVER_ID}', '--skip-log-bin', '--console'], 'database')
        for attempt in range(100):
            try:
                guard()
                break
            except (subprocess.CalledProcessError, AssertionError):
                if db.poll() is not None:
                    raise RuntimeError('Test database stopped; inspect database.log')
                time.sleep(.1)
        else:
            raise RuntimeError('Test database did not become ready')
        check('disposable server ID, port and data directory verified', True)
        candidate, baseline = work / 'candidate', work / 'baseline'
        snapshot(candidate)
        snapshot(baseline, args.baseline)
        ca = work / 'unit-test-ca.pem'
        ca.write_text('synthetic test CA\n', encoding='ascii')
        helper_check = r'''
require $argv[1];
function expect_runtime(callable $call): void {
    try { $call(); } catch (RuntimeException $e) { return; }
    throw new Exception('Expected RuntimeException');
}
$base = database_pdo_options(['APP_ENV' => 'development']);
if (count($base) !== 3 || $base[PDO::ATTR_EMULATE_PREPARES] !== false) {
    throw new Exception('Base options changed');
}
$tls = database_pdo_options(['APP_ENV' => 'production', 'DB_SSL_CA_FILE' => $argv[2]]);
if ($tls[PDO::MYSQL_ATTR_SSL_CA] !== $argv[2]
    || $tls[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] !== true) {
    throw new Exception('TLS options missing');
}
expect_runtime(fn() => database_pdo_options(['APP_ENV' => 'production']));
expect_runtime(fn() => database_pdo_options(['DB_SSL_CA_FILE' => $argv[3]]));
expect_runtime(fn() => database_pdo_options(['DB_SSL_CA_FILE' => $argv[4]]));
echo 'OK';
'''
        helper = candidate / 'config/database_options.php'
        helper_result = run([args.php, '-r', helper_check, helper, ca,
                             work / 'missing-ca.pem', work], env=env).stdout.decode()
        check('database TLS options are pure and fail closed', helper_result == 'OK')
        guard()
        raw((candidate / 'database/schema.sql').read_text(encoding='utf-8'))
        for migration in ['007_add_escalations', '008_add_escalation_replies',
                          '009_add_escalation_messages', '024_add_support_trip_shares']:
            sql((candidate / f'database/migration_{migration}.sql').read_text(encoding='utf-8'))
        pw = run([args.php, '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', PASSWORD], env=env).stdout.decode()
        for i, role in enumerate(ROLES, 1):
            system_role = role if role in ['staff', 'admin'] else 'customer'
            sql(f"INSERT INTO users(id,name,email,password_hash,email_verified_at,role) VALUES ({i},'{role}','ui-{role}@example.test','{pw}',UTC_TIMESTAMP(),'{system_role}')")
        sql("INSERT INTO trips(id,user_id,name,destination,start_date,end_date,status,time_zone,budget_currency) VALUES (1,1,'Local regression trip','Cebu','2027-01-01','2027-01-03','planning','Asia/Manila','PHP'),(2,5,'Other trip','Tokyo','2027-02-01','2027-02-03','planning','Asia/Tokyo','JPY'); INSERT INTO trip_memberships(trip_id,user_id,role) VALUES (1,2,'editor'),(1,3,'commenter'),(1,4,'viewer'); INSERT INTO itinerary_items(id,trip_id,item_date,item_time,end_time,title,category) VALUES (1,1,'2027-01-01','09:00','11:00','Fixture activity','activity'); INSERT INTO escalations(id,user_id,message,status) VALUES (1,1,'Local fixture only','open');")
        for i, (visibility, creator) in enumerate([('members', 1), ('editors', 1), ('private', 1), ('private', 2)], 1):
            sql(f"INSERT INTO trip_references(id,trip_id,created_by,title,kind,visibility) VALUES ({i},1,{creator},'Sentinel reference {i}','booking','{visibility}'); INSERT INTO trip_documents(id,reference_id,uploaded_by,object_key,original_name,mime_type,byte_size,file_hash,state) VALUES ({i},{i},{creator},'{str(i)*64}','fixture.pdf','application/pdf',{len(PDF)},'{hashlib.sha256(PDF).hexdigest()}','ready');")
        check('fresh schema initializes itinerary version',
              sql('SELECT version FROM itinerary_items WHERE id=1') == '1')
        sql('ALTER TABLE itinerary_items DROP CONSTRAINT chk_itinerary_items_version, DROP COLUMN version')
        sql((candidate / 'database/migration_030_add_itinerary_item_version.sql').read_text(encoding='utf-8'))
        check('migration upgrades existing activity with version one',
              sql('SELECT version FROM itinerary_items WHERE id=1') == '1')
        servers = {}
        for label, root in [('main', baseline), ('ux', candidate)]:
            sessions = work / ('sessions-' + label)
            sessions.mkdir()
            app_env = {k: v for k, v in env.items() if k != 'MYSQL_PWD'}
            app_env.update(APP_ENV='development', DB_HOST='127.0.0.1', DB_PORT=str(db_port),
                           DB_USER='root', DB_PASS=PASSWORD, DB_NAME='travelease',
                           CLIENT_IP_SOURCE='none', SUPABASE_URL=f'http://127.0.0.1:{storage.server_port}',
                           SUPABASE_SECRET_KEY='sb_secret_local_test', GROQ_API_KEY='local-test-only',
                           GROQ_API_URL=f'http://127.0.0.1:{storage.server_port}',
                           APP_URL='http://localhost:8000', BREVO_API_KEY='local-test-only',
                           MAIL_FROM_EMAIL='sender@example.test',
                           BREVO_API_URL=f'http://127.0.0.1:{storage.server_port}/mail')
            http_port = port()
            launch([args.php, '-d', f'session.save_path="{sessions.as_posix()}"', '-S',
                    f'127.0.0.1:{http_port}', '-t', root], label, app_env)
            base = f'http://127.0.0.1:{http_port}'
            for attempt in range(100):
                try:
                    Client(base).request('/auth/login.php')
                    break
                except urllib.error.URLError:
                    time.sleep(.1)
            else:
                raise RuntimeError('PHP test server not ready')
            clients = {r: Client(base).login(r) for r in ROLES}
            servers[label] = (base, clients)
            check(label + ' guest cannot open trip', Client(base).request('/customer/trips/view.php?id=1')[0] == 302)
            for role, client in clients.items():
                expected = 403 if role in ['staff', 'admin'] else 404 if role == 'outsider' else 200
                check(label + ' trip guard ' + role, client.request('/customer/trips/view.php?id=1')[0] == expected)
                if expected != 200:
                    continue
                status, _, body = client.request('/customer/trips/references.php?id=1')
                visible = {1, 2, 3, 4} if role == 'owner' else {1, 2, 4} if role == 'editor' else {1}
                check(label + ' reference visibility ' + role,
                      status == 200 and all((f'Sentinel reference {i}'.encode() in body) == (i in visible) for i in range(1, 5)))
                for i in range(1, 5):
                    status, headers, content = client.request(f'/customer/trips/document_download.php?id={i}')
                    check(f'{label} direct document {i} {role}',
                          (status == 200 and content == PDF and 'attachment' in headers.get('Content-Disposition', ''))
                          if i in visible else status == 404)
        for role in ['owner', 'editor', 'commenter', 'viewer', 'staff', 'admin']:
            paths = ['/profile.php']
            if role in ['owner', 'editor', 'commenter', 'viewer']:
                paths += ['/customer/dashboard.php', '/customer/trips/view.php?id=1',
                          '/customer/trips/references.php?id=1', '/customer/trips/reminders.php?id=1',
                          '/customer/trips/edit.php?id=1', '/customer/trips/share_links.php?id=1']
            else:
                paths += [f'/{role}/dashboard.php']
            for path in paths:
                old = servers['main'][1][role].request(path)
                new = servers['ux'][1][role].request(path)
                old_page, new_page = Forms(old[2]), Forms(new[2])
                check(f'form/status parity {role} {path}', old[0] == new[0]
                      and old_page.signatures == new_page.signatures)
                # Archived remains on the dashboard; its duplicate global nav link was intentionally removed.
                missing = canonical_links(old_page.links, path) - canonical_links(new_page.links, path) - {'/customer/dashboard.php?archived=1'}
                check(f'link parity {role} {path} (missing: {sorted(missing)})', not missing)
        base, clients = servers['ux']
        viewer = clients['viewer']
        token = viewer.token('/customer/trips/view.php?id=1')
        check('viewer cannot forge activity mutation', viewer.request('/customer/trips/itinerary_action.php',
              {'csrf_token': token, 'item_id': 1, 'action': 'complete'})[0] == 403)
        editor = clients['editor']
        token = editor.token('/customer/trips/view.php?id=1')
        check('CSRF rejects mutation', editor.request('/customer/trips/itinerary_action.php',
              {'csrf_token': 'invalid', 'item_id': 1, 'action': 'complete'})[0] == 403)
        sql("UPDATE trip_memberships SET role='viewer' WHERE trip_id=1 AND user_id=2")
        check('role downgrade invalidates loaded edit', editor.request('/customer/trips/itinerary_action.php',
              {'csrf_token': token, 'item_id': 1, 'action': 'complete'})[0] == 403)
        sql("UPDATE trip_memberships SET role='editor' WHERE trip_id=1 AND user_id=2")
        check('editor can complete activity', editor.request('/customer/trips/itinerary_action.php',
              {'csrf_token': token, 'item_id': 1, 'action': 'complete'})[0] == 302
              and sql('SELECT completed_at IS NOT NULL FROM itinerary_items WHERE id=1') == '1')
        sql('DELETE FROM trip_memberships WHERE trip_id=1 AND user_id=4')
        check('removed member loses direct document access', viewer.request('/customer/trips/document_download.php?id=1')[0] == 404)
        for path in ['/customer/trips/view.php?id=1', '/customer/trips/references.php?id=1']:
            check('removed member denied ' + path, viewer.request(path)[0] == 404)
        sql("INSERT INTO trip_memberships(trip_id,user_id,role) VALUES(1,4,'viewer')")
        # A second process is required for overlapping PHP requests on Windows.
        peer_port = port()
        peer_env = dict(app_env, CLIENT_IP_SOURCE='header:X-Test-IP')
        launch([args.php, '-d', f'session.save_path="{(work / "sessions-ux").as_posix()}"',
                '-S', f'127.0.0.1:{peer_port}', '-t', candidate], 'ux-peer', peer_env)
        peer_base = f'http://127.0.0.1:{peer_port}'
        for attempt in range(100):
            try:
                peer = Client(peer_base).login('editor')
                break
            except urllib.error.URLError:
                time.sleep(.1)
        owner = clients['owner']
        edit_path = '/customer/trips/itinerary_edit.php?id=1'

        def edit_data(client, title):
            status, _, body = client.request(edit_path)
            version = re.search(rb'name="version" value="([^"]*)"', body)[1].decode()
            csrf = re.search(rb'name="csrf_token" value="([^"]*)"', body)[1].decode()
            return dict(csrf_token=csrf, id=1, version=version, title=title,
                        item_date='2027-01-01', item_time='09:00', end_time='11:00',
                        location='', notes='', estimated_cost='', actual_cost='',
                        category='activity', assignee_id='')

        stale = edit_data(owner, 'Owner typed work')
        fresh = edit_data(peer, 'Editor saved work')
        check('activity edit saves current version', peer.request(edit_path, fresh)[0] == 302)
        before = sql("SELECT version FROM itinerary_items WHERE id=1")
        status, _, conflict = owner.request(edit_path, stale)
        check('stale edit returns 409 and preserves typed work', status == 409
              and b'Owner typed work' in conflict and b'Save changes again' in conflict
              and sql('SELECT title FROM itinerary_items WHERE id=1') == 'Editor saved work')
        stale['version'] = re.search(rb'name="version" value="([^"]*)"', conflict)[1].decode()
        check('explicit second save replaces current version', owner.request(edit_path, stale)[0] == 302
              and sql('SELECT title FROM itinerary_items WHERE id=1') == 'Owner typed work')
        no_op = edit_data(owner, 'Owner typed work')
        before = sql("SELECT version FROM itinerary_items WHERE id=1")
        logs = sql("SELECT COUNT(*) FROM trip_activity_log WHERE event_type='activity_updated'")
        check('unchanged save does not bump version or history', owner.request(edit_path, no_op)[0] == 302
              and sql('SELECT version FROM itinerary_items WHERE id=1') == before
              and sql("SELECT COUNT(*) FROM trip_activity_log WHERE event_type='activity_updated'") == logs)
        for invalid in ['', '0', 'abc', '4294967296']:
            bad = dict(no_op, version=invalid, title='Must not save')
            check('invalid version conflicts: ' + repr(invalid), owner.request(edit_path, bad)[0] == 409)
        old = edit_data(owner, 'Preserved stale value')
        check('intervening edit saves', peer.request(edit_path, edit_data(peer, 'Newer value'))[0] == 302)
        invalid_form = dict(old, title='')
        status, _, body = owner.request(edit_path, invalid_form)
        check('validation error retains submitted stale version', status == 200
              and ('name="version" value="' + old['version'] + '"').encode() in body)
        check('correcting validation still detects stale version', owner.request(edit_path, old)[0] == 409)
        # Released together, two requests usually overlap, but a fast round can
        # finish one before the other starts. Every attempt must be correct; the
        # loop continues until 10 attempts have genuinely overlapped.
        overlapped = 0
        for round_no in range(40):
            if overlapped == 10:
                break
            first = edit_data(owner, f'Concurrent owner {round_no}')
            second = edit_data(peer, f'Concurrent editor {round_no}')
            barrier = threading.Barrier(2)
            def submit(client, values):
                barrier.wait()
                started = time.monotonic()
                response = client.request(edit_path, values)
                return response[0], started, time.monotonic(), values['title']
            version_before = int(sql('SELECT version FROM itinerary_items WHERE id=1'))
            logs_before = int(sql("SELECT COUNT(*) FROM trip_activity_log WHERE event_type='activity_updated'"))
            with concurrent.futures.ThreadPoolExecutor(2) as pool:
                a = pool.submit(submit, owner, first)
                b = pool.submit(submit, peer, second)
                pair = [a.result(), b.result()]
            statuses = sorted(row[0] for row in pair)
            winner = next((row for row in pair if row[0] == 302), None)
            check(f'simultaneous saves round {round_no}: one wins, one conflicts', statuses == [302, 409]
                  and int(sql('SELECT version FROM itinerary_items WHERE id=1')) == version_before + 1
                  and int(sql("SELECT COUNT(*) FROM trip_activity_log WHERE event_type='activity_updated'")) == logs_before + 1
                  and winner is not None and sql('SELECT title FROM itinerary_items WHERE id=1') == winner[3])
            if max(row[1] for row in pair) < min(row[2] for row in pair):
                overlapped += 1
        check(f'10 genuinely overlapping save pairs observed ({round_no + 1} attempts)', overlapped == 10)
        malformed_base = edit_data(owner, 'Must not save malformed input')
        unchanged = sql('SELECT title, version FROM itinerary_items WHERE id=1')
        for field in ['item_date', 'item_time', 'title', 'location', 'notes', 'estimated_cost', 'actual_cost']:
            bad = dict(malformed_base)
            del bad[field]
            bad[field + '[]'] = 'malformed-sentinel'
            check('activity rejects array ' + field, owner.request(edit_path, bad)[0] == 400
                  and sql('SELECT title, version FROM itinerary_items WHERE id=1') == unchanged)
        nul_date = dict(malformed_base, item_date='2027-01\0-01')
        check('NUL date is rejected without fatal error', owner.request(edit_path, nul_date)[0] == 200
              and sql('SELECT title, version FROM itinerary_items WHERE id=1') == unchanged)
        for route in ['/auth/google_login.php', '/auth/google_link.php']:
            check('Google route rejects array CSRF ' + route,
                  owner.request(route, {'csrf_token[]': 'bad', 'credential': 'bad'})[0] == 403)
            check('Google route rejects array credential ' + route,
                  owner.request(route, {'csrf_token': malformed_base['csrf_token'],
                                        'credential[]': 'bad', 'password': 'bad'})[0] == 400)
        trip_path = '/customer/trips/edit.php?id=1'
        trip_token = owner.token(trip_path)
        trip_before = sql('SELECT name, destination, start_date, end_date, notes FROM trips WHERE id=1')
        for field in ['name', 'destination', 'start_date', 'end_date', 'status',
                      'budget_currency', 'budget_amount', 'notes']:
            check('trip rejects array ' + field, owner.request(trip_path,
                  {'csrf_token': trip_token, field + '[]': 'bad'})[0] == 400
                  and sql('SELECT name, destination, start_date, end_date, notes FROM trips WHERE id=1') == trip_before)
        # Remaining forms: an array-valued field is a 400, never a TypeError/500, and changes nothing.
        guest = Client(base)
        users_before = sql('SELECT COUNT(*) FROM users')
        for path, fields in [('/auth/login.php', {'email': 'ui-owner@example.test', 'password': PASSWORD}),
                             ('/auth/register.php', {'name': 'Array Tester', 'email': 'array@example.test',
                                                     'password': PASSWORD, 'confirm_password': PASSWORD}),
                             ('/auth/resend_verification.php', {'email': 'ui-owner@example.test'})]:
            for field in fields:
                bad = dict(fields, csrf_token=guest.token(path))
                del bad[field]
                bad[field + '[]'] = 'malformed-sentinel'
                status, _, body = guest.request(path, bad)
                check(f'{path} rejects array {field}', status == 400 and b'Invalid form input' in body)
        check('array sign-up attempts create no account', sql('SELECT COUNT(*) FROM users') == users_before)
        items_before = sql('SELECT COUNT(*) FROM itinerary_items')
        add_base = {'trip_id': '1', 'item_date': '2027-01-02', 'item_time': '', 'title': 'Array add',
                    'location': '', 'notes': '', 'estimated_cost': '', 'actual_cost': ''}
        for field in ['item_date', 'item_time', 'title', 'location', 'notes', 'estimated_cost', 'actual_cost']:
            bad = dict(add_base, csrf_token=owner.token('/customer/trips/view.php?id=1'))
            del bad[field]
            bad[field + '[]'] = 'malformed-sentinel'
            status, _, body = owner.request('/customer/trips/itinerary_add.php', bad)
            check('activity add rejects array ' + field, status == 400 and b'Invalid form input' in body)
        check('array activity adds create nothing', sql('SELECT COUNT(*) FROM itinerary_items') == items_before)
        name_before = sql('SELECT name FROM users WHERE id=1')
        status, _, body = owner.request('/profile.php', {
            'csrf_token': owner.token('/profile.php'), 'name[]': 'malformed-sentinel'})
        check('profile rejects array name', status == 400 and b'Invalid form input' in body
              and sql('SELECT name FROM users WHERE id=1') == name_before)
        production_ini = ROOT / 'docker/php-production.ini'
        settings = run([args.php, '-c', production_ini, '-r',
                        "echo json_encode([ini_get('display_errors'),ini_get('display_startup_errors'),ini_get('log_errors'),ini_get('zend.exception_ignore_args')]);"], env=env)
        check('production PHP error settings disable browser details',
              json.loads(settings.stdout) == ['', '', '1', '1'])
        stale_plan = edit_data(owner, 'Must not overwrite applied plan')
        review_token = owner.token('/customer/trips/view.php?id=1')
        check('local stub plan review request succeeds', owner.request('/customer/trips/plan_review.php',
              {'csrf_token': review_token, 'trip_id': 1})[0] == 302)
        _, _, reviewed = owner.request('/customer/trips/view.php?id=1')
        review_id = re.search(rb'name="review_id" value="([^"]+)"', reviewed)
        check('local stub produces an applicable schedule suggestion', review_id is not None)
        version_before = int(sql('SELECT version FROM itinerary_items WHERE id=1'))
        check('applying AI suggestion increments activity version', owner.request('/customer/trips/plan_apply.php',
              {'csrf_token': review_token, 'trip_id': 1, 'suggestion': 0,
               'review_id': review_id[1].decode()})[0] == 302
              and int(sql('SELECT version FROM itinerary_items WHERE id=1')) == version_before + 1
              and sql('SELECT item_date FROM itinerary_items WHERE id=1') == '2027-01-02')
        check('pre-plan edit cannot overwrite applied suggestion', owner.request(edit_path, stale_plan)[0] == 409
              and sql('SELECT item_date FROM itinerary_items WHERE id=1') == '2027-01-02')
        for role in ['viewer', 'commenter', 'outsider']:
            bad = dict(stale_plan, csrf_token=clients[role].token('/customer/dashboard.php'),
                       version=sql('SELECT version FROM itinerary_items WHERE id=1'))
            item_before = sql('SELECT title, item_date, version FROM itinerary_items WHERE id=1')
            check('activity edit denies ' + role, clients[role].request(edit_path, bad)[0]
                  == (404 if role == 'outsider' else 403)
                  and sql('SELECT title, item_date, version FROM itinerary_items WHERE id=1') == item_before)
        # Use a syntactically valid absent bearer: limiter runs before lookup.
        share_data = {'csrf_token': owner.token('/customer/dashboard.php'), 'token': 'a' * 64}
        for route in ['/public/share_resolve.php', '/customer/trips/share_join.php']:
            check('share access skips IP bucket when disabled ' + route,
                  owner.request(route, share_data)[0] == 404
                  and sql('SELECT COUNT(*) FROM trip_share_access_limits') == '0')
        peer_share = dict(share_data, csrf_token=peer.token('/customer/dashboard.php'))
        for route, (busy_ip, other_ip) in [('/public/share_resolve.php', ('192.0.2.1', '192.0.2.2')),
                                           ('/customer/trips/share_join.php', ('192.0.2.3', '192.0.2.4'))]:
            ip_hash = hashlib.sha256(busy_ip.encode()).hexdigest()
            sql(f"INSERT INTO trip_share_access_limits VALUES ('{ip_hash}',UTC_TIMESTAMP(),59)")
            check('share visitor last slot allowed ' + route,
                  peer.request(route, peer_share, {'X-Test-IP': busy_ip})[0] == 404)
            status, headers, _ = peer.request(route, peer_share, {'X-Test-IP': busy_ip})
            retry_after = headers.get('Retry-After') or ''
            check('share visitor next slot limited with Retry-After ' + route, status == 429
                  and retry_after.isdigit() and 1 <= int(retry_after) <= 60)
            check('different share visitor has independent bucket ' + route,
                  peer.request(route, peer_share, {'X-Test-IP': other_ip})[0] == 404)
        sql('UPDATE users SET email_verified_at=NULL WHERE id=5')
        resend = {'csrf_token': owner.token('/auth/resend_verification.php'), 'email': 'ui-outsider@example.test'}
        resend_key = hashlib.sha256(b'resend_user|5').hexdigest()

        def outcome_message(body):
            # User input is echoed, but the outcome message must remain identical.
            match = re.search(rb'<p class="success-message"[^>]*>(.*?)</p>', body)
            return match[1] if match else None

        outcomes = {}
        mail_start = mail_state['attempts']
        status, _, outcomes['sent'] = owner.request('/auth/resend_verification.php', resend)
        check('resend first request sends', status == 200 and mail_state['attempts'] == mail_start + 1)
        _, _, outcomes['cooldown'] = owner.request('/auth/resend_verification.php', resend)
        check('resend immediate retry suppressed', mail_state['attempts'] == mail_start + 1
              and sql(f"SELECT attempts FROM rate_limits WHERE limit_key='{resend_key}'") == '1')
        for _ in range(2):
            sql('UPDATE email_verification_resend_limits SET last_sent_at=NULL WHERE user_id=5')
            owner.request('/auth/resend_verification.php', resend)
        sql('UPDATE email_verification_resend_limits SET last_sent_at=NULL WHERE user_id=5')
        _, _, outcomes['limited'] = owner.request('/auth/resend_verification.php', resend)
        check('resend hourly account cap enforced', mail_state['attempts'] == mail_start + 3)
        for label, email in [('missing', 'missing@example.test'), ('verified', 'ui-owner@example.test')]:
            _, _, outcomes[label] = owner.request('/auth/resend_verification.php', dict(resend, email=email))
        messages = {label: outcome_message(body) for label, body in outcomes.items()}
        check('resend outcomes share one message: ' + ', '.join(sorted(messages)),
              messages['sent'] is not None and len(set(messages.values())) == 1)
        sql('DELETE FROM rate_limits')
        mail_state['status'] = 500
        failure_start = mail_state['attempts']
        owner.request('/auth/resend_verification.php', resend)
        check('failed resend reached mail stub', mail_state['attempts'] == failure_start + 1)
        mail_state['status'] = 201
        check('failed resend releases cooldown and hourly slot',
              owner.request('/auth/resend_verification.php', resend)[0] == 200
              and mail_state['attempts'] == failure_start + 2
              and sql(f"SELECT attempts FROM rate_limits WHERE limit_key='{resend_key}'") == '1')
        # Rotate visitor addresses without clearing the cooldown table.
        sql('DELETE FROM rate_limits')
        rotation_start = mail_state['attempts']
        peer_resend = dict(resend, csrf_token=peer.token('/auth/resend_verification.php'))
        for n in range(1, 5):
            peer.request('/auth/resend_verification.php', peer_resend,
                         {'X-Test-IP': f'198.51.100.{n}'})
        check('resend account cap survives changing visitor addresses',
              mail_state['attempts'] == rotation_start + 3)
        # Password re-check limit on account deletion (a dedicated solo account).
        sql(f"INSERT INTO users(id,name,email,password_hash,email_verified_at,role) VALUES (50,'deleter','ui-deleter@example.test','{pw}',UTC_TIMESTAMP(),'customer')")
        deleter = Client(base).login('deleter')
        reauth_key = hashlib.sha256(b'reauth_user|50').hexdigest()
        reauth_count = lambda: sql(f"SELECT COALESCE(MAX(attempts),0) FROM rate_limits WHERE limit_key='{reauth_key}'")
        delete_form = lambda password, confirm='DELETE': {
            'csrf_token': deleter.token('/profile.php'), 'confirm': confirm, 'password': password}
        deleter.request('/account/delete.php', delete_form(PASSWORD, confirm='delete'))
        deleter.request('/account/delete.php', delete_form(''))
        check('unconfirmed or empty deletion attempts are not counted', reauth_count() == '0')
        for _ in range(5):
            deleter.request('/account/delete.php', delete_form('Wrong-Password-1'))
        check('wrong deletion passwords are counted', reauth_count() == '5')
        status, headers, _ = deleter.request('/account/delete.php', delete_form(PASSWORD))
        check('correct password is refused once the re-check limit is reached', status == 302
              and sql('SELECT COUNT(*) FROM users WHERE id=50') == '1' and reauth_count() == '5')
        sql(f"UPDATE rate_limits SET attempts=4 WHERE limit_key='{reauth_key}'")
        status, headers, _ = deleter.request('/account/delete.php', delete_form(PASSWORD))
        check('correct password below the limit deletes the account', status == 302
              and 'account_deleted=1' in (headers.get('Location') or '')
              and sql('SELECT COUNT(*) FROM users WHERE id=50') == '0')

        # B9: a separate trip keeps support/expense acceptance from changing
        # the activity and chatbot fixtures used elsewhere in this run.
        sql("INSERT INTO trips(id,user_id,name,destination,start_date,end_date,status,time_zone,budget_currency) VALUES (3,1,'Support acceptance trip','Baguio','2027-03-01','2027-03-03','planning','Asia/Manila','PHP'); INSERT INTO trip_memberships(trip_id,user_id,role) VALUES (3,2,'editor'),(3,3,'commenter'),(3,4,'viewer'); INSERT INTO itinerary_items(trip_id,item_date,title,category) VALUES (3,'2027-03-01','Support visible activity','activity'); INSERT INTO trip_references(trip_id,created_by,title,kind,visibility,confirmation_code) VALUES (3,1,'Support hidden reference','booking','private','SUPPORT-PRIVATE-SENTINEL')")
        staff = clients['staff']
        support_view = '/staff/shared_trip.php?escalation_id=1&trip_id=3'
        support_form = {'csrf_token': owner.token('/customer/support_access.php'),
                        'trip_id': 3, 'action': 'share'}
        check('staff cannot see a trip before owner shares it', staff.request(support_view)[0] == 404)
        editor_support = dict(support_form, csrf_token=editor.token('/customer/support_access.php'))
        check('editor cannot grant support access',
              editor.request('/customer/support_access.php', editor_support)[0] == 403
              and sql('SELECT COUNT(*) FROM support_trip_shares WHERE trip_id=3') == '0')
        check('owner can grant trip access to support',
              owner.request('/customer/support_access.php', support_form)[0] == 302
              and sql('SELECT COUNT(*) FROM support_trip_shares WHERE trip_id=3 AND escalation_id=1 AND revoked_at IS NULL') == '1')
        status, _, shared_page = staff.request(support_view)
        check('staff shared view exposes plan without private references', status == 200
              and b'Support acceptance trip' in shared_page and b'Support visible activity' in shared_page
              and b'SUPPORT-PRIVATE-SENTINEL' not in shared_page
              and b'Support hidden reference' not in shared_page)
        check('staff remains unable to edit a shared trip',
              staff.request('/customer/trips/edit.php?id=3')[0] == 403
              and sql('SELECT name FROM trips WHERE id=3') == 'Support acceptance trip')
        check('owner revocation immediately ends staff trip access',
              owner.request('/customer/support_access.php', dict(support_form, action='revoke'))[0] == 302
              and staff.request(support_view)[0] == 404)
        owner.request('/customer/support_access.php', support_form)
        check('owner can regrant support access', staff.request(support_view)[0] == 200)
        resolve_form = {'csrf_token': staff.token('/staff/escalations.php'), 'escalation_id': 1}
        check('resolving support request ends shared trip access',
              staff.request('/staff/resolve_escalation.php', resolve_form)[0] == 302
              and sql('SELECT status FROM escalations WHERE id=1') == 'resolved'
              and staff.request(support_view)[0] == 404)
        # Restore only the synthetic original request for later chat/browser tests.
        sql("UPDATE escalations SET status='open', resolved_at=NULL WHERE id=1")
        check('reopened request does not leave trip shared with support',
              owner.request('/customer/support_access.php', dict(support_form, action='revoke'))[0] == 302
              and sql('SELECT COUNT(*) FROM support_trip_shares WHERE trip_id=3 AND revoked_at IS NULL') == '0')

        expense_path = '/customer/trips/expense_action.php'
        expense_form = {'csrf_token': editor.token('/customer/trips/expenses.php?id=3'),
                        'trip_id': '3', 'action': 'create', 'title': 'Acceptance equal expense',
                        'category': 'food', 'split_mode': 'equal', 'receipt_url': '',
                        'payer_id': '1', 'travelers[0]': '1', 'travelers[1]': '2',
                        'travelers[2]': '3', 'amount': '100.01', 'currency': 'PHP'}
        check('editor can record equal-split expense', editor.request(expense_path, expense_form)[0] == 302
              and sql("SELECT COUNT(*) FROM trip_expenses WHERE trip_id=3 AND title='Acceptance equal expense' AND amount_minor=10001 AND currency='PHP'") == '1')
        expense_id = sql("SELECT id FROM trip_expenses WHERE trip_id=3 AND title='Acceptance equal expense'")
        check('equal expense preserves every cent across three travelers',
              sql(f'SELECT SUM(amount_minor), COUNT(*) FROM trip_expense_shares WHERE expense_id={expense_id}') == '10001\t3'
              and sql(f'SELECT GROUP_CONCAT(amount_minor ORDER BY traveler_id) FROM trip_expense_shares WHERE expense_id={expense_id}') == '3334,3334,3333')
        viewer_expense = dict(expense_form, csrf_token=viewer.token('/customer/trips/expenses.php?id=3'))
        check('viewer cannot create an expense', viewer.request(expense_path, viewer_expense)[0] == 403
              and sql('SELECT COUNT(*) FROM trip_expenses WHERE trip_id=3') == '1')
        settlement = {'csrf_token': expense_form['csrf_token'], 'trip_id': '3',
                      'action': 'settle', 'expense_id': expense_id, 'version': '1', 'traveler_id': '2'}
        check('editor can settle a non-payer expense share', editor.request(expense_path, settlement)[0] == 302
              and sql(f'SELECT settled_at IS NOT NULL FROM trip_expense_shares WHERE expense_id={expense_id} AND traveler_id=2') == '1'
              and sql(f'SELECT version FROM trip_expenses WHERE id={expense_id}') == '2')
        check('stale expense settlement cannot overwrite current share state',
              editor.request(expense_path, dict(settlement, action='reopen'))[0] == 409
              and sql(f'SELECT settled_at IS NOT NULL FROM trip_expense_shares WHERE expense_id={expense_id} AND traveler_id=2') == '1'
              and sql(f'SELECT version FROM trip_expenses WHERE id={expense_id}') == '2')
        check('editor can reopen settled expense share with current version',
              editor.request(expense_path, dict(settlement, action='reopen', version='2'))[0] == 302
              and sql(f'SELECT settled_at IS NULL FROM trip_expense_shares WHERE expense_id={expense_id} AND traveler_id=2') == '1'
              and sql(f'SELECT version FROM trip_expenses WHERE id={expense_id}') == '3')
        def chat_request(role, trip_id=1, message='What is my trip status?', omit_trip=False):
            client = clients[role]
            body = {'csrf_token': client.token('/profile.php'), 'message': message}
            if not omit_trip:
                body['trip_id'] = trip_id
            return client.request('/chat/ask.php', body, {'Content-Type': 'application/json'})

        sql('DELETE FROM rate_limits')
        for role in ['owner', 'editor', 'commenter', 'viewer']:
            status, _, body = chat_request(role)
            answer = json.loads(body)
            check('trip chatbot authorized ' + role, status == 200 and not answer['escalate']
                  and '2027-01-01 to 2027-01-03' in answer['reply']
                  and '1 of 1 activities' in answer['reply'] and not chat_payloads)
        outsider_response = chat_request('outsider')
        missing_response = chat_request('owner', 999)
        check('chat unrelated and missing trips indistinguishable', outsider_response[0] == 404
              and missing_response[0] == 404 and outsider_response[2] == missing_response[2])
        for role in ['staff', 'admin']:
            check('chat context denies system role ' + role, chat_request(role)[0] == 403)
        for invalid in [0, -1, 1.5, 'abc', [], {}, 4294967296, True, None, '01']:
            sql('DELETE FROM rate_limits')
            check('chat rejects malformed trip ' + repr(invalid), chat_request('owner', invalid)[0] == 400)
        sql('DELETE FROM trip_memberships WHERE trip_id=1 AND user_id=4')
        check('chat rechecks removed member', chat_request('viewer')[0] == 404)
        sql("INSERT INTO trip_memberships(trip_id,user_id,role) VALUES(1,4,'viewer')")
        sql("UPDATE users SET role='staff' WHERE id=2")
        check('chat rechecks changed system role', chat_request('editor')[0] == 403)
        sql("UPDATE users SET role='customer' WHERE id=2")
        for question in ['When is my trip?', 'When does my trip start?']:
            status, _, body = chat_request('owner', message=question)
            check('trip date intent ' + question, status == 200 and b'Saved dates:' in body and not chat_payloads)
        sql("UPDATE trips SET status='confirmed', archived_at=UTC_TIMESTAMP() WHERE id=1")
        status, _, body = chat_request('owner')
        check('chat uses current saved status and archive state', status == 200
              and b'Confirmed' in body and b'Archived: yes' in body and b'not a confirmed booking' in body)
        sql("UPDATE trips SET status='planning', archived_at=NULL WHERE id=1")
        status, _, body = chat_request('owner', message='How do I add an activity?')
        check('general support receives no trip snapshot', status == 200 and len(chat_payloads) == 1
              and all(sentinel not in json.dumps(chat_payloads[-1]) for sentinel in
                      ['2027-01-01', 'Asia/Manila', 'activity_total', 'trip_id', 'Sentinel reference', 'Cebu', 'Local regression trip']))
        status, _, body = chat_request('owner', message='General help please', omit_trip=True)
        check('dashboard chat keeps general support behavior', status == 200 and len(chat_payloads) == 2)
        status, _, body = chat_request('owner', message='I need a refund for this trip')
        check('trip chat hard escalation preserves only original message', status == 200
              and json.loads(body)['escalate'] and len(chat_payloads) == 2
              and sql('SELECT message FROM escalation_messages ORDER BY id DESC LIMIT 1') == 'I need a refund for this trip')
        for question in ['How do I mark an activity done?', 'Can I change the dates?',
                         'How do I archive a trip?', 'When I delete a trip, what happens?']:
            sql('DELETE FROM rate_limits')
            calls = len(chat_payloads)
            status, _, body = chat_request('owner', message=question)
            check('how-to retains general support ' + question, status == 200 and len(chat_payloads) == calls + 1
                  and b'Saved trip status' not in body)
        if args.node:
            assert args.modules, '--modules is required with --node'
            browser = subprocess.run([args.node, str(ROOT / 'tests/launcher_smoke.cjs'), args.modules, base, str(work)],
                                     env=env, capture_output=True, timeout=120)
            browser_log = (browser.stdout + browser.stderr).decode('utf-8', errors='replace')
            (work / 'browser.log').write_text(browser_log, encoding='utf-8')
            print(browser_log, end='')
            check('browser launcher scenarios', browser.returncode == 0)
        check('candidate and baseline snapshots contain no .env',
              not any(p.name == '.env' for root in [candidate, baseline] for p in root.rglob('.env')))
    finally:
        storage.shutdown()
        storage.server_close()
        for proc in reversed(processes):
            if proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.wait()
        for handle in handles:
            handle.close()
        report = {'baseline': run(['git', '-C', ROOT, 'rev-parse', args.baseline]).stdout.decode().strip(),
                  'candidate': run(['git', '-C', ROOT, 'rev-parse', 'HEAD']).stdout.decode().strip(),
                  'working_diff': run(['git', '-C', ROOT, 'diff', '--stat']).stdout.decode(), 'checks': results}
        (work / 'results.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
        print('Results and logs:', work, flush=True)
    print(f'{len(results)} checks passed. Local fixtures/stub storage only; no production or external providers tested.')


if __name__ == '__main__':
    main()

"""Explicit local backup/restore drill on disposable synthetic data.

Starts its own MariaDB, loads the schema plus synthetic fixtures into
`travelease`, dumps it with mysqldump, restores the dump into
`travelease_restore` on the same disposable instance and compares the two.
A deliberately truncated dump restored into `travelease_partial` must fail
that comparison, and the app must sign in and serve a trip from the restored
schema. Never reads .env, never contacts a real database or provider, and
keeps every artifact under work/local-tests/<run>.

This proves a MariaDB 10.4 logical dump of this schema restores completely
on the same tooling. It says nothing about Aiven's managed backups, MySQL 8.4
dump formats, retention, or production restore procedures.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error

sys.path.insert(0, str(Path(__file__).resolve().parent))
from local_smoke import PASSWORD, ROOT, Client, port, run, snapshot  # noqa: E402

SERVER_ID = 11272028          # distinct from the smoke (…26) and TLS (…27) harnesses
SOURCE, RESTORE, PARTIAL = 'travelease', 'travelease_restore', 'travelease_partial'
# Synthetic multilingual values that must survive the round trip byte for byte.
EDITOR_NAME = 'Editor 編集者 🌏'
TRIP_NAME = 'Restore drill 東京 trip'
COMMENT_BODY = 'Looks great 👍 — ok'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mysql-bin', type=Path, default=Path('C:/xampp/mysql/bin'))
    parser.add_argument('--php', default=shutil.which('php'))
    args = parser.parse_args()
    if not args.php or not Path(args.php).is_file():
        raise RuntimeError('PHP executable required')
    for exe in ['mysql_install_db.exe', 'mysqld.exe', 'mysql.exe', 'mysqldump.exe']:
        if not (args.mysql_bin / exe).is_file():
            raise RuntimeError(f'Missing {exe}')

    runs = ROOT.parent / 'work' / 'local-tests'
    runs.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix='te-backup-', dir=runs)).resolve()
    data = work / 'data'
    db_port = port()
    processes, handles, results = [], [], []
    # Runtime essentials only; the root password of the disposable server goes
    # through MYSQL_PWD, never on a command line.
    env = {k: v for k, v in os.environ.items() if k.upper() in {
        'PATH', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA',
        'PROGRAMFILES', 'PROGRAMFILES(X86)', 'PROGRAMW6432', 'PROGRAMDATA', 'COMSPEC'}}
    env['MYSQL_PWD'] = PASSWORD
    client_args = ['--no-defaults', '--protocol=tcp', '--host=127.0.0.1', f'--port={db_port}',
                   '--user=root', '--default-character-set=utf8mb4']
    mysql = [args.mysql_bin / 'mysql.exe', *client_args, '--connect-timeout=2', '--batch', '--skip-column-names']

    def raw(statement, database=None, check=True):
        command = mysql + ([database] if database else [])
        return subprocess.run([str(a) for a in command], input=statement.encode('utf-8'),
                              capture_output=True, timeout=60, env=env, check=check)

    def query(statement, database=None):
        return raw(statement, database).stdout.decode('utf-8').strip()

    def guard():
        values = query('SELECT @@port, @@server_id, @@datadir').split('\t')
        if int(values[0]) != db_port or int(values[1]) != SERVER_ID:
            raise RuntimeError('Wrong database server; refusing to continue')
        if Path(values[2]).resolve() != data.resolve():
            raise RuntimeError('Wrong data directory; refusing to continue')

    def write(statement, database=None, check=True):
        guard()                                    # every write re-checks the server identity
        return raw(statement, database, check)

    def record(label, condition, detail=None):
        results.append({'check': label, 'passed': bool(condition), **({'detail': detail} if detail else {})})
        if not condition:
            raise RuntimeError(f'{label}: {detail}' if detail else label)
        print('PASS', label, flush=True)

    def launch(command, label, app_env):
        log = (work / (label + '.log')).open('wb')
        handles.append(log)
        proc = subprocess.Popen([str(a) for a in command], env=app_env, stdout=log, stderr=log)
        processes.append(proc)
        return proc

    def tables(db):
        return query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='" + db
                     + "' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME").splitlines()

    def readable(statement):
        # A missing table (as in a partial restore) is a difference, not a crash.
        result = raw(statement, check=False)
        if result.returncode != 0:
            return 'UNREADABLE'
        return result.stdout.decode('utf-8').strip()

    def signature(db):
        """Everything a restore must reproduce, keyed by section."""
        names = tables(db)
        sections = {'tables': names}
        sections['rows'] = {t: readable(f'SELECT COUNT(*) FROM `{db}`.`{t}`') for t in names}
        sections['checksums'] = {t: readable(f'CHECKSUM TABLE `{db}`.`{t}` EXTENDED').split('\t')[-1] for t in names}
        meta = {
            'columns': 'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, '
                       'COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA FROM information_schema.COLUMNS '
                       "WHERE TABLE_SCHEMA='{db}' ORDER BY TABLE_NAME, ORDINAL_POSITION",
            'foreign_keys': 'SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE '
                            "FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{db}' "
                            'ORDER BY TABLE_NAME, CONSTRAINT_NAME',
            'foreign_key_columns': 'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, '
                                   'REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
                                   "WHERE TABLE_SCHEMA='{db}' AND REFERENCED_TABLE_NAME IS NOT NULL "
                                   'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            'checks': 'SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS '
                      "WHERE CONSTRAINT_SCHEMA='{db}' ORDER BY TABLE_NAME, CONSTRAINT_NAME",
            'indexes': 'SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE '
                       "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{db}' "
                       'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            'auto_increment': 'SELECT TABLE_NAME, AUTO_INCREMENT, TABLE_COLLATION FROM information_schema.TABLES '
                              "WHERE TABLE_SCHEMA='{db}' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME",
        }
        for key, sql_text in meta.items():
            sections[key] = query(sql_text.format(db=db))
        # Representative values, compared as bytes/epoch seconds so encoding or
        # time-zone drift cannot hide behind identical-looking text.
        sections['values'] = readable(
            f'SELECT HEX(name), email, SHA2(password_hash, 256), UNIX_TIMESTAMP(email_verified_at), role '
            f'FROM `{db}`.users ORDER BY id;'
            f'SELECT HEX(name), budget_amount, HEX(notes), UNIX_TIMESTAMP(created_at), archived_at '
            f'FROM `{db}`.trips ORDER BY id;'
            f'SELECT id, version, estimated_cost, item_time, UNIX_TIMESTAMP(completed_at), place_id '
            f'FROM `{db}`.itinerary_items ORDER BY id;'
            f'SELECT latitude, longitude, HEX(name) FROM `{db}`.trip_places ORDER BY id;'
            f'SELECT HEX(body) FROM `{db}`.itinerary_item_comments ORDER BY id;'
            f'SELECT traveler_id, amount_minor, settled_at FROM `{db}`.trip_expense_shares ORDER BY expense_id, traveler_id')
        return sections

    def differences(a, b):
        sa, sb = signature(a), signature(b)
        return [key for key in sa if sa[key] != sb.get(key)]

    print('Isolated backup/restore run:', work, flush=True)
    try:
        run([args.mysql_bin / 'mysql_install_db.exe', f'--datadir={data}',
             f'--password={PASSWORD}', f'--port={db_port}'], env=env)
        db = launch([args.mysql_bin / 'mysqld.exe', '--no-defaults', f'--basedir={args.mysql_bin.parent}',
                     f'--datadir={data}', f'--port={db_port}', '--bind-address=127.0.0.1',
                     f'--server-id={SERVER_ID}', '--skip-log-bin', '--console'], 'database', env)
        for _ in range(150):
            try:
                guard()
                break
            except (subprocess.CalledProcessError, ValueError, IndexError):
                if db.poll() is not None:
                    raise RuntimeError('Test database stopped; inspect database.log')
                time.sleep(.1)
        else:
            raise RuntimeError('Test database did not become ready')
        record('disposable server ID, port and data directory verified', True)

        candidate = work / 'candidate'
        snapshot(candidate)                         # working tree without .env files
        if any(p.name.startswith('.env') for p in candidate.rglob('*')):
            raise RuntimeError('Snapshot contains an environment file')
        write((candidate / 'database/schema.sql').read_text(encoding='utf-8'))
        for name in ['007_add_escalations', '008_add_escalation_replies',
                     '009_add_escalation_messages', '024_add_support_trip_shares']:
            write((candidate / f'database/migration_{name}.sql').read_text(encoding='utf-8'), SOURCE)
        pw = run([args.php, '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', PASSWORD],
                 env=env).stdout.decode()
        object_key = 'a' * 64
        write(f"""
INSERT INTO users(id,name,email,email_verified_at,password_hash,role) VALUES
 (1,'Owner Ana','ui-owner@example.test','2027-01-01 00:00:00','{pw}','customer'),
 (2,'{EDITOR_NAME}','ui-editor@example.test','2027-01-01 00:00:00','{pw}','customer'),
 (3,'Staff Sam','ui-staff@example.test','2027-01-01 00:00:00','{pw}','staff');
INSERT INTO trips(id,user_id,name,destination,start_date,end_date,status,time_zone,budget_currency,budget_amount,notes)
 VALUES (1,1,'{TRIP_NAME}','Tokyo','2027-03-01','2027-03-04','planning','Asia/Tokyo','JPY',12345.67,'Pack 🧳 early');
INSERT INTO trip_memberships(trip_id,user_id,role) VALUES (1,2,'editor');
INSERT INTO trip_places(id,trip_id,name,latitude,longitude,category,source,created_by)
 VALUES (1,1,'浅草寺',35.714765,139.796655,'sightseeing','manual',1);
INSERT INTO itinerary_items(id,trip_id,item_date,item_time,end_time,title,place_id,category,assignee_id,
 sort_order,estimated_cost,version,completed_at,completed_by) VALUES
 (1,1,'2027-03-01','09:00','11:00','Visit 浅草寺',1,'sightseeing',2,1,1500.50,3,'2027-03-01 02:00:00',2),
 (2,1,'2027-03-02',NULL,NULL,'Free day',NULL,NULL,NULL,1,NULL,1,NULL,NULL);
INSERT INTO itinerary_item_votes(itinerary_item_id,user_id,vote) VALUES (1,2,1);
INSERT INTO itinerary_item_comments(id,itinerary_item_id,user_id,body) VALUES (1,1,2,'{COMMENT_BODY}');
INSERT INTO trip_references(id,trip_id,created_by,title,kind,visibility) VALUES (1,1,1,'Hotel record','booking','members');
INSERT INTO trip_documents(id,reference_id,uploaded_by,object_key,original_name,mime_type,byte_size,file_hash,state)
 VALUES (1,1,1,'{object_key}','fixture.pdf','application/pdf',42,'{'b' * 64}','ready');
INSERT INTO trip_expenses(id,trip_id,title,category,currency,amount_minor,payer_id,payer_name,split_mode)
 VALUES (1,1,'Dinner','food','JPY',500000,1,'Owner Ana','equal');
INSERT INTO trip_expense_shares(expense_id,traveler_id,traveler_name,amount_minor,settled_at) VALUES
 (1,1,'Owner Ana',250000,NULL),(1,2,'{EDITOR_NAME}',250000,'2027-03-02 10:00:00');
INSERT INTO trip_activity_log(id,trip_id,actor_id,event_type,details) VALUES (1,1,1,'trip_updated','dates');
INSERT INTO notifications(id,user_id,trip_id,event_type,activity_log_id) VALUES (1,2,1,'trip_updated',1);
INSERT INTO trip_reminders(id,trip_id,user_id,itinerary_item_id,title,due_at,activity_start_at)
 VALUES (1,1,1,1,'Leave for temple','2027-02-28 23:00:00','2027-03-01 00:00:00');
INSERT INTO escalations(id,user_id,message,status) VALUES (1,1,'Need help 🙏','open');
INSERT INTO escalation_messages(escalation_id,sender_type,sender_id,message) VALUES (1,'customer',1,'Need help 🙏');
INSERT INTO rate_limits(limit_key,window_started,attempts) VALUES ('{'c' * 64}','2027-01-01 00:00:00',2);
""", SOURCE)
        filled = [t for t in tables(SOURCE) if query(f'SELECT COUNT(*) FROM `{SOURCE}`.`{t}`') != '0']
        record('synthetic fixtures span linked tables', len(filled) >= 15, f'{len(filled)} non-empty tables')

        dump = work / 'travelease-synthetic.sql'
        guard()
        run([args.mysql_bin / 'mysqldump.exe', *client_args, '--single-transaction', '--routines', '--triggers',
             '--events', '--hex-blob', f'--result-file={dump}', SOURCE], env=env)
        dump_bytes = dump.read_bytes()
        record('dump written without a database switch',
               dump_bytes and b'CREATE DATABASE' not in dump_bytes and b'\nUSE ' not in dump_bytes)
        dump_sha256 = hashlib.sha256(dump_bytes).hexdigest()

        write(f'CREATE DATABASE `{RESTORE}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        guard()
        raw(dump_bytes.decode('utf-8'), RESTORE)
        diffs = differences(SOURCE, RESTORE)
        record('restored schema matches source (tables, rows, checksums, columns, keys, checks, indexes, values)',
               not diffs, ', '.join(diffs))
        expected = [EDITOR_NAME.encode().hex().upper(), TRIP_NAME.encode().hex().upper(),
                    COMMENT_BODY.encode().hex().upper()]
        restored_hex = query(f'SELECT HEX(name) FROM `{RESTORE}`.users WHERE id=2; '
                             f'SELECT HEX(name) FROM `{RESTORE}`.trips WHERE id=1; '
                             f'SELECT HEX(body) FROM `{RESTORE}`.itinerary_item_comments WHERE id=1').splitlines()
        record('multilingual text restored byte for byte', restored_hex == expected)

        # A dump loads with FOREIGN_KEY_CHECKS=0, so prove no orphan rows and that
        # the restored constraints are enforced again afterwards.
        fk_columns = query('SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, '
                           'REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
                           f"WHERE TABLE_SCHEMA='{RESTORE}' AND REFERENCED_TABLE_NAME IS NOT NULL "
                           'ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION').splitlines()
        constraints = {}
        for line in fk_columns:
            name, table, column, ref_table, ref_column = line.split('\t')
            constraints.setdefault((name, table, ref_table), []).append((column, ref_column))
        def orphan_constraints():
            found = []
            for (name, table, ref_table), pairs in constraints.items():
                join = ' AND '.join(f'c.`{c}` = p.`{r}`' for c, r in pairs)
                present = ' AND '.join(f'c.`{c}` IS NOT NULL' for c, _ in pairs)
                count = query(f'SELECT COUNT(*) FROM `{RESTORE}`.`{table}` c LEFT JOIN `{RESTORE}`.`{ref_table}` p '
                              f'ON {join} WHERE {present} AND p.`{pairs[0][1]}` IS NULL')
                if count != '0':
                    found.append(name)
            return found

        orphans = orphan_constraints()
        record(f'no orphan rows across {len(constraints)} restored foreign keys', constraints and not orphans,
               ', '.join(orphans))
        # Positive control: an orphan slipped in with checks off must be reported.
        write(f"SET FOREIGN_KEY_CHECKS=0; INSERT INTO `{RESTORE}`.trip_memberships(trip_id,user_id,role) "
              "VALUES (999999,1,'viewer')")
        planted = orphan_constraints()
        write(f'DELETE FROM `{RESTORE}`.trip_memberships WHERE trip_id = 999999')
        record('orphan detector reports a planted orphan row', len(planted) == 1 and orphan_constraints() == [],
               ', '.join(planted))
        orphan = write(f"INSERT INTO `{RESTORE}`.trip_memberships(trip_id,user_id,role) VALUES (999999,1,'viewer')",
                       check=False)
        record('restored foreign keys are enforced', orphan.returncode != 0 and b'1452' in orphan.stderr)

        # Negative control: a dump cut off halfway must not pass the comparison.
        lines = dump_bytes.splitlines(keepends=True)
        partial = b''.join(lines[:len(lines) // 2])
        write(f'CREATE DATABASE `{PARTIAL}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        partial_load = write(partial.decode('utf-8', errors='ignore'), PARTIAL, check=False)
        partial_diffs = differences(SOURCE, PARTIAL)
        record('truncated dump fails the restore comparison', bool(partial_diffs),
               f'load exit {partial_load.returncode}; differs in: {", ".join(partial_diffs)}')

        # The restored schema must serve the app: sign in and open the trip. A
        # marker written only to the restored copy proves which schema answered.
        write(f"UPDATE `{RESTORE}`.trips SET name = CONCAT(name, ' [restored copy]') WHERE id = 1")
        marker_diffs = differences(SOURCE, RESTORE)
        record('comparison detects a single changed value', 'checksums' in marker_diffs and 'values' in marker_diffs,
               ', '.join(marker_diffs))
        sessions = work / 'sessions'
        sessions.mkdir()
        app_env = {k: v for k, v in env.items() if k != 'MYSQL_PWD'}
        app_env.update(APP_ENV='development', DB_HOST='127.0.0.1', DB_PORT=str(db_port), DB_USER='root',
                       DB_PASS=PASSWORD, DB_NAME=RESTORE, CLIENT_IP_SOURCE='none')
        http_port = port()
        launch([args.php, '-d', f'session.save_path="{sessions.as_posix()}"', '-S',
                f'127.0.0.1:{http_port}', '-t', candidate], 'app', app_env)
        base = f'http://127.0.0.1:{http_port}'
        for _ in range(100):
            try:
                Client(base).request('/auth/login.php')
                break
            except urllib.error.URLError:
                time.sleep(.1)
        else:
            raise RuntimeError('PHP test server not ready')
        for role in ['owner', 'editor']:
            status, _, body = Client(base).login(role).request('/customer/trips/view.php?id=1')
            record(f'restored app signs in {role} and serves the trip', status == 200
                   and '[restored copy]'.encode() in body and TRIP_NAME.encode() in body)
        staff_status = Client(base).login('staff').request('/customer/trips/view.php?id=1')[0]
        record('restored app keeps access control for staff', staff_status in (302, 403, 404),
               f'status {staff_status}')
    finally:
        for proc in processes:
            if proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.wait()
        for handle in handles:
            handle.close()
        summary = {
            'scope': 'local MariaDB 10.4 logical dump/restore of synthetic data only; '
                     'not Aiven, MySQL 8.4, managed backups, retention or production restore',
            'checks': results,
        }
        if 'dump_sha256' in locals():
            summary['dump'] = {'file': 'travelease-synthetic.sql', 'bytes': len(dump_bytes), 'sha256': dump_sha256}
        (work / 'results.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
        print('Results and logs:', work, flush=True)
    print(f'{len(results)} local backup/restore checks passed; production backups remain unverified.')


if __name__ == '__main__':
    main()

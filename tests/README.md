# Local regression baseline

Run from the repository root with Python 3, PHP with PDO MySQL/cURL, and the existing XAMPP MariaDB binaries:

```powershell
python tests/local_smoke.py
```

Optional real Chrome checks use the installed browser and an existing Playwright package directory:

```powershell
python tests/local_smoke.py --node <node.exe> --modules <node_modules-directory>
```

Override `--php`, `--mysql-bin` or `--baseline` when needed. No packages are installed. The default comparison is the locally available `origin/main`; refresh or verify that ref before interpreting the result.

Each run initializes a new disposable MariaDB data directory under the workspace's `work/local-tests` directory and verifies its port, server ID and exact data path before fixture writes. It runs application snapshots on loopback ports, excludes `.env` files, passes only synthetic configuration, and serves document bytes from a local storage stub. It does not start a Windows service, read application credentials, reset an existing database, or contact real email/AI/storage providers. All child processes stop on completion; logs, screenshots and results remain in the printed run directory.

Coverage: password sign-in, guest/system/trip-role guards, reference visibility and direct downloads, form/link/status parity against the baseline, forged mutations, CSRF, role changes after page load, activity completion, and removed-member access. Link comparison permits the intentional removal of the duplicate Archived header link; Archived remains reachable on the dashboard. Optional browser scenarios exercise the Help launcher on dashboard and trip pages with and without a staff conversation at 360, 768 and 1280 pixels, checking runtime errors and failed local requests on those pages. The sign-in page's Google SDK is deliberately blocked; Google sign-in is not tested.

The normal suite also checks the pure database TLS option builder without loading `.env`: local development remains compatible, production requires a readable CA file, and certificate verification is enabled when a CA is configured. Run `python tests/db_tls_check.py` separately for a disposable live-TLS check; it currently targets Windows/XAMPP MariaDB, requires OpenSSL, and is intentionally not part of the dependency-light baseline.

Activity stale-edit coverage includes version conflicts, preserved input, explicit second saves, no-op saves, invalid versions, validation-error token retention, and ten overlapping owner/editor submissions through separate PHP servers. Fresh schema and migration upgrade checks run only against the disposable database. A local Groq stub exercises review, suggestion application, version increment and rejection of an older edit form. Share-access IP policy and verification resend caps use local fixtures and a local mail stub.

This is a small regression baseline, not a complete acceptance suite. It does not certify production, provider delivery, production database TLS, production backup restoration, or Safari.

Additional checks reject array-valued edit/Google fields and NUL-containing dates, verify production PHP error settings, and exercise password re-check limits with a dedicated synthetic account. Chrome checks cover named non-modal chat regions, input labels, Escape/close focus return, delayed AI replies, and support conversation identity changes. Actual screen-reader announcements and delayed overlapping poll responses still require separate verification.

## Local backup/restore drill

Run separately; it is not part of the baseline:

```powershell
python tests/backup_restore_check.py
```

It starts its own disposable MariaDB (separate server ID, port and data directory, re-verified before every write), loads the schema and support migrations with synthetic fixtures across 17 linked tables, including multilingual text, and dumps `travelease` with the XAMPP `mysqldump` (password through `MYSQL_PWD` only). The dump is restored into `travelease_restore` on the same instance and compared: tables, row counts, `CHECKSUM TABLE`, columns, foreign keys, check constraints, indexes, `AUTO_INCREMENT` and representative values (byte-exact text, epoch timestamps). It also checks every restored foreign key for orphan rows and confirms an orphan insert is refused. Two controls prove the comparison can fail: a dump truncated halfway, restored into `travelease_partial`, and a single value changed in the restored copy. Finally the app runs against `travelease_restore`: owner and editor sign in and open the trip (a marker written only to the restored copy proves which schema answered), and staff are still refused.

The synthetic dump, its size and SHA-256, logs and `results.json` stay in the printed `work/local-tests/te-backup-*` directory. This proves only that a MariaDB 10.4 logical dump of this schema restores completely on the same tooling. It does not verify Aiven's managed backups, schedule, retention or point-in-time recovery; MySQL 8.4 dump compatibility; Supabase document objects or local profile pictures, which are outside the database; or any production restore procedure.

Run directories retain a full disposable database and can be large. Once the evidence is no longer needed and the run has stopped, remove only that run's printed directory manually.

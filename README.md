# TravelEase

TravelEase is a web-based personal travel planner built with PHP, MySQL, and plain HTML, CSS, and JavaScript. Travelers can organize trips, build dated itineraries, compare estimated and actual costs, and ask for support without keeping their plans across separate notes and chat threads.

**[Open the live website](https://travelease-zp7o.onrender.com/auth/login.php)** · **[Project documents and source archive](https://drive.google.com/drive/folders/1C91ePF2k7MLfVX0I5oTWhF0jxdev-ipV)**

This is a Mapúa University academic team project. The repository has progressed beyond its original authentication and trip-CRUD milestones: it includes itinerary management, budgeting, profile updates, staff support, and admin account management.

## Features

| Area | Implemented behavior |
| --- | --- |
| Accounts | Email/password registration and login, email verification, Google Sign-In with explicit account linking, logout, role-based routing |
| Trip planning | Create, view, edit, archive, restore and delete customer-owned trips with destination, dates, status and notes. Owners archive/restore; both owned and shared archives are available from the dashboard. |
| Collaboration | Owners invite verified customers by exact email as Editor, Commenter, or Viewer; invitees accept or decline from their dashboard. Editors change the itinerary. Owners, Editors and Commenters can vote up or down on each itinerary activity (one vote per member, changeable) and comment on it; Viewers see votes and comments but cannot add them (FR-17 activity slice; voting on destination proposals is deferred). Only the owner edits trip details, invites, or deletes the trip. Owners can change member roles, remove members, revoke pending invitations, and transfer ownership (the previous owner stays as an editor; a trip always has exactly one owner). Members can leave a trip without affecting its plan or other members. Owners and Editors manage day-by-day activities (FR-19): start and end time on the same day, location, category, notes, costs and an assignee (any current customer member; an assignment is kept, and labelled, if that person later leaves), mark activities done or reopen them, and move them up or down within a day (once a day has been reordered, new activities on it go last). The trip page warns about overlapping activities (same day, both with start and end times; back-to-back times are fine) and about activities outside the trip dates; warnings never block saving, and trip dates cannot be changed to leave activities outside them (FR-20). Back-to-back activities at two different saved places with coordinates are also checked for travel time: a member presses "Check travel times" (at most 5 lookups per press, counted against the 30-per-hour place-lookup limit), Geoapify driving estimates are cached for 30 days, and the trip page warns when the estimate is longer than the gap. Pages never call the provider on load, and missing data or provider failures simply show no travel warning. Each trip has a Places & map page (FR-21/FR-27): every member sees saved places on an OpenStreetMap map (Leaflet), each day's linked places in itinerary order (a schematic order of visits, not a road route or travel time), and OpenStreetMap directions links, and can search places or nearby points of interest through Geoapify; Owners and Editors save, edit, remove and link places to activities, and can add places manually without a Geoapify key. Every member can open the trip's Activity history (FR-18): trip creation and edits, invitations and responses, role changes, removals, departures, ownership transfers, activity changes and comments, with who did it and when (pending invitations show the role only, never an email). Invitation email lookups are limited to 20 per hour per account |
| Itineraries | Add, edit, and remove dated activities with optional time, location, notes, and costs |
| Budgeting | Trip currency and budget, estimated/actual itinerary costs, totals, and remaining/over-budget display |
| Shared expenses (FR-22/23) | Planned and actual itinerary costs by category, plus a separate expense ledger with equal or custom shares, settlement status, and balances by currency. Owners and editors manage the ledger. Receipt links are visible to trip members. |
| Booking references and documents (FR-24) | Owners and editors manage references and PDF/JPEG/PNG attachments up to 2 MB, stored in private Supabase Storage. Attachments inherit reference visibility: all members, owner/editors, or creator/current owner. Every download re-checks current membership and visibility. Removal ends access immediately; scheduled cleanup removes stored objects. |
| Share links (FR-15) | Owners create expiring join links (verified customer sign-in and explicit confirmation, Viewer access) or sanitized view-only links (anyone holding the link). View-only links show destination, dates, time zone and itinerary dates/times/titles/locations only. No notes, member identities, costs, feedback, references or reminders. Links expire after 1 or 7 days and can be revoked. Ownership transfer or member removal revokes all current links. Joined members keep their role after a link expires; remove a member separately to end membership. |
| Progress (FR-26) | Separate counts show completed activities, confirmed references visible to the current member, settled non-payer expense shares and that member's acknowledged due reminders. Empty categories show no items; no combined completion score is claimed. |
| Notifications (FR-30) | Customer inbox with read/unread, dismiss/restore and an in-app opt-out. Invitations notify the invitee once per issue; role, comment, itinerary, place and expense updates notify other current customer members. Inbox visibility and unread counts follow current membership; private reference events are excluded. Removed members see only a generic access-removal message. Scheduled reminders use in-app notifications and optional reminder email; other trip events remain in-app. |
| Profiles | Profile editing and validated profile-picture uploads with unique replacement filenames. Every signed-in system role can update its own notification preferences from Profile; the customer notification inbox and its row actions remain customer-only. |
| Planning assistant | Trip ideas, and "Review my plan" for owners and editors (FR-28/FR-29): Groq returns budget tips, ideas and suggested time changes for existing activities. Changes are applied one at a time only when the user presses Apply. Each review has a random server-side ID carried by its Apply/Clear forms (an old tab cannot act on a newer review) and a server-only fingerprint of the trip and all its activities; Apply re-checks that fingerprint under the trip lock and refuses if the relevant trip fields (destination, dates, time zone, budget) or any activity's times, title, category, place, costs, completion or order changed since the review, including same-second edits. Applying one change ends that review. Suggestions live in the session for 30 minutes; 5 reviews per account per hour. Sent: destination, dates, budget, totals and each activity's title, date, times, category, place name and estimated cost; never notes, comments, expenses, references, reminders or member details. `GROQ_API_URL` is honoured only for a local HTTP stub under the PHP CLI or built-in server (tests); any other value is refused |
| Support | AI support widget, escalation queue, customer/staff message threads, staff replies and resolution. Escalation success requires a saved thread/message transaction; failed saves return a retryable error. Optional EmailJS failures do not disable chat or the saved in-app conversation. |
| Staff | Support queue and escalation handling. Staff see how many trips a customer is on; a trip's details and itinerary are visible read-only only after its owner shares it from an open support request, until they stop sharing, the request is resolved or ownership changes (FR-32) |
| Administration | User listing, role updates, and account deletion. Admins cannot change or delete their own account, and each change locks the acting and target accounts and re-checks that the acting account is still an admin, so two admins acting on each other at once can never leave the app without an administrator (FR-33) |
| Privacy rights | Profile page: download your own data as JSON, and customer self-service account deletion (password or recent Google sign-in, type DELETE). Blocked while you own a trip with other members; solo trips are deleted, comments keep the author's name and expense entries keep name snapshots (FR-34) |
| Information | Public information page at `/public/information.php` (FR-36): terms of use, privacy, AI disclaimer and third-party services in plain language, linked from sign-in, registration and profile. It is informational, not a legal contract |

TravelEase is a planner with support tools. It does not process payments, reserve flights or hotels, or connect to third-party booking inventory. A trip's status is an internal planning field, not proof of a supplier reservation.

## Academic phases and documentation

The Drive folder preserves the project documents as submitted. Early plans describe intended features; the current source is the reference for implemented behavior.

| Document | What it covers |
| --- | --- |
| [Initial project draft](https://drive.google.com/file/d/1RyhfOdH-yl4muA6iKOZIxIHLwYZtzNQ3/view) | Original problem statement, target users, proposed booking/agency scope, architecture, and development process |
| [Phases 1 and 2](https://drive.google.com/file/d/1Rc6w07YDFLcHRxIXPsAK1iYWZ56YmQjo/view) | Refined personal-planner scope, objectives, architecture, ERD, technology choices, timeline, authentication design, role expansion, and page prototypes |
| [Phase 3](https://drive.google.com/file/d/16CmN7sfx5YvAoTDMd2_c2xNle04Hfe7p/view) | Google Identity Services and Groq integrations, request flow, escalation behavior, and screenshots |
| [Phase 4: Security and Testing Report](https://drive.google.com/file/d/1ROSTpY9BFbPKNNvjeWoo658ihhU35KsU/view) | Manual test cases, evidence, usability feedback, deployment issues, fixes, remaining limitations, and team contributions |

The scope evolved: the initial draft included agency bookings and payments; Phase 1 narrowed the project to personal planning; Phase 2 added staff/admin roles; the current implementation adds AI-assisted support and human escalation. Booking transactions and payments remain outside the implemented scope.

## Stack and structure

- PHP with PDO and MySQL; no application framework or front-end build step.
- HTML, CSS, and JavaScript for the browser interface.
- Google Identity Services for optional Google Sign-In.
- Groq's chat-completions API for AI support; the source currently selects `openai/gpt-oss-20b`.
- EmailJS for client-side escalation notifications, alongside the database-backed support queue.
- Docker with PHP 8.2/Apache for the Render deployment; the Phase 4 report documents Aiven MySQL hosting.

| Path | Responsibility |
| --- | --- |
| `auth/` | Registration, password login, Google login, logout |
| `customer/` | Customer dashboard, trips, itinerary actions, support messages |
| `staff/` | Read-only staff dashboard, support queue and replies |
| `admin/` | User administration and role changes |
| `chat/ask.php` | Authenticated chat endpoint and escalation recording |
| `includes/` | Authentication/session checks, CSRF, validation, Google token verification, AI helper, shared widgets |
| `config/` | Environment loading and PDO connection |
| `database/` | Base schema and incremental migrations |
| `assets/` | Styles and browser scripts |
| `public/index.php` | Entry point and authentication routing |

## Run locally

Use PHP 8.2 or later and a compatible MySQL/MariaDB database. Enable `pdo_mysql`, `mbstring`, and `openssl`; AI support and private documents need `curl`, and document validation needs `fileinfo`. Google token verification needs outbound HTTPS and a correctly configured CA trust store. XAMPP can provide the database and PHP runtime.

1. Clone the repository and enter its root directory.
2. Copy `.env.example` to `.env`. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, a long random `APP_SECRET`, `APP_ENV=development`, `APP_URL=http://localhost:8000`, and the verification-email settings below. Keep `.env` out of version control.
3. For a fresh database, import the base schema, then add the support tables and fields:

   ```sh
   mysql -u root -p < database/schema.sql
   mysql -u root -p travelease < database/migration_007_add_escalations.sql
   mysql -u root -p travelease < database/migration_008_add_escalation_replies.sql
   mysql -u root -p travelease < database/migration_009_add_escalation_messages.sql
   mysql -u root -p travelease < database/migration_024_add_support_trip_shares.sql
   ```

   Replace `travelease` if using a different database name. The base schema includes Google subject, email verification, trips, trip memberships and invitations, profile picture, itinerary, and budget fields; it does **not** include the support tables (migrations 007-009 and the support trip sharing table in 024). For an existing installation, inspect its schema and apply only missing migrations in numerical order. Do not blindly rerun `ALTER TABLE` or message-backfill migrations.

4. Ensure `uploads/profile_pictures/` exists and is writable by PHP for profile uploads.
5. Start the development server **from the repository root**:

   ```sh
   php -S localhost:8000
   ```

6. Open `http://localhost:8000/public/index.php` or `http://localhost:8000/auth/login.php`.

The application uses root-relative URLs such as `/auth/login.php`. Serving it under `http://localhost/TravelEase/` without adjusting routing will break those links. With Apache, configure a dedicated document root at the repository root.

Self-registration creates customer accounts. Provision staff/admin access through a trusted database administration process; registration does not accept a privileged role from the client.

### Verification email (required for password sign-up)

New password accounts must verify their email before signing in. Emails go through Brevo's HTTPS transactional API using PHP's curl extension; Render's free tier blocks outbound SMTP, so PHP `mail()` and SMTP are not used. Set these in `.env` locally and in the Render service's Environment settings in production:

| Variable | Value |
| --- | --- |
| `APP_URL` | Public base URL with no trailing slash, e.g. `https://travelease-zp7o.onrender.com`. Required: verification links are never built from the request's Host header. |
| `BREVO_API_KEY` | API key from Brevo > SMTP & API > API Keys. Secret: never commit it. |
| `MAIL_FROM_EMAIL` | A sender address verified in Brevo > Senders. |
| `MAIL_FROM_NAME` | Display name, e.g. `TravelEase`. |

If any value is missing or Brevo rejects the request, no email is sent, the unused token is deleted, and registration says the email could not be sent. The resend page always shows the same message, so it does not reveal whether an account exists, and a failed send frees its 60-second retry window. "Sent" means Brevo accepted the message; later bounces or spam filtering are not tracked.

### Optional integrations

**Google Sign-In:** Create a Google Web OAuth client and set `GOOGLE_CLIENT_ID` in the environment. Add `http://localhost:8000` as an authorized JavaScript origin for local testing, and authorize the deployed origin when hosting. If the consent configuration restricts access to test users, add the intended test accounts. The app verifies Google ID tokens server-side; it does not use a Google client secret. Matching an existing password account's email does not automatically link the two accounts.

**AI support:** Set `GROQ_API_KEY` in the server environment or `.env`; this variable is used by the source but is not listed in the current `.env.example`. The key stays on the server. Missing credentials, failed API requests, and empty responses take the escalation path. Keyword rules route sensitive support topics to staff before calling the model; other responses use off-topic/escalation markers. This is application routing, not a guarantee that every model response is correct.

**Email notifications:** The current `assets/js/chat-widget.js` contains project-specific EmailJS public configuration. Configure your own EmailJS service/template and allowed origins for another deployment. The optional email sends name, account email, message and timestamp through EmailJS; this is disclosed on the public information page. An escalation is acknowledged only after its in-app thread/message is saved. Missing or failed EmailJS initialization/delivery does not disable chat or undo that saved conversation.

### Scheduled trip reminders

Apply `database/migration_023_add_trip_reminders.sql` after migration 022 and before deploying this feature. Fresh installs already include it in `schema.sql`. Existing trips default to `Asia/Manila`; owners can choose another IANA time zone in the trip form. Review that default for trips elsewhere. Custom reminders retain their saved UTC instant when the zone changes; pending automatic reminders follow the activity's current local time and trip zone.

Customers manage their own reminders from **Trip > Reminders**, including viewers and commenters. Automatic reminders are generated for timed, incomplete activities in the next 48 hours, due 30 minutes before the activity. Personal reminders are private even from the trip owner. Due reminders can be acknowledged, and the trip's reminder progress counts only the current user's due, uncancelled reminders.

The worker uses the existing Brevo settings for optional email. Email reminders default off; customers opt in under **Notifications > Preferences**. Notification text and email omit reminder titles and booking details. Cancelled/completed trips, former members, staff and completed activities do not receive new deliveries. An email already handed to the provider may still arrive after cancellation. Failed or uncertain emails are not retried automatically; the customer can explicitly retry after 60 seconds, up to three total attempts. An uncertain retry can duplicate a delayed email.

Production setup (no new paid service):

1. Generate a random secret of at least 32 characters. Set the same `REMINDER_JOB_SECRET` in Render's Environment and GitHub **Settings > Secrets and variables > Actions > New repository secret**. Keep the value out of source, logs and chat.
2. After migration and deployment, run **Actions > Deliver trip reminders > Run workflow**. Check its JSON result and the customer's Notifications/Reminders pages. The workflow POSTs to `/jobs/reminders.php` with an `X-Reminder-Key` header; unauthorized requests cannot process reminders.
3. The workflow runs every five minutes. GitHub schedules can be delayed and public-repository schedules can be disabled after 60 days without repository activity. Render cold starts also delay delivery: this is best-effort processing, not an exact-time alarm. Standard GitHub-hosted runners are free for this public repository; reassess billing if its visibility changes.

Each worker invocation is bounded to 100 due candidates and three email attempts, with a database advisory lock preventing concurrent runs. Large backlogs drain over later runs. `php jobs/reminders.php --dry-run` counts existing due reminders without creating rows or sending mail; it still requires the correct database configuration. Tests may point the existing `BREVO_API_URL` override at a local fake endpoint; leave it unset in production.

## Security and testing

### Account/profile and support regression checks (FR-03 / FR-04 / FR-31 / FR-36)

No migration or new setting is needed for these fixes. Google linking explicitly loads the shared email-normalization helper while retaining Google signature/claim checks, password verification, same-email matching and 1062-only duplicate handling. Profile picture replacements use a random filename component so same-second uploads cannot overwrite or delete one another. Notification preferences are self-scoped for all authenticated roles; only customer accounts can open or change customer inbox rows.

Verify **Profile > Link Google account** with a matching verified Google identity and the current password; wrong passwords, mismatched emails and already-linked subjects must fail cleanly. On Profile, save preferences as Customer, Staff and Admin, and confirm the latter two still cannot open the customer inbox. Replace a profile picture twice quickly; the latest picture must remain visible. Test support with EmailJS unavailable, and with a disposable database rejecting message insertion: core chat must remain usable, and a failed save must show a retryable error rather than a successful staff handoff. Provider-failure tests must not send real emails or modify production records.

### Trip archive and dashboard filters (FR-06 / FR-11)

Apply `database/migration_028_add_trip_archive.sql` after 027 before deployment. It adds nullable `trips.archived_at`; all existing trips remain unarchived, with their statuses, content, roles and memberships preserved. Fresh installs include the column in `schema.sql`.

Only the current customer owner can archive or restore from the trip page (CSRF-protected POST, trip lock and fresh permission check). Archiving is reversible filing: the trip moves out of Active trips into Archived trips for its owner and collaborators. It is **not** cancellation, deletion, access revocation or a read-only mode. Existing permissions, share links, reminder processing and booking status stay unchanged. Archive/restore are recorded in history and in-app notifications; repeated identical requests do not duplicate those events. The account export includes the archive timestamp.

The dashboard's search, status filter and sorting now cover owned and shared trip cards in the selected Active/Archived view. Sorting keeps those two groups separate, and controls remain available for customers who have only shared trips. No providers, environment variables or dependencies are added.

### Private travel documents (FR-24 / NFR-06)

Apply `database/migration_027_add_trip_documents.sql` after 026 **before deployment**. The migration creates two tables with 2 primary keys and 3 foreign keys; fresh installs include them in `schema.sql`. It does not alter existing user or trip records. Create a **private** Supabase Storage bucket named `travelease-documents`, restrict it to 2 MB and MIME types `application/pdf,image/jpeg,image/png`, and do not add public or anonymous storage policies. Aiven remains the application database; Supabase Data API and GitHub integration are not required.

Set `SUPABASE_URL` to the project's HTTPS URL, `SUPABASE_SECRET_KEY` to a server-only `sb_secret_...` key, and `SUPABASE_STORAGE_BUCKET=travelease-documents`. Never put this key in JavaScript, Git, logs or chat. No SDK or Composer dependency is added. The private Storage REST API is used over HTTPS with certificate verification, no redirects, bounded responses and 3-second connect / 10-second request timeouts. Upload checks the bucket is still private. A local HTTP stub is accepted only on `127.0.0.1` in development under the PHP CLI/built-in server; production rejects that URL.

Open **Trip > Booking references**. Owners/editors can attach at most 3 files per visible reference, 20 per trip, with 30 upload attempts per account per hour. The server checks actual type, extension, image dimensions, size and uploaded-file origin rather than trusting the browser's MIME header. Filenames are escaped; object names contain 32 random bytes and no original filename. Downloads go through PHP, re-read trip membership/visibility under a shared trip lock, verify byte count and SHA-256, and force a non-cacheable attachment download. No signed/public storage URLs are issued. File-type validation is not malware scanning; no inline document preview or claim of file safety is made.

Attachments follow their reference's visibility, including after ownership/role changes. Public share links, support, AI and non-member administrators do not get attachments. JSON account exports include only currently visible attachment metadata, not file bytes, object keys or hashes; download needed files separately.

Deleting an attachment ends app access immediately. Deleting its reference, trip or uploader also invalidates access and retains the object key in a cleanup record. The existing five-minute GitHub workflow calls `/jobs/documents.php` with the existing `REMINDER_JOB_SECRET`; there is no new scheduler or job secret. Each run handles up to 3 objects; failed deletions retry in later runs. Unfinished uploads older than one hour are also retired, so an interrupted external write cannot lose its cleanup key. Pending/retired metadata and stored objects remain until cleanup succeeds; outages and delayed schedules can postpone physical deletion. Active ready files are never removed by this cleanup. Keep this Supabase project and bucket unchanged until its cleanup queue is empty.

`php jobs/documents.php --dry-run` only counts eligible cleanup records and makes no provider requests or writes. Configure `fileinfo` and `curl` in local PHP; the PHP Docker image includes fileinfo and already installs curl. If a provider is unavailable, reference metadata remains editable and downloads show a recoverable error. Use **Actions > Deliver trip reminders > Run workflow** after deployment to check both job steps.

### Share links

Apply `database/migration_026_add_trip_share_links.sql` after 025 **before deploying** this feature. It adds two tables (2 primary keys, 2 foreign keys); fresh installs already include them in `schema.sql`. No new service, dependency or setting is required. Link creation uses the existing trusted `APP_URL`, never the request Host header.

Open **Trip > Share links** as its owner. Link addresses are shown once after creation, then only their metadata remains; revoke and recreate a lost address. Creation is limited to 10 active links per trip and 30 new links per account per hour. View-only creation requires explicit confirmation of what becomes visible. Anyone holding that link can copy or forward its contents; revocation cannot erase copies already made. Never put secrets in itinerary titles or locations that you share publicly.

Tokens contain 32 random bytes and are stored only as SHA-256 hashes. A link uses a URL fragment (`/public/share.php#...`), so its secret does not enter HTTP request URLs or referrers. Opening it requires JavaScript and a CSRF-protected POST; no external scripts, fonts or maps are loaded on that page. Public reads and join attempts share a database-backed 60-per-minute limit per `REMOTE_ADDR`, with `429` and `Retry-After`. Proxy headers are not trusted, so people behind one network/proxy can share a quota. Counter rows use hashed addresses; at most 100 rows older than one day are pruned per request.

Join links never grant editing access, never bypass email verification, and never downgrade an existing member. They retire any pending email invitation when creating a new Viewer membership. Revoking a link prevents future use but does not remove people who already joined. Removing a member or transferring ownership revokes every current link, including view-only links, so copied old links cannot restore removed access. Share-link creation and revocation are logged generically; account export includes creator metadata, without tokens or hashes.

The source includes password hashing, PDO prepared statements, server-side validation, CSRF checks, session regeneration, role guards, and customer ownership checks. Session permissions are refreshed against the database so role changes or account deletion can take effect on subsequent requests. These are implemented controls, not a claim that the application has passed an independent security audit.

The Phase 4 report records **40 manual test cases passed, seven bugs fixed, and one remaining storage issue**, with usability feedback reported separately. These are the project team's reported results; they are not an automated test suite or a new independent reproduction. The public login page was reachable during this documentation review; authenticated workflows were not rerun.

Useful smoke checks for a new environment include login/logout, denied customer access to staff/admin pages, trip ownership across two customer accounts, itinerary cost totals, and a full customer-to-staff support thread. Verify Google and AI integrations separately with your own configured credentials.

## Deployment and limitations

- The included `Dockerfile` serves the project root through PHP/Apache and reads Render's `PORT` at startup. Supply database and integration settings through the hosting environment.
- Before deploying booking references and progress to an existing database, apply `database/migration_021_add_trip_references.sql` once. Fresh installs already include this table in `schema.sql`; do not run 021 on top of that schema. FR-24 attachments/downloads still require private object storage. FR-25 reminders require the scheduler setup above.
- Before deploying account export and deletion (FR-34) to an existing database, apply `database/migration_025_keep_comments_after_account_deletion.sql` once after 024. It adds `itinerary_item_comments.author_name` (backfilled) and changes the comment author link to `ON DELETE SET NULL`; fresh installs already have this in `schema.sql`.
- Before deploying support trip sharing (FR-32) to an existing database, apply `database/migration_024_add_support_trip_shares.sql` once after 023. It creates `support_trip_shares`, which references the support `escalations` table, so it is not part of `schema.sql`; fresh installs run it after migrations 007-009.
- Before deploying notifications to an existing database, apply `database/migration_022_add_notifications.sql` once after 021. It adds `users.notify_in_app` and `notifications`; both are already in the fresh schema. Existing history is not backfilled into notifications.
- Protect configuration, environment files, SQL files, and internal documents from direct HTTP access when serving the repository root. Do not treat the development server or the current container configuration as a complete production hardening baseline.
- Uploaded profile pictures use local storage. Render's ephemeral filesystem can discard them on restart/redeploy; durable storage requires a persistent disk or external object storage.
- Login and chat rate limits are per-session, not shared limits across sessions or hosts.
- Password reset is not implemented. Collaboration does not yet send invitation emails. Comments cannot be edited or deleted yet, and destination proposals (the destination part of FR-17) are not built.
- The deployment report describes a database TLS configuration, but the current `config/database.php` does not explicitly configure PDO TLS options. Verify the actual connection configuration before relying on encrypted database transport.
- AI answers can be inaccurate, and human follow-up depends on staff availability. Do not submit payment credentials or other sensitive information to the chat.

## Team

The Phase 4 report credits Morish Alfonso Macayan with team leadership, deployment/infrastructure, testing, and the security report; Aaron John Gonzales and John Vincent Reyes with back-end contributions and support-widget feedback; and Mac Jhansen Tubon with customer dashboard and trip-management front-end work. See the original documents for the submitted phase-specific credits.

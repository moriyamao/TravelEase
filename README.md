# TravelEase

TravelEase is a web-based personal travel planner built with PHP, MySQL, and plain HTML, CSS, and JavaScript. Travelers can organize trips, build dated itineraries, compare estimated and actual costs, and ask for support without keeping their plans across separate notes and chat threads.

**[Open the live website](https://travelease-zp7o.onrender.com/auth/login.php)** · **[Project documents and source archive](https://drive.google.com/drive/folders/1C91ePF2k7MLfVX0I5oTWhF0jxdev-ipV)**

This is a Mapúa University academic team project. The repository has progressed beyond its original authentication and trip-CRUD milestones: it includes itinerary management, budgeting, profile updates, staff support, and admin account management.

## Features

| Area | Implemented behavior |
| --- | --- |
| Accounts | Email/password registration and login, Google Sign-In, logout, role-based routing |
| Trip planning | Create, view, edit, and delete customer-owned trips with destination, dates, status, and notes |
| Itineraries | Add, edit, and remove dated activities with optional time, location, notes, and costs |
| Budgeting | Trip currency and budget, estimated/actual itinerary costs, totals, and remaining/over-budget display |
| Profiles | Profile editing and validated profile-picture uploads |
| Support | AI support widget, escalation queue, customer/staff message threads, staff replies and resolution |
| Staff | Trip oversight and status updates, support dashboard and escalation handling |
| Administration | User listing, role updates, and account deletion |
| Information | Public Terms and Privacy pages, AI disclaimer |

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
| `staff/` | Staff dashboard, trip status actions, support queue and replies |
| `admin/` | User administration and role changes |
| `chat/ask.php` | Authenticated chat endpoint and escalation recording |
| `includes/` | Authentication/session checks, CSRF, validation, Google token verification, AI helper, shared widgets |
| `config/` | Environment loading and PDO connection |
| `database/` | Base schema and incremental migrations |
| `assets/` | Styles and browser scripts |
| `public/index.php` | Entry point and authentication routing |

## Run locally

Use PHP 8.2 or later and a compatible MySQL/MariaDB database. Enable `pdo_mysql`, `mbstring`, and `openssl`; AI support also needs `curl`. Google token verification needs outbound HTTPS and a correctly configured CA trust store. XAMPP can provide the database and PHP runtime.

1. Clone the repository and enter its root directory.
2. Copy `.env.example` to `.env`. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, a long random `APP_SECRET`, and `APP_ENV=development`. Keep `.env` out of version control.
3. For a fresh database, import the base schema, then add the support tables and fields:

   ```sh
   mysql -u root -p < database/schema.sql
   mysql -u root -p travelease < database/migration_007_add_escalations.sql
   mysql -u root -p travelease < database/migration_008_add_escalation_replies.sql
   mysql -u root -p travelease < database/migration_009_add_escalation_messages.sql
   ```

   Replace `travelease` if using a different database name. The base schema includes Google subject, trips, profile picture, itinerary, and budget fields; it does **not** include the support tables. For an existing installation, inspect its schema and apply only missing migrations in numerical order. Do not blindly rerun `ALTER TABLE` or message-backfill migrations.

4. Ensure `uploads/profile_pictures/` exists and is writable by PHP for profile uploads.
5. Start the development server **from the repository root**:

   ```sh
   php -S localhost:8000
   ```

6. Open `http://localhost:8000/public/index.php` or `http://localhost:8000/auth/login.php`.

The application uses root-relative URLs such as `/auth/login.php`. Serving it under `http://localhost/TravelEase/` without adjusting routing will break those links. With Apache, configure a dedicated document root at the repository root.

Self-registration creates customer accounts. Provision staff/admin access through a trusted database administration process; registration does not accept a privileged role from the client.

### Optional integrations

**Google Sign-In:** Create a Google Web OAuth client and set `GOOGLE_CLIENT_ID` in the environment. Add `http://localhost:8000` as an authorized JavaScript origin for local testing, and authorize the deployed origin when hosting. If the consent configuration restricts access to test users, add the intended test accounts. The app verifies Google ID tokens server-side; it does not use a Google client secret. Matching an existing password account's email does not automatically link the two accounts.

**AI support:** Set `GROQ_API_KEY` in the server environment or `.env`; this variable is used by the source but is not listed in the current `.env.example`. The key stays on the server. Missing credentials, failed API requests, and empty responses take the escalation path. Keyword rules route sensitive support topics to staff before calling the model; other responses use off-topic/escalation markers. This is application routing, not a guarantee that every model response is correct.

**Email notifications:** The current `assets/js/chat-widget.js` contains project-specific EmailJS public configuration. Configure your own EmailJS service/template and allowed origins for another deployment. Email notification and in-app escalation storage are separate operations; success in one does not prove the other succeeded.

## Security and testing

The source includes password hashing, PDO prepared statements, server-side validation, CSRF checks, session regeneration, role guards, and customer ownership checks. Session permissions are refreshed against the database so role changes or account deletion can take effect on subsequent requests. These are implemented controls, not a claim that the application has passed an independent security audit.

The Phase 4 report records **40 manual test cases passed, seven bugs fixed, and one remaining storage issue**, with usability feedback reported separately. These are the project team's reported results; they are not an automated test suite or a new independent reproduction. The public login page was reachable during this documentation review; authenticated workflows were not rerun.

Useful smoke checks for a new environment include login/logout, denied customer access to staff/admin pages, trip ownership across two customer accounts, itinerary cost totals, and a full customer-to-staff support thread. Verify Google and AI integrations separately with your own configured credentials.

## Deployment and limitations

- The included `Dockerfile` serves the project root through PHP/Apache and reads Render's `PORT` at startup. Supply database and integration settings through the hosting environment.
- Protect configuration, environment files, SQL files, and internal documents from direct HTTP access when serving the repository root. Do not treat the development server or the current container configuration as a complete production hardening baseline.
- Uploaded profile pictures use local storage. Render's ephemeral filesystem can discard them on restart/redeploy; durable storage requires a persistent disk or external object storage.
- Login and chat rate limits are per-session, not shared limits across sessions or hosts.
- Password reset, email verification, and manual Google/password account linking are not implemented.
- The deployment report describes a database TLS configuration, but the current `config/database.php` does not explicitly configure PDO TLS options. Verify the actual connection configuration before relying on encrypted database transport.
- AI answers can be inaccurate, and human follow-up depends on staff availability. Do not submit payment credentials or other sensitive information to the chat.

## Team

The Phase 4 report credits Morish Alfonso Macayan with team leadership, deployment/infrastructure, testing, and the security report; Aaron John Gonzales and John Vincent Reyes with back-end contributions and support-widget feedback; and Mac Jhansen Tubon with customer dashboard and trip-management front-end work. See the original documents for the submitted phase-specific credits.

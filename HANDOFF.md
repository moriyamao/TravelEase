# TravelEase — Handoff Notes

Read this first if you're picking this project up in a new conversation.

## Who's building this

**Mori (Morish Alfonso Macayan)** — BSCS student, Mapua University. Direct
communicator, no fluff, wants clean intentional code, hates vibecoding.
"Just give me the code" = raw code blocks, no explanation. "Go wild" =
be creative.

## Stack (fixed — do not change without explicit instruction)

PHP + MySQL + XAMPP, plain HTML/CSS/JS frontend. This was set as a hard
constraint in the original project spec. Mori also has a separate general
"production-aware development" reference doc (Supabase/Prisma/Next.js-
flavored) — that doc describes his general engineering philosophy
(security, testing, proportional complexity), **not** a mandate to swap
TravelEase's stack. If asked to apply "the system prompt" to TravelEase,
apply the *principles* (validation, error handling, no hardcoded secrets,
etc.), not the specific tools it names, unless Mori explicitly asks for
a stack change.

## What TravelEase is

Web-based trip planning/booking app. Roles: customer, staff, admin.
Explicitly NOT a clone of "Mayi – Travel Buddy" (a reference app) — study
its concepts, don't copy its UX/branding/workflows. See original master
instructions (was pasted into an early conversation) for full detail:
incremental milestones only, security built in from the start, IDOR
prevention mandatory, no fake implementations, ask before big
architectural/DB changes.

## Status: Milestones 1 & 2 complete and verified working live

**Milestone 1 — Foundation + email/password auth**
- `users` table: id, name, email (unique), password_hash (nullable),
  role (enum customer/staff/admin), created_at/updated_at (UTC)
- Register/login/logout, bcrypt hashing, CSRF tokens, secure sessions
  (HttpOnly/SameSite, regenerated on login), per-session login rate
  limiting, server-side-only role assignment
- Role-based dashboard stubs (customer/staff/admin) — deliberately bare,
  just prove `require_role()` works. Real dashboard UI comes with
  Milestone 3.

**Milestone 2 — Google Sign-In**
- `google_subject` column (nullable, unique) added via migration
- `includes/google_auth.php`: hand-rolled server-side ID token
  verification — fetches Google's JWKS, verifies RS256 signature via
  OpenSSL (manual DER encoding, no GMP dependency), checks iss/aud/exp
- `auth/google_login.php`: verifies token, finds-or-creates user, sets
  same session shape as password login
- Deliberate: no auto-linking if a Google email matches an existing
  password account — shows an error telling them to use their password
  instead. A manual "link accounts" flow is NOT built yet.
- **Verified working end-to-end live** — Mori successfully signed in
  with Google and landed on the customer dashboard, confirmed in DB.

## Local environment — hard-won details, don't relitigate these

- Project lives at `C:\xampp\htdocs\TravelEase`
- Runs via `php -S localhost:8000` from that folder (NOT via Apache/
  `http://localhost/TravelEase/...` — that path breaks because the app
  uses root-relative links like `/auth/login.php`, which resolve wrong
  under a subfolder). A `.local` vhost was tried and abandoned — Google
  OAuth rejects non-public-TLD origins.
- Mori has TWO PHP installs: XAMPP's bundled PHP 8.2 (`C:\xampp\php`)
  and a standalone WinGet PHP 8.4. `php -S` uses the **WinGet 8.4** one.
  Its php.ini is at:
  `C:\Users\moris\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.ini`
  That ini needed `curl` and `openssl` extensions uncommented, plus
  `curl.cainfo` / `openssl.cafile` pointed at `C:\php\cacert.pem`
  (downloaded from https://curl.se/ca/cacert.pem) for Google's HTTPS
  cert to verify. If Google API calls start failing again with cURL/
  SSL errors, check this first.
- MySQL via XAMPP needed the Control Panel run **as Administrator** —
  it was silently failing to start otherwise (permissions issue writing
  PID file), even though its own log showed no error.
- `.env` is gitignored, so it's NOT in any zip export — always needs to
  be recreated from `.env.example` after extracting a fresh copy.
- Google Cloud Console OAuth Client: Authorized JavaScript origins /
  redirect URIs currently only has `http://localhost:8000`. Add any
  new origin there before testing from a different URL. OAuth consent
  screen is in "Testing" status — only added test users can sign in.

## Database decisions already made (don't re-litigate)

- `id`: INT UNSIGNED AUTO_INCREMENT
- `email`: UNIQUE, normalized to lowercase at every read/write
- `password_hash`: nullable (Google-only accounts have none)
- `google_subject`: nullable, unique
- `role`: ENUM('customer','staff','admin') — deliberate, not a
  separate roles table, given fixed small role set
- Timestamps: UTC everywhere (`SET time_zone = '+00:00'` on the PDO
  connection, `date_default_timezone_set('UTC')` in PHP)
- `utf8mb4` / `utf8mb4_unicode_ci` throughout

## Next planned milestone

**Trips table + basic trip CRUD** scoped to the authenticated customer,
with ownership checks (IDOR prevention) from the start. This is also
where the first real (non-stub) dashboard UI happens, since there'll
finally be real data to design around — don't build dashboard polish
before this exists.

## Do NOT do yet (explicitly out of scope until asked)

- Account linking flow (Google ↔ existing password account)
- Password reset, email verification
- Destinations, itineraries, budgets, booking requests, payments
- Admin user management, reporting
- SEO/production-readiness checklist items (404 page, sitemap, cookie
  banner, etc. — these came up via Mori sharing two Instagram reels;
  they're valid but premature, already covered by the original spec's
  own §41/§55 for later)

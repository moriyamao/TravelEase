# TravelEase

A web-based travel planning and booking application.

## Status: Milestone 2 — Google Sign-In

This milestone establishes the project skeleton and a working, secure
email/password authentication system with role-based routing
(customer / staff / admin). No trip, itinerary, budget, or booking
features exist yet — those are later milestones.

## Setup (XAMPP / local PHP + MySQL)

1. Copy `.env.example` to `.env` and fill in your local DB credentials
   and an `APP_SECRET`. **Never commit `.env`.**
2. Import the schema:
   ```
   mysql -u root -p < database/schema.sql
   ```
3. Point your web server's document root at the project root (not
   `public/`), so that `/auth/`, `/customer/`, etc. resolve correctly.
   With XAMPP, place the project under `htdocs/TravelEase` and visit
   `http://localhost/TravelEase/public/`.
4. Visit `/public/index.php` — you'll be redirected to
   `/auth/register.php` if not logged in.

## Setup: Google Sign-In (Milestone 2)

1. Run the migration against your **existing** database:
   ```
   mysql -u root -p travelease < database/migration_002_add_google_subject.sql
   ```
   (Or paste its contents into phpMyAdmin's SQL tab.) If you're setting
   up the database fresh, `schema.sql` already includes this column —
   skip this step.
2. In Google Cloud Console → APIs & Services → Credentials, create an
   OAuth Client ID (Web application), with `http://localhost:8000`
   added under both Authorized JavaScript origins and Authorized
   redirect URIs.
3. Copy the Client ID into `.env`:
   ```
   GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
   ```
   The Client Secret is **not** used by this app and should not be
   placed anywhere in the project.
4. While the OAuth consent screen is in "Testing" mode, add your own
   Google account as a test user (Google Cloud Console → OAuth consent
   screen → Test users) — only test users can sign in until the app is
   published.
5. Restart `php -S localhost:8000` and visit `/auth/login.php` — the
   "Sign in with Google" button should appear below the password form.

## Architecture decisions (for Milestone 2 compatibility)

- `password_hash` is nullable at the DB level (Milestone 1 code always
  writes a valid hash for email/password accounts) so a future
  Google-only account with no password doesn't require an ALTER on a
  populated table.
- All timestamps stored and compared in UTC (`SET time_zone = '+00:00'`
  on the DB connection, `date_default_timezone_set('UTC')` in PHP).
- Email addresses normalized to lowercase before storage/lookup.
- Session/authorization (`includes/auth.php`) is authentication-method
  agnostic: it only reads `$_SESSION['user_id']` / `$_SESSION['user_role']`,
  which any future login method (Google included) sets the same way.
- See `database/schema.sql` for the exact planned Milestone 2 migration.

## What's working

- User registration (name, email, password) with server-side validation
- Login with generic error messages (no user enumeration via error text)
- Password hashing via `password_hash()` / `password_verify()`
- CSRF protection on register/login forms
- Secure session handling (HttpOnly, SameSite, regenerated on login)
- Basic per-session login rate limiting
- Server-side role assignment — role is never accepted from the client
- Role-based dashboard stubs (customer/staff/admin) with `require_role()`
  authorization guards
- Google Sign-In via official Google Identity Services, with server-side
  ID token verification (signature, issuer, audience, expiry — no
  third-party JWT library, no GMP/cURL hard dependency)
- Google-authenticated users get the exact same session shape and
  `require_role()` guards as password-authenticated ones
- Deliberate no-auto-link policy: an existing email/password account
  is not silently linked to a Google sign-in attempt with the same email

## What's NOT built yet

- Account linking flow (letting a user manually connect Google to an
  existing password account)
- Password reset, email verification
- Trips, destinations, itineraries, budgets, booking requests
- Admin user management, reporting
- Any UI beyond bare-bones auth pages and empty dashboard stubs

## Known limitations

- Login rate limiting is per-session only (not IP-based); acceptable
  for this stage but should be revisited before production.
- No email verification on registration yet.
- No password reset flow yet.

## Next logical step

Design and implement the `trips` table and basic trip CRUD
(create/view/edit/delete a trip) scoped to the authenticated customer,
with ownership checks (IDOR prevention) from the start.

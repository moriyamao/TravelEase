<?php
/**
 * FR-15: public landing page for a trip share link.
 *
 * The link's secret is in the URL fragment (#token), which browsers never
 * send to the server, so it stays out of request URLs, access logs and
 * Referer headers. trip-share.js reads the fragment and POSTs it to
 * share_resolve.php, then renders the result with textContent only.
 *
 * - View-only link: shows the allowlisted itinerary (no sign-in).
 * - Join link: shows the join form below, which stays hidden until the
 *   resolver says the link is a join link. Joining needs a signed-in,
 *   verified customer, this page's CSRF token and an explicit POST to
 *   share_join.php; the resolver never joins anyone.
 *
 * No external assets (no fonts, maps or scripts from other sites), and
 * the page is not cached, indexed, or sent as a referrer.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
// Only this site's own script, stylesheet and endpoints; no inline code, framing or other origins.
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

// Who is looking decides what the join section offers; the server checks again on join.
$viewer = 'guest';
if (is_logged_in()) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $stmt = get_db_connection()->prepare('SELECT role, email_verified_at FROM users WHERE id = :id');
        $stmt->execute(['id' => current_user_id()]);
        $row = $stmt->fetch();
        if ($row === false) {
            $viewer = 'guest';
        } elseif ($row['role'] !== 'customer') {
            $viewer = 'not_customer';
        } else {
            $viewer = $row['email_verified_at'] === null ? 'unverified' : 'customer';
        }
    } catch (Throwable $e) {
        error_log('TravelEase: share page account check failed.');
        $viewer = 'unknown';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Shared trip — TravelEase</title>
    <link rel="stylesheet" href="/assets/css/shares.css">
</head>
<body class="share-public">
    <main class="share-page">
        <header class="share-header">
            <span class="share-brand"><svg class="share-brand-mark" viewBox="0 0 512 512" width="30" height="30" aria-hidden="true" focusable="false"><rect width="512" height="512" rx="120" fill="#152b43"/><path d="M112 388C236 388 238 252 352 252" fill="none" stroke="#f6f2e8" stroke-width="42" stroke-linecap="round"/><circle cx="112" cy="388" r="30" fill="#f6f2e8"/><path d="M370 290C336 244 306 214 306 166a64 64 0 1 1 128 0c0 48-30 78-64 124Z" fill="#bd4d28"/><circle cx="370" cy="166" r="25" fill="#f6f2e8"/></svg>Travel<span class="share-brand-accent">Ease</span></span>
            <a href="/public/information.php" target="_blank" rel="noopener noreferrer">About &amp; privacy</a>
        </header>

        <p class="share-status" data-share-status role="status" aria-live="polite">Checking the link…</p>
        <noscript><p class="share-error">This page needs JavaScript to open a share link.</p></noscript>

        <section class="share-panel" data-share-view hidden aria-labelledby="share-view-heading">
            <p class="share-eyebrow">Shared itinerary (view only)</p>
            <h1 id="share-view-heading" data-share-destination></h1>
            <p class="share-dates" data-share-dates></p>
            <p class="share-zone" data-share-zone></p>
            <div data-share-activities></div>
            <p class="share-note">Shared by the trip's owner. It shows only dates, times, titles and locations.</p>
        </section>

        <section class="share-panel" data-share-join hidden aria-labelledby="share-join-heading">
            <p class="share-eyebrow">Invitation to join a trip</p>
            <h1 id="share-join-heading">Join the trip to <span data-share-join-destination></span></h1>
            <p class="share-dates" data-share-join-dates></p>
            <p>You'll join as a <strong>Viewer</strong>: you can see the trip but not change it. If you're already on this trip, your current role stays the same.</p>

            <?php if ($viewer === 'customer'): ?>
                <form method="post" action="/customer/trips/share_join.php" data-share-join-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="" data-share-token>
                    <button type="submit" class="share-button">Join this trip as a Viewer</button>
                </form>
            <?php elseif ($viewer === 'unverified'): ?>
                <p class="share-error">Verify your email address before joining. Check your inbox, then reload this page.</p>
                <p><a href="/auth/resend_verification.php" target="_blank" rel="noopener">Resend the verification email</a> (opens in a new tab).</p>
            <?php elseif ($viewer === 'not_customer'): ?>
                <p class="share-error">Only customer accounts can join trips. Sign in with a customer account to join.</p>
            <?php elseif ($viewer === 'unknown'): ?>
                <p class="share-error">We couldn't check your account right now. Please reload this page in a moment.</p>
            <?php else: ?>
                <p>Sign in with a verified customer account to join. Sign-in opens in a new tab so this link stays open here.</p>
                <p class="share-actions">
                    <a class="share-button" href="/auth/login.php" target="_blank" rel="noopener">Sign in</a>
                    <a class="share-button-secondary" href="/auth/register.php" target="_blank" rel="noopener">Create an account</a>
                </p>
                <p class="share-note">After you sign in, come back to this tab and reload the page, then press Join.</p>
            <?php endif; ?>
        </section>
    </main>
    <script src="/assets/js/trip-share.js" defer></script>
</body>
</html>

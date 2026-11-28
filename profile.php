<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/profile_validation.php';
require_once __DIR__ . '/includes/account_privacy.php';

// Any logged-in role can manage their own profile -- not role-specific.
require_login();

$pdo = get_db_connection();
$userId = current_user_id();

$stmt = $pdo->prepare('SELECT name, email, profile_picture, google_subject, password_hash IS NOT NULL AS has_password, notify_in_app, notify_email FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    // Session points at a user that no longer exists -- treat as logged out.
    header('Location: /auth/login.php');
    exit;
}

$errors = [];
$success = false;
$notificationNotice = $_SESSION['notification_notice'] ?? null;
$notificationError = $_SESSION['notification_error'] ?? null;
unset($_SESSION['notification_notice'], $_SESSION['notification_error']);
$name = $user['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        // Form fields must be plain strings; an array (field[]=x) is rejected
        // instead of reaching trim() as a TypeError.
        foreach (['name'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) {
                http_response_code(400);
                exit('Invalid form input. Reload the page and try again.');
            }
        }
        $name = trim($_POST['name'] ?? '');

        if ($err = validate_display_name($name)) {
            $errors[] = $err;
        }

        $newPicturePath = null;
        $hasFileUpload = isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($hasFileUpload) {
            try {
                $extension = validate_profile_picture_upload($_FILES['profile_picture']);

                $uploadDir = __DIR__ . '/uploads/profile_pictures/';
                $filename  = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
                $destPath  = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destPath)) {
                    throw new UploadException('Could not save the uploaded image. Please try again.');
                }

                $newPicturePath = '/uploads/profile_pictures/' . $filename;

            } catch (UploadException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (empty($errors)) {
            try {
                if ($newPicturePath !== null) {
                    $stmt = $pdo->prepare(
                        'UPDATE users SET name = :name, profile_picture = :picture WHERE id = :id'
                    );
                    $stmt->execute(['name' => $name, 'picture' => $newPicturePath, 'id' => $userId]);

                    // Clean up the old file now that the DB points at the new one.
                    if (!empty($user['profile_picture'])) {
                        $oldFile = __DIR__ . $user['profile_picture'];
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }

                    $user['profile_picture'] = $newPicturePath;
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = :name WHERE id = :id');
                    $stmt->execute(['name' => $name, 'id' => $userId]);
                }

                // Keep the session's cached display name in sync immediately.
                $_SESSION['user_name'] = $name;
                $user['name'] = $name;
                $success = true;

            } catch (PDOException $e) {
                error_log('TravelEase: profile update failed (database code ' . (int) ($e->errorInfo[1] ?? 0) . ').');
                $errors[] = 'Something went wrong while saving your profile. Please try again.';
            }
        }
    }
}

$dashboardByRole = [
    'customer' => '/customer/dashboard.php',
    'staff'    => '/staff/dashboard.php',
    'admin'    => '/admin/dashboard.php',
];
$backLink = $dashboardByRole[current_user_role()] ?? '/public/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings — TravelEase</title>
    <link rel="icon" href="/assets/images/travelease-logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/profile.css">
    <link rel="stylesheet" href="/assets/css/notifications.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body>
    <?php $navCurrent = 'profile'; require __DIR__ . '/includes/partials/app_header.php'; ?>
    <main class="app-page legacy-page" id="main-content" tabindex="-1">
        <div class="page-head">
            <p class="eyebrow">Your account</p>
            <h1 class="page-title">Profile settings</h1>
            <p class="page-head-links"><a href="#notification-preferences">Notification preferences</a> <a href="/public/information.php">Terms &amp; privacy</a></p>
        </div>

        <div class="form-card">
            <?php if ($success): ?>
                <p class="success-message">Profile updated.</p>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <ul class="form-errors" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="profile-picture-current">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars($user['profile_picture'], ENT_QUOTES, 'UTF-8') ?>" alt="Your profile picture" class="profile-picture-img">
                <?php else: ?>
                    <div class="profile-picture-placeholder">
                        <?= htmlspecialchars(mb_substr($user['name'], 0, 1), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post" action="/profile.php" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>

                <label for="name">Name</label>
                <input type="text" id="name" name="name" required maxlength="100"
                       value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">

                <label>Email</label>
                <p class="profile-email"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>

                <label for="profile_picture">Profile picture (optional)</label>
                <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp">
                <p class="field-hint">JPG, PNG, or WEBP. Max 2MB.</p>

                <button type="submit">Save Changes</button>
            </form>
        </div>
        <section class="form-card" id="notification-preferences" aria-labelledby="notification-preferences-heading">
            <h2 id="notification-preferences-heading">Notification preferences</h2>
            <?php if ($notificationNotice): ?><p class="success-message" role="status"><?= htmlspecialchars($notificationNotice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($notificationError): ?><p class="form-errors" role="alert"><?= htmlspecialchars($notificationError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <form method="post" action="/customer/notification_action.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="preferences">
                <input type="hidden" name="return_to" value="profile">
                <input type="hidden" name="enabled" value="0">
                <label><input type="checkbox" name="enabled" value="1"<?= (int) $user['notify_in_app'] === 1 ? ' checked' : '' ?>> Show in-app notifications</label>
                <input type="hidden" name="email_enabled" value="0">
                <label><input type="checkbox" name="email_enabled" value="1"<?= (int) $user['notify_email'] === 1 ? ' checked' : '' ?>> Email me when a reminder is due</label>
                <p class="field-hint">Changes apply to future notifications.</p>
                <button type="submit">Save preferences</button>
            </form>
        </section>
        <?php if ($user['google_subject'] === null): ?>
        <div class="form-card">
            <h2>Link Google account</h2>
            <p class="field-hint">Use your TravelEase password and a Google account with the same email address.</p>
            <label for="google-link-password">TravelEase password</label>
            <input type="password" id="google-link-password" required>
            <div id="google-link-error" class="form-errors" role="alert" style="display:none"></div>
            <div id="google-link-button"></div>
        </div>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
        <script>
            window.onload = function () {
                google.accounts.id.initialize({
                    client_id: <?= json_encode(getenv('GOOGLE_CLIENT_ID') ?: '', JSON_UNESCAPED_SLASHES) ?>,
                    callback: function (response) {
                        const errorBox = document.getElementById('google-link-error');
                        const data = new URLSearchParams();
                        data.set('credential', response.credential);
                        data.set('password', document.getElementById('google-link-password').value);
                        data.set('csrf_token', <?= json_encode(csrf_token()) ?>);
                        fetch('/auth/google_link.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data.toString() })
                            .then(async res => { const body = await res.json(); if (!res.ok) throw new Error(body.error || 'Google linking failed.'); return body; })
                            .then(() => window.location.reload())
                            .catch(error => { errorBox.textContent = error.message; errorBox.style.display = 'block'; });
                    }
                });
                google.accounts.id.renderButton(document.getElementById('google-link-button'), {theme: 'outline', size: 'large', width: 320});
            };
        </script>
        <?php endif; ?>

        <?php
        $accountError = $_SESSION['account_error'] ?? null;
        unset($_SESSION['account_error']);
        $isCustomer = current_user_role() === 'customer';
        $deletionBlockers = $isCustomer ? account_deletion_blockers($pdo, $userId) : [];
        ?>
        <section class="form-card account-privacy" id="your-data" aria-labelledby="your-data-heading">
            <h2 id="your-data-heading">Your data</h2>
            <?php if ($accountError): ?><p class="form-errors" role="alert"><?= htmlspecialchars($accountError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <p>
                Download a copy of your account, the trips you own or belong to (as your role shows them), and your own comments,
                votes, reminders, notifications, invitations and support requests, as one JSON file. Other members' email addresses
                and anything you can't see in TravelEase are not included.
            </p>
            <form method="post" action="/account/export.php">
                <?= csrf_field() ?>
                <button type="submit">Download my data</button>
            </form>
        </section>

        <?php if ($isCustomer): ?>
        <section class="form-card account-privacy" id="delete-account" aria-labelledby="delete-heading">
            <h2 id="delete-heading">Delete my account</h2>
            <p>
                This permanently deletes your account and cannot be undone. Trips you own alone are deleted with it, and you are
                removed from trips you belong to. Your reminders, notifications, invitations and support requests are deleted.
                Comments you wrote and expense entries keep showing your name to the other travelers.
            </p>
            <?php if ($deletionBlockers): ?>
                <p class="form-errors">
                    You own trips that other people are on. Transfer ownership to another member or delete these trips first:
                    <?php foreach ($deletionBlockers as $blockerId => $blockerName): ?>
                        <a href="/customer/trips/view.php?id=<?= (int) $blockerId ?>"><?= htmlspecialchars($blockerName, ENT_QUOTES, 'UTF-8') ?></a><?= $blockerName !== end($deletionBlockers) ? ',' : '' ?>
                    <?php endforeach; ?>
                </p>
            <?php else: ?>
                <form method="post" action="/account/delete.php" onsubmit="return confirm('Permanently delete your TravelEase account? This cannot be undone.');">
                    <?= csrf_field() ?>
                    <?php if ((int) $user['has_password'] === 1): ?>
                        <label for="delete-password">Your password</label>
                        <input type="password" id="delete-password" name="password" required autocomplete="current-password">
                    <?php else: ?>
                        <p>You sign in with Google. If you signed in more than 10 minutes ago, sign out and sign in again first.</p>
                    <?php endif; ?>
                    <label for="delete-confirm">Type DELETE to confirm</label>
                    <input id="delete-confirm" name="confirm" required pattern="DELETE" autocomplete="off">
                    <button type="submit" class="btn-danger">Delete my account</button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
</body>
</html>

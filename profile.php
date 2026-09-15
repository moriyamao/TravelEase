<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/profile_validation.php';

// Any logged-in role can manage their own profile -- not role-specific.
require_login();

$pdo = get_db_connection();
$userId = current_user_id();

$stmt = $pdo->prepare('SELECT name, email, profile_picture FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    // Session points at a user that no longer exists -- treat as logged out.
    header('Location: /auth/login.php');
    exit;
}

$errors = [];
$success = false;
$name = $user['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
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
                $filename  = 'user_' . $userId . '_' . time() . '.' . $extension;
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
                error_log('TravelEase: profile update failed: ' . $e->getMessage());
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
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="/assets/css/trips.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/profile.css">
</head>
<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <h1>Profile Settings</h1>
            <a href="<?= htmlspecialchars($backLink, ENT_QUOTES, 'UTF-8') ?>" class="logout-link">Back to Dashboard</a>
        </header>

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
    </main>
</body>
</html>

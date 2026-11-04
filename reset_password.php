<?php
require 'includes/app.php';
ensureResetTable();

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$reset = findResetToken($token);
$error = '';

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';

    if (strlen($password) < 8) {
        $error = 'Use a password with at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $error = 'Use a password of 72 characters or fewer.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords don\'t match.';
    } else {
        resetPassword((int) $reset['user_id'], $password);
        if (currentUser()) logOut();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        flash('Your password has been changed. Log in with your new password.');
        redirect('login.php');
    }
}

pageStart('Choose a new password', null, 'page-auth');
?>
<main id="main" class="auth-panel">
    <?php if (!$reset): ?>
        <h1>This link doesn't work any more</h1>
        <p class="lede">Reset links work once and expire after <?= RESET_LINK_MINUTES ?> minutes. You can ask for a new one.</p>
        <p><a class="btn" href="forgot_password.php">Send a new link</a></p>
    <?php else: ?>
        <h1>Choose a new password</h1>
        <p class="lede">For <?= e($reset['email']) ?></p>
        <?php if ($error): ?>
            <p class="alert alert-bad" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <form method="POST" class="stack">
            <?= csrfField() ?>
            <label>New password
                <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required autofocus aria-describedby="password-hint">
                <span class="hint" id="password-hint">At least 8 characters.</span>
            </label>
            <label>Confirm new password
                <input type="password" name="confirm" autocomplete="new-password" minlength="8" maxlength="72" required>
            </label>
            <button type="submit" class="btn">Save new password</button>
        </form>
    <?php endif; ?>
</main>
<?php pageEnd(); ?>

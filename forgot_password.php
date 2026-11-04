<?php
require 'includes/app.php';
ensureResetTable();

if (currentUser()) {
    redirect('groups.php');
}

$email = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = postText('email');
    $user = $email === '' ? null : findUserByEmail($email);

    if ($user) {
        $token = createResetToken((int) $user['id']);
        if ($token !== null) {
            $link = siteUrl('reset_password.php?token=' . $token);
            $body = "Hi,\n\n"
                  . "Someone asked to reset the password for your " . APP_NAME . " account.\n"
                  . "To choose a new password, open this link within " . RESET_LINK_MINUTES . " minutes:\n\n"
                  . "$link\n\n"
                  . "If it wasn't you, you can ignore this email. Your password won't change.\n\n"
                  . APP_NAME . "\n";
            [$status, $error] = sendEmail(cleanEmail($email), '', 'Reset your ' . APP_NAME . ' password', $body);
            logReset("$email: account found, email $status" . ($error ? " ($error)" : ''));
        } else {
            logReset("$email: account found, but asked again too soon (wait 2 minutes)");
        }
    } elseif ($email !== '') {
        logReset("$email: no account with this email");
    }
    // Always the same answer, so the form doesn't reveal who has an account
    $sent = true;
}

pageStart('Forgot your password?', null, 'page-auth');
?>
<main id="main" class="auth-panel">
    <h1>Forgot your password?</h1>

    <?php if ($sent): ?>
        <p class="alert alert-good" role="status">
            If there's an account for <?= e($email) ?>, we've emailed it a link to choose a new password.
            The link works for <?= RESET_LINK_MINUTES ?> minutes. Check your spam folder if it doesn't arrive.
        </p>
        <p class="switch"><a href="login.php">Back to log in</a></p>
    <?php else: ?>
        <p class="lede">Enter the email you signed up with and we'll send you a link to choose a new password.</p>
        <form method="POST" class="stack">
            <?= csrfField() ?>
            <label>Email
                <input type="email" name="email" autocomplete="email" value="<?= e($email) ?>" required autofocus>
            </label>
            <button type="submit" class="btn">Send reset link</button>
        </form>
        <p class="switch">Remembered it? <a href="login.php">Log in</a></p>
    <?php endif; ?>
</main>
<?php pageEnd(); ?>

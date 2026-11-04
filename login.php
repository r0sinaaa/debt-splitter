<?php
require 'includes/app.php';

if (currentUser()) {
    redirect('groups.php');
}

$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = postText('email');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    $user = $email === '' ? null : findUserByEmail($email);

    // When there's no such account, still check the password against a made-up hash,
    // so it takes as long as a wrong password and doesn't reveal who has an account
    $hash = $user['password_hash'] ?? password_hash('no-such-account', PASSWORD_DEFAULT);

    if ($user && password_verify($password, $hash)) {
        // Keep stored hashes up to date if PHP's default algorithm gets stronger
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->bind_param("si", $new_hash, $user['id']);
            $stmt->execute();
        }
        logIn((int) $user['id']);
        redirect('groups.php');
    }
    $error = 'That email and password don\'t match an account.';
}

pageStart('Log in', null, 'page-auth');
?>
<main id="main" class="auth-panel">
    <h1>Log in</h1>
    <p class="lede">Use the email you signed up with.</p>

    <?php if ($error): ?>
        <p class="alert alert-bad" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" class="stack">
        <?= csrfField() ?>
        <label>Email
            <input type="email" name="email" autocomplete="username" value="<?= e($email) ?>" required <?= $email === '' ? 'autofocus' : '' ?>>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required <?= $email !== '' ? 'autofocus' : '' ?>>
        </label>
        <button type="submit" class="btn">Log in</button>
    </form>

    <p class="switch"><a href="forgot_password.php">Forgot your password?</a></p>

    <p class="switch">New here? <a href="register.php">Create an account</a></p>
</main>
<?php pageEnd(); ?>

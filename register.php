<?php
require 'includes/app.php';

if (currentUser()) {
    redirect('groups.php');
}

$form = ['name' => '', 'email' => ''];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $form = ['name' => postText('name'), 'email' => postText('email')];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';

    [$error, $contact] = checkContactFields($form['name'], $form['email']);

    if ($error === '' && $contact['email'] === null) {
        $error = 'Add your email address. You\'ll use it to log in.';
    } elseif ($error === '' && strlen($password) < 8) {
        $error = 'Use a password with at least 8 characters.';
    } elseif ($error === '' && strlen($password) > 72) {
        $error = 'Use a password of 72 characters or fewer.';   // the hashing only reads the first 72
    } elseif ($error === '' && $password !== $confirm) {
        $error = 'The two passwords don\'t match.';
    }

    // One account per email address
    if ($error === '' && findUserByEmail($contact['email'])) {
        $error = 'There\'s already an account with that email. Log in instead?';
    }

    if ($error === '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $contact['name'], $contact['email'], $hash);
        try {
            $stmt->execute();
            logIn($conn->insert_id);
            flash('Your account is ready.');
            redirect('groups.php');
        } catch (mysqli_sql_exception $e) {
            $error = 'That email was just used to make another account. Try logging in.';
        }
    }
}

pageStart('Create an account', null, 'page-auth');
?>
<main id="main" class="auth-panel">
    <h1>Create your account</h1>
    <p class="lede">You'll log in with your email address.</p>

    <?php if ($error): ?>
        <p class="alert alert-bad" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" class="stack">
        <?= csrfField() ?>
        <label>Your name
            <input type="text" name="name" autocomplete="name" maxlength="100" value="<?= e($form['name']) ?>" required>
        </label>
        <label>Email
            <input type="email" name="email" autocomplete="email" value="<?= e($form['email']) ?>" required>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required aria-describedby="password-hint">
            <span class="hint" id="password-hint">At least 8 characters.</span>
        </label>
        <label>Confirm password
            <input type="password" name="confirm" autocomplete="new-password" minlength="8" maxlength="72" required>
        </label>
        <button type="submit" class="btn">Create account</button>
    </form>

    <p class="switch">Already have an account? <a href="login.php">Log in</a></p>
</main>
<?php pageEnd(); ?>

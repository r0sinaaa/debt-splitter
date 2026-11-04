<?php
// The top and bottom of every page

// The logo: an equals sign, because everyone ends up even
function logoMark(): string {
    return '<svg class="logo-mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
         . '<rect class="logo-bg" width="32" height="32" rx="9" fill="#FFE14D"/>'
         . '<rect class="logo-bar" x="8" y="10.5" width="16" height="4" rx="2" fill="#000"/>'
         . '<rect class="logo-bar" x="8" y="17.5" width="16" height="4" rx="2" fill="#000"/></svg>';
}

function pageStart(string $title, ?array $user, string $body_class = ''): void {
    $full_title = $title === '' ? APP_NAME . ': split your debts, not your friendships' : "$title | " . APP_NAME;
    $favicon = 'data:image/svg+xml,' . rawurlencode(str_replace(' class="logo-mark" aria-hidden="true" focusable="false"', ' xmlns="http://www.w3.org/2000/svg"', logoMark()));
    $flash = takeFlash();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($full_title) ?></title>
    <link rel="icon" href="<?= e($favicon) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400..900&display=swap">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= e($body_class) ?>">
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="site-nav">
        <a class="brand" href="index.php"><?= logoMark() ?><span><?= e(APP_NAME) ?></span></a>
        <nav class="nav-links" aria-label="Account">
            <?php if ($user): ?>
                <a href="groups.php">Dashboard</a>
                <form method="POST" action="logout.php">
                    <?= csrfField() ?>
                    <button type="submit" class="btn-link">Log out</button>
                </form>
            <?php else: ?>
                <a href="login.php">Log in</a>
                <a class="btn btn-small" href="register.php">Create account</a>
            <?php endif; ?>
        </nav>
    </header>
    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status"><p><?= nl2br(e($flash['text'])) ?></p></div>
    <?php endif; ?>
    <?php
}

function pageEnd(): void {
    ?>
    <footer class="site-footer">
        <p><?= logoMark() ?><span><?= e(APP_NAME) ?>. Split your debts, not your friendships.</span></p>
    </footer>
</body>
</html>
    <?php
}

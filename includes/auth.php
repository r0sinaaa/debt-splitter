<?php
// Accounts, login sessions and form protection

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');   // ignore session ids the server didn't create
    session_set_cookie_params([
        'lifetime' => 0,          // the login ends when the browser is closed
        'path'     => '/',
        'httponly' => true,       // JavaScript on the page can't read the cookie
        'samesite' => 'Lax',      // other websites can't submit forms as you
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// The logged-in user, or null if nobody is logged in
function currentUser(): ?array {
    global $conn;
    static $user = false;   // looked up once per page load
    if ($user !== false) return $user;

    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $id = (int) $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
    }
    return $user;
}

// Sends people who aren't logged in to the login page
function requireLogin(): array {
    $user = currentUser();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

// Finds an account by email address
function findUserByEmail(string $email): ?array {
    global $conn;
    $email = cleanEmail($email);
    $stmt = $conn->prepare("SELECT id, password_hash FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function logIn(int $user_id): void {
    session_regenerate_id(true);   // fresh session id, so an old one can't be reused
    $_SESSION['user_id'] = $user_id;
}

function logOut(): void {
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $cookie['path'],
        'domain'   => $cookie['domain'],
        'secure'   => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    session_destroy();
}

// A random token kept in the session. Every form sends it back, which proves the
// form came from this site and not from another page trying to post as you.
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}

function checkCsrf(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        http_response_code(400);
        exit('This form has expired. Go back, refresh the page and try again.');
    }
}

// ---- Password reset ---------------------------------------------------------
// "Forgot your password?" emails a one-time link. Only a hash of the token is
// stored, so someone who could read the database still couldn't use a link.
// Links work once and expire after an hour.

const RESET_LINK_MINUTES = 60;

// Older databases were created before this feature, so make sure the table exists
function ensureResetTable(): void {
    global $conn;
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
}

// Makes a reset link for this user and returns the token, or null if one was
// already sent in the last 2 minutes (stops the form being used to flood an inbox)
function createResetToken(int $user_id): ?string {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 2 MINUTE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_assoc()['n'] > 0) return null;

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $minutes = RESET_LINK_MINUTES;
    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ? MINUTE)");
    $stmt->bind_param("isi", $user_id, $hash, $minutes);
    $stmt->execute();
    return $token;
}

// Notes what happened to each reset request in storage/reset.log (never the link itself),
// so the site owner can see why an email didn't arrive
function logReset(string $line): void {
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/reset.log', '[' . date('Y-m-d H:i:s') . "] $line\n", FILE_APPEND | LOCK_EX);
}

// The user a reset link belongs to, if the link is still valid (unused, not expired)
function findResetToken(string $token): ?array {
    global $conn;
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT r.id, r.user_id, u.name, u.email FROM password_resets r JOIN users u ON u.id = r.user_id
                            WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

// Saves the new password and switches off every reset link this user still has
function resetPassword(int $user_id, string $password): void {
    global $conn;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param("si", $hash, $user_id);
    $stmt->execute();
    $stmt = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $conn->commit();
}

// The full address of a page on this site, for links in emails
function siteUrl(string $page): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . "://$host$dir/$page";
}

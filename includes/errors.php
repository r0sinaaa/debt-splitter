<?php
// What happens when something goes wrong.
// Instead of PHP's "Fatal error" page (which shows file paths to anyone),
// visitors get a short, friendly page. The full details go to storage/error.log,
// and are also shown on the page when you open the site on this computer (localhost).

ini_set('display_errors', '0');
ini_set('log_errors', '0');   // we write our own log below
error_reporting(E_ALL);

function logError(string $message): void {
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/error.log', '[' . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND | LOCK_EX);
}

// A plain-language hint for the problems people actually run into on XAMPP
function errorHint(Throwable $e): string {
    $code = $e instanceof mysqli_sql_exception ? $e->getCode() : 0;
    $text = $e->getMessage();

    if ($code === 1932) {
        return 'MySQL has lost track of a table (this usually happens after XAMPP\'s MySQL crashes). '
             . 'Open phpMyAdmin, run DROP DATABASE ' . (defined('DB_NAME') ? DB_NAME : 'split_your_debt') . '; '
             . 'and reload the site to create a fresh one. Or change DB_NAME in config.php to a new name.';
    }
    if ($code === 1146 || $code === 1054) {
        return 'The database is missing a table or column, probably from an older version of the site. '
             . 'Changing DB_NAME in config.php to a new name gives you a fresh, complete database.';
    }
    if (in_array($code, [1044, 1045, 1698], true) || str_contains($text, 'Access denied')) {
        return 'MySQL refused the username or password. Check DB_USER and DB_PASS in config.php.';
    }
    if ($code === 2002 || str_contains($text, 'Connection refused')) {
        return 'MySQL isn\'t running. Start it in the XAMPP Control Panel.';
    }
    if (str_contains($text, 'Undefined constant')) {
        return 'A setting is missing from config.php. Compare it with the one in the download and add the missing line back.';
    }
    return 'Try again. If it keeps happening, the details are in storage/error.log.';
}

function isLocalVisitor(): bool {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

set_exception_handler(function (Throwable $e) {
    $details = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    logError($details);
    $hint = errorHint($e);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Something went wrong.\n$hint\n\n$details\n");
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $show = isLocalVisitor();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Something went wrong</title><style>'
       . 'body{font-family:system-ui,sans-serif;background:#fceaf1;color:#2e1a35;margin:0;padding:24px}'
       . 'main{max-width:560px;margin:40px auto;background:#fff;border:1px solid #eed6e4;border-radius:20px;padding:28px}'
       . 'h1{margin:0 0 12px;font-size:26px}p{line-height:1.5}code{display:block;white-space:pre-wrap;word-break:break-word;'
       . 'background:#f1e8fa;border-radius:10px;padding:12px;font-size:13px;margin-top:16px}a{color:#6e4c9a}'
       . '</style></head><body><main><h1>Something went wrong</h1>'
       . '<p>' . htmlspecialchars($hint) . '</p>'
       . ($show ? '<code>' . htmlspecialchars($details) . '</code><p style="font-size:14px;color:#5b4663">You can see these details because you\'re on this computer. Other visitors only see the message above.</p>' : '')
       . '<p><a href="javascript:history.back()">Go back</a></p></main></body></html>';
    exit;
});

// Warnings and notices don't stop the page; they're written to storage/error.log
// instead of being printed in the middle of it
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) return false;   // silenced with @
    logError("PHP warning: $message in $file:$line");
    return true;
});

<?php
// Safe defaults for every setting in config.php.
// If a line goes missing from config.php (easy to do while editing), the site
// uses the value below instead of crashing, and notes it in storage/error.log.

$config_defaults = [
    'APP_NAME'                => 'Evenly',
    'CURRENCY'                => '€',
    'TIMEZONE'                => 'Europe/Copenhagen',
    'DB_HOST'                 => 'localhost',
    'DB_USER'                 => 'root',
    'DB_PASS'                 => '',
    'DB_NAME'                 => 'split_your_debt',
    'SMTP_HOST'               => '',
    'SMTP_PORT'               => 587,
    'SMTP_SECURE'             => 'tls',
    'SMTP_USER'               => '',
    'SMTP_PASS'               => '',
    'MAIL_FROM'               => '',
    'MAIL_FROM_NAME'          => null,   // falls back to APP_NAME
    'REMINDER_COOLDOWN_HOURS' => 12,
    'AUTO_REMINDER_DAYS'      => 3,
];

$missing = [];
foreach ($config_defaults as $name => $value) {
    if (!defined($name)) {
        define($name, $value ?? APP_NAME);
        $missing[] = $name;
    }
}
date_default_timezone_set(TIMEZONE);   // so log times are local

// Note it in the log once (not on every page load), until config.php changes
$note = $missing ? 'config.php is missing ' . implode(', ', $missing) . '. Using default values for now.' : '';
$marker = __DIR__ . '/../storage/config_warning.txt';
if ($note !== '' && @file_get_contents($marker) !== $note) {
    logError($note);
    @file_put_contents($marker, $note);
} elseif ($note === '' && is_file($marker)) {
    @unlink($marker);
}
unset($config_defaults, $missing, $name, $value, $note, $marker);

<?php
require_once __DIR__ . '/errors.php';            // friendly error page instead of "Fatal error"
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/config_defaults.php';   // fills in any setting missing from config.php

date_default_timezone_set(TIMEZONE);

// Database problems become exceptions instead of silent failures
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// If this fails, errors.php shows what to check (is MySQL running, are the details right)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '" . date('P') . "'");   // MySQL's clock matches PHP's

// First run: create the database and tables
$conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db(DB_NAME);

if ($conn->query("SHOW TABLES LIKE 'reminders'")->num_rows === 0) {
    $conn->multi_query(file_get_contents(__DIR__ . '/schema.sql'));
    do {
        if ($result = $conn->store_result()) $result->free();
    } while ($conn->more_results() && $conn->next_result());
}

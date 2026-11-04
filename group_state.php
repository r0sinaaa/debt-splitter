<?php
// Polled by assets/live.js every few seconds (AJAX). Returns a small JSON
// fingerprint of the group; when it changes, the page fetches the new version
// of the group and swaps in the updated sections without a full reload.
require 'includes/app.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = currentUser();
$group = $user ? getOwnedGroup((int) ($_GET['id'] ?? 0), $user['id']) : null;

if ($group === null) {
    http_response_code($user ? 404 : 401);
    echo json_encode(['error' => $user ? 'not_found' : 'logged_out']);
    exit;
}

echo json_encode(['version' => groupVersion($group['id'])]);

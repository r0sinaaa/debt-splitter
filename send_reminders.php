<?php
// Automatic reminders. Run this on a schedule (see README.md) and everyone who
// still owes money gets a reminder every AUTO_REMINDER_DAYS days until it's paid.
// Only people who owe are contacted.
//
//     php send_reminders.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/app.php';

$groups = $conn->query("SELECT id, owner_id, name, settle_mode FROM expense_groups ORDER BY id")->fetch_all(MYSQLI_ASSOC);

$total = 0;
foreach ($groups as $group) {
    $group['id'] = (int) $group['id'];
    $group['owner_id'] = (int) $group['owner_id'];

    foreach (sendReminders($group, null, true) as $r) {
        $done = array_merge($r['sent'], $r['logged']);
        if ($done) {
            $total++;
            echo "{$group['name']}: emailed {$r['name']}"
               . ($r['logged'] ? ' (saved to storage/outbox.log, email isn\'t set up yet)' : '') . "\n";
        }
        foreach ($r['failed'] as $why) {
            echo "{$group['name']}: couldn't email {$r['name']}. $why\n";
        }
    }
}

echo $total === 0 ? "Nobody was due a reminder.\n" : "Done: $total " . ($total === 1 ? 'person' : 'people') . " reminded.\n";

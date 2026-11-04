<?php
// Payment reminders. Only people who owe money are ever contacted;
// people who are owed money never get a message.

// Which reminders count as "reminded": once email is set up, only emails that really
// went out. Before that, the ones saved to the outbox count, so testing still works.
function countedStatuses(): string {
    return emailConfigured() ? "('sent')" : "('sent', 'logged')";
}

// When each person in the group was last reminded: [member_id => 'Y-m-d H:i:s']
function lastReminders(int $group_id): array {
    global $conn;
    $stmt = $conn->prepare("SELECT member_id, MAX(sent_at) AS last_sent FROM reminders
                            WHERE group_id = ? AND status IN " . countedStatuses() . "
                            GROUP BY member_id");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $last = [];
    while ($row = $result->fetch_assoc()) {
        $last[(int) $row['member_id']] = $row['last_sent'];
    }
    return $last;
}

function logReminder(int $group_id, int $member_id, string $channel, float $amount, string $status, ?string $error): void {
    global $conn;
    $error = $error === null ? null : substr($error, 0, 255);
    $stmt = $conn->prepare("INSERT INTO reminders (group_id, member_id, channel, amount, status, error) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisdss", $group_id, $member_id, $channel, $amount, $status, $error);
    $stmt->execute();
}

// "Niraj €10.00 and Mia €4.00"
function describePayments(array $payments, array $members): string {
    $parts = [];
    foreach ($payments as $to => $amount) {
        $parts[] = ($members[$to]['name'] ?? 'someone') . ' ' . money($amount);
    }
    return listNames($parts);
}

// Returns [subject, body]
function reminderEmail(string $debtor, string $sender, string $group_name, array $debt, array $members): array {
    $subject = 'Reminder: you owe ' . money($debt['total']) . " for $group_name";

    $lines = '';
    foreach ($debt['payments'] as $to => $amount) {
        $lines .= '    ' . str_pad($members[$to]['name'] ?? 'Someone', 20) . money($amount) . "\n";
    }
    if (count($debt['payments']) > 1) {
        $lines .= "\n    " . str_pad('Total', 20) . money($debt['total']) . "\n";
    }

    $body = "Hi $debtor,\n\n"
          . "This is a reminder from $sender about $group_name. Here's what you still need to pay:\n\n"
          . $lines . "\n"
          . "When you've paid, let $sender know so it can be marked as paid.\n\n"
          . "Sent with " . APP_NAME . "\n";

    return [$subject, $body];
}

// Emails the people who owe money in a group.
//   $only_member_id  remind just this person; null = everyone who owes
//   $automatic       true when send_reminders.php runs on a schedule. It waits
//                    AUTO_REMINDER_DAYS between reminders instead of REMINDER_COOLDOWN_HOURS.
// Returns one result per person who owes:
//   ['name', 'sent' => ['email'], 'logged' => ['email'], 'failed' => ['email' => why],
//    'skipped' => null | 'self' | 'no_email' | 'recent', 'last' => datetime]
function sendReminders(array $group, ?int $only_member_id = null, bool $automatic = false): array {
    global $conn;

    $stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $group['owner_id']);
    $stmt->execute();
    $owner = $stmt->get_result()->fetch_assoc();

    $members = getMembers($group['id']);
    $debtors = getDebtors(getSettlement($group));
    $last = lastReminders($group['id']);
    $wait_seconds = ($automatic ? AUTO_REMINDER_DAYS * 24 : REMINDER_COOLDOWN_HOURS) * 3600;

    $results = [];
    foreach ($debtors as $member_id => $debt) {
        if ($only_member_id !== null && $member_id !== $only_member_id) continue;
        $member = $members[$member_id] ?? null;
        if ($member === null) continue;

        $result = ['name' => $member['name'], 'sent' => [], 'logged' => [], 'failed' => [], 'skipped' => null, 'last' => $last[$member_id] ?? null];

        if ($member['user_id'] === $group['owner_id']) {
            $result['skipped'] = 'self';   // never remind yourself
        } elseif (!$member['email']) {
            $result['skipped'] = 'no_email';
        } elseif (isset($last[$member_id]) && strtotime($last[$member_id]) > time() - $wait_seconds) {
            $result['skipped'] = 'recent';
        } else {
            [$subject, $body] = reminderEmail($member['name'], $owner['name'], $group['name'], $debt, $members);
            [$status, $error] = sendEmail($member['email'], $member['name'], $subject, $body, $owner);
            logReminder($group['id'], $member_id, 'email', $debt['total'], $status, $error);
            @file_put_contents(__DIR__ . '/../storage/reminders.log',
                '[' . date('Y-m-d H:i:s') . "] to {$member['name']} <{$member['email']}>: $status" . ($error ? " ($error)" : '') . "\n",
                FILE_APPEND | LOCK_EX);

            if ($status === 'failed') {
                $result['failed']['email'] = $error;
            } else {
                $result[$status][] = 'email';
            }
        }
        $results[] = $result;
    }
    return $results;
}

// Turns sendReminders() results into a short message for the page. Returns [text, 'good' | 'bad']
function describeReminderResults(array $results): array {
    $lines = [];
    $any_ok = false;
    $logged = [];

    foreach ($results as $r) {
        $name = $r['name'];
        if ($r['sent']) {
            $any_ok = true;
            $lines[] = "Emailed a reminder to $name.";
        }
        if ($r['logged']) {
            $any_ok = true;
            $logged[] = $name;
        }
        foreach ($r['failed'] as $why) {
            $lines[] = "Couldn't email $name. $why";
        }
        switch ($r['skipped']) {
            case 'recent':
                $lines[] = "$name was already reminded " . timeAgo($r['last']) . '. You can remind them again '
                         . REMINDER_COOLDOWN_HOURS . ' hours after the last one.';
                break;
            case 'no_email':
                $lines[] = "$name has no email address yet. Add one under People.";
                break;
        }
    }

    if ($logged) {
        $lines[] = 'Email isn\'t set up yet, so the reminder for ' . listNames($logged)
                 . ' was saved to storage/outbox.log. Add your email settings in config.php to send it for real.';
    }
    if (!$lines) {
        return ['Nobody needs a reminder right now.', 'good'];
    }
    return [implode("\n", $lines), $any_ok ? 'good' : 'bad'];
}

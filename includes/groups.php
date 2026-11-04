<?php
// Groups, the people in them, and adding expenses

// A group, but only if it belongs to this user (null otherwise)
function getOwnedGroup(int $group_id, int $user_id): ?array {
    global $conn;
    $stmt = $conn->prepare("SELECT id, owner_id, name, settle_mode FROM expense_groups WHERE id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    if (!$group) return null;

    $group['id'] = (int) $group['id'];
    $group['owner_id'] = (int) $group['owner_id'];
    return $group;
}

// Every group this user owns, newest first, with how many people are in each
function getUserGroups(int $user_id): array {
    global $conn;
    $stmt = $conn->prepare("SELECT g.id, g.name, g.settle_mode, COUNT(m.id) AS people
                            FROM expense_groups g
                            LEFT JOIN members m ON m.group_id = g.id
                            WHERE g.owner_id = ?
                            GROUP BY g.id, g.name, g.settle_mode
                            ORDER BY g.id DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Everyone in the group, in the order they were added:
// [member_id => ['id', 'name', 'email', 'user_id']]
function getMembers(int $group_id): array {
    global $conn;
    $stmt = $conn->prepare("SELECT id, user_id, name, email FROM members WHERE group_id = ? ORDER BY id");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $members = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] === null ? null : (int) $row['user_id'];
        $members[$row['id']] = $row;
    }
    return $members;
}

// The member row that is the logged-in user themselves
function ownMemberId(array $members, int $user_id): ?int {
    foreach ($members as $id => $member) {
        if ($member['user_id'] === $user_id) return $id;
    }
    return null;
}

function nameTaken(array $members, string $name, ?int $except_id = null): bool {
    foreach ($members as $id => $member) {
        if ($id !== $except_id && strcasecmp($member['name'], $name) === 0) return true;
    }
    return false;
}

// Makes a group and adds its owner as the first person in it
function createGroup(array $user, string $name): int {
    global $conn;
    $conn->begin_transaction();

    $stmt = $conn->prepare("INSERT INTO expense_groups (owner_id, name) VALUES (?, ?)");
    $stmt->bind_param("is", $user['id'], $name);
    $stmt->execute();
    $group_id = $conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO members (group_id, user_id, name, email) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $group_id, $user['id'], $user['name'], $user['email']);
    $stmt->execute();

    $conn->commit();
    return $group_id;
}

function renameGroup(int $group_id, string $name): void {
    global $conn;
    $stmt = $conn->prepare("UPDATE expense_groups SET name = ? WHERE id = ?");
    $stmt->bind_param("si", $name, $group_id);
    $stmt->execute();
}

function setSettleMode(int $group_id, string $mode): void {
    global $conn;
    $mode = $mode === 'shared' ? 'shared' : 'fewest';
    $stmt = $conn->prepare("UPDATE expense_groups SET settle_mode = ? WHERE id = ?");
    $stmt->bind_param("si", $mode, $group_id);
    $stmt->execute();
}

// Removes the group and everything in it
function deleteGroup(int $group_id): void {
    global $conn;
    $conn->begin_transaction();
    foreach ([
        "DELETE FROM reminders WHERE group_id = ?",
        "DELETE es FROM expense_splits es JOIN expenses e ON e.id = es.expense_id WHERE e.group_id = ?",
        "DELETE FROM expenses WHERE group_id = ?",
        "DELETE FROM members WHERE group_id = ?",
        "DELETE FROM expense_groups WHERE id = ?",
    ] as $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
    }
    $conn->commit();
}

// $contact comes from checkContactFields()
function addMember(int $group_id, array $contact): void {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO members (group_id, name, email) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $group_id, $contact['name'], $contact['email']);
    $stmt->execute();
}

function updateMember(int $member_id, array $contact): void {
    global $conn;
    $stmt = $conn->prepare("UPDATE members SET name = ?, email = ? WHERE id = ?");
    $stmt->bind_param("ssi", $contact['name'], $contact['email'], $member_id);
    $stmt->execute();
}

// True if the person paid for, or shared in, any expense (then they can't be removed)
function memberHasExpenses(int $member_id): bool {
    global $conn;
    $stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM expenses WHERE payer_id = ?)
                                 + (SELECT COUNT(*) FROM expense_splits WHERE member_id = ?) AS uses");
    $stmt->bind_param("ii", $member_id, $member_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['uses'] > 0;
}

function removeMember(int $member_id): void {
    global $conn;
    $conn->begin_transaction();
    foreach (["DELETE FROM reminders WHERE member_id = ?", "DELETE FROM members WHERE id = ?"] as $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
    }
    $conn->commit();
}

// Saves an expense split equally between $split_ids.
// Works in cents so the shares always add up to exactly the amount paid
// (e.g. 10.00 between 3 people becomes 3.34 + 3.33 + 3.33).
// A payment back (someone settling what they owe) is saved the same way:
// the person paying is the "payer" and the person receiving it has the whole share.
function addExpense(int $group_id, int $payer_id, float $amount, string $description, array $split_ids, bool $is_payment = false): void {
    global $conn;
    $conn->begin_transaction();

    $payment_flag = $is_payment ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO expenses (group_id, payer_id, amount, description, is_payment) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iidsi", $group_id, $payer_id, $amount, $description, $payment_flag);
    $stmt->execute();
    insertSplits($conn->insert_id, $amount, $split_ids);

    $conn->commit();
}

// Shares an amount equally, in cents, between $split_ids
function insertSplits(int $expense_id, float $amount, array $split_ids): void {
    global $conn;
    $total_cents = (int) round($amount * 100);
    $people = count($split_ids);
    $base_cents = intdiv($total_cents, $people);
    $leftover_cents = $total_cents % $people;

    $stmt = $conn->prepare("INSERT INTO expense_splits (expense_id, member_id, share) VALUES (?, ?, ?)");
    foreach (array_values($split_ids) as $i => $member_id) {
        $share = ($base_cents + ($i < $leftover_cents ? 1 : 0)) / 100;
        $stmt->bind_param("iid", $expense_id, $member_id, $share);
        $stmt->execute();
    }
}

// One expense from this group, with who it was split between (null if it isn't in the group)
function getExpense(int $group_id, int $expense_id): ?array {
    global $conn;
    $stmt = $conn->prepare("SELECT id, payer_id, amount, description, is_payment, settled_at FROM expenses WHERE id = ? AND group_id = ?");
    $stmt->bind_param("ii", $expense_id, $group_id);
    $stmt->execute();
    $expense = $stmt->get_result()->fetch_assoc();
    if (!$expense) return null;

    $stmt = $conn->prepare("SELECT member_id FROM expense_splits WHERE expense_id = ?");
    $stmt->bind_param("i", $expense_id);
    $stmt->execute();
    $expense['split_ids'] = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'member_id'));
    return $expense;
}

// Changes an expense and works out the shares again
function updateExpense(int $expense_id, int $payer_id, float $amount, string $description, array $split_ids): void {
    global $conn;
    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE expenses SET payer_id = ?, amount = ?, description = ? WHERE id = ?");
    $stmt->bind_param("idsi", $payer_id, $amount, $description, $expense_id);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM expense_splits WHERE expense_id = ?");
    $stmt->bind_param("i", $expense_id);
    $stmt->execute();
    insertSplits($expense_id, $amount, $split_ids);

    $conn->commit();
}

function deleteExpense(int $expense_id): void {
    global $conn;
    $conn->begin_transaction();
    foreach (["DELETE FROM expense_splits WHERE expense_id = ?", "DELETE FROM expenses WHERE id = ?"] as $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $expense_id);
        $stmt->execute();
    }
    $conn->commit();
}

// True if the exact same expense was added in the last 30 seconds (a double click)
function isRecentDuplicate(int $group_id, int $payer_id, float $amount, string $description): bool {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM expenses
                            WHERE group_id = ? AND payer_id = ? AND amount = ? AND description = ? AND is_payment = 0
                              AND created_at > NOW() - INTERVAL 30 SECOND");
    $stmt->bind_param("iids", $group_id, $payer_id, $amount, $description);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['n'] > 0;
}

// A short fingerprint of everything shown on the group page. It changes whenever
// anything in the group changes (people, expenses, edits, payments, settling up,
// reminders), so the page can poll for it and refresh only when needed.
function groupVersion(int $group_id): string {
    global $conn;
    $stmt = $conn->prepare("SELECT
        (SELECT CONCAT_WS('|', name, settle_mode) FROM expense_groups WHERE id = ?) AS g,
        (SELECT CONCAT_WS('|', COUNT(*), BIT_XOR(CRC32(CONCAT_WS(',', id, name, IFNULL(email, '')))))
            FROM members WHERE group_id = ?) AS m,
        (SELECT CONCAT_WS('|', COUNT(*), BIT_XOR(CRC32(CONCAT_WS(',', id, payer_id, amount, description, IFNULL(settled_at, '')))))
            FROM expenses WHERE group_id = ?) AS e,
        (SELECT CONCAT_WS('|', COUNT(*), BIT_XOR(CRC32(CONCAT_WS(',', es.expense_id, es.member_id, es.share))))
            FROM expense_splits es JOIN expenses x ON x.id = es.expense_id WHERE x.group_id = ?) AS s,
        (SELECT CONCAT_WS('|', COUNT(*), IFNULL(MAX(id), 0)) FROM reminders WHERE group_id = ?) AS r");
    $stmt->bind_param("iiiii", $group_id, $group_id, $group_id, $group_id, $group_id);
    $stmt->execute();
    return substr(sha1(implode('#', $stmt->get_result()->fetch_row())), 0, 16);
}

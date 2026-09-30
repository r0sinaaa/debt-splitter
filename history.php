<?php
// Expense history and settling up.
// Once everyone has paid, the current expenses are marked as settled. They stay
// in the history, but the settlement only counts expenses added after that.

// Adds the settled_at column the first time the page loads, so there's no
// manual database step. NULL means the expense hasn't been settled yet.
function ensureSettledColumn(): void {
    global $conn;
    $result = $conn->query("SHOW COLUMNS FROM expenses LIKE 'settled_at'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE expenses ADD COLUMN settled_at DATETIME NULL DEFAULT NULL");
    }
}

// Marks every unsettled expense in the group as settled, all with the same time,
// so they appear together as one settled round in the history.
function settleUp(int $group_id): void {
    global $conn;
    $stmt = $conn->prepare("UPDATE expenses SET settled_at = NOW() WHERE group_id = ? AND settled_at IS NULL");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
}

// Every expense in the group, newest first, with the share each person owes.
// Returns ['open' => [id => expense, ...], 'settled' => [settled_at => [id => expense, ...], ...]]
function getExpenseHistory(int $group_id): array {
    global $conn;
    $sql = "SELECT e.id, e.payer_id, e.amount, e.description, e.settled_at, es.member_id, es.share
            FROM expenses e
            LEFT JOIN expense_splits es ON e.id = es.expense_id
            WHERE e.group_id = ?
            ORDER BY e.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $expenses = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        if (!isset($expenses[$id])) {
            $expenses[$id] = [
                'payer_id'    => (int) $row['payer_id'],
                'amount'      => (float) $row['amount'],
                'description' => $row['description'],
                'settled_at'  => $row['settled_at'],
                'shares'      => [],
            ];
        }
        if ($row['member_id'] !== null) {
            $expenses[$id]['shares'][(int) $row['member_id']] = (float) $row['share'];
        }
    }

    $history = ['open' => [], 'settled' => []];
    foreach ($expenses as $id => $expense) {
        if ($expense['settled_at'] === null) {
            $history['open'][$id] = $expense;
        } else {
            $history['settled'][$expense['settled_at']][$id] = $expense;
        }
    }
    krsort($history['settled']);   // most recent settle-up first
    return $history;
}

// One line describing an expense, e.g. "Niraj paid · split between everyone (€10.00 each)"
function describeExpense(array $expense, array $members): string {
    $payer = $members[$expense['payer_id']] ?? 'Someone';
    $shares = $expense['shares'];
    if (empty($shares)) {
        return "$payer paid";
    }

    if (count($shares) === 1) {
        $only = array_key_first($shares);
        return $only === $expense['payer_id']
            ? "$payer paid for themselves"
            : "$payer paid for " . ($members[$only] ?? 'someone');
    }

    // Names in the same order as the member list
    $names = [];
    foreach ($members as $id => $name) {
        if (isset($shares[$id])) $names[] = $name;
    }
    $who = count($shares) === count($members) && count($names) === count($members)
        ? 'everyone'
        : implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);

    $low = min($shares);
    $high = max($shares);
    $each = $low == $high
        ? '€' . number_format($low, 2) . ' each'
        : '€' . number_format($low, 2) . '–€' . number_format($high, 2) . ' each';

    return "$payer paid · split between $who ($each)";
}
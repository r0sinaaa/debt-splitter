<?php
// Expense history and settling up.
// "Mark everything as paid" marks the current expenses as settled. They stay
// in the history, but the settlement only counts expenses added after that.

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
    $sql = "SELECT e.id, e.payer_id, e.amount, e.description, e.is_payment, e.created_at, e.settled_at,
                   es.member_id, es.share
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
                'is_payment'  => (bool) $row['is_payment'],
                'created_at'  => $row['created_at'],
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

// One line describing an expense, e.g. "Niraj paid, split between everyone (€10.00 each)"
// $members is [id => name]
function describeExpense(array $expense, array $members): string {
    $payer = $members[$expense['payer_id']] ?? 'Someone';
    $shares = $expense['shares'];

    if ($expense['is_payment']) {
        $to = $members[array_key_first($shares)] ?? 'someone';
        return "$payer paid $to back";
    }
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
    $who = count($shares) === count($members) ? 'everyone' : listNames($names);

    $low = min($shares);
    $high = max($shares);
    $each = $low == $high
        ? money($low) . ' each'
        : money($low) . ' to ' . money($high) . ' each';

    return "$payer paid, split between $who ($each)";
}

<?php
require 'db.php';

function getGroupBalances($group_id, $unsettled_only = false) {
    global $conn;
    $balances = [];

    $sql = "SELECT e.payer_id, e.amount, es.member_id, es.share
            FROM expenses e
            JOIN expense_splits es ON e.id = es.expense_id
            WHERE e.group_id = ?";
    // The app passes true so expenses from earlier settle-ups are left out (see history.php)
    if ($unsettled_only) {
        $sql .= " AND e.settled_at IS NULL";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $payer = $row['payer_id'];
        $member = $row['member_id'];
        $share = $row['share'];

        if (!isset($balances[$payer])) $balances[$payer] = 0;
        if (!isset($balances[$member])) $balances[$member] = 0;

        $balances[$payer] += $share;
        $balances[$member] -= $share;
    }

    return $balances;
}
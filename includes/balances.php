<?php
// Each person's balance in a group, read off the debt graph: what their
// incoming edges bring in minus what their outgoing edges take out.
// Positive = they are owed money, negative = they owe.
// Pass $unsettled_only = true to leave out expenses from earlier settle-ups.
function getGroupBalances(int $group_id, bool $unsettled_only = false): array {
    $balances = [];
    foreach (netPositions(loadDebtGraph($group_id, $unsettled_only)) as $id => $cents) {
        $balances[$id] = $cents / 100;
    }
    return $balances;
}

// How much money still has to change hands in the group
function groupOutstanding(int $group_id): float {
    $total = 0;
    foreach (getGroupBalances($group_id, true) as $balance) {
        if ($balance > 0.004) $total += $balance;
    }
    return round($total, 2);
}

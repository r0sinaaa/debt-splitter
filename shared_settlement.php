<?php
// Settlement where people only pay someone they have shared an expense with.
// Uses the same tables as balances.php. Amounts are handled in cents so nothing
// gets lost to rounding.

function pairKey(int $a, int $b): string {
    return min($a, $b) . '-' . max($a, $b);
}

// Who owes whom directly, from each expense's payer and the people it was split with.
// Returns [$debts, $shared]:
//   $debts[$debtor][$creditor] = cents owed, after netting each pair against each other
//   $shared['1-4'] = true when those two people have been on an expense together
function getPairwiseDebts(int $group_id): array {
    global $conn;

    $sql = "SELECT e.payer_id, es.member_id, es.share
            FROM expenses e
            JOIN expense_splits es ON e.id = es.expense_id
            WHERE e.group_id = ? AND e.settled_at IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $owes = [];
    $shared = [];
    while ($row = $result->fetch_assoc()) {
        $payer = (int) $row['payer_id'];
        $member = (int) $row['member_id'];
        $cents = (int) round($row['share'] * 100);
        if ($payer === $member || $cents <= 0) continue;   // your own share isn't a debt

        $owes[$member][$payer] = ($owes[$member][$payer] ?? 0) + $cents;
        $shared[pairKey($member, $payer)] = true;
    }

    // If A owes B 10 and B owes A 4, that's just A owes B 6
    $debts = [];
    foreach ($owes as $a => $row) {
        foreach ($row as $b => $amount) {
            $back = $owes[$b][$a] ?? 0;
            if ($amount > $back) {
                $debts[$a][$b] = $amount - $back;
            }
        }
    }
    return [$debts, $shared];
}

// Add a debt from -> to, cancelling against any debt going the other way
function addDebt(array &$debts, int $from, int $to, int $cents): void {
    $back = $debts[$to][$from] ?? 0;
    if ($back > $cents) {
        $debts[$to][$from] = $back - $cents;
    } else {
        unset($debts[$to][$from]);
        if ($cents > $back) {
            $debts[$from][$to] = ($debts[$from][$to] ?? 0) + $cents - $back;
        }
    }
    if (empty($debts[$to])) unset($debts[$to]);
}

function reduceDebt(array &$debts, int $from, int $to, int $cents): void {
    $debts[$from][$to] -= $cents;
    if ($debts[$from][$to] <= 0) unset($debts[$from][$to]);
    if (empty($debts[$from])) unset($debts[$from]);
}

// Find a loop of debts (A owes B, B owes C, C owes A). Returns the people in order, or null.
function findDebtCycle(array $debts): ?array {
    $state = [];   // 1 = on the current path, 2 = finished
    $path = [];

    $visit = function (int $v) use (&$visit, &$state, &$path, $debts): ?array {
        $state[$v] = 1;
        $path[] = $v;
        foreach ($debts[$v] ?? [] as $w => $amount) {
            $s = $state[$w] ?? 0;
            if ($s === 1) {
                return array_slice($path, array_search($w, $path));
            }
            if ($s === 0) {
                $cycle = $visit($w);
                if ($cycle !== null) return $cycle;
            }
        }
        array_pop($path);
        $state[$v] = 2;
        return null;
    };

    foreach (array_keys($debts) as $v) {
        if (($state[$v] ?? 0) === 0) {
            $cycle = $visit($v);
            if ($cycle !== null) return $cycle;
        }
    }
    return null;
}

// Approach 1: tidy up the direct debts without ever asking someone to pay a
// person they haven't shared an expense with. Two moves, repeated until neither applies:
//   1. Loops cancel out: if A owes B, B owes C and C owes A, everyone's debt
//      around the loop drops by the smallest amount in it.
//   2. Chains shorten: if A owes B and B owes C, A can pay C directly,
//      but only if A and C have shared an expense.
// Each move lowers the total amount being passed around, so it always finishes,
// and nobody's overall balance changes.
function cancelLoopsAndShortenChains(array $debts, array $shared): array {
    $changed = true;
    while ($changed) {
        $changed = false;

        while (($cycle = findDebtCycle($debts)) !== null) {
            $n = count($cycle);
            $smallest = PHP_INT_MAX;
            for ($i = 0; $i < $n; $i++) {
                $smallest = min($smallest, $debts[$cycle[$i]][$cycle[($i + 1) % $n]]);
            }
            for ($i = 0; $i < $n; $i++) {
                reduceDebt($debts, $cycle[$i], $cycle[($i + 1) % $n], $smallest);
            }
            $changed = true;
        }

        foreach ($debts as $a => $outgoing) {
            foreach ($outgoing as $b => $ab) {
                foreach ($debts[$b] ?? [] as $c => $bc) {
                    if ($c === $a || !isset($shared[pairKey($a, $c)])) continue;
                    $move = min($ab, $bc);
                    reduceDebt($debts, $a, $b, $move);
                    reduceDebt($debts, $b, $c, $move);
                    addDebt($debts, $a, $c, $move);
                    $changed = true;
                    break 3;   // the debts changed, so start the checks again
                }
            }
        }
    }
    return $debts;
}

// Each person's overall balance in cents (positive = they are owed money)
function netBalances(array $debts): array {
    $balances = [];
    foreach ($debts as $from => $outgoing) {
        foreach ($outgoing as $to => $cents) {
            $balances[$from] = ($balances[$from] ?? 0) - $cents;
            $balances[$to] = ($balances[$to] ?? 0) + $cents;
        }
    }
    return $balances;
}

// Approach 2: the same largest-creditor/largest-debtor idea as greedySimplify(),
// but a debtor can only pay a creditor they've shared an expense with. If the
// nearest debtor isn't directly connected, the money is passed along a short
// chain of people who have shared expenses with each other.
function routeThroughSharedPeople(array $balances, array $shared): array {
    $neighbours = [];
    foreach (array_keys($shared) as $key) {
        [$a, $b] = array_map('intval', explode('-', $key));
        $neighbours[$a][] = $b;
        $neighbours[$b][] = $a;
    }

    $debts = [];
    $stuck = [];   // safety net: creditors with nobody left to reach
    while (true) {
        $creditors = array_filter($balances, fn($v) => $v > 0);
        foreach ($stuck as $c => $_) unset($creditors[$c]);
        if (empty($creditors)) break;
        arsort($creditors);
        $creditor = array_key_first($creditors);

        // Breadth-first search out from the creditor through shared connections
        $cameFrom = [$creditor => null];
        $distance = [$creditor => 0];
        $queue = [$creditor];
        while ($queue) {
            $v = array_shift($queue);
            foreach ($neighbours[$v] ?? [] as $w) {
                if (!array_key_exists($w, $cameFrom)) {
                    $cameFrom[$w] = $v;
                    $distance[$w] = $distance[$v] + 1;
                    $queue[] = $w;
                }
            }
        }

        // Prefer the closest debtor, then the one who owes the most
        $debtor = null;
        foreach ($distance as $v => $d) {
            if (($balances[$v] ?? 0) >= 0) continue;
            if ($debtor === null || $d < $distance[$debtor]
                || ($d === $distance[$debtor] && $balances[$v] < $balances[$debtor])) {
                $debtor = $v;
            }
        }
        if ($debtor === null) { $stuck[$creditor] = true; continue; }

        $amount = min($balances[$creditor], -$balances[$debtor]);
        for ($v = $debtor; $cameFrom[$v] !== null; $v = $cameFrom[$v]) {
            addDebt($debts, $v, $cameFrom[$v], $amount);   // each hop is one payment
        }
        $balances[$creditor] -= $amount;
        $balances[$debtor] += $amount;
    }
    return $debts;
}

function countPayments(array $debts): int {
    return array_sum(array_map('count', $debts));
}

// Settlement where nobody pays someone they haven't shared an expense with.
// Tries both approaches and keeps whichever needs fewer payments.
function simplifySharedOnly(array $debts, array $shared): array {
    $direct = cancelLoopsAndShortenChains($debts, $shared);
    $routed = cancelLoopsAndShortenChains(routeThroughSharedPeople(netBalances($debts), $shared), $shared);
    $best = countPayments($routed) < countPayments($direct) ? $routed : $direct;

    // Same format as greedySimplify(), largest payments first
    $txns = [];
    foreach ($best as $from => $outgoing) {
        foreach ($outgoing as $to => $cents) {
            $txns[] = ['from' => $from, 'to' => $to, 'amount' => $cents / 100];
        }
    }
    usort($txns, fn($x, $y) => $y['amount'] <=> $x['amount']);
    return $txns;
}
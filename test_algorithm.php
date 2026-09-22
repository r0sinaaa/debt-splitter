<?php
function greedySimplify(array $balances): array {
    $bal = $balances;
    $txns = [];
    while (true) {
        $creditor = array_keys($bal, max($bal))[0];
        $debtor   = array_keys($bal, min($bal))[0];
        if ($bal[$creditor] < 0.01 || $bal[$debtor] > -0.01) break;
        $amt = min($bal[$creditor], -$bal[$debtor]);
        if ($amt < 0.01) break;
        $txns[] = ['from' => $debtor, 'to' => $creditor, 'amount' => round($amt, 2)];
        $bal[$creditor] -= $amt;
        $bal[$debtor]   += $amt;
    }
    return $txns;
}

$balances = ['A' => 30, 'B' => -10, 'C' => -20];
print_r(greedySimplify($balances));
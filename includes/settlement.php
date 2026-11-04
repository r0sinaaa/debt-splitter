<?php
// Who pays whom. Every method takes the group's debt graph (debt_graph.php)
// and returns a settlement graph with the same net position at every node.

// Which method "Fewest payments" uses for this graph: the exact optimum when
// few enough people are not even, otherwise the greedy heuristic
function fewestMethod(array $g): string {
    $not_even = count(array_filter(netPositions($g)));
    return $not_even <= OPTIMAL_MAX_PEOPLE ? 'optimal' : 'greedy';
}

// Settles the group's debt graph using the group's chosen way of settling:
//   'fewest' = optimalSettle() (optimal.php) for up to OPTIMAL_MAX_PEOPLE people,
//              otherwise greedySettle() (algorithm.php)
//   'shared' = sharedSettle() (shared_settlement.php)
// Returns ['graph' => debt graph, 'settlement' => settlement graph,
//          'transactions' => [['from', 'to', 'amount'], ...], 'method' => 'optimal' | 'greedy' | 'shared' | 'none']
function getSettlementDetails(array $group): array {
    $graph = loadDebtGraph($group['id']);
    if (!$graph['nodes']) {
        return ['graph' => $graph, 'settlement' => $graph, 'transactions' => [], 'method' => 'none'];
    }

    if ($group['settle_mode'] === 'shared') {
        $method = 'shared';
        $settlement = sharedSettle($graph);
    } else {
        $method = fewestMethod($graph);
        $settlement = $method === 'optimal' ? optimalSettle($graph) : greedySettle($graph);
    }
    return [
        'graph'        => $graph,
        'settlement'   => $settlement,
        'transactions' => graphPayments($settlement),
        'method'       => $method,
    ];
}

function getSettlement(array $group): array {
    return getSettlementDetails($group)['transactions'];
}

// The people who owe money, and who they have to pay:
// [member_id => ['total' => float, 'payments' => [to_member_id => amount, ...]]]
function getDebtors(array $transactions): array {
    $debtors = [];
    foreach ($transactions as $t) {
        $from = (int) $t['from'];
        $to = (int) $t['to'];
        $debtors[$from]['total'] = round(($debtors[$from]['total'] ?? 0) + $t['amount'], 2);
        $debtors[$from]['payments'][$to] = round(($debtors[$from]['payments'][$to] ?? 0) + $t['amount'], 2);
    }
    return $debtors;
}

<?php
// Greedy settlement of a debt graph ("Fewest payments" for big groups).
//
// 1. Collapse the debt graph to each node's net position (netPositions):
//    money in on incoming edges minus money out on outgoing edges.
// 2. Build a new graph on the same nodes, one edge at a time: an edge from the
//    node that owes the most to the node that is owed the most, worth the
//    smaller of the two amounts.
//
// Every new edge brings at least one of its two nodes to zero, and a node at
// zero never gets another edge. So the settlement graph has no cycles (it is a
// forest) and at most (people not even - 1) edges. It is fast, but it never
// looks for smaller subgroups whose debts cancel out, so it can use more edges
// than necessary (see optimal.php).
//
// Everything runs in whole cents (integers). Doing it with decimals let tiny
// rounding errors build up, and a real 1-cent debt could come out as
// 0.00999... and get dropped.

// $g: a debt graph (debt_graph.php). Returns the settlement graph.
function greedySettle(array $g): array {
    $settlement = newDebtGraph(array_keys($g['nodes']));
    addGreedyEdges($settlement, netPositions($g));
    return $settlement;
}

// The greedy rule itself. $positions: [node => cents], adding up to zero.
// Adds the payment edges to $settlement.
function addGreedyEdges(array &$settlement, array $positions): void {
    while ($positions) {
        $creditor = array_keys($positions, max($positions))[0];
        $debtor   = array_keys($positions, min($positions))[0];
        if ($positions[$creditor] <= 0 || $positions[$debtor] >= 0) break;   // everyone is even

        $amount = min($positions[$creditor], -$positions[$debtor]);
        addDebtEdge($settlement, $debtor, $creditor, $amount);
        $positions[$creditor] -= $amount;
        $positions[$debtor]   += $amount;
    }
}

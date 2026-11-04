<?php
// The debt graph: the data structure every settlement method works on.
//
// A group's debts are a directed, weighted graph:
//   - every member is a node
//   - every debt is an edge from the person who owes to the person they owe,
//     weighted by the amount in whole cents
// Between two people there is at most one edge. If A owes B €10 and B owes
// A €4, the graph holds a single edge A -> B worth €6.
//
// A graph is a plain array:
//   'nodes'  => [member_id => true, ...]
//   'edges'  => [from_id => [to_id => cents, ...], ...]   (adjacency list, cents > 0)
//   'shared' => ['1-4' => true, ...]   pairs who have been on an expense together
//
// A settlement is a debt graph too: the same nodes, with one edge per payment.
// It is correct when every node keeps the same net position as in the
// original graph, so each person ends up exactly where all the original debts
// would have left them. The number of edges is the number of payments.

function pairKey(int|string $a, int|string $b): string {
    return $a < $b ? "$a-$b" : "$b-$a";
}

function newDebtGraph(array $nodes = []): array {
    $g = ['nodes' => [], 'edges' => [], 'shared' => []];
    foreach ($nodes as $v) $g['nodes'][$v] = true;
    return $g;
}

// Builds the debt graph from expense shares. Each share is [payer, member, cents]:
// the member owes the payer that many cents. Shares people pay for themselves
// are not debts, so they add a node but no edge.
function buildDebtGraph(iterable $shares, array $nodes = []): array {
    $g = newDebtGraph($nodes);
    $owes = [];
    foreach ($shares as [$payer, $member, $cents]) {
        $g['nodes'][$payer] = true;
        $g['nodes'][$member] = true;
        if ($payer === $member || $cents <= 0) continue;
        $owes[$member][$payer] = ($owes[$member][$payer] ?? 0) + $cents;
        $g['shared'][pairKey($member, $payer)] = true;
    }

    // Net each pair of opposite edges into one: A -> B 10 and B -> A 4 become A -> B 6
    foreach ($owes as $a => $row) {
        foreach ($row as $b => $amount) {
            $back = $owes[$b][$a] ?? 0;
            if ($amount > $back) $g['edges'][$a][$b] = $amount - $back;
        }
    }
    return $g;
}

// The debt graph of a group, built from its expenses in the database.
// By default only expenses since the last "Mark everything as paid" count.
function loadDebtGraph(int $group_id, bool $unsettled_only = true): array {
    global $conn;
    $sql = "SELECT e.payer_id, es.member_id, es.share
            FROM expenses e
            JOIN expense_splits es ON e.id = es.expense_id
            WHERE e.group_id = ?";
    if ($unsettled_only) {
        $sql .= " AND e.settled_at IS NULL";
    }
    $sql .= " ORDER BY es.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $shares = [];
    while ($row = $result->fetch_assoc()) {
        $shares[] = [(int) $row['payer_id'], (int) $row['member_id'], (int) round($row['share'] * 100)];
    }
    $g = buildDebtGraph($shares);
    ksort($g['nodes']);
    return $g;
}

// Adds a debt to a graph, cancelling it against any edge going the other way
function addDebtEdge(array &$g, int|string $from, int|string $to, int $cents): void {
    if ($from === $to || $cents <= 0) return;
    $g['nodes'][$from] = true;
    $g['nodes'][$to] = true;

    $back = $g['edges'][$to][$from] ?? 0;
    if ($back > $cents) {
        $g['edges'][$to][$from] = $back - $cents;
        return;
    }
    unset($g['edges'][$to][$from]);
    if (empty($g['edges'][$to])) unset($g['edges'][$to]);
    if ($cents > $back) {
        $g['edges'][$from][$to] = ($g['edges'][$from][$to] ?? 0) + $cents - $back;
    }
}

// Number of edges = number of payments
function edgeCount(array $g): int {
    return array_sum(array_map('count', $g['edges']));
}

// Each node's net position in cents: what its incoming edges bring in minus
// what its outgoing edges take out. Positive = owed money, negative = owes.
// The positions of any debt graph add up to exactly zero.
function netPositions(array $g): array {
    $pos = array_fill_keys(array_keys($g['nodes']), 0);
    foreach ($g['edges'] as $from => $out) {
        foreach ($out as $to => $cents) {
            $pos[$from] -= $cents;
            $pos[$to] += $cents;
        }
    }
    return $pos;
}

// True when two graphs leave every node in the same net position, i.e. one of
// them settles exactly the debts in the other
function samePositions(array $a, array $b): bool {
    $pa = array_filter(netPositions($a));
    $pb = array_filter(netPositions($b));
    ksort($pa);
    ksort($pb);
    return $pa === $pb;
}

// Connected components, ignoring edge direction (breadth-first search).
// Nodes without any edge (people who are already even) are left out.
function connectedComponents(array $g): array {
    $adj = [];
    foreach ($g['edges'] as $a => $out) {
        foreach ($out as $b => $_) {
            $adj[$a][] = $b;
            $adj[$b][] = $a;
        }
    }

    $seen = [];
    $components = [];
    foreach (array_keys($adj) as $start) {
        if (isset($seen[$start])) continue;
        $seen[$start] = true;
        $queue = [$start];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($adj[$queue[$i]] as $w) {
                if (!isset($seen[$w])) {
                    $seen[$w] = true;
                    $queue[] = $w;
                }
            }
        }
        $components[] = $queue;
    }
    return $components;
}

// A graph is a forest (no cycles, ignoring direction) exactly when
// edges = nodes touched by an edge - connected components.
// Every settlement with the fewest payments is a forest: one tree per zero-sum subgroup.
function isForest(array $g): bool {
    $components = connectedComponents($g);
    $touched = array_sum(array_map('count', $components));
    return edgeCount($g) === $touched - count($components);
}

// The payments in a settlement graph, largest first, in euros for display:
// [['from' => member_id, 'to' => member_id, 'amount' => float], ...]
function graphPayments(array $g): array {
    $txns = [];
    foreach ($g['edges'] as $from => $out) {
        foreach ($out as $to => $cents) {
            $txns[] = ['from' => $from, 'to' => $to, 'amount' => $cents / 100];
        }
    }
    usort($txns, fn($x, $y) => $y['amount'] <=> $x['amount']);
    return $txns;
}

// A debt graph with the given balances (in euros, positive = owed money).
// Used by the tests and examples, which start from balances rather than
// expenses: each debtor gets edges to the creditors in turn.
function debtGraphFromBalances(array $balances): array {
    $g = newDebtGraph(array_keys($balances));
    $creditors = [];
    $debtors = [];
    foreach ($balances as $id => $amount) {
        $c = (int) round($amount * 100);
        if ($c > 0) $creditors[$id] = $c;
        if ($c < 0) $debtors[$id] = -$c;
    }
    foreach ($debtors as $d => $owed) {
        foreach ($creditors as $c => $left) {
            if ($owed === 0) break;
            $pay = min($owed, $left);
            if ($pay === 0) continue;
            addDebtEdge($g, $d, $c, $pay);
            $creditors[$c] -= $pay;
            $owed -= $pay;
        }
    }
    return $g;
}

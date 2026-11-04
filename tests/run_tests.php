<?php
// Tests for the money maths: the debt graph, the three settlement methods
// that work on it, and reading typed amounts. They don't need the database.
// Run from the project folder:
//
//     php tests/run_tests.php            (on XAMPP: C:\xampp\php\php.exe tests\run_tests.php)
//
// Besides a few hand-checked examples, each method gets thousands of random
// groups, and we check the result is always right: the settlement graph leaves
// every person in the same net position as the debt graph, so after everyone
// makes their payments, every single person ends at exactly 0.00.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const CURRENCY = '€';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/debt_graph.php';
require __DIR__ . '/../includes/algorithm.php';
require __DIR__ . '/../includes/optimal.php';
require __DIR__ . '/../includes/shared_settlement.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $why = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
        echo "FAIL  $name" . ($why !== '' ? "\n      $why" : '') . "\n";
    }
}

// A random group: 2-6 people and 1-8 expenses, each split equally (to the cent, like addExpense does).
// Returns [balances in cents worked out directly from the expenses,
//          the debt graph built by buildDebtGraph(),
//          the netted pairwise debts worked out separately by hand]
function randomGroup(): array {
    $people = range(1, mt_rand(2, 6));
    $cents = array_fill_keys($people, 0);
    $shares = [];
    $owes = [];

    for ($e = mt_rand(1, 8); $e > 0; $e--) {
        $payer = $people[array_rand($people)];
        $split = (array) array_rand(array_flip($people), mt_rand(1, count($people)));
        $total = mt_rand(1, 50000);
        $base = intdiv($total, count($split));
        $left = $total % count($split);

        foreach (array_values($split) as $i => $member) {
            $share = $base + ($i < $left ? 1 : 0);
            $shares[] = [$payer, $member, $share];
            $cents[$payer] += $share;
            $cents[$member] -= $share;
            if ($member !== $payer && $share > 0) $owes[$member][$payer] = ($owes[$member][$payer] ?? 0) + $share;
        }
    }

    // Net each pair by hand, to check buildDebtGraph() against
    $debts = [];
    foreach ($owes as $a => $row) {
        foreach ($row as $b => $amount) {
            $back = $owes[$b][$a] ?? 0;
            if ($amount > $back) $debts[$a][$b] = $amount - $back;
        }
    }
    return [$cents, buildDebtGraph($shares, $people), $debts];
}

// Balances made from whole-euro shares (like splitting a bill "€10 each"), in cents
function roundBalances(int $people): array {
    $cents = array_fill(0, $people, 0);
    for ($e = mt_rand(1, 8); $e > 0; $e--) {
        $payer = mt_rand(0, $people - 1);
        $share = 100 * [5, 10, 15, 20, 25][mt_rand(0, 4)];
        foreach ((array) array_rand(range(0, $people - 1), mt_rand(1, $people)) as $member) {
            $cents[$payer] += $share;
            $cents[$member] -= $share;
        }
    }
    return $cents;
}

// Applies the payments; returns what each person still has left (should be all zeros)
function leftAfterPaying(array $cents, array $txns): array {
    foreach ($txns as $t) {
        $c = (int) round($t['amount'] * 100);
        $cents[$t['from']] += $c;
        $cents[$t['to']] -= $c;
    }
    return array_filter($cents);
}

// True when every connected component of a settlement graph has net positions adding up to zero
function componentsAreZeroSum(array $settlement): bool {
    $pos = netPositions($settlement);
    foreach (connectedComponents($settlement) as $component) {
        if (array_sum(array_map(fn($v) => $pos[$v], $component)) !== 0) return false;
    }
    return true;
}

// ---- The debt graph (debt_graph.php) ---------------------------------------

// Ana (1) pays 30.00 for Ana, Ben (2) and Cy (3); Ben pays 12.00 for Ana and Ben.
// Ben owes Ana 10 and Ana owes Ben 6, so the graph has one edge Ben -> Ana worth 4.
$g = buildDebtGraph([[1, 1, 1000], [1, 2, 1000], [1, 3, 1000], [2, 1, 600], [2, 2, 600]]);
check('graph: opposite debts between two people become one edge',
    $g['edges'] == [2 => [1 => 400], 3 => [1 => 1000]], json_encode($g['edges']));
check('graph: net position = incoming minus outgoing edges',
    netPositions($g) === [1 => 1400, 2 => -400, 3 => -1000], json_encode(netPositions($g)));
check('graph: shared-expense pairs are recorded', array_keys($g['shared']) === ['1-2', '1-3']);

$g = newDebtGraph();
addDebtEdge($g, 'A', 'B', 1000);
addDebtEdge($g, 'B', 'A', 400);
check('graph: adding an opposite edge cancels against it', $g['edges'] === ['A' => ['B' => 600]], json_encode($g['edges']));
addDebtEdge($g, 'B', 'A', 600);
check('graph: an edge that cancels exactly disappears', $g['edges'] === [] && edgeCount($g) === 0, json_encode($g['edges']));

mt_srand(3);
$bad_positions = 0;
$bad_edges = 0;
$both_ways = 0;
for ($i = 0; $i < 5000; $i++) {
    [$cents, $g, $debts] = randomGroup();
    if (netPositions($g) !== $cents || array_sum(netPositions($g)) !== 0) $bad_positions++;
    if ($g['edges'] != $debts) $bad_edges++;   // same edges and weights (order doesn't matter)
    foreach ($g['edges'] as $a => $out) {
        foreach ($out as $b => $_) {
            if (isset($g['edges'][$b][$a])) $both_ways++;
        }
    }
}
check('graph: 5,000 random groups, net positions match the expenses and add up to zero', $bad_positions === 0, "$bad_positions groups were wrong");
check('graph: 5,000 random groups, edges match pairwise debts netted by hand', $bad_edges === 0, "$bad_edges groups were wrong");
check('graph: never an edge in both directions between two people', $both_ways === 0, "$both_ways pairs had two edges");

// ---- Fewest payments, greedy (greedySettle) ---------------------------------

$txns = graphPayments(greedySettle(debtGraphFromBalances(['A' => 30, 'B' => -10, 'C' => -20])));
check('greedy: simple example', $txns == [
    ['from' => 'C', 'to' => 'A', 'amount' => 20],
    ['from' => 'B', 'to' => 'A', 'amount' => 10],
], json_encode($txns));

check('greedy: nobody owes anything', edgeCount(greedySettle(debtGraphFromBalances(['A' => 0, 'B' => 0]))) === 0);
check('greedy: a single cent is not lost',
    graphPayments(greedySettle(debtGraphFromBalances(['A' => 0.01, 'B' => -0.01]))) == [['from' => 'B', 'to' => 'A', 'amount' => 0.01]]);

// The case the review found: decimals that don't add up exactly in binary
$txns = graphPayments(greedySettle(debtGraphFromBalances(['A' => 0.1 + 0.2, 'B' => -0.3])));
check('greedy: 0.1 + 0.2 still settles 0.30', count($txns) === 1 && abs($txns[0]['amount'] - 0.3) < 0.001, json_encode($txns));

mt_srand(42);
$bad = 0;
$not_forest = 0;
for ($i = 0; $i < 20000; $i++) {
    [$cents, $g] = randomGroup();
    $settlement = greedySettle($g);
    $txns = graphPayments($settlement);
    $left = leftAfterPaying($cents, $txns);
    if ($left || !samePositions($g, $settlement) || count($txns) > count($cents) - 1) {
        $bad++;
        if ($bad === 1) echo "      example: " . json_encode(['cents' => $cents, 'payments' => $txns, 'left' => $left]) . "\n";
    }
    if (!isForest($settlement)) $not_forest++;
}
check('greedy: 20,000 random groups all settle to exactly 0.00 in at most n-1 payments', $bad === 0, "$bad groups were wrong");
check('greedy: the settlement graph is always a forest (no cycles)', $not_forest === 0, "$not_forest groups had a cycle");

// ---- Fewest payments, exact (optimalSettle) ----------------------------------

// A completely different exact method, used only to check optimalSettle:
// settle the first person who isn't even with each possible partner in turn,
// and keep the cheapest way found (tries every possibility, so only for small groups)
function bruteForceMinimum(array $cents): int {
    $cents = array_values(array_filter($cents));
    if (!$cents) return 0;
    $first = $cents[0];
    $rest = array_slice($cents, 1);
    $best = PHP_INT_MAX;
    foreach ($rest as $i => $c) {
        if (($c > 0) === ($first > 0)) continue;   // only pay someone on the other side
        $next = $rest;
        $next[$i] += $first;
        $best = min($best, 1 + bruteForceMinimum($next));
        if ($next[$i] === 0) break;   // settling someone exactly is never worse
    }
    return $best;
}

// The example in the thesis (Figure 3.4): greedy needs 4 payments, the exact
// method 3, because {A, E} and {B, C, D} each add up to zero and settle as two trees
$g = debtGraphFromBalances(['A' => 4, 'B' => 3, 'C' => 2, 'D' => -5, 'E' => -4]);
$exact = optimalSettle($g);
$trees = array_map(function ($t) { sort($t); return $t; }, connectedComponents($exact));
sort($trees);
check('optimal: beats greedy where greedy needs an extra payment (4 vs 3)',
    edgeCount(greedySettle($g)) === 4 && edgeCount($exact) === 3);
check('optimal: that settlement is two trees, {A, E} and {B, C, D}',
    $trees === [['A', 'E'], ['B', 'C', 'D']], json_encode($trees));
check('optimal: nobody owes anything', edgeCount(optimalSettle(debtGraphFromBalances(['A' => 0, 'B' => 0]))) === 0);

mt_srand(99);
$wrong_count = 0;
$not_settled = 0;
$worse_than_greedy = 0;
$bad_forest = 0;
$bad_identity = 0;
for ($i = 0; $i < 3000; $i++) {
    [$cents, $g] = randomGroup();
    // every third group uses round amounts, where zero-sum subgroups are common
    if ($i % 3 === 0) {
        $cents = roundBalances(count($cents));
        $g = debtGraphFromBalances(array_map(fn($c) => $c / 100, $cents));
    }
    $exact = optimalSettle($g);
    $greedy = greedySettle($g);
    if (leftAfterPaying($cents, graphPayments($exact)) || !samePositions($g, $exact)) $not_settled++;
    if (edgeCount($exact) !== bruteForceMinimum($cents)) $wrong_count++;
    if (edgeCount($exact) > edgeCount($greedy)) $worse_than_greedy++;

    // Graph checks: a forest of zero-sum trees, with edges = people not even - trees
    $not_even = count(array_filter($cents));
    $exact_trees = count(connectedComponents($exact));
    if (!isForest($exact) || !componentsAreZeroSum($exact) || edgeCount($exact) !== $not_even - $exact_trees) $bad_forest++;
    // Greedy's extra payments are exactly the trees it failed to separate
    if (edgeCount($greedy) - edgeCount($exact) !== $exact_trees - count(connectedComponents($greedy))) $bad_identity++;
}
check('optimal: 3,000 random groups all settle to exactly 0.00', $not_settled === 0, "$not_settled groups were wrong");
check('optimal: always matches an independent brute-force search for the true minimum', $wrong_count === 0, "$wrong_count groups differed");
check('optimal: never needs more payments than greedy', $worse_than_greedy === 0, "$worse_than_greedy groups were worse");
check('optimal: the settlement is a forest of zero-sum trees, payments = people not even - trees', $bad_forest === 0, "$bad_forest groups were wrong");
check('greedy vs optimal: extra greedy payments = trees greedy failed to separate', $bad_identity === 0, "$bad_identity groups were wrong");

// ---- Only people who shared (sharedSettle) -----------------------------------

mt_srand(7);
$bad_total = 0;
$bad_pair = 0;
for ($i = 0; $i < 5000; $i++) {
    [$cents, $g] = randomGroup();
    $settlement = sharedSettle($g);
    if (leftAfterPaying($cents, graphPayments($settlement)) || !samePositions($g, $settlement)) $bad_total++;
    foreach (graphPayments($settlement) as $t) {
        if (!isset($g['shared'][pairKey($t['from'], $t['to'])])) {
            $bad_pair++;
            break;
        }
    }
}
check('shared: 5,000 random groups all settle to exactly 0.00', $bad_total === 0, "$bad_total groups were wrong");
check('shared: payments only follow edges of the shared-expense graph', $bad_pair === 0, "$bad_pair groups broke the rule");

// ---- Reading amounts typed into the form (parseAmount) ---------------------

foreach ([
    '12' => 12.0, '12.5' => 12.5, '12.50' => 12.5, '12,50' => 12.5, ' 7 ' => 7.0, '0.01' => 0.01, '1 234' => 1234.0,
    '1.234,56' => null, '1,234.56' => null, '12abc' => null, '1e3' => null, '-5' => null, '' => null, '12.345' => null, 'abc' => null,
] as $typed => $expected) {
    $got = parseAmount((string) $typed);
    check("parseAmount('$typed')", $got === $expected, 'got ' . var_export($got, true) . ', expected ' . var_export($expected, true));
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

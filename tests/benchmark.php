<?php
// Benchmark: naive vs greedy vs optimal vs "only people who shared".
// Generates random groups of different sizes the same way the app records
// expenses, builds each group's debt graph, runs every method on that graph,
// checks every settlement graph, and saves the results as CSV files in tests/results/.
//
// naive   = edges in the debt graph (everyone pays back each person they owe, directly)
// greedy, optimal, shared = edges in each method's settlement graph
//
//     php tests/benchmark.php            (on XAMPP: C:\xampp\php\php.exe tests\benchmark.php)
//
// Two kinds of amounts are tested:
//   cents  - any amount to the cent (e.g. 37.42 split three ways)
//   round  - whole-euro shares, like "10 each" (common when splitting bills)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('memory_limit', '1024M');

const CURRENCY = '€';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/debt_graph.php';
require __DIR__ . '/../includes/algorithm.php';
require __DIR__ . '/../includes/optimal.php';
require __DIR__ . '/../includes/shared_settlement.php';

const SIZES = [4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 25, 30];
const OPTIMAL_UP_TO = 22;   // the exact method is only timed up to this many people
const SEED = 2026;

// How many random groups to test at each size (fewer for the slow, large ones)
function trialsFor(int $n): int {
    if ($n <= 14) return 200;
    if ($n <= 18) return 50;
    if ($n <= 20) return 20;
    return 10;
}

// A random group's history: 2n expenses, each paid by one person and split equally
// (to the cent, exactly like addExpense) between 2..n people.
// Returns [net positions in cents worked out directly from the expenses, the debt graph]
function makeGroup(int $n, string $kind): array {
    $cents = array_fill(1, $n, 0);
    $shares = [];
    for ($e = 0; $e < 2 * $n; $e++) {
        $payer = mt_rand(1, $n);
        $split = (array) array_rand(array_fill(1, $n, true), mt_rand(2, $n));
        $people = count($split);
        $total = $kind === 'round' ? $people * 100 * [5, 10, 15, 20, 25][mt_rand(0, 4)] : mt_rand(100, 20000);
        $base = intdiv($total, $people);
        $left = $total % $people;
        foreach (array_values($split) as $i => $member) {
            $share = $base + ($i < $left ? 1 : 0);
            $shares[] = [$payer, $member, $share];
            $cents[$payer] += $share;
            $cents[$member] -= $share;
        }
    }
    return [$cents, buildDebtGraph($shares, range(1, $n))];
}

// Runs a method and times it in milliseconds
function timed(callable $method): array {
    $start = hrtime(true);
    $txns = $method();
    return [$txns, (hrtime(true) - $start) / 1e6];
}

// True if the settlement's payments leave everyone at exactly 0.00
function settlesExactly(array $cents, array $settlement): bool {
    foreach (graphPayments($settlement) as $t) {
        $c = (int) round($t['amount'] * 100);
        $cents[$t['from']] += $c;
        $cents[$t['to']] -= $c;
    }
    return !array_filter($cents);
}

// True when every connected component of a settlement graph adds up to zero
function componentsAreZeroSum(array $settlement): bool {
    $pos = netPositions($settlement);
    foreach (connectedComponents($settlement) as $component) {
        if (array_sum(array_map(fn($v) => $pos[$v], $component)) !== 0) return false;
    }
    return true;
}

$out = __DIR__ . '/results';
if (!is_dir($out)) mkdir($out, 0775, true);
$trials_file = fopen("$out/benchmark_trials.csv", 'w');
fputcsv($trials_file, ['amounts', 'people', 'trial', 'not_even', 'naive', 'greedy', 'optimal', 'shared', 'greedy_ms', 'optimal_ms', 'shared_ms', 'all_correct', 'greedy_trees', 'optimal_trees']);

mt_srand(SEED);
$summary = [];
$problems = 0;

foreach (['cents', 'round'] as $kind) {
    foreach (SIZES as $n) {
        $rows = [];
        for ($t = 1; $t <= trialsFor($n); $t++) {
            [$cents, $graph] = makeGroup($n, $kind);
            $not_even = count(array_filter($cents));

            $naive = edgeCount($graph);
            [$greedy, $g_ms] = timed(fn() => greedySettle($graph));
            [$shared, $s_ms] = timed(fn() => sharedSettle($graph));
            $optimal = null;
            $o_ms = null;
            if ($n <= OPTIMAL_UP_TO) {
                [$optimal, $o_ms] = timed(fn() => optimalSettle($graph));
            }
            $greedy_trees = count(connectedComponents($greedy));
            $optimal_trees = $optimal === null ? null : count(connectedComponents($optimal));

            // The debt graph must match the expenses; every settlement must settle
            // everyone exactly; greedy's settlement is a forest with at most
            // (people not even - 1) edges; "only people who shared" only uses
            // edges of the shared-expense graph; the optimum is a forest of
            // zero-sum trees that can never be beaten, and greedy's extra
            // payments are exactly the trees it failed to separate
            $ok = netPositions($graph) === $cents
               && settlesExactly($cents, $greedy) && settlesExactly($cents, $shared)
               && isForest($greedy) && edgeCount($greedy) <= max(0, $not_even - 1);
            foreach (graphPayments($shared) as $tx) {
                if (!isset($graph['shared'][pairKey($tx['from'], $tx['to'])])) $ok = false;
            }
            if ($optimal !== null) {
                $ok = $ok && settlesExactly($cents, $optimal)
                    && isForest($optimal) && componentsAreZeroSum($optimal)
                    && edgeCount($optimal) === $not_even - $optimal_trees
                    && edgeCount($greedy) - edgeCount($optimal) === $optimal_trees - $greedy_trees
                    && edgeCount($optimal) <= edgeCount($greedy) && edgeCount($optimal) <= edgeCount($shared) && edgeCount($optimal) <= $naive;
            }
            if (!$ok) $problems++;

            $row = [$kind, $n, $t, $not_even, $naive, edgeCount($greedy), $optimal === null ? '' : edgeCount($optimal),
                    edgeCount($shared), round($g_ms, 4), $o_ms === null ? '' : round($o_ms, 4), round($s_ms, 4), $ok ? 1 : 0,
                    $greedy_trees, $optimal_trees ?? ''];
            fputcsv($trials_file, $row);
            $rows[] = $row;
        }

        $col = fn(int $i) => array_column($rows, $i);
        $mean = fn(array $xs) => $xs ? array_sum($xs) / count($xs) : null;
        $median = function (array $xs) { sort($xs); $m = intdiv(count($xs), 2); return $xs ? (count($xs) % 2 ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2) : null; };
        $has_opt = $n <= OPTIMAL_UP_TO;
        $gaps = $has_opt ? array_map(fn($r) => $r[5] - $r[6], $rows) : [];

        $summary[] = [
            'amounts'          => $kind,
            'people'           => $n,
            'trials'           => count($rows),
            'naive_mean'       => round($mean($col(4)), 2),
            'greedy_mean'      => round($mean($col(5)), 2),
            'optimal_mean'     => $has_opt ? round($mean($col(6)), 2) : '',
            'shared_mean'      => round($mean($col(7)), 2),
            'greedy_worse_pct' => $has_opt ? round(100 * count(array_filter($gaps)) / count($rows), 1) : '',
            'max_extra'        => $has_opt ? max($gaps) : '',
            'mean_gap_pct'     => $has_opt ? round(100 * $mean(array_map(fn($r) => $r[6] ? ($r[5] - $r[6]) / $r[6] : 0, $rows)), 2) : '',
            'greedy_ms_median' => round($median($col(8)), 4),
            'optimal_ms_median'=> $has_opt ? round($median($col(9)), 3) : '',
            'shared_ms_median' => round($median($col(10)), 4),
            'greedy_trees_mean'  => round($mean($col(12)), 2),
            'optimal_trees_mean' => $has_opt ? round($mean($col(13)), 2) : '',
            'all_correct'      => array_sum($col(11)) === count($rows) ? 'yes' : 'NO',
        ];
        $last = end($summary);
        printf("%-5s n=%2d  naive %6.2f  greedy %6.2f  optimal %6s  shared %6.2f  greedy worse in %5s%%  optimal %8s ms  correct: %s\n",
            $kind, $n, $last['naive_mean'], $last['greedy_mean'], $last['optimal_mean'], $last['shared_mean'],
            $last['greedy_worse_pct'], $last['optimal_ms_median'], $last['all_correct']);
    }
}
fclose($trials_file);

$f = fopen("$out/benchmark_summary.csv", 'w');
fputcsv($f, array_keys($summary[0]));
foreach ($summary as $row) fputcsv($f, $row);
fclose($f);

echo "\nSeed " . SEED . ". PHP " . PHP_VERSION . ". " . ($problems === 0 ? 'Every answer checked out.' : "$problems groups had a problem!") . "\n";
echo "Saved tests/results/benchmark_summary.csv and benchmark_trials.csv\n";
exit($problems === 0 ? 0 : 1);

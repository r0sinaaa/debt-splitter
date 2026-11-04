<?php
// Exact settlement of a debt graph: the true minimum number of payments.
//
// Why this works, in graph terms. In any settlement graph, money only moves
// along edges, so the net positions inside each connected component add up to
// zero. A connected component with k people needs at least k - 1 edges (a tree).
// So if n people are not even and the settlement splits them into c components,
// it has at least n - c edges. The fewest payments therefore come from
// splitting the people into as MANY zero-sum subgroups as possible and
// connecting each subgroup as a tree: the minimum settlement is a forest with
// the most trees.
//
// Finding that split is the Maximum Zero-Sum Partition problem, which is
// NP-hard, so it is solved exactly with dynamic programming over subsets
// (bitmasks) of the people who are not even:
//   sum[mask] = total net position of the people in mask
//   dp[mask]  = the most zero-sum subgroups the people in mask can be split into
//              = max over i in mask of dp[mask without i], plus 1 if sum[mask] = 0
// This takes O(2^n * n) time and O(2^n) memory, so it is only used for small
// groups (OPTIMAL_MAX_PEOPLE); bigger groups use greedySettle().
//
// All amounts are whole cents, so "adds up to exactly zero" is an exact test.

const OPTIMAL_MAX_PEOPLE = 16;

// $g: a debt graph (debt_graph.php). Returns the settlement graph.
function optimalSettle(array $g): array {
    $settlement = newDebtGraph(array_keys($g['nodes']));

    // Only people who are not already even take part
    $ids = [];
    $cents = [];
    foreach (netPositions($g) as $id => $c) {
        if ($c !== 0) {
            $ids[] = $id;
            $cents[] = $c;
        }
    }
    $n = count($cents);
    if ($n === 0) return $settlement;

    $full = (1 << $n) - 1;
    $index_of = [];   // [1 => 0, 2 => 1, 4 => 2, ...]: which person a single bit stands for
    for ($i = 0; $i < $n; $i++) $index_of[1 << $i] = $i;

    $sum = new SplFixedArray($full + 1);
    $dp = new SplFixedArray($full + 1);
    $sum[0] = 0;
    $dp[0] = 0;

    for ($mask = 1; $mask <= $full; $mask++) {
        // sum of mask = sum of mask without its lowest person + that person's position
        $low = $mask & -$mask;
        $sum[$mask] = $sum[$mask ^ $low] + $cents[$index_of[$low]];

        $best = 0;
        for ($rest = $mask; $rest; $rest &= $rest - 1) {
            $bit = $rest & -$rest;
            if ($dp[$mask ^ $bit] > $best) $best = $dp[$mask ^ $bit];
        }
        $dp[$mask] = $best + ($sum[$mask] === 0 ? 1 : 0);
    }

    // Rebuild the subgroups: walk back from everyone, removing one person at a
    // time along an optimal path. Every point where the remaining people sum to
    // zero is a boundary between two subgroups.
    $subgroups = [];
    $mask = $full;
    $boundary = $full;
    while ($mask) {
        $target = $dp[$mask] - ($sum[$mask] === 0 ? 1 : 0);
        for ($rest = $mask; $rest; $rest &= $rest - 1) {
            $bit = $rest & -$rest;
            if ($dp[$mask ^ $bit] === $target) {
                $mask ^= $bit;
                break;
            }
        }
        if ($sum[$mask] === 0) {
            $subgroups[] = $boundary ^ $mask;   // the people removed since the last boundary
            $boundary = $mask;
        }
    }

    // Connect each zero-sum subgroup as its own tree. The greedy rule inside a
    // zero-sum subgroup of k people adds exactly k - 1 edges (it can't need
    // fewer, or the DP would have split the subgroup further).
    foreach ($subgroups as $group) {
        $positions = [];
        for ($i = 0; $i < $n; $i++) {
            if ($group & (1 << $i)) $positions[$ids[$i]] = $cents[$i];
        }
        addGreedyEdges($settlement, $positions);
    }
    return $settlement;
}

// The most zero-sum subgroups (trees) a settlement of this graph can have.
// The minimum number of payments is (people not even) - this.
function maxZeroSumSubgroups(array $g): int {
    return count(connectedComponents(optimalSettle($g)));
}

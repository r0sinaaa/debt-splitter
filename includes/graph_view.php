<?php
// Draws a debt graph as an SVG picture for the group page: people as circles
// placed around a ring, debts as arrows from the person who owes to the person
// they owe. The same $order is used for both pictures on the page, so every
// person sits in the same place in "debts as recorded" and in "the settlement".

function debtGraphSvg(array $g, array $names, array $order, string $id, int $max_labels = 12): string {
    $w = 340;
    $h = 300;
    $cx = $w / 2;
    $cy = $h / 2;
    $n = count($order);
    $ring = $n > 12 ? 118 : 100;
    $r = (int) max(8, min(17, M_PI * $ring / max(1, $n) - 4));   // circles shrink in big groups

    // Where each person goes: evenly around the ring, starting at the top
    $at = [];
    foreach (array_values($order) as $i => $v) {
        $angle = -M_PI / 2 + 2 * M_PI * $i / max(1, $n);
        $at[$v] = [$cx + $ring * cos($angle), $cy + $ring * sin($angle), $angle];
    }

    $svg = sprintf('<svg viewBox="0 0 %d %d" role="img" aria-labelledby="%s-title" xmlns="http://www.w3.org/2000/svg">', $w, $h, e($id));
    $svg .= sprintf('<title id="%s-title">%s</title>', e($id), e(graphDescription($g, $names)));
    $svg .= sprintf('<defs><marker id="%s-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="graph-arrow"/></marker></defs>', e($id));

    // Edges first, so the circles sit on top of them
    $label_edges = edgeCount($g) <= $max_labels;
    $edge_class = edgeCount($g) > 30 ? 'graph-edge graph-edge-dense' : 'graph-edge';   // lighter lines when there are lots
    $labels = '';
    foreach ($g['edges'] as $from => $out) {
        foreach ($out as $to => $cents) {
            if (!isset($at[$from], $at[$to])) continue;
            [$x1, $y1] = $at[$from];
            [$x2, $y2] = $at[$to];
            $len = max(1, hypot($x2 - $x1, $y2 - $y1));
            $ux = ($x2 - $x1) / $len;
            $uy = ($y2 - $y1) / $len;
            $svg .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="%s" marker-end="url(#%s-arrow)"/>',
                $x1 + $ux * ($r + 1), $y1 + $uy * ($r + 1), $x2 - $ux * ($r + 3), $y2 - $uy * ($r + 3), $edge_class, e($id));
            if ($label_edges) {
                $labels .= sprintf('<text x="%.1f" y="%.1f" class="graph-amount">%s</text>',
                    ($x1 + $x2) / 2, ($y1 + $y2) / 2 + 3, e(money($cents / 100)));
            }
        }
    }
    $svg .= $labels;

    // People
    foreach ($at as $v => [$x, $y, $angle]) {
        $name = $names[$v] ?? (string) $v;
        $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%d" class="graph-node"/>', $x, $y, $r);
        $svg .= sprintf('<text x="%.1f" y="%.1f" class="graph-initial" style="font-size:%dpx">%s</text>',
            $x, $y + $r * 0.35, max(8, (int) round($r * 0.75)), e(initials($name)));
        if ($n <= 12) {
            $lx = $x + cos($angle) * ($r + 6);
            $ly = $y + sin($angle) * ($r + 6) + 4 + max(0, sin($angle)) * 6;
            $anchor = abs(cos($angle)) < 0.3 ? 'middle' : (cos($angle) > 0 ? 'start' : 'end');
            $svg .= sprintf('<text x="%.1f" y="%.1f" text-anchor="%s" class="graph-name">%s</text>',
                $lx, $ly, $anchor, e(mb_strimwidth($name, 0, 12, '…')));
        }
    }
    return $svg . '</svg>';
}

// "AB" for "Ana Berg", "S" for "Sam"
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [''];
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

// A text version of the graph for screen readers
function graphDescription(array $g, array $names): string {
    $parts = [];
    foreach (graphPayments($g) as $t) {
        $parts[] = ($names[$t['from']] ?? $t['from']) . ' to ' . ($names[$t['to']] ?? $t['to']) . ' ' . money($t['amount']);
    }
    return $parts ? implode('; ', $parts) : 'No debts';
}

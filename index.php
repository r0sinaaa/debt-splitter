<?php
require 'db.php';
require 'balances.php';
require 'algorithm.php';
require 'shared_settlement.php';
require 'history.php';

$group_id = 1; // hardcoded for now - your "Flat 4B" test group
ensureSettledColumn();

$members = [];
$stmt = $conn->prepare("SELECT id, name FROM members WHERE group_id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $members[$row['id']] = $row['name'];
}

// Form values: by default everyone is ticked; after an error, keep what the user entered
$form = [
    'payer_id'    => array_key_first($members),
    'amount'      => '',
    'description' => '',
    'split_with'  => array_keys($members),
];
$error = '';

// Which settlement to show: fewest payments overall, or only between people who shared an expense
$view = (($_GET['view'] ?? $_POST['view'] ?? '') === 'shared') ? 'shared' : 'fewest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle') {
    // Everyone has paid: move the current expenses into the history
    settleUp($group_id);
    header("Location: index.php" . ($view === 'shared' ? '?view=shared' : ''));
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payer_id = (int) ($_POST['payer_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    // Only keep ticked people who really belong to this group
    $split_ids = array_values(array_intersect(
        array_map('intval', $_POST['split_with'] ?? []),
        array_keys($members)
    ));

    if (!isset($members[$payer_id])) {
        $error = 'Choose who paid.';
    } elseif ($amount <= 0) {
        $error = 'Enter an amount greater than zero.';
    } elseif ($description === '') {
        $error = 'Add a short description.';
    } elseif (count($split_ids) === 0) {
        $error = 'Tick at least one person to split the expense between.';
    } else {
        $stmt = $conn->prepare("INSERT INTO expenses (group_id, payer_id, amount, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iids", $group_id, $payer_id, $amount, $description);
        $stmt->execute();
        $expense_id = $conn->insert_id;

        // Work in cents so the shares always add up to exactly the amount paid
        // (e.g. 10.00 between 3 people becomes 3.34 + 3.33 + 3.33)
        $total_cents = (int) round($amount * 100);
        $people = count($split_ids);
        $base_cents = intdiv($total_cents, $people);
        $leftover_cents = $total_cents % $people;

        $stmt = $conn->prepare("INSERT INTO expense_splits (expense_id, member_id, share) VALUES (?, ?, ?)");
        foreach ($split_ids as $i => $member_id) {
            $share = ($base_cents + ($i < $leftover_cents ? 1 : 0)) / 100;
            $stmt->bind_param("iid", $expense_id, $member_id, $share);
            $stmt->execute();
        }

        header("Location: index.php" . ($view === 'shared' ? '?view=shared' : ''));
        exit;
    }

    $form = [
        'payer_id'    => $payer_id,
        'amount'      => $_POST['amount'] ?? '',
        'description' => $description,
        'split_with'  => $split_ids,
    ];
}

// Only expenses added since the last settle-up count towards what people owe now
$balances = getGroupBalances($group_id, true);
// A brand-new group has no balances yet, so there is nothing to settle
$transactions = empty($balances) ? [] : greedySimplify($balances);

if ($view === 'shared') {
    [$debts, $shared] = getPairwiseDebts($group_id);
    $transactions = simplifySharedOnly($debts, $shared);
}
$payment_count = count($transactions);

$history = getExpenseHistory($group_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Debt Splitter</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7;
            margin: 0;
            /* smaller outer gap on phones, up to 40px on desktop */
            padding: clamp(12px, 4vw, 40px);
            color: #222;
            line-height: 1.4;
            -webkit-text-size-adjust: 100%;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: clamp(20px, 5vw, 32px);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            margin: 0;
            font-size: clamp(22px, 5vw, 26px);
        }
        h2 {
            font-size: 16px;
            color: #555;
            border-top: 1px solid #eee;
            padding-top: 20px;
            margin: 24px 0 12px;
        }
        label {
            display: block;
            font-size: 14px;
            color: #444;
            margin-bottom: 14px;
        }
        select, input[type="number"], input[type="text"] {
            display: block;
            width: 100%;
            min-height: 44px;       /* comfortable tap target */
            padding: 10px;
            margin-top: 6px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 16px;        /* 16px stops iPhones zooming in on focus */
            font-family: inherit;
            background: white;
            color: inherit;
        }
        button {
            width: 100%;
            min-height: 44px;
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 18px;
            border-radius: 6px;
            font-size: 16px;
            font-family: inherit;
            cursor: pointer;
        }
        button:hover {
            background: #1d4ed8;
        }
        select:focus-visible, input:focus-visible, button:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
        ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .payments li {
            background: #f0f9f0;
            border: 1px solid #cdeecd;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 15px;
            overflow-wrap: anywhere;  /* long names wrap instead of overflowing */
        }
        p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }

        fieldset {
            border: 0;
            padding: 0;
            margin: 0 0 16px;
            min-width: 0;
        }
        legend {
            font-size: 14px;
            color: #444;
            padding: 0;
            margin-bottom: 8px;
        }
        .split-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .person {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            margin: 0;
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
            color: #222;
            cursor: pointer;
            overflow-wrap: anywhere;
        }
        .person input {
            width: 18px;
            height: 18px;
            margin: 0;
            flex-shrink: 0;
            accent-color: #2563eb;
        }
        .person:has(input:checked) {
            border-color: #2563eb;
            background: #eff4ff;
        }
        .person:has(input:focus-visible) {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
        .error {
            color: #b91c1c;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        .view-switch {
            display: flex;
            gap: 4px;
            padding: 4px;
            margin-bottom: 12px;
            background: #f0f1f4;
            border-radius: 8px;
        }
        .view-switch a {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 8px;
            border-radius: 6px;
            font-size: 14px;
            color: #444;
            text-align: center;
            text-decoration: none;
        }
        .view-switch a[aria-current="page"] {
            background: white;
            color: #222;
            font-weight: 600;
            box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        }
        .view-switch a:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
        .view-note {
            margin-bottom: 12px;
        }

        button.secondary {
            background: white;
            color: #2563eb;
            border: 1px solid #2563eb;
        }
        button.secondary:hover {
            background: #eff4ff;
        }
        .settle-form {
            margin-top: 16px;
        }
        .settle-form p {
            margin-top: 8px;
        }

        h3 {
            font-size: 14px;
            font-weight: 600;
            color: #444;
            margin: 0 0 4px;
        }
        .history-list li {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        .history-list li:last-child {
            border-bottom: 0;
        }
        .expense-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 15px;
        }
        .expense-desc {
            overflow-wrap: anywhere;
        }
        .expense-amount {
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .history-list p {
            margin-top: 2px;
            font-size: 13px;
            overflow-wrap: anywhere;
        }
        .round {
            border-top: 1px solid #eee;
        }
        .round summary {
            padding: 12px 0;        /* keeps the open/close arrow and a good tap size */
            font-size: 14px;
            color: #444;
            cursor: pointer;
        }
        .round summary:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
        .round .history-list {
            padding-left: 12px;
            border-left: 2px solid #eee;
            margin-bottom: 12px;
        }

        /* Desktop and tablets in landscape: form and settlement side by side */
        @media (min-width: 760px) {
            .container {
                max-width: 880px;
            }
            .layout {
                display: grid;
                grid-template-columns: 1fr 1fr;
                column-gap: 40px;
                align-items: start;
            }
            button {
                width: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
    <h1>Flat 4B</h1>

    <div class="layout">
        <section>
            <h2>Add an expense</h2>
            <?php if ($error): ?>
                <p class="error" role="alert"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="view" value="<?= $view ?>">
                <label>Who paid?
                    <select name="payer_id" required>
                        <?php foreach ($members as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $id == $form['payer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Amount: <input type="number" step="0.01" min="0.01" name="amount" inputmode="decimal" value="<?= htmlspecialchars($form['amount']) ?>" required></label>
                <label>Description: <input type="text" name="description" value="<?= htmlspecialchars($form['description']) ?>" required></label>

                <fieldset>
                    <legend>Split equally between</legend>
                    <div class="split-options">
                        <?php foreach ($members as $id => $name): ?>
                            <label class="person">
                                <input type="checkbox" name="split_with[]" value="<?= $id ?>" <?= in_array($id, $form['split_with']) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <button type="submit">Add Expense</button>
            </form>
        </section>

        <section>
            <h2>Settlement</h2>
            <nav class="view-switch" aria-label="Settlement view">
                <a href="index.php" <?= $view === 'fewest' ? 'aria-current="page"' : '' ?>>Fewest payments</a>
                <a href="index.php?view=shared" <?= $view === 'shared' ? 'aria-current="page"' : '' ?>>Only people who shared</a>
            </nav>
            <?php if (empty($transactions)): ?>
                <p>Everyone's settled up!</p>
            <?php else: ?>
                <p class="view-note">
                    <?= $payment_count ?> <?= $payment_count === 1 ? 'payment' : 'payments' ?>,
                    <?= $view === 'shared'
                        ? 'each between people who have shared an expense.'
                        : 'kept as few as possible. Some may be between people who haven\'t shared an expense.' ?>
                </p>
                <ul class="payments">
                    <?php foreach ($transactions as $t): ?>
                        <li><?= htmlspecialchars($members[$t['from']]) ?> pays <?= htmlspecialchars($members[$t['to']]) ?>: €<?= number_format($t['amount'], 2) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($history['open'])): ?>
                <form method="POST" class="settle-form"
                      onsubmit="return confirm('Mark everything as paid? These expenses will move to the history, and new expenses will start a fresh settlement.');">
                    <input type="hidden" name="action" value="settle">
                    <input type="hidden" name="view" value="<?= $view ?>">
                    <button type="submit" class="secondary">Mark all as paid</button>
                    <p>Press this once everyone has paid. New expenses will then start a fresh settlement.</p>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <section class="history">
        <h2>Expense history</h2>
        <?php if (empty($history['open']) && empty($history['settled'])): ?>
            <p>No expenses yet.</p>
        <?php endif; ?>

        <?php if (!empty($history['open'])): ?>
            <?php if (!empty($history['settled'])): ?>
                <h3>Not settled yet</h3>
            <?php endif; ?>
            <ul class="history-list">
                <?php foreach ($history['open'] as $expense): ?>
                    <li>
                        <div class="expense-top">
                            <span class="expense-desc"><?= htmlspecialchars($expense['description']) ?></span>
                            <span class="expense-amount">€<?= number_format($expense['amount'], 2) ?></span>
                        </div>
                        <p><?= htmlspecialchars(describeExpense($expense, $members)) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php foreach ($history['settled'] as $settled_at => $round): ?>
            <details class="round">
                <summary>
                    Settled <?= date('j M Y, H:i', strtotime($settled_at)) ?>
                    · <?= count($round) ?> <?= count($round) === 1 ? 'expense' : 'expenses' ?>
                    · €<?= number_format(array_sum(array_column($round, 'amount')), 2) ?>
                </summary>
                <ul class="history-list">
                    <?php foreach ($round as $expense): ?>
                        <li>
                            <div class="expense-top">
                                <span class="expense-desc"><?= htmlspecialchars($expense['description']) ?></span>
                                <span class="expense-amount">€<?= number_format($expense['amount'], 2) ?></span>
                            </div>
                            <p><?= htmlspecialchars(describeExpense($expense, $members)) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endforeach; ?>
    </section>
    </div>
</body>
</html>
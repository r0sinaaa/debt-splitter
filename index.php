<?php
require 'db.php';
require 'balances.php';
require 'algorithm.php';

$group_id = 1; // hardcoded for now - your "Flat 4B" test group

$members = [];
$stmt = $conn->prepare("SELECT id, name FROM members WHERE group_id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $members[$row['id']] = $row['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payer_id = $_POST['payer_id'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];

    $stmt = $conn->prepare("INSERT INTO expenses (group_id, payer_id, amount, description) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iids", $group_id, $payer_id, $amount, $description);
    $stmt->execute();
    $expense_id = $conn->insert_id;

    $share = round($amount / count($members), 2);
    $stmt = $conn->prepare("INSERT INTO expense_splits (expense_id, member_id, share) VALUES (?, ?, ?)");
    foreach ($members as $member_id => $name) {
        $stmt->bind_param("iid", $expense_id, $member_id, $share);
        $stmt->execute();
    }

    header("Location: index.php");
    exit;
}

$balances = getGroupBalances($group_id);
$transactions = greedySimplify($balances);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Debt Splitter</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7;
            margin: 0;
            padding: 40px 20px;
            color: #222;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            margin-top: 0;
            font-size: 24px;
        }
        h2 {
            font-size: 16px;
            color: #555;
            border-top: 1px solid #eee;
            padding-top: 20px;
            margin-top: 24px;
        }
        label {
            display: block;
            font-size: 14px;
            color: #444;
            margin-bottom: 12px;
        }
        select, input[type="number"], input[type="text"] {
            width: 100%;
            padding: 8px;
            margin-top: 4px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }
        button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }
        button:hover {
            background: #1d4ed8;
        }
        ul {
            list-style: none;
            padding: 0;
        }
        li {
            background: #f0f9f0;
            border: 1px solid #cdeecd;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 14px;
        }
        p {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
    <h1>Flat 4B</h1>

    <h2>Add an expense</h2>
    <form method="POST">
        <label>Who paid?
            <select name="payer_id" required>
                <?php foreach ($members as $id => $name): ?>
                    <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Amount: <input type="number" step="0.01" name="amount" required></label>
        <label>Description: <input type="text" name="description" required></label>

        <button type="submit">Add Expense</button>
    </form>

    <h2>Settlement</h2>
    <?php if (empty($transactions)): ?>
        <p>Everyone's settled up!</p>
    <?php else: ?>
        <ul>
            <?php foreach ($transactions as $t): ?>
                <li><?= htmlspecialchars($members[$t['from']]) ?> pays <?= htmlspecialchars($members[$t['to']]) ?>: €<?= number_format($t['amount'], 2) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    </div>
</body>
</html>
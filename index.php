<?php
require 'db.php';
require 'balances.php';
require 'algorithm.php';

$group_id = 1; // hardcoded for now - your "Flat 4B" test group

// Get all members in this group, for the dropdown and for splitting
$members = [];
$stmt = $conn->prepare("SELECT id, name FROM members WHERE group_id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $members[$row['id']] = $row['name'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payer_id = $_POST['payer_id'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];

    // Insert the expense
    $stmt = $conn->prepare("INSERT INTO expenses (group_id, payer_id, amount, description) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iids", $group_id, $payer_id, $amount, $description);
    $stmt->execute();
    $expense_id = $conn->insert_id;

    // Split evenly among all members
    $share = round($amount / count($members), 2);
    $stmt = $conn->prepare("INSERT INTO expense_splits (expense_id, member_id, share) VALUES (?, ?, ?)");
    foreach ($members as $member_id => $name) {
        $stmt->bind_param("iid", $expense_id, $member_id, $share);
        $stmt->execute();
    }

    // Redirect to avoid re-submitting the form on refresh
    header("Location: index.php");
    exit;
}

// Calculate current settlement
$balances = getGroupBalances($group_id);
$transactions = greedySimplify($balances);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Debt Splitter</title>
</head>
<body>
    <h1>Flat 4B</h1>

    <h2>Add an expense</h2>
    <form method="POST">
        <label>Who paid?
            <select name="payer_id" required>
                <?php foreach ($members as $id => $name): ?>
                    <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label><br><br>

        <label>Amount: <input type="number" step="0.01" name="amount" required></label><br><br>
        <label>Description: <input type="text" name="description" required></label><br><br>

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
</body>
</html>
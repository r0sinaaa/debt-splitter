<?php
require 'includes/app.php';
$user = requireLogin();

$group_id = (int) ($_GET['id'] ?? 0);
$group = getOwnedGroup($group_id, $user['id']);

if ($group === null) {
    http_response_code(404);
    pageStart('Group not found', $user, 'theme-blush');
    ?>
    <main id="main" class="page">
        <div class="panel">
            <h1>Group not found</h1>
            <p class="muted" style="margin-top: 8px">It may have been deleted, or it belongs to someone else.</p>
            <p style="margin-top: 16px"><a href="groups.php">Back to your dashboard</a></p>
        </div>
    </main>
    <?php
    pageEnd();
    exit;
}

$self = "group.php?id=$group_id";
$members = getMembers($group_id);
$names = array_map(fn($m) => $m['name'], $members);   // [id => name]
$me = ownMemberId($members, $user['id']);

// Form values: by default you paid and everyone is ticked; after an error, keep what was typed
$expense_form = ['payer_id' => $me ?? array_key_first($members), 'amount' => '', 'description' => '', 'split_with' => array_keys($members)];
$expense_error = '';
$person_form = ['name' => '', 'email' => ''];
$person_error = '';

// Checks the add/edit expense form. Returns [error or '', data]
function checkExpenseForm(array $members): array {
    $amount = parseAmount(postText('amount'));
    $ticked = is_array($_POST['split_with'] ?? null) ? $_POST['split_with'] : [];
    $data = [
        'payer_id'    => postInt('payer_id'),
        'amount'      => $amount ?? 0.0,
        'description' => postText('description'),
        // Only keep ticked people who really belong to this group
        'split_ids'   => array_values(array_intersect(array_map('intval', $ticked), array_keys($members))),
    ];

    if (!isset($members[$data['payer_id']])) return ['Choose who paid.', $data];
    if ($amount === null) return ['Write the amount like 12.50 or 12,50, without thousands separators.', $data];
    if ($amount <= 0) return ['Enter an amount greater than zero.', $data];
    if ($data['description'] === '') return ['Add a short description, like "Groceries".', $data];
    if (strlen($data['description']) > 255) return ['Keep the description under 255 characters.', $data];
    if (!$data['split_ids']) return ['Tick at least one person to split it between.', $data];
    return ['', $data];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = postText('action');

    if ($action === 'add_expense' || $action === 'edit_expense') {
        [$expense_error, $data] = checkExpenseForm($members);
        $editing = $action === 'edit_expense' ? getExpense($group_id, postInt('expense_id')) : null;

        if ($action === 'edit_expense' && (!$editing || $editing['settled_at'] !== null || $editing['is_payment'])) {
            flash('That expense can\'t be changed any more.', 'bad');
            redirect("$self#history");
        }
        if ($expense_error === '') {
            if ($editing) {
                updateExpense((int) $editing['id'], $data['payer_id'], $data['amount'], $data['description'], $data['split_ids']);
                flash('Saved ' . $data['description'] . ' (' . money($data['amount']) . ').');
                redirect("$self#history");
            }
            if (isRecentDuplicate($group_id, $data['payer_id'], $data['amount'], $data['description'])) {
                flash($data['description'] . ' was already added a moment ago, so it wasn\'t added twice.', 'bad');
            } else {
                addExpense($group_id, $data['payer_id'], $data['amount'], $data['description'], $data['split_ids']);
                flash('Added ' . $data['description'] . ' (' . money($data['amount']) . ').');
            }
            redirect("$self#expense");
        }
        if ($editing) {
            flash('Couldn\'t save ' . $editing['description'] . ". $expense_error", 'bad');
            redirect("$self#history");
        }
        $expense_form = ['payer_id' => $data['payer_id'], 'amount' => postText('amount'), 'description' => $data['description'], 'split_with' => $data['split_ids']];

    } elseif ($action === 'delete_expense') {
        $expense = getExpense($group_id, postInt('expense_id'));
        if ($expense && $expense['settled_at'] === null) {
            deleteExpense((int) $expense['id']);
            flash($expense['is_payment'] ? 'Undid that payment.' : 'Deleted ' . $expense['description'] . '.');
        } else {
            flash('That expense can\'t be deleted any more.', 'bad');
        }
        redirect("$self#history");

    } elseif ($action === 'add_member') {
        $person_form = ['name' => postText('name'), 'email' => postText('email')];
        [$person_error, $contact] = checkContactFields($person_form['name'], $person_form['email']);
        if ($person_error === '' && nameTaken($members, $contact['name'])) {
            $person_error = 'There\'s already someone called ' . $contact['name'] . ' in this group. Add an initial to tell them apart.';
        }
        if ($person_error === '') {
            addMember($group_id, $contact);
            flash('Added ' . $contact['name'] . '.');
            redirect("$self#people");
        }

    } elseif ($action === 'edit_member') {
        $member_id = postInt('member_id');
        if (isset($members[$member_id])) {
            [$error, $contact] = checkContactFields(postText('name'), postText('email'));
            if ($error === '' && nameTaken($members, $contact['name'], $member_id)) {
                $error = 'There\'s already someone called ' . $contact['name'] . ' in this group.';
            }
            if ($error === '') {
                updateMember($member_id, $contact);
                flash('Saved ' . $contact['name'] . '.');
            } else {
                flash('Couldn\'t save ' . $members[$member_id]['name'] . ". $error", 'bad');
            }
        }
        redirect("$self#people");

    } elseif ($action === 'remove_member') {
        $member_id = postInt('member_id');
        if (isset($members[$member_id]) && $member_id !== $me) {
            if (memberHasExpenses($member_id)) {
                flash($names[$member_id] . ' is part of some expenses, so they can\'t be removed.', 'bad');
            } else {
                removeMember($member_id);
                flash('Removed ' . $names[$member_id] . '.');
            }
        }
        redirect("$self#people");

    } elseif ($action === 'set_mode') {
        setSettleMode($group_id, postText('mode'));
        redirect("$self#settle");

    } elseif ($action === 'mark_paid') {
        // Use the amount from the current settlement, not the form, so a double
        // click or an old tab can't record the same payment twice
        $from = postInt('from');
        $to = postInt('to');
        $match = null;
        foreach (getSettlement($group) as $t) {
            if ((int) $t['from'] === $from && (int) $t['to'] === $to) {
                $match = $t;
                break;
            }
        }
        if ($match) {
            addExpense($group_id, $from, $match['amount'], 'Payment', [$to], true);
            flash($names[$from] . ' paying ' . $names[$to] . ' ' . money($match['amount']) . ' is marked as paid.');
        } else {
            flash('That payment was already marked as paid.', 'bad');
        }
        redirect("$self#settle");

    } elseif ($action === 'settle_all') {
        settleUp($group_id);
        flash('Everything is marked as paid. New expenses will start a fresh settlement.');
        redirect("$self#settle");

    } elseif ($action === 'remind') {
        $member_id = postInt('member_id');
        [$text, $type] = describeReminderResults(sendReminders($group, $member_id ?: null));
        flash($text, $type);
        redirect("$self#settle");

    } elseif ($action === 'rename') {
        $name = postText('name');
        if ($name === '' || strlen($name) > 100) {
            flash('Group names need 1 to 100 characters.', 'bad');
        } else {
            renameGroup($group_id, $name);
            flash("Renamed the group to $name.");
        }
        redirect($self);

    } elseif ($action === 'delete_group') {
        deleteGroup($group_id);
        flash('Deleted ' . $group['name'] . '.');
        redirect('groups.php');
    }
}

$settlement = getSettlementDetails($group);
$transactions = $settlement['transactions'];
$debtors = getDebtors($transactions);
$last_reminded = lastReminders($group_id);
$history = getExpenseHistory($group_id);
$outstanding = groupOutstanding($group_id);
$payment_count = count($transactions);
$debt_edges = edgeCount($settlement['graph']);
$settle_groups = count(connectedComponents($settlement['settlement']));

// Who can get a reminder (not you, and they need an email address)
$remindable = array_filter(array_keys($debtors), fn($id) => $id !== $me && $members[$id]['email']);

pageStart($group['name'], $user, 'theme-blush');
?>
<main id="main" class="page" data-version="<?= e(groupVersion($group_id)) ?>" data-state-url="group_state.php?id=<?= $group_id ?>">
    <div class="page-head">
        <p><a href="groups.php">Dashboard</a> <span class="live-status" id="live-status" aria-live="polite"></span></p>
        <h1><?= e($group['name']) ?></h1>
        <p>
            <?= count($members) ?> <?= count($members) === 1 ? 'person' : 'people' ?>.
            <?php if ($outstanding > 0): ?>
                <span class="amount"><?= money($outstanding) ?></span> still to settle.
            <?php elseif ($history['open'] || $history['settled']): ?>
                Everyone is settled up.
            <?php endif; ?>
        </p>
    </div>

    <div class="layout">
        <section class="panel" id="expense" aria-labelledby="expense-title">
            <h2 id="expense-title">Add an expense</h2>

            <?php if (count($members) < 2): ?>
                <p class="empty">Add the people you're splitting with first, under <a href="#people">People</a>.</p>
            <?php else: ?>
                <?php if ($expense_error): ?>
                    <p class="alert alert-bad" role="alert" style="margin-bottom: 16px"><?= e($expense_error) ?></p>
                <?php endif; ?>
                <form method="POST" action="<?= e($self) ?>" class="stack">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_expense">
                    <label>Who paid?
                        <select name="payer_id" required>
                            <?php foreach ($members as $id => $m): ?>
                                <option value="<?= $id ?>" <?= $id === (int) $expense_form['payer_id'] ? 'selected' : '' ?>><?= e($m['name']) ?><?= $id === $me ? ' (you)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="form-row">
                        <label>Amount (<?= e(CURRENCY) ?>)
                            <input type="text" name="amount" inputmode="decimal" autocomplete="off" placeholder="0.00" value="<?= e($expense_form['amount']) ?>" required>
                        </label>
                        <label>What was it for?
                            <input type="text" name="description" maxlength="255" placeholder="Groceries" value="<?= e($expense_form['description']) ?>" required>
                        </label>
                    </div>
                    <fieldset>
                        <legend>Split equally between</legend>
                        <div class="chips">
                            <?php foreach ($members as $id => $m): ?>
                                <label class="chip">
                                    <input type="checkbox" name="split_with[]" value="<?= $id ?>" <?= in_array($id, $expense_form['split_with']) ? 'checked' : '' ?>>
                                    <?= e($m['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <button type="submit" class="btn">Add expense</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel" id="settle" aria-labelledby="settle-title">
            <h2 id="settle-title">Who pays whom</h2>

            <form method="POST" action="<?= e($self) ?>" class="mode-switch" aria-label="How to settle">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_mode">
                <button type="submit" name="mode" value="fewest" aria-pressed="<?= $group['settle_mode'] !== 'shared' ? 'true' : 'false' ?>">Fewest payments</button>
                <button type="submit" name="mode" value="shared" aria-pressed="<?= $group['settle_mode'] === 'shared' ? 'true' : 'false' ?>">Only people who shared</button>
            </form>

            <?php if (!$transactions): ?>
                <?php if (!$history['open'] && !$history['settled']): ?>
                    <p class="empty">Nothing to settle yet. Add an expense and the payments show up here.</p>
                <?php else: ?>
                    <p class="settled-msg">Everyone's settled up.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted mode-note">
                    <?= $payment_count ?> <?= $payment_count === 1 ? 'payment' : 'payments' ?>,
                    <?php if ($settlement['method'] === 'shared'): ?>
                        each between people who have shared an expense.
                    <?php elseif ($settlement['method'] === 'optimal'): ?>
                        the exact minimum for this group. Some may be between people who haven't shared an expense.
                    <?php else: ?>
                        worked out with the fast method, because more than <?= OPTIMAL_MAX_PEOPLE ?> people are involved.
                        It's usually the minimum, but not always.
                    <?php endif; ?>
                </p>
                <ul class="rows">
                    <?php foreach ($transactions as $t): ?>
                        <li>
                            <div class="row-main">
                                <?= e($names[$t['from']]) ?> pays <?= e($names[$t['to']]) ?>
                                <span class="amount"><?= money($t['amount']) ?></span>
                            </div>
                            <div class="row-actions">
                                <form method="POST" action="<?= e($self) ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="from" value="<?= (int) $t['from'] ?>">
                                    <input type="hidden" name="to" value="<?= (int) $t['to'] ?>">
                                    <button type="submit" class="btn btn-quiet btn-small">Mark as paid</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <h3 class="subhead">The debt graph</h3>
                <p class="muted">Each arrow is a debt, from the person who owes to the person they're owed by. Settling keeps everyone's balance the same with fewer arrows.</p>
                <div class="graph-view">
                    <figure>
                        <?= debtGraphSvg($settlement['graph'], $names, array_keys($members), 'graph-recorded') ?>
                        <figcaption>As recorded: <?= $debt_edges ?> <?= $debt_edges === 1 ? 'debt' : 'debts' ?></figcaption>
                    </figure>
                    <figure>
                        <?= debtGraphSvg($settlement['settlement'], $names, array_keys($members), 'graph-settled') ?>
                        <figcaption>
                            Settled: <?= $payment_count ?> <?= $payment_count === 1 ? 'payment' : 'payments' ?><?php if ($settle_groups > 1): ?>,
                            in <?= $settle_groups ?> groups that settle separately<?php endif; ?>
                        </figcaption>
                    </figure>
                </div>

                <h3 class="subhead">Reminders</h3>
                <p class="muted">Only people who owe get one. People who are owed money never get a message.</p>
                <ul class="rows">
                    <?php foreach ($debtors as $id => $debt): ?>
                        <?php
                        $m = $members[$id];
                        $is_me = $id === $me;
                        ?>
                        <li>
                            <div class="row-main">
                                <strong><?= $is_me ? 'You' : e($m['name']) ?></strong> <?= $is_me ? 'owe' : 'owes' ?>
                                <span class="amount"><?= money($debt['total']) ?></span>
                                <?php if ($is_me): ?>
                                    <span class="muted">Pay <?= e(describePayments($debt['payments'], $members)) ?></span>
                                <?php elseif (count($debt['payments']) > 1): ?>
                                    <span class="muted">Pays <?= e(describePayments($debt['payments'], $members)) ?></span>
                                <?php endif; ?>
                                <?php if (!$is_me): ?>
                                    <span class="muted"><?= $m['email'] ? e($m['email']) : 'No email yet. <a href="#people">Add one</a>' ?></span>
                                    <span class="muted">
                                        <?= isset($last_reminded[$id]) ? 'Last reminded ' . e(timeAgo($last_reminded[$id])) : 'Not reminded yet' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$is_me): ?>
                                <div class="row-actions">
                                    <?php if (in_array($id, $remindable, true)): ?>
                                        <form method="POST" action="<?= e($self) ?>">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="remind">
                                            <input type="hidden" name="member_id" value="<?= $id ?>">
                                            <button type="submit" class="btn btn-quiet btn-small">Send reminder</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (count($remindable) > 1): ?>
                    <form method="POST" action="<?= e($self) ?>" class="remind-all">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="remind">
                        <button type="submit" class="btn">Remind everyone who owes</button>
                    </form>
                <?php endif; ?>

                <form method="POST" action="<?= e($self) ?>" class="settle-all"
                      onsubmit="return confirm('Mark everything as paid? These expenses move to the history, and new expenses start a fresh settlement.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="settle_all">
                    <button type="submit" class="btn btn-quiet">Mark everything as paid</button>
                    <p class="muted">Use this once everyone has paid. New expenses then start a fresh settlement.</p>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel" id="people" aria-labelledby="people-title">
        <h2 id="people-title">People</h2>
        <p class="muted">Email addresses are only used for payment reminders.</p>

        <ul class="rows people-list">
            <?php foreach ($members as $id => $m): ?>
                <li>
                    <div class="row-main">
                        <strong><?= e($m['name']) ?></strong><?php if ($id === $me): ?> <span class="you">(you)</span><?php endif; ?>
                        <span class="muted"><?= e($m['email'] ?: 'No email') ?></span>
                    </div>
                    <details>
                        <summary>Edit<span class="visually-hidden"> <?= e($m['name']) ?></span></summary>
                        <form method="POST" action="<?= e($self) ?>" class="edit-person stack">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="edit_member">
                            <input type="hidden" name="member_id" value="<?= $id ?>">
                            <label>Name
                                <input type="text" name="name" maxlength="100" value="<?= e($m['name']) ?>" required>
                            </label>
                            <label>Email
                                <input type="email" name="email" value="<?= e($m['email']) ?>">
                            </label>
                            <div class="row-actions">
                                <button type="submit" class="btn btn-small">Save changes</button>
                            </div>
                        </form>
                        <?php if ($id !== $me): ?>
                            <form method="POST" action="<?= e($self) ?>" class="row-actions" style="margin-top: 8px"
                                  onsubmit="return confirm(<?= e(json_encode('Remove ' . $m['name'] . ' from this group?')) ?>);">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="member_id" value="<?= $id ?>">
                                <button type="submit" class="btn btn-danger btn-small">Remove from group</button>
                            </form>
                        <?php endif; ?>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>

        <details class="add-person" <?= count($members) < 2 || $person_error ? 'open' : '' ?>>
            <summary>Add a person</summary>
            <?php if ($person_error): ?>
                <p class="alert alert-bad" role="alert" style="margin: 8px 0"><?= e($person_error) ?></p>
            <?php endif; ?>
            <form method="POST" action="<?= e($self) ?>" class="stack">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_member">
                <label>Name
                    <input type="text" name="name" maxlength="100" value="<?= e($person_form['name']) ?>" required>
                </label>
                <label>Email <span class="you">(for payment reminders)</span>
                    <input type="email" name="email" value="<?= e($person_form['email']) ?>">
                </label>
                <button type="submit" class="btn">Add person</button>
            </form>
        </details>
    </section>

    <section class="panel" id="history" aria-labelledby="history-title">
        <h2 id="history-title">History</h2>
        <?php if (!$history['open'] && !$history['settled']): ?>
            <p class="empty">No expenses yet.</p>
        <?php endif; ?>

        <?php if ($history['open']): ?>
            <?php if ($history['settled']): ?>
                <h3 class="subhead" style="margin-top: 0">Not settled yet</h3>
            <?php endif; ?>
            <ul class="history-list">
                <?php foreach ($history['open'] as $expense_id => $expense): ?>
                    <li class="<?= $expense['is_payment'] ? 'is-payment' : '' ?>">
                        <div class="expense-top">
                            <span class="expense-desc"><?= e($expense['is_payment'] ? describeExpense($expense, $names) : $expense['description']) ?></span>
                            <span class="amount-plain"><?= money($expense['amount']) ?></span>
                        </div>
                        <?php if (!$expense['is_payment']): ?>
                            <p><?= e(describeExpense($expense, $names)) ?></p>
                        <?php endif; ?>
                        <details class="edit-expense">
                            <summary><?= $expense['is_payment'] ? 'Undo' : 'Edit' ?><span class="visually-hidden"> <?= e($expense['description']) ?></span></summary>
                            <?php if (!$expense['is_payment']): ?>
                                <form method="POST" action="<?= e($self) ?>" class="stack">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="edit_expense">
                                    <input type="hidden" name="expense_id" value="<?= (int) $expense_id ?>">
                                    <label>Who paid?
                                        <select name="payer_id">
                                            <?php foreach ($members as $id => $m): ?>
                                                <option value="<?= $id ?>" <?= $id === $expense['payer_id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <div class="form-row">
                                        <label>Amount (<?= e(CURRENCY) ?>)
                                            <input type="text" name="amount" inputmode="decimal" value="<?= e(number_format($expense['amount'], 2, '.', '')) ?>" required>
                                        </label>
                                        <label>What was it for?
                                            <input type="text" name="description" maxlength="255" value="<?= e($expense['description']) ?>" required>
                                        </label>
                                    </div>
                                    <fieldset>
                                        <legend>Split equally between</legend>
                                        <div class="chips">
                                            <?php foreach ($members as $id => $m): ?>
                                                <label class="chip">
                                                    <input type="checkbox" name="split_with[]" value="<?= $id ?>" <?= isset($expense['shares'][$id]) ? 'checked' : '' ?>>
                                                    <?= e($m['name']) ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>
                                    <button type="submit" class="btn btn-small">Save changes</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="<?= e($self) ?>" class="delete-expense"
                                  onsubmit="return confirm(<?= e(json_encode($expense['is_payment'] ? 'Undo this payment? The amount will be owed again.' : 'Delete ' . $expense['description'] . '?')) ?>);">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_expense">
                                <input type="hidden" name="expense_id" value="<?= (int) $expense_id ?>">
                                <button type="submit" class="btn btn-danger btn-small"><?= $expense['is_payment'] ? 'Undo payment' : 'Delete expense' ?></button>
                            </form>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php foreach ($history['settled'] as $settled_at => $round): ?>
            <?php $round_total = array_sum(array_column(array_filter($round, fn($x) => !$x['is_payment']), 'amount')); ?>
            <details class="round">
                <summary>
                    Settled on <?= date('j M Y \a\t H:i', strtotime($settled_at)) ?>
                    (<?= count($round) ?> <?= count($round) === 1 ? 'entry' : 'entries' ?>, <?= money($round_total) ?> spent)
                </summary>
                <ul class="history-list">
                    <?php foreach ($round as $expense): ?>
                        <li class="<?= $expense['is_payment'] ? 'is-payment' : '' ?>">
                            <div class="expense-top">
                                <span class="expense-desc"><?= e($expense['is_payment'] ? describeExpense($expense, $names) : $expense['description']) ?></span>
                                <span class="amount-plain"><?= money($expense['amount']) ?></span>
                            </div>
                            <?php if (!$expense['is_payment']): ?>
                                <p><?= e(describeExpense($expense, $names)) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endforeach; ?>
    </section>

    <section class="panel group-settings">
        <details>
            <summary>Group settings</summary>
            <form method="POST" action="<?= e($self) ?>" style="margin-top: 12px">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="rename">
                <label>Group name
                    <input type="text" name="name" maxlength="100" value="<?= e($group['name']) ?>" required>
                </label>
                <button type="submit" class="btn btn-small">Rename group</button>
            </form>
            <form method="POST" action="<?= e($self) ?>"
                  onsubmit="return confirm(<?= e(json_encode('Delete ' . $group['name'] . ' and all its expenses? This can\'t be undone.')) ?>);">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_group">
                <p class="muted">Deleting the group removes its people, expenses and history for good.</p>
                <button type="submit" class="btn btn-danger btn-small">Delete group</button>
            </form>
        </details>
    </section>
</main>
<script src="assets/forms.js"></script>
<script src="assets/live.js"></script>
<?php pageEnd(); ?>

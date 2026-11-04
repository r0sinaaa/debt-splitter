<?php
// Your dashboard: every group you run, what you're owed, and a little about the site
require 'includes/app.php';
$user = requireLogin();

$error = '';
$new_name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $new_name = postText('name');
    if ($new_name === '') {
        $error = 'Give the group a name, like "Flat 4B" or "Weekend in Aarhus".';
    } elseif (strlen($new_name) > 100) {
        $error = 'Keep the name under 100 characters.';
    } else {
        $group_id = createGroup($user, $new_name);
        flash("Made $new_name. Add the people you split costs with.");
        redirect("group.php?id=$group_id#people");
    }
}

$groups = getUserGroups($user['id']);

// Your own place in each group: [group_id => member_id]
$stmt = $conn->prepare("SELECT group_id, id FROM members WHERE user_id = ?");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$my_member = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $my_member[(int) $row['group_id']] = (int) $row['id'];
}

// Totals across all your groups
$owed_to_me = 0;
$i_owe = 0;
$groups_open = 0;
foreach ($groups as &$group) {
    $group['id'] = (int) $group['id'];
    $balances = getGroupBalances($group['id'], true);
    $mine = round($balances[$my_member[$group['id']] ?? 0] ?? 0, 2);

    $group['outstanding'] = groupOutstanding($group['id']);
    $group['mine'] = $mine;
    if ($mine > 0) $owed_to_me += $mine;
    if ($mine < 0) $i_owe -= $mine;
    if ($group['outstanding'] > 0) $groups_open++;
}
unset($group);

$stmt = $conn->prepare("SELECT COUNT(*) AS sent FROM reminders r
                        JOIN expense_groups g ON g.id = r.group_id
                        WHERE g.owner_id = ? AND r.status IN " . countedStatuses() . "
                          AND r.sent_at > NOW() - INTERVAL 30 DAY");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$reminders_sent = (int) $stmt->get_result()->fetch_assoc()['sent'];

pageStart('Your dashboard', $user, 'theme-blush');
?>
<main id="main" class="page">
    <div class="page-head">
        <h1>Hi <?= e($user['name']) ?></h1>
        <p>
            <?php if (!$groups): ?>
                Welcome to <?= e(APP_NAME) ?>. Start a group and add what people paid.
            <?php elseif ($groups_open === 0): ?>
                Everything's settled. Nice.
            <?php elseif ($groups_open === count($groups)): ?>
                <?= ['', 'Your group has', 'Both your groups have'][count($groups)] ?? 'All ' . count($groups) . ' of your groups have' ?>
                money still to settle.
            <?php else: ?>
                <?= $groups_open ?> of your <?= count($groups) ?> groups <?= $groups_open === 1 ? 'has' : 'have' ?> money still to settle.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($groups): ?>
        <dl class="panel glance" aria-label="At a glance">
            <div>
                <dt>You're owed</dt>
                <dd><?= money($owed_to_me) ?></dd>
            </div>
            <div>
                <dt>You owe</dt>
                <dd><?= money($i_owe) ?></dd>
            </div>
            <div>
                <dt>Reminders sent</dt>
                <dd><?= $reminders_sent ?> <span class="glance-note">in the last 30 days</span></dd>
            </div>
        </dl>
    <?php endif; ?>

    <div class="dash-layout">
        <div class="dash-main">
            <?php if ($groups): ?>
                <h2 class="dash-title">Your groups</h2>
                <ul class="group-list">
                    <?php foreach ($groups as $group): ?>
                        <li>
                            <a href="group.php?id=<?= $group['id'] ?>">
                                <span class="group-name"><?= e($group['name']) ?></span>
                                <span class="group-people"><?= (int) $group['people'] ?> <?= $group['people'] == 1 ? 'person' : 'people' ?></span>
                                <?php if ($group['mine'] > 0): ?>
                                    <span class="group-mine">You're owed <?= money($group['mine']) ?></span>
                                <?php elseif ($group['mine'] < 0): ?>
                                    <span class="group-mine">You owe <?= money(-$group['mine']) ?></span>
                                <?php endif; ?>
                                <?php if ($group['outstanding'] > 0): ?>
                                    <span class="group-status"><span class="amount"><?= money($group['outstanding']) ?></span><br>to settle</span>
                                <?php else: ?>
                                    <span class="group-status is-settled">All settled</span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <section class="panel" aria-labelledby="new-group-title">
                <h2 id="new-group-title"><?= $groups ? 'Start a new group' : 'Start your first group' ?></h2>
                <?php if (!$groups): ?>
                    <p class="muted">A group is the people you share costs with: your flat, a trip, a dinner out.</p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert alert-bad" role="alert"><?= e($error) ?></p>
                <?php endif; ?>
                <form method="POST" class="new-group">
                    <?= csrfField() ?>
                    <label>Group name
                        <input type="text" name="name" maxlength="100" value="<?= e($new_name) ?>" required <?= $groups ? '' : 'autofocus' ?>>
                    </label>
                    <button type="submit" class="btn">Create group</button>
                </form>
            </section>
        </div>

        <aside class="dash-side">
            <section class="panel about" aria-labelledby="about-title">
                <h2 id="about-title">About <?= e(APP_NAME) ?></h2>
                <p>
                    <?= e(APP_NAME) ?> keeps track of shared costs for flats, trips and dinners out,
                    and tells everyone exactly who to pay.
                </p>
                <ul>
                    <li>
                        <strong>Shares to the cent</strong>
                        <?= money(10) ?> between three people becomes <?= money(3.34) ?>, <?= money(3.33) ?> and <?= money(3.33) ?>,
                        so the shares always add up to what was paid.
                    </li>
                    <li>
                        <strong>Two ways to settle</strong>
                        Fewest payments, or only between people who shared a cost. You can switch in any group.
                    </li>
                    <li>
                        <strong>Reminders only go to people who owe</strong>
                        Nobody who's owed money gets a message, and reminders stop once a payment is marked as paid.
                    </li>
                    <li>
                        <strong>Your contacts stay with you</strong>
                        Only you can see your groups. Email addresses are only used for payment reminders.
                    </li>
                </ul>
            </section>

            <section class="panel setup" aria-labelledby="setup-title">
                <h2 id="setup-title">Email reminders</h2>
                <dl>
                    <div>
                        <dt>Email</dt>
                        <?php if (emailConfigured()): ?>
                            <dd><span class="status status-on">On</span> Reminders are emailed, and replies come back to you.</dd>
                        <?php else: ?>
                            <dd><span class="status status-off">Not set up</span> Reminders are saved instead of sent until email settings are added in config.php.</dd>
                        <?php endif; ?>
                    </div>
                    <div>
                        <dt>Automatic nudges</dt>
                        <dd>Every <?= AUTO_REMINDER_DAYS ?> days for anyone who still owes, once the daily reminder task is scheduled (see README.md).</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>
</main>
<?php pageEnd(); ?>

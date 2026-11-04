<?php
require 'includes/app.php';

$user = currentUser();
pageStart('', $user, 'page-home');
?>
<main id="main">
    <section class="hero">
        <div class="hero-text">
            <h1>Split your debts, not your friendships.</h1>
            <p class="hero-lede">
                Add what everyone paid. <?= e(APP_NAME) ?> works out who pays whom in as few payments
                as possible, then emails a reminder to the people who owe until it's settled.
            </p>
            <div class="hero-actions">
                <?php if ($user): ?>
                    <a class="btn btn-big" href="groups.php">Go to your groups</a>
                <?php else: ?>
                    <a class="btn btn-big" href="register.php">Create a free account</a>
                    <a class="text-link" href="login.php">I already have one</a>
                <?php endif; ?>
            </div>
        </div>

        <figure class="untangle" id="untangle" data-state="after">
            <svg viewBox="0 0 420 320" role="img" aria-labelledby="untangle-caption">
                <defs>
                    <marker id="arrow" viewBox="0 0 10 10" refX="7" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M0 0 L10 5 L0 10 z" fill="#000"/>
                    </marker>
                </defs>

                <!-- Before: six separate IOUs between four friends -->
                <g class="ious">
                    <path d="M300 227 L125 96" marker-end="url(#arrow)"/>
                    <path d="M90 212 L90 114" marker-end="url(#arrow)"/>
                    <path d="M128 70 L286 70" marker-end="url(#arrow)"/>
                    <path d="M120 227 L295 96" marker-end="url(#arrow)"/>
                    <path d="M292 250 L134 250" marker-end="url(#arrow)"/>
                    <path d="M330 108 L330 206" marker-end="url(#arrow)"/>
                    <g class="tag"><rect x="222" y="174" width="44" height="22" rx="11"/><text x="244" y="189"><?= e(CURRENCY) ?>12</text></g>
                    <g class="tag"><rect x="72" y="149" width="36" height="22" rx="11"/><text x="90" y="164"><?= e(CURRENCY) ?>8</text></g>
                    <g class="tag"><rect x="192" y="59" width="36" height="22" rx="11"/><text x="210" y="74"><?= e(CURRENCY) ?>6</text></g>
                    <g class="tag"><rect x="154" y="174" width="44" height="22" rx="11"/><text x="176" y="189"><?= e(CURRENCY) ?>10</text></g>
                    <g class="tag"><rect x="192" y="239" width="36" height="22" rx="11"/><text x="210" y="254"><?= e(CURRENCY) ?>4</text></g>
                    <g class="tag"><rect x="312" y="149" width="36" height="22" rx="11"/><text x="330" y="164"><?= e(CURRENCY) ?>3</text></g>
                </g>

                <!-- After: the same money, settled in two payments -->
                <g class="payments">
                    <path d="M90 212 L90 114" marker-end="url(#arrow)" pathLength="1"/>
                    <path d="M330 212 L330 114" marker-end="url(#arrow)" pathLength="1"/>
                    <g class="tag tag-marked"><rect x="100" y="149" width="50" height="22" rx="11"/><text x="125" y="164"><?= e(CURRENCY) ?>14</text></g>
                    <g class="tag tag-marked"><rect x="340" y="149" width="50" height="22" rx="11"/><text x="365" y="164"><?= e(CURRENCY) ?>13</text></g>
                </g>

                <g class="people">
                    <g><circle cx="90" cy="70" r="34"/><text x="90" y="75">Asha</text></g>
                    <g><circle cx="330" cy="70" r="34"/><text x="330" y="75">Niraj</text></g>
                    <g><circle cx="90" cy="250" r="34"/><text x="90" y="255">Leo</text></g>
                    <g><circle cx="330" cy="250" r="34"/><text x="330" y="255">Mia</text></g>
                </g>
            </svg>
            <figcaption id="untangle-caption">
                <span class="caption-before">Six IOUs between four friends after a weekend away.</span>
                <span class="caption-after">The same money, settled in two payments.</span>
            </figcaption>
            <button type="button" class="btn btn-quiet btn-small" id="untangle-toggle" aria-controls="untangle" hidden>
                Show the IOUs again
            </button>
        </figure>
    </section>

    <section class="steps" aria-labelledby="steps-title">
        <h2 id="steps-title">How it works</h2>
        <ol>
            <li>
                <h3>Start a group</h3>
                <p>Your flat, a trip, a dinner out. Add everyone with their email address. Only you need an account.</p>
            </li>
            <li>
                <h3>Add what was paid</h3>
                <p>Who paid, how much, and who it was for. Shares are worked out to the cent, so they always add up.</p>
            </li>
            <li>
                <h3>Settle up</h3>
                <p>Everyone gets a short list of who to pay. Mark payments as they come in, and the rest stays on the list.</p>
            </li>
        </ol>
    </section>

    <section class="ways" aria-labelledby="ways-title">
        <h2 id="ways-title">Two ways to settle</h2>
        <div class="ways-grid">
            <div>
                <h3>Fewest payments</h3>
                <p>The smallest number of transfers, even if that means paying someone you didn't split anything with directly.</p>
            </div>
            <div>
                <h3>Only people who shared</h3>
                <p>Everyone pays someone they actually split a cost with. Sometimes it takes an extra payment, but nobody gets a request from a stranger.</p>
            </div>
        </div>
    </section>

    <section class="nudge" aria-labelledby="nudge-title">
        <div class="nudge-text">
            <h2 id="nudge-title">Reminders go to the people who owe. Nobody else.</h2>
            <p>
                When you send reminders, everyone who still owes gets an email with their own amounts.
                People who are owed money never get a message. Reminders stop the moment a payment is marked as paid.
            </p>
        </div>
        <div class="email-msg" aria-label="Example reminder email">
            <p class="msg-subject">Reminder: you owe <?= e(CURRENCY) ?>14.00 for Weekend in Aarhus</p>
            <p class="msg-from">From <?= e(APP_NAME) ?>, on behalf of Asha</p>
            <p class="msg-body">Hi Leo,<br><br>This is a reminder from Asha about Weekend in Aarhus. Here's what you still need to pay:</p>
            <p class="msg-line"><span>Asha</span><span><?= e(CURRENCY) ?>14.00</span></p>
        </div>
    </section>

    <section class="closer">
        <h2>Start with the next thing you split.</h2>
        <?php if ($user): ?>
            <a class="btn btn-big" href="groups.php">Go to your groups</a>
        <?php else: ?>
            <a class="btn btn-big" href="register.php">Create a free account</a>
        <?php endif; ?>
    </section>
</main>
<script src="assets/home.js"></script>
<?php pageEnd(); ?>

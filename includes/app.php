<?php
// Loads everything a page needs. Every page starts with: require 'includes/app.php';

require_once __DIR__ . '/db.php';           // also loads config.php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/debt_graph.php';     // the graph every settlement method works on
require_once __DIR__ . '/balances.php';
require_once __DIR__ . '/algorithm.php';
require_once __DIR__ . '/optimal.php';
require_once __DIR__ . '/shared_settlement.php';
require_once __DIR__ . '/settlement.php';
require_once __DIR__ . '/graph_view.php';
require_once __DIR__ . '/history.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/reminders.php';

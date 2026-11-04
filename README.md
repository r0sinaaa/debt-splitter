# Evenly: split your debts, not your friendships

A PHP and MySQL site for splitting shared costs. You add what everyone paid, it works out who pays whom,
and it emails a reminder to the people who owe. People who are owed money never get a message.

## Run it with XAMPP

1. Copy the `split-your-debt` folder into `C:\xampp\htdocs\` (on a Mac: `/Applications/XAMPP/htdocs/`).
2. Copy `config.example.php` to `config.php` (passwords go in `config.php`, which is never uploaded to GitHub).
3. Start **Apache** and **MySQL** in the XAMPP control panel.
4. Open <http://localhost/split-your-debt/>.

The database (`split_your_debt`) and its tables are created the first time a page loads.
If your MySQL user isn't `root` with no password, change it in `config.php`.

## How it works

- You log in with your email and a password. Only the person running a group needs an account.
  Everyone else is just a name, plus an email address for reminders.
- Every group's debts are kept as a **debt graph** (`includes/debt_graph.php`): each person is a node and each
  debt is an arrow from the person who owes to the person they owe, weighted by the amount. It's rebuilt from the
  expenses whenever it's needed, and two opposite debts between the same people become one arrow.
  Everyone's balance is read off the graph (money in on incoming arrows minus money out on outgoing ones).
- **Who pays whom** turns the debt graph into a settlement graph with fewer arrows, where every arrow is a payment
  and everyone keeps the same balance. It has two modes. *Fewest payments* uses the exact optimum
  (`includes/optimal.php`, dynamic programming over subsets, which finds the settlement forest with the most
  separate trees) when up to 16 people are not even, and the greedy algorithm (`includes/algorithm.php`) above that.
  *Only people who shared* (`includes/shared_settlement.php`) cancels cycles of debt with depth-first search,
  shortens chains, and routes money with breadth-first search, only along pairs who have shared an expense.
- The group page draws both graphs side by side (`includes/graph_view.php`): the debts as recorded and the settlement.
- The group page updates itself every 5 seconds with **AJAX polling** (`assets/live.js` asks `group_state.php`
  for a fingerprint of the group and swaps in changed sections). Anything you're typing is left alone.
- **Mark as paid** records one payment (you can undo it from History). **Mark everything as paid** closes the round and starts a fresh one.
- Expenses that aren't settled yet can be **edited or deleted** from History. Adding the same expense twice within 30 seconds is ignored.
- All money is worked out in whole cents, so amounts always add up exactly.
- **Send reminder** emails the people who owe. The same person can't be reminded
  again within 12 hours. Change `REMINDER_COOLDOWN_HOURS` in `config.php` if you want a different wait.

## Turn on real emails

Until this is set up, reminders are written to `storage/outbox.log` so you can see them.

With Gmail:
1. Turn on 2-Step Verification for your Google account.
2. Create an app password at <https://myaccount.google.com/apppasswords>.
3. In `config.php` set `SMTP_HOST` to `'smtp.gmail.com'`, `SMTP_USER` and `MAIL_FROM` to your Gmail address,
   and `SMTP_PASS` to the app password.

Replies to a reminder go to the group owner's email, if they have one.

## Send reminders automatically

`send_reminders.php` reminds everyone who still owes, at most once every `AUTO_REMINDER_DAYS` days (3 by default).
Run it once a day:

- **Windows (Task Scheduler):** create a daily task. Program: `C:\xampp\php\php.exe`.
  Arguments: `C:\xampp\htdocs\split-your-debt\send_reminders.php`
- **Mac/Linux (cron):** `0 18 * * * /Applications/XAMPP/bin/php /Applications/XAMPP/htdocs/split-your-debt/send_reminders.php`

You can also run it by hand to test: `php send_reminders.php`

## Tests

The debt graph, the settlement methods and amount parsing have tests that don't need the database:

```
C:\xampp\php\php.exe tests\run_tests.php
```

Besides hand-checked examples, they run thousands of random groups through the graph and the algorithms and check that:
the graph's balances match the expenses; every settlement graph leaves everyone at exactly 0.00; greedy and exact
settlements are forests (no cycles); the exact one is a forest of zero-sum trees and matches a separate brute-force
search; greedy's extra payments are exactly the trees it failed to separate; and "Only people who shared" only
uses pairs who have shared an expense.

## Benchmark (the thesis results)

```
C:\xampp\php\php.exe tests\benchmark.php
```

Builds the debt graph of 2,700 random groups (seed 2026), settles each one with greedy, exact and "only people who
shared", compares them with the naive baseline (the number of edges in the debt graph), checks every settlement graph, and writes `tests/results/benchmark_summary.csv` and `benchmark_trials.csv`. Takes a minute or two.

## If something goes wrong

- Errors show a short "Something went wrong" page with a hint. On your own computer you also see the details;
  everyone else doesn't. Everything is written to `storage/error.log`.
- A setting missing from `config.php` no longer breaks the site. A default is used and noted in `storage/error.log`.

- **"SSL certificate problem" when sending email on Windows:** PHP can't find a list of trusted certificates.
  In `C:\xampp\php\php.ini`, set `curl.cainfo` and `openssl.cafile` to a CA bundle file
  (download `cacert.pem` from <https://curl.se/docs/caextract.html>), then restart Apache.

## Files

| File | What it does |
| --- | --- |
| `index.php` | Home page |
| `register.php`, `login.php`, `logout.php` | Accounts (email + password) |
| `groups.php` | Your dashboard: your groups, what you owe and are owed, and info about the site |
| `group.php` | One group: expenses, who pays whom, reminders, people, history |
| `group_state.php` | Small JSON endpoint polled by the group page for live updates |
| `send_reminders.php` | Automatic reminders, run on a schedule |
| `config.php` | All settings |
| `includes/` | Shared code: database, accounts, settlement, reminders, email (PHPMailer) |
| `assets/` | Styles and the home page animation |
| `tests/` | `run_tests.php` (tests) and `benchmark.php` (thesis experiments, results in `tests/results/`) |
| `storage/` | `outbox.log` while email isn't set up, and `error.log` |

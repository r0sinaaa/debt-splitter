-- The tables for the site. includes/db.php runs this automatically the first
-- time a page loads, so you don't need to import it yourself.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS expense_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    settle_mode VARCHAR(10) NOT NULL DEFAULT 'fewest',   -- 'fewest' or 'shared'
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id)
);

-- Everyone in a group. Only the group's owner needs an account (user_id);
-- everyone else just needs a name, plus an email for reminders.
CREATE TABLE IF NOT EXISTS members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    FOREIGN KEY (group_id) REFERENCES expense_groups(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    payer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    is_payment TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = someone paying back what they owed
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settled_at DATETIME NULL,                   -- set by "Mark everything as paid"
    FOREIGN KEY (group_id) REFERENCES expense_groups(id),
    FOREIGN KEY (payer_id) REFERENCES members(id)
);

CREATE TABLE IF NOT EXISTS expense_splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    member_id INT NOT NULL,
    share DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (expense_id) REFERENCES expenses(id),
    FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Every reminder sent, so people aren't reminded too often
CREATE TABLE IF NOT EXISTS reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    member_id INT NOT NULL,
    channel VARCHAR(10) NOT NULL,     -- always 'email'
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(10) NOT NULL,      -- 'sent', 'logged' (email not set up yet) or 'failed'
    error VARCHAR(255) NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES expense_groups(id),
    FOREIGN KEY (member_id) REFERENCES members(id)
);

-- One-time links for "Forgot your password?" (only a hash of each token is stored)
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

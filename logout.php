<?php
require 'includes/app.php';

// Logging out only happens through the button's form, so a link on
// another site can't log you out
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
checkCsrf();

logOut();
session_start();   // a fresh session, just to carry the message below
flash('You\'ve been logged out.');
redirect('login.php');

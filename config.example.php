<?php
// Settings for the whole site. Copy this file to config.php and edit that copy.
// config.php is not uploaded to GitHub, so your passwords stay on your computer.

// ---- The site -------------------------------------------------------------
const APP_NAME = 'Evenly';
const CURRENCY = 'dkk';
const TIMEZONE = 'Europe/Copenhagen';

// ---- Database -------------------------------------------------------------
// XAMPP's default is user "root" with no password.
// The database and its tables are created for you the first time a page loads.
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'evenly_db';

// ---- Email reminders ------------------------------------------------------
// Sent through an SMTP server. With Gmail:
//   1. Turn on 2-Step Verification for your Google account
//   2. Create an app password at https://myaccount.google.com/apppasswords
//   3. Fill in: SMTP_HOST 'smtp.gmail.com', SMTP_USER your Gmail address,
//      SMTP_PASS the 16-letter app password, MAIL_FROM your Gmail address
// While SMTP_HOST is empty, emails are written to storage/outbox.log instead,
// so you can test everything without sending real email.
const SMTP_HOST = '';           // empty = write emails to storage/outbox.log instead
const SMTP_PORT = 587;
const SMTP_SECURE = 'tls';       // 'tls' for port 587, 'ssl' for port 465, '' for none
const SMTP_USER = '';
const SMTP_PASS = '';
const MAIL_FROM = '';
const MAIL_FROM_NAME = APP_NAME;

// ---- When reminders go out ------------------------------------------------
const REMINDER_COOLDOWN_HOURS = 0;   // no wait: you can send a reminder any time
const AUTO_REMINDER_DAYS = 3;         // send_reminders.php nudges people who still owe this often
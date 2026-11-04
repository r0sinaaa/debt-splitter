<?php
// Small helpers used across the site

// Escape text before printing it into HTML
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string {
    return CURRENCY . number_format($amount, 2);
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

// A text field from the submitted form ('' if it's missing or not text)
function postText(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function postInt(string $key): int {
    $value = $_POST[$key] ?? '';
    return is_string($value) && ctype_digit($value) ? (int) $value : 0;
}

// Reads an amount typed into a form. "12", "12.5", "12.50" and "12,50" are all fine.
// Returns null for anything else ("1.234,56", "12abc", "1e3"), so the form can say so
// instead of quietly saving the wrong number.
function parseAmount(string $raw): ?float {
    $raw = str_replace([' ', "\u{00A0}"], '', $raw);
    if (!preg_match('/^\d{1,8}([.,]\d{1,2})?$/', $raw)) {
        return null;
    }
    return round((float) str_replace(',', '.', $raw), 2);
}

function cleanEmail(string $raw): string {
    return strtolower(trim($raw));
}

// Checks a name and email typed into a form.
// Returns [error message or '', ['name' => ..., 'email' => ?string]]
function checkContactFields(string $name, string $email): array {
    $email = cleanEmail($email);
    $clean = ['name' => $name, 'email' => $email === '' ? null : $email];

    if ($name === '') return ['Add a name.', $clean];
    if (strlen($name) > 100) return ['Keep the name under 100 characters.', $clean];
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255)) {
        return ['That email address doesn\'t look right.', $clean];
    }
    return ['', $clean];
}

// One-off messages shown after a redirect, e.g. "Added Asha."
function flash(string $text, string $type = 'good'): void {
    $_SESSION['flash'] = ['text' => $text, 'type' => $type];
}

function takeFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function timeAgo(string $datetime): string {
    $seconds = max(0, time() - strtotime($datetime));
    if ($seconds < 60) return 'just now';
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) return $minutes === 1 ? '1 minute ago' : "$minutes minutes ago";
    $hours = intdiv($minutes, 60);
    if ($hours < 24) return $hours === 1 ? '1 hour ago' : "$hours hours ago";
    $days = intdiv($hours, 24);
    return $days === 1 ? 'yesterday' : "$days days ago";
}

// "Asha", "Asha and Mia", "Asha, Mia and Leo"
function listNames(array $names): string {
    if (count($names) <= 1) return implode('', $names);
    return implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
}

<?php
// Used by the public registration form: is this email free to register?
// GET ?email=...  ->  {"available": true|false}
// Checks staff and Design Creator accounts. Limited to 10 checks per minute per IP address.
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/freelancer_profile.php';

// Every answer takes a little longer, which slows down anyone trying lots of emails.
usleep(200000);

if (!phase8_ready()) {
    http_response_code(503);
    echo json_encode(['error' => 'Registration is not open yet.']);
    exit;
}
if (rate_limited('check_email', 10, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many checks. Please wait a minute and try again.']);
    exit;
}
rate_log('check_email');

$email = strtolower(trim((string)($_GET['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    http_response_code(400);
    echo json_encode(['error' => 'Please type a valid email address.']);
    exit;
}
echo json_encode(['available' => !email_taken($email)]);

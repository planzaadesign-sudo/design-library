<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$pdo = getDB();
$type = ($_POST['type'] ?? $_GET['type'] ?? 'staff') === 'freelancer' ? 'freelancer' : 'staff'; // "freelancer" stays in URLs for old links
$error = '';
$challengeError = '';
$callout = null; // [colour, title, message HTML]
$secure = security_ready();
$email = '';

// Messages from redirects.
if (!empty($_GET['expired']) || ($_SESSION['expired'] ?? '') !== '') {
    $callout = ['amber', 'Your session has expired. Please sign in again.', 'For your safety we sign you out after a while without activity.'];
    unset($_SESSION['expired']);
} elseif (!empty($_GET['replaced'])) {
    $callout = ['amber', 'You were signed out.', 'Your account was signed in from another browser. Only one admin session can be active at a time.'];
} elseif (!empty($_GET['signed_out'])) {
    $callout = ['green', 'You have signed out.', 'See you soon.'];
} elseif (!empty($_GET['reset'])) {
    $callout = ['green', 'Your password has been changed.', 'Sign in with your new password.'];
}

// The math question appears after a few failed tries (from this browser or this internet connection).
$needChallenge = function () use ($secure) {
    return (int)($_SESSION['login_fails'] ?? 0) >= LOGIN_CHALLENGE_AFTER || ($secure && login_recent_ip_fails() >= LOGIN_CHALLENGE_AFTER);
};
$failed = function ($email) use ($secure) {
    $_SESSION['login_fails'] = (int)($_SESSION['login_fails'] ?? 0) + 1;
    if ($secure) login_log($email, false);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $callout = null;
    $ipWait = $secure ? login_ip_wait() : 0;
    $emailWait = $secure && !$ipWait ? login_email_wait($email) : 0;

    if (!sec_form_ok('login')) {
        $error = 'This page was open for too long. Please try again.';
    } elseif ($ipWait) {
        $error = 'Too many failed attempts. Please try again in 15 minutes.';
    } elseif ($emailWait) {
        $error = 'This account has been temporarily locked due to too many failed sign-in attempts. Try again in ' . minutes_text($emailWait) . ' or reset your password.';
    } elseif ($needChallenge() && !challenge_check('login', $_POST['challenge'] ?? '')) {
        $challengeError = trim((string)($_POST['challenge'] ?? '')) === '' ? 'Please answer the question.' : 'That answer is not right. Please try again.';
        $error = 'Please answer the quick check below.';
        $failed($email);
    } else {
        $table = $type === 'freelancer' ? 'freelancers' : 'staff';
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // Same work whether or not the email exists, so response times do not give it away.
        $ok = password_verify($password, $user ? $user['password_hash'] : '$2y$10$OdrYGhZu5YOjVC6NuAVB5umqoIWAPQKg6izfB848EFQzEsMD30qaS');
        if (!$user || !$ok) {
            $failed($email);
            $error = 'Incorrect email or password.';
        } else {
            if ($secure) { login_log($email, true); login_clear($email); }
            unset($_SESSION['login_fails']);
            challenge_clear('login');
            if ($type === 'freelancer') {
                // Only active accounts may sign in. (Checked here, not in auth.php, so a Design Creator who is
                // already signed in is not thrown out mid-session.) No status column = before phase 8 = active.
                $status = $user['status'] ?? 'active';
                if ($status === 'pending') {
                    $callout = ['amber', 'Your account is still under review.', 'Our team will activate it within 48 hours. Contact us at <a href="mailto:info@planzaa.in">info@planzaa.in</a> if you have questions.'];
                } elseif ($status === 'rejected') {
                    $reason = trim((string)($user['rejection_reason'] ?? ''));
                    $callout = ['red', 'Your registration was not approved.', ($reason !== '' ? 'Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '. ' : '') . 'Contact us at <a href="mailto:info@planzaa.in">info@planzaa.in</a> if you think this is a mistake.'];
                } elseif ($status === 'suspended') {
                    $callout = ['red', 'Your account has been suspended.', 'Contact us at <a href="mailto:info@planzaa.in">info@planzaa.in</a> for more information.'];
                } else {
                    sec_sign_in_freelancer($user);
                    header('Location: freelancer/index.php');
                    exit;
                }
            } else {
                sec_sign_in_staff($user);
                if (!empty($_SESSION['staff_must_change'])) { header('Location: set-password.php'); exit; }
                header('Location: ' . ($user['role'] === 'admin' ? 'admin/index.php' : 'inhouse/index.php'));
                exit;
            }
        }
    }
}
$showChallenge = !$callout && $needChallenge() && !($secure && ($error !== '' && strpos($error, 'Too many') === 0));
if ($showChallenge && $challengeError === '') challenge_new('login'); // a fresh question each time the form is shown
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260926a">
<link rel="stylesheet" href="assets/register.css?v=3">
<link rel="stylesheet" href="assets/password.css?v=1">
</head>
<body>
<div class="topbar"><div class="topbar-inner"><div class="wordmark">planzaa<span>.</span> team</div></div></div>
<div class="wrap"><div class="login-box">
  <h1>Sign in</h1>
  <div class="login-tabs">
    <a class="login-tab <?= $type === 'staff' ? 'active' : '' ?>" href="?type=staff">Staff</a>
    <a class="login-tab <?= $type === 'freelancer' ? 'active' : '' ?>" href="?type=freelancer">Design Creator</a>
  </div>
  <?php if ($callout): ?><div class="login-callout <?= $callout[0] ?>" role="alert"><strong><?= $h($callout[1]) ?></strong><?= $callout[2] ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-note" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <form method="POST" action="login.php">
    <input type="hidden" name="type" value="<?= $h($type) ?>">
    <input type="hidden" name="csrf" value="<?= $h(sec_form_token('login')) ?>">
    <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required autocomplete="email" value="<?= $h($email) ?>"></div>
    <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
    <p class="forgot-link"><a href="forgot-password.php?type=<?= $h($type) ?>">Forgot your password?</a></p>
    <?php if ($showChallenge): ?><?= challenge_html('login', $challengeError) ?><?php endif; ?>
    <button class="btn btn-primary btn-block" style="margin-top:6px" type="submit">Sign in</button>
  </form>
  <?php if ($type === 'freelancer'): ?>
  <p class="login-register">Don't have an account? <a href="register.php">Join as a Design Creator &rarr;</a></p>
  <?php endif; ?>
</div></div>
</body>
</html>

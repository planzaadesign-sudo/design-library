<?php
// "Forgot your password?" for staff and Design Creators. Always shows the same message, so
// nobody can use this page to find out which emails have an account.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/auth_ui.php';
require_once __DIR__ . '/includes/freelancer_profile.php'; // fp_mail(), fp_site_url(), rate limits

$type = ($_GET['type'] ?? $_POST['type'] ?? 'staff') === 'freelancer' ? 'freelancer' : 'staff';
$sent = false;
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && security_ready()) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!sec_form_ok('forgot')) {
        $error = 'This page was open for too long. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please type the email address you sign in with.';
    } elseif (phase8_ready() && rate_limited('forgot_ip', 10, 3600)) {
        $error = 'Too many reset requests from this internet connection. Please try again in an hour.';
    } else {
        if (phase8_ready()) rate_log('forgot_ip');
        // Staff first, then Design Creators (an email belongs to at most one account).
        $user = sec_q("SELECT id, name, email FROM staff WHERE email = ?", [$email])->fetch();
        $userType = 'staff';
        if (!$user) { $user = sec_q("SELECT id, name, email FROM freelancers WHERE email = ?", [$email])->fetch(); $userType = 'freelancer'; }
        // At most 3 reset emails per account per hour; above that we quietly send nothing.
        if ($user && reset_requests_last_hour($userType, $user['id']) < RESET_PER_EMAIL_PER_HOUR) {
            $token = reset_token_create($userType, $user['id']);
            $link = fp_site_url() . '/reset-password.php?token=' . $token;
            $body = "Hi {$user['name']},\n\n"
                . "Someone (hopefully you) asked to reset the password for your Planzaa account.\n\n"
                . "Set a new password here (the link works for 1 hour, and only once):\n{$link}\n\n"
                . "If you did not ask for this, you can ignore this email — your password stays the same.\n\n— Planzaa\n";
            try { fp_mail($user['email'], 'Reset your Planzaa password', $body); } catch (Throwable $e) { error_log('Reset email failed: ' . $e->getMessage()); }
        } else {
            usleep(random_int(150000, 350000)); // about the time sending takes, so timing reveals nothing either
        }
        $sent = true;
    }
}

echo auth_page_start('Forgot your password?');
if ($sent): ?>
<h1>Check your email</h1>
<div class="login-callout green" role="status"><strong>If this email is registered, we've sent a reset link.</strong>The link works for 1 hour. Check your spam folder if you don't see it in a few minutes.</div>
<p class="back-to"><a href="login.php?type=<?= sec_h($type) ?>">&larr; Back to sign in</a></p>
<?php elseif (!security_ready()): ?>
<h1>Forgot your password?</h1>
<p class="lead">Password reset is not switched on yet. Please ask the admin to reset it for you, or write to <a href="mailto:info@planzaa.in">info@planzaa.in</a>.</p>
<p class="back-to"><a href="login.php?type=<?= sec_h($type) ?>">&larr; Back to sign in</a></p>
<?php else: ?>
<h1>Forgot your password?</h1>
<p class="lead">Type the email you sign in with. We'll send you a link to set a new password.</p>
<?php if ($error): ?><div class="error-note" role="alert"><?= sec_h($error) ?></div><?php endif; ?>
<form method="post" action="forgot-password.php">
  <input type="hidden" name="csrf" value="<?= sec_h(sec_form_token('forgot')) ?>">
  <input type="hidden" name="type" value="<?= sec_h($type) ?>">
  <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required autocomplete="email" value="<?= sec_h($email) ?>"></div>
  <button class="btn btn-primary btn-block" type="submit">Send me a reset link</button>
</form>
<p class="back-to"><a href="login.php?type=<?= sec_h($type) ?>">&larr; Back to sign in</a></p>
<?php endif;
echo auth_page_end();

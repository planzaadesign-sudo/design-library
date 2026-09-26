<?php
// Opened from the reset email. The link works once, for 1 hour. Using it also cancels every
// other open reset link for the same account.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/auth_ui.php';

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$row = security_ready() ? reset_token_find($token) : null;
$error = '';

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string)($_POST['new_password'] ?? '');
    $table = $row['user_type'] === 'staff' ? 'staff' : 'freelancers';
    $account = sec_q("SELECT id, email, password_hash FROM $table WHERE id = ?", [(int)$row['user_id']])->fetch();
    if (!sec_form_ok('reset')) $error = 'This page was open for too long. Please try again.';
    elseif (!$account) $row = null;
    elseif ($rule = password_rule_error($new)) $error = $rule;
    elseif ($new !== (string)($_POST['password2'] ?? '')) $error = 'The two passwords do not match.';
    elseif (password_verify($new, $account['password_hash'])) $error = 'Please choose a password you have not used for this account before.';
    else {
        // Use the token up first (all of this account's tokens), so a second click can never reuse it.
        $st = sec_q("UPDATE password_reset_tokens SET used = 1 WHERE id = ? AND used = 0", [(int)$row['id']]);
        if ($st->rowCount() === 1) {
            reset_tokens_invalidate($row['user_type'], $row['user_id']);
            if ($table === 'staff') {
                // New password, no forced change, and any signed-in admin browser is signed out.
                sec_q("UPDATE staff SET password_hash = ?, must_change_password = 0, session_token = NULL WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), (int)$account['id']]);
            } else {
                sec_q("UPDATE freelancers SET password_hash = ? WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), (int)$account['id']]);
            }
            // A reset also lifts a lock caused by failed sign-ins.
            sec_q("UPDATE login_attempts SET cleared = 1 WHERE email = ? AND success = 0 AND cleared = 0", [strtolower($account['email'])]);
            header('Location: login.php?type=' . ($table === 'staff' ? 'staff' : 'freelancer') . '&reset=1');
            exit;
        }
        $row = null;
    }
}

echo auth_page_start('Set a new password', true);
if (!$row): ?>
<h1>This link can't be used</h1>
<div class="login-callout red" role="alert"><strong>This reset link has expired or has already been used.</strong>Please request a new one.</div>
<a class="btn btn-primary btn-block" href="forgot-password.php">Request a new link</a>
<p class="back-to"><a href="login.php">&larr; Back to sign in</a></p>
<?php else: ?>
<h1>Set a new password</h1>
<p class="lead">Choose a new password for your Planzaa account.</p>
<form method="post" action="reset-password.php">
  <input type="hidden" name="csrf" value="<?= sec_h(sec_form_token('reset')) ?>">
  <input type="hidden" name="token" value="<?= sec_h($token) ?>">
  <?= auth_new_password_fields($error) ?>
  <button class="btn btn-primary btn-block" type="submit">Save my new password</button>
</form>
<?php endif;
echo auth_page_end((bool)$row);

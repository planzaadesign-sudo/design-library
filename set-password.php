<?php
// "Set your password": staff land here after signing in with a temporary password (or after the
// admin asked everyone to change theirs). Nothing else opens until a new password is set.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/auth_ui.php';

if (empty($_SESSION['staff_id'])) { header('Location: login.php?type=staff' . (($_SESSION['expired'] ?? '') === 'staff' ? '&expired=1' : '')); exit; }
$state = sec_staff_state();
if ($state === 'gone' || $state === 'replaced') { sec_logout(); header('Location: login.php?type=staff' . ($state === 'replaced' ? '&replaced=1' : '')); exit; }
$home = ($_SESSION['staff_role'] ?? '') === 'admin' ? 'admin/index.php' : 'inhouse/index.php';
if ($state !== 'must_change') { header('Location: ' . $home); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string)($_POST['new_password'] ?? '');
    $hash = (string)sec_q("SELECT password_hash FROM staff WHERE id = ?", [(int)$_SESSION['staff_id']])->fetchColumn();
    if (!sec_form_ok('setpw')) $error = 'This page was open for too long. Please try again.';
    elseif ($rule = password_rule_error($new)) $error = $rule;
    elseif ($new !== (string)($_POST['password2'] ?? '')) $error = 'The two passwords do not match.';
    elseif (password_verify($new, $hash)) $error = 'Please choose a new password — you cannot keep the temporary one.';
    else {
        sec_q("UPDATE staff SET password_hash = ?, must_change_password = 0 WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['staff_id']]);
        session_regenerate_id(true);
        $_SESSION['staff_must_change'] = false;
        if ($home === 'admin/index.php') $_SESSION['admin_flash'] = ['ok', 'Your new password is set. Welcome!'];
        else $_SESSION['dash_flash'] = ['ok', 'Your new password is set. Welcome!'];
        header('Location: ' . $home);
        exit;
    }
}

echo auth_page_start('Set your password');
?>
<h1>Set your password</h1>
<p class="lead">Hi <?= sec_h($_SESSION['staff_name'] ?? '') ?>. Before you continue, please choose your own password. You cannot keep the one you were given.</p>
<form method="post" action="set-password.php">
  <input type="hidden" name="csrf" value="<?= sec_h(sec_form_token('setpw')) ?>">
  <?= auth_new_password_fields($error) ?>
  <button class="btn btn-primary btn-block" type="submit">Save my new password</button>
</form>
<p class="back-to"><a href="logout.php">Sign out</a></p>
<?php
echo auth_page_end(true);

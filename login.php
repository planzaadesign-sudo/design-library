<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$pdo = getDB();
$type = $_GET['type'] ?? 'staff';
$error = '';
$callout = null; // [colour, title, message] for designer accounts that cannot sign in yet

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $type = $_POST['type'] ?? 'staff';

    if ($type === 'freelancer') {
        $stmt = $pdo->prepare("SELECT * FROM freelancers WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            // Only active accounts may sign in. (Checked here, not in auth.php, so a designer who is
            // already signed in is not thrown out mid-session.) No status column = before phase 8 = active.
            $status = $user['status'] ?? 'active';
            if ($status === 'pending') {
                $callout = ['amber', 'Your account is still under review.', 'Our team will activate it within 48 hours. Contact us at <a href="mailto:info@planzaa.in">info@planzaa.in</a> if you have questions.'];
            } elseif ($status === 'rejected') {
                $reason = trim((string)($user['rejection_reason'] ?? ''));
                $callout = ['red', 'Your registration was not approved.', ($reason !== '' ? 'Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '. ' : '') . 'Contact us at <a href="mailto:info@planzaa.in">info@planzaa.in</a> if you think this is a mistake.'];
            } elseif ($status === 'suspended') {
                $callout = ['red', 'Your account has been suspended.', 'Contact us at <a href="mailto:info@planzaa.in">info@planzaa.in</a> for more information.'];
            }
        }
        if (!$callout && $user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['freelancer_id'] = $user['id'];
            $_SESSION['freelancer_name'] = $user['name'];
            header('Location: freelancer/index.php');
            exit;
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['staff_id'] = $user['id'];
            $_SESSION['staff_name'] = $user['name'];
            $_SESSION['staff_role'] = $user['role'];
            header('Location: ' . ($user['role'] === 'admin' ? 'admin/index.php' : 'inhouse/index.php'));
            exit;
        }
    }
    if (!$callout) $error = 'Incorrect email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260926a">
<link rel="stylesheet" href="assets/register.css?v=2">
</head>
<body>
<div class="topbar"><div class="topbar-inner"><div class="wordmark">planzaa<span>.</span> team</div></div></div>
<div class="wrap"><div class="login-box">
  <h1>Sign in</h1>
  <div class="login-tabs">
    <a class="login-tab <?= $type==='staff'?'active':'' ?>" href="?type=staff">Staff</a>
    <a class="login-tab <?= $type==='freelancer'?'active':'' ?>" href="?type=freelancer">Freelancer</a>
  </div>
  <?php if ($callout): ?><div class="login-callout <?= $callout[0] ?>" role="alert"><strong><?= htmlspecialchars($callout[1]) ?></strong><?= $callout[2] ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-note"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="POST">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    <div class="field"><label>Email</label><input type="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['email'] ?? '') : '') ?>"></div>
    <div class="field"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary btn-block" style="margin-top:6px" type="submit">Sign in</button>
  </form>
  <?php if ($type === 'freelancer'): ?>
  <p class="login-register">Don't have an account? <a href="register.php">Register as a designer &rarr;</a></p>
  <?php endif; ?>
</div></div>
</body>
</html>

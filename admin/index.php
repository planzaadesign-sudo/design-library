<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
requireStaff('admin');

// Tab files and helpers check this before doing anything, so they can never run on their own.
define('PLANZAA_ADMIN', true);
require __DIR__ . '/_lib.php';
require_once __DIR__ . '/../includes/brief_ui.php'; // similarity engine, brief form, publishing
require_once __DIR__ . '/../includes/design_ui.php'; // design codes, files, history, order notes
require_once __DIR__ . '/../includes/freelancer_profile.php'; // Design Creator registrations

$pdo = getDB();
$myId = (int)$_SESSION['staff_id'];

$TABS = [
    'dashboard' => 'Dashboard', 'orders' => 'Orders', 'designs' => 'Designs', 'modifications' => 'Modifications',
    'briefs' => 'Briefs', 'submissions' => 'Submissions', 'team' => 'Team', 'freelancers' => 'Design Creators', 'settings' => 'Settings',
];
$tab = $_GET['tab'] ?? 'dashboard';
if (!isset($TABS[$tab])) $tab = 'dashboard';

$problems = schema_problems();

if (!$problems) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // A file bigger than the server allows arrives with an empty form (no token either).
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('That file is too big for the server to accept. Please make it smaller and try again.', 'err');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
            exit;
        }
        csrf_check();
        require __DIR__ . '/_actions.php'; // every action ends in a redirect
        exit;
    }
    if (($_GET['export'] ?? '') === 'orders') {
        require __DIR__ . '/_export.php';
        exit;
    }
}

$flash = take_flash();
$OLD = $_SESSION['admin_old'] ?? [];
unset($_SESSION['admin_old']);

// Small counters next to the menu items that need attention.
$counts = ['orders' => 0, 'submissions' => 0, 'freelancers' => 0];
if (!$problems) {
    $counts['orders'] = (int)q("SELECT COUNT(*) FROM library_orders WHERE status <> 'delivered'
        AND (needs_manual_review = 1 OR (contact_preference = 'call' AND status = 'new'))")->fetchColumn();
    $counts['submissions'] = (int)q("SELECT COUNT(*) FROM submissions WHERE review_status = 'pending'")->fetchColumn();
    $counts['freelancers'] = (int)q("SELECT COUNT(*) FROM freelancers WHERE status = 'pending'")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($TABS[$tab]) ?> &#8212; Planzaa Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css?v=20260927a">
<link rel="stylesheet" href="../assets/admin.css?v=7">
<link rel="stylesheet" href="../assets/brief-form.css?v=2">
<link rel="stylesheet" href="../assets/password.css?v=1">
</head>
<body class="adm-body">
<div class="adm" id="adm">
  <aside class="adm-side" id="admSide" aria-label="Admin menu">
    <div class="adm-brand">planzaa<span>.</span> admin</div>
    <nav class="adm-nav">
      <?php foreach ($TABS as $key => $label): ?>
        <a href="<?= h(url(['tab' => $key])) ?>" class="<?= $tab === $key ? 'active' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
          <?= svg($key) ?><span><?= h($label) ?></span>
          <?php if (!empty($counts[$key])): ?><em class="adm-count"><?= (int)$counts[$key] ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="adm-who">
      <span>Signed in as<br><strong><?= h($_SESSION['staff_name']) ?></strong></span>
      <a href="../logout.php">Sign out</a>
    </div>
  </aside>
  <div class="adm-scrim" id="admScrim" hidden></div>

  <main class="adm-main">
    <header class="adm-top">
      <button type="button" class="adm-burger" id="admBurger" aria-controls="admSide" aria-expanded="false" aria-label="Open menu"><?= svg('menu') ?></button>
      <h1><?= h($TABS[$tab]) ?></h1>
    </header>

    <?php if ($flash): ?>
      <div class="adm-flash <?= $flash[0] === 'err' ? 'err' : 'ok' ?>" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <?php if ($problems): ?>
      <div class="adm-card adm-setup">
        <h2>Database update needed</h2>
        <p>The admin dashboard needs a few database changes that have not been run yet. Open phpMyAdmin and run the SQL below, then reload this page.</p>
        <ul><?php foreach ($problems as $p): ?><li><?= $p ?></li><?php endforeach; ?></ul>
      </div>
    <?php else: ?>
      <?php require __DIR__ . '/_tab_' . $tab . '.php'; ?>
    <?php endif; ?>
  </main>
</div>

<dialog class="adm-confirm" id="admConfirm">
  <form method="dialog">
    <h2>Are you sure?</h2>
    <p id="admConfirmText">This cannot be undone.</p>
    <div class="adm-actions">
      <button class="btn" value="cancel">Cancel</button>
      <button class="btn btn-danger" value="ok" id="admConfirmOk">Yes, do it</button>
    </div>
  </form>
</dialog>
<script src="../assets/admin.js?v=1"></script>
<script src="../assets/brief-form.js?v=3"></script>
<script src="../assets/password.js?v=1"></script>
</body>
</html>

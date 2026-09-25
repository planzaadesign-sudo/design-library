<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
requireStaff('inhouse');
require_once __DIR__ . '/../includes/brief_ui.php'; // similarity checks, expanded brief form, publishing
$pdo = getDB();
$tab = $_GET['tab'] ?? 'assigned';
$myId = $_SESSION['staff_id'];
$ready = phase5_ready(); // false until schema-phase5.sql has been run

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['review_action'])) {
        $subId = (int)$_POST['submission_id'];
        $outcome = $_POST['review_action'] === 'approve' ? 'approved' : 'rejected';
        $notes = trim($_POST['review_notes'] ?? '');
        if ($outcome === 'approved' && $ready && empty($_POST['confirm_different'])) {
            // The reviewer must confirm the design is different enough from the library.
            $_SESSION['inhouse_flash'] = ['err', 'Please check the similarity confirmation before approving.'];
            header('Location: index.php?tab=' . urlencode($tab));
            exit;
        }
        $pdo->prepare("UPDATE submissions SET review_status = ?, reviewer_id = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$outcome, $myId, $notes, $subId]);
        $stmt = $pdo->prepare("SELECT brief_id FROM submissions WHERE id = ?");
        $stmt->execute([$subId]);
        $briefId = $stmt->fetchColumn();
        $newBriefStatus = $outcome === 'approved' ? 'approved' : 'needs_revision';
        $pdo->prepare("UPDATE briefs SET status = ? WHERE id = ?")->execute([$newBriefStatus, $briefId]);
    }
    if (isset($_POST['publish_submission'])) {
        // Shared with the admin dashboard: copies every brief parameter onto the new design.
        publish_submission((int)$_POST['submission_id'], $myId);
    }
    if (isset($_POST['post_brief'])) {
        if (!$ready) {
            $_SESSION['inhouse_flash'] = ['err', 'Posting briefs needs a database update first (schema-phase5.sql).'];
        } else {
            [$d, $err] = validate_brief_input($_POST);
            if (!$err) $err = save_brief($d, $myId, !empty($_POST['confirm_similar']));
            if ($err) {
                $_SESSION['inhouse_flash'] = ['err', $err];
                $_SESSION['inhouse_old'] = $_POST;
            } else {
                $_SESSION['inhouse_flash'] = ['ok', 'Brief posted. Freelancers can now claim it.'];
            }
        }
    }
    header('Location: index.php?tab=' . urlencode($tab));
    exit;
}

$flash = $_SESSION['inhouse_flash'] ?? null;
$old = $_SESSION['inhouse_old'] ?? [];
unset($_SESSION['inhouse_flash'], $_SESSION['inhouse_old']);

function fmt($n) { return '&#8377;' . number_format((int)$n); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>In-house Team &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css?v=20260925g">
<link rel="stylesheet" href="../assets/brief-form.css?v=1">
</head>
<body>
<div class="topbar"><div class="topbar-inner topnav">
  <div class="wordmark">planzaa<span>.</span> in-house</div>
  <div class="who">Signed in as <?= htmlspecialchars($_SESSION['staff_name']) ?> &#183; <a href="../logout.php">Sign out</a></div>
</div></div>
<div class="wrap">
<h1>In-house team</h1>
<p class="section-gap">Your assigned orders, submissions waiting on your review, and briefs you post for freelancers.</p>
<?php if ($flash): ?>
  <div class="<?= $flash[0] === 'err' ? 'banner banner-nomatch' : 'toast' ?>" style="margin-bottom:16px" role="alert"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$ready): ?>
  <p class="phase5-note">Design similarity checks are off until the database update <strong>schema-phase5.sql</strong> is run. Ask your admin.</p>
<?php endif; ?>
<div class="subtabs">
  <a class="subtab <?= $tab==='assigned'?'active':'' ?>" href="?tab=assigned">My orders</a>
  <a class="subtab <?= $tab==='review'?'active':'' ?>" href="?tab=review">Review queue</a>
  <a class="subtab <?= $tab==='standardize'?'active':'' ?>" href="?tab=standardize">Ready to standardize</a>
  <a class="subtab <?= $tab==='postbrief'?'active':'' ?>" href="?tab=postbrief">Post a brief</a>
</div>

<?php if ($tab === 'assigned'):
  $stmt = $pdo->prepare("SELECT o.*, d.name AS design_name FROM library_orders o JOIN designs d ON o.design_id = d.id WHERE o.assigned_to = ? ORDER BY o.id DESC");
  $stmt->execute([$myId]);
  $orders = $stmt->fetchAll();
  if (!$orders): ?><p class="section-gap">Nothing assigned to you right now.</p><?php else:
  foreach ($orders as $o): ?>
  <div class="item-card"><div class="item-top">
    <div><div class="item-title"><?= htmlspecialchars($o['design_name']) ?> &#8212; <?= htmlspecialchars($o['order_code']) ?></div>
    <div class="item-meta"><?= htmlspecialchars($o['customer_name']) ?> &#183; <?= htmlspecialchars($o['customer_city']) ?></div></div>
    <span class="badge b-blue"><?= htmlspecialchars($o['status']) ?></span>
  </div></div>
<?php endforeach; endif; ?>

<?php elseif ($tab === 'review'):
  $subs = $pdo->query("SELECT s.*, b.title, b.requirements, f.name AS freelancer_name FROM submissions s
                        JOIN briefs b ON s.brief_id = b.id JOIN freelancers f ON s.freelancer_id = f.id
                        WHERE s.review_status = 'pending' ORDER BY s.id")->fetchAll();
  if (!$subs): ?><p class="section-gap">Review queue is empty.</p><?php else:
  foreach ($subs as $s):
    $sim = ['html' => '', 'matches' => []];
    if ($ready && ($brief = brief_params($s['brief_id']))) $sim = render_similarity_review($brief, $s, '../');
  ?>
  <div class="item-card">
    <div class="item-top"><div><div class="item-title"><?= htmlspecialchars($s['title']) ?></div>
    <div class="item-meta">Submitted by <?= htmlspecialchars($s['freelancer_name']) ?></div></div>
    <span class="badge b-amber">Pending review</span></div>
    <div class="item-notes"><?= nl2br(htmlspecialchars($s['requirements'])) ?></div>
    <?php if ($s['notes']): ?><div class="item-notes">Freelancer note: <?= htmlspecialchars($s['notes']) ?></div><?php endif; ?>
    <?php if ($s['cad_file_path']): ?><div class="item-meta" style="margin-top:8px"><a href="../<?= htmlspecialchars($s['cad_file_path']) ?>" download>Download the submitted file</a></div><?php endif; ?>
    <?= $sim['html'] ?>
    <form method="POST">
      <input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
      <?= $ready ? render_review_extras($sim['matches']) : '' ?>
      <div class="field" style="margin-top:8px"><textarea name="review_notes" placeholder="Notes for the freelancer (required if sending back)"></textarea></div>
      <div class="review-actions">
        <button class="btn btn-primary btn-small" name="review_action" value="approve"<?= $ready ? ' data-needs-confirm' : '' ?>>Approve</button>
        <button class="btn btn-small" name="review_action" value="reject" style="background:var(--attention); border-color:var(--attention); color:#fff;">Send back for revision</button>
      </div>
    </form>
  </div>
<?php endforeach; endif; ?>

<?php elseif ($tab === 'standardize'):
  $subs = $pdo->query("SELECT s.*, b.title FROM submissions s JOIN briefs b ON s.brief_id = b.id WHERE s.review_status = 'approved' AND s.published = 0")->fetchAll();
  if (!$subs): ?><p class="section-gap">Nothing waiting to be standardized.</p><?php else:
  foreach ($subs as $s): ?>
  <div class="item-card">
    <div class="item-top"><div><div class="item-title"><?= htmlspecialchars($s['title']) ?></div>
    <div class="item-meta">Approved submission, ready to finalize</div></div>
    <span class="badge b-green">Approved</span></div>
    <form method="POST"><input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
      <div class="review-actions"><button class="btn btn-primary btn-small" name="publish_submission" value="1">Standardize &amp; publish</button></div>
    </form>
  </div>
<?php endforeach; endif; ?>

<?php else: ?>
<h2 style="margin:4px 0 6px">Post a new brief</h2>
<p class="section-gap">Answer each question so we can check the library for similar designs before a freelancer starts work.</p>
<?php if ($ready): ?>
  <?= render_brief_form($old, [
      'hidden' => '<input type="hidden" name="post_brief" value="1">',
      'api' => '../api/similarity-check.php',
      'design_url' => '../design.php?id=',
  ]) ?>
<?php else: ?>
  <p class="phase5-note">Posting briefs needs the database update <strong>schema-phase5.sql</strong> first.</p>
<?php endif; ?>
<?php endif; ?>
</div>
<script src="../assets/brief-form.js?v=1"></script>
</body>
</html>

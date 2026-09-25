<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
requireStaff('inhouse');
$pdo = getDB();
$tab = $_GET['tab'] ?? 'assigned';
$myId = $_SESSION['staff_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['review_action'])) {
        $subId = (int)$_POST['submission_id'];
        $outcome = $_POST['review_action'] === 'approve' ? 'approved' : 'rejected';
        $notes = trim($_POST['review_notes'] ?? '');
        $pdo->prepare("UPDATE submissions SET review_status = ?, reviewer_id = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$outcome, $myId, $notes, $subId]);
        $stmt = $pdo->prepare("SELECT brief_id FROM submissions WHERE id = ?");
        $stmt->execute([$subId]);
        $briefId = $stmt->fetchColumn();
        $newBriefStatus = $outcome === 'approved' ? 'approved' : 'needs_revision';
        $pdo->prepare("UPDATE briefs SET status = ? WHERE id = ?")->execute([$newBriefStatus, $briefId]);
    }
    if (isset($_POST['publish_submission'])) {
        $subId = (int)$_POST['submission_id'];
        $stmt = $pdo->prepare("SELECT s.*, b.title, b.plot_width, b.plot_length, b.facing, b.house_type, b.payout
                                FROM submissions s JOIN briefs b ON s.brief_id = b.id WHERE s.id = ?");
        $stmt->execute([$subId]);
        $sub = $stmt->fetch();
        if ($sub && $sub['review_status'] === 'approved' && !$sub['published']) {
            $price = max(15000, (int)$sub['payout'] * 6);
            $ins = $pdo->prepare("INSERT INTO designs
                (name, plot_width, plot_length, facing, floors, bhk, base_price, delivery_days, variant, source_submission_id, standardized_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $sub['title'], $sub['plot_width'] ?: 30, $sub['plot_length'] ?: 40, $sub['facing'] ?: 'East',
                $sub['house_type'] ?: 'G+1', 3, $price, 12, rand(0, 2), $subId, $myId,
            ]);
            $pdo->prepare("UPDATE submissions SET published = 1 WHERE id = ?")->execute([$subId]);
            $pdo->prepare("UPDATE briefs SET status = 'published' WHERE id = ?")->execute([$sub['brief_id']]);
            // Illustrative royalty split, same placeholder rate used in the earlier demo -- the real split is still a decision to make.
            $pdo->prepare("UPDATE freelancers SET earnings = earnings + ? WHERE id = ?")->execute([round($price * 0.1), $sub['freelancer_id']]);
        }
    }
    if (isset($_POST['post_brief'])) {
        $stmt = $pdo->prepare("INSERT INTO briefs (title, plot_width, plot_length, facing, house_type, requirements, payout, deadline, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($_POST['title']), $_POST['plot_width'] ?: null, $_POST['plot_length'] ?: null, $_POST['facing'] ?: null,
            trim($_POST['house_type'] ?? ''), trim($_POST['requirements']), (int)$_POST['payout'], $_POST['deadline'], $myId,
        ]);
    }
    header('Location: index.php?tab=' . urlencode($tab));
    exit;
}

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
<link rel="stylesheet" href="../assets/style.css?v=20260925f">
</head>
<body>
<div class="topbar"><div class="topbar-inner topnav">
  <div class="wordmark">planzaa<span>.</span> in-house</div>
  <div class="who">Signed in as <?= htmlspecialchars($_SESSION['staff_name']) ?> &#183; <a href="../logout.php">Sign out</a></div>
</div></div>
<div class="wrap">
<h1>In-house team</h1>
<p class="section-gap">Your assigned orders, submissions waiting on your review, and briefs you post for freelancers.</p>
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
  foreach ($subs as $s): ?>
  <div class="item-card">
    <div class="item-top"><div><div class="item-title"><?= htmlspecialchars($s['title']) ?></div>
    <div class="item-meta">Submitted by <?= htmlspecialchars($s['freelancer_name']) ?></div></div>
    <span class="badge b-amber">Pending review</span></div>
    <div class="item-notes"><?= htmlspecialchars($s['requirements']) ?></div>
    <?php if ($s['notes']): ?><div class="item-notes">Freelancer note: <?= htmlspecialchars($s['notes']) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="submission_id" value="<?= $s['id'] ?>">
      <div class="field" style="margin-top:8px"><textarea name="review_notes" placeholder="Notes for the freelancer (required if sending back)"></textarea></div>
      <div class="review-actions">
        <button class="btn btn-primary btn-small" name="review_action" value="approve">Approve</button>
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
<div class="add-form">
<h3 style="margin-bottom:12px">Post a new brief</h3>
<form method="POST">
<div class="form-grid">
<div class="field"><label>Title</label><input name="title" required></div>
<div class="field"><label>Plot width (ft)</label><input name="plot_width" type="number"></div>
<div class="field"><label>Plot length (ft)</label><input name="plot_length" type="number"></div>
<div class="field"><label>Facing</label><select name="facing"><option>East</option><option>West</option><option>North</option><option>South</option></select></div>
<div class="field"><label>House type</label><input name="house_type" placeholder="e.g. G+1, 3BHK"></div>
<div class="field"><label>Payout (&#8377;)</label><input name="payout" type="number" required></div>
<div class="field"><label>Deadline</label><input name="deadline" type="date" required></div>
</div>
<div class="field" style="margin-bottom:12px"><label>Requirements</label><textarea name="requirements" required placeholder="Layout, vastu notes, style, anything a freelancer needs to know"></textarea></div>
<button class="btn btn-primary" name="post_brief" value="1">Post brief</button>
</form>
</div>
<?php endif; ?>
</div>
</body>
</html>

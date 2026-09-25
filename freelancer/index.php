<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
requireFreelancer();
$pdo = getDB();
$tab = $_GET['tab'] ?? 'open';
$myId = $_SESSION['freelancer_id'];
$claimError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['claim_brief'])) {
        $briefId = (int)$_POST['brief_id'];
        // The atomic part: this UPDATE only succeeds if the brief is STILL 'open'
        // at the exact moment it runs. If two freelancers click claim within the
        // same instant, only one of these statements actually changes a row --
        // the database itself resolves the race, not application logic that
        // could lose to a timing coincidence.
        $stmt = $pdo->prepare("UPDATE briefs SET status = 'claimed', claimed_by = ? WHERE id = ? AND status = 'open'");
        $stmt->execute([$myId, $briefId]);
        if ($stmt->rowCount() === 0) {
            $claimError = 'Someone else just claimed this brief a moment before you.';
        } else {
            // Go straight to the claimed brief, where similar library designs are shown.
            $tab = 'mine';
        }
    }
    if (isset($_POST['submit_design'])) {
        $briefId = (int)$_POST['brief_id'];
        $notes = trim($_POST['notes'] ?? '');
        $filePath = null;
        if (!empty($_FILES['cad_file']['name'])) {
            $uploadDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $safeName = 'sub_' . $briefId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['cad_file']['name']));
            if (move_uploaded_file($_FILES['cad_file']['tmp_name'], $uploadDir . $safeName)) {
                $filePath = 'uploads/' . $safeName;
            }
        }
        $pdo->prepare("INSERT INTO submissions (brief_id, freelancer_id, cad_file_path, notes) VALUES (?, ?, ?, ?)")
            ->execute([$briefId, $myId, $filePath, $notes]);
        $pdo->prepare("UPDATE briefs SET status = 'in_review' WHERE id = ?")->execute([$briefId]);
    }
    if ($claimError === '') {
        header('Location: index.php?tab=' . urlencode($tab));
        exit;
    }
}

function fmt($n) { return '&#8377;' . number_format((int)$n); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Freelancer &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css?v=20260925g">
<link rel="stylesheet" href="../assets/brief-form.css?v=1">
</head>
<body>
<div class="topbar"><div class="topbar-inner topnav">
  <div class="wordmark">planzaa<span>.</span> freelancer</div>
  <div class="who">Signed in as <?= htmlspecialchars($_SESSION['freelancer_name']) ?> &#183; <a href="../logout.php">Sign out</a></div>
</div></div>
<div class="wrap">
<h1>Freelancer dashboard</h1>
<p class="section-gap">Open briefs anyone can claim, your own claimed work, and what happens after you submit.</p>
<?php if ($claimError): ?><div class="error-note" style="margin-bottom:14px"><?= htmlspecialchars($claimError) ?></div><?php endif; ?>
<div class="subtabs">
  <a class="subtab <?= $tab==='open'?'active':'' ?>" href="?tab=open">Open briefs</a>
  <a class="subtab <?= $tab==='mine'?'active':'' ?>" href="?tab=mine">My claimed briefs</a>
  <a class="subtab <?= $tab==='submissions'?'active':'' ?>" href="?tab=submissions">My submissions</a>
  <a class="subtab <?= $tab==='earnings'?'active':'' ?>" href="?tab=earnings">My earnings</a>
</div>

<?php if ($tab === 'open'):
  $briefs = $pdo->query("SELECT * FROM briefs WHERE status = 'open' ORDER BY deadline")->fetchAll();
  if (!$briefs): ?><p class="section-gap">No open briefs right now.</p><?php else:
  foreach ($briefs as $b): ?>
  <div class="item-card"><div class="item-top">
    <div><div class="item-title"><?= htmlspecialchars($b['title']) ?></div>
    <div class="item-meta"><?= $b['plot_width'] ?>&#215;<?= $b['plot_length'] ?> ft, <?= htmlspecialchars($b['facing']) ?> &#183; Due <?= htmlspecialchars($b['deadline']) ?></div></div>
    <div class="item-title"><?= fmt($b['payout']) ?></div></div>
    <div class="item-notes"><?= htmlspecialchars($b['requirements']) ?></div>
    <form method="POST"><input type="hidden" name="brief_id" value="<?= $b['id'] ?>">
      <div class="review-actions"><button class="btn btn-primary btn-small" name="claim_brief" value="1">Claim this brief</button></div>
    </form>
  </div>
<?php endforeach; endif; ?>

<?php elseif ($tab === 'mine'):
  $stmt = $pdo->prepare("SELECT * FROM briefs WHERE claimed_by = ? AND status = 'claimed' ORDER BY deadline");
  $stmt->execute([$myId]);
  $briefs = $stmt->fetchAll();
  if (!$briefs): ?><p class="section-gap">Nothing claimed right now &#8212; check Open briefs.</p><?php else:
  foreach ($briefs as $b): ?>
  <div class="item-card"><div class="item-top">
    <div><div class="item-title"><?= htmlspecialchars($b['title']) ?></div><div class="item-meta">Due <?= htmlspecialchars($b['deadline']) ?></div></div>
    <span class="badge b-blue">Claimed</span></div>
    <div class="fl-brief-notes item-notes"><?= htmlspecialchars($b['requirements']) ?><?php if (!empty($b['differentiation_notes'])): ?><strong>What should be different about your design</strong><?= htmlspecialchars($b['differentiation_notes']) ?><?php endif; ?></div>
    <div class="fl-sim" data-brief="<?= (int)$b['id'] ?>" hidden></div>
    <form method="POST" enctype="multipart/form-data" style="margin-top:10px">
      <input type="hidden" name="brief_id" value="<?= $b['id'] ?>">
      <div class="field"><label>CAD file</label><input type="file" name="cad_file"></div>
      <div class="field"><label>Notes for reviewer</label><textarea name="notes"></textarea></div>
      <button class="btn btn-primary btn-small" name="submit_design" value="1">Submit design</button>
    </form>
  </div>
<?php endforeach; endif; ?>

<?php elseif ($tab === 'submissions'):
  $stmt = $pdo->prepare("SELECT s.*, b.title FROM submissions s JOIN briefs b ON s.brief_id = b.id WHERE s.freelancer_id = ? ORDER BY s.id DESC");
  $stmt->execute([$myId]);
  $subs = $stmt->fetchAll();
  if (!$subs): ?><p class="section-gap">No submissions yet.</p><?php else:
  foreach ($subs as $s):
    $badgeClass = $s['review_status']==='pending' ? 'b-amber' : ($s['review_status']==='rejected' ? 'b-rust' : 'b-green');
    $label = $s['review_status']==='pending' ? 'Pending review' : ($s['review_status']==='rejected' ? 'Needs revision' : 'Approved');
  ?>
  <div class="item-card"><div class="item-top">
    <div><div class="item-title"><?= htmlspecialchars($s['title']) ?></div><div class="item-meta">Submitted <?= htmlspecialchars($s['submitted_at']) ?></div></div>
    <span class="badge <?= $badgeClass ?>"><?= $label ?></span></div>
    <?php if ($s['review_notes']): ?><div class="item-notes"><?= htmlspecialchars($s['review_notes']) ?></div><?php endif; ?>
  </div>
<?php endforeach; endif; ?>

<?php else:
  $stmt = $pdo->prepare("SELECT d.name, d.base_price FROM designs d WHERE d.source_submission_id IN (SELECT id FROM submissions WHERE freelancer_id = ?)");
  $stmt->execute([$myId]);
  $designs = $stmt->fetchAll();
?>
<table class="admin-table">
<thead><tr><th>Published design</th><th>Your royalty</th></tr></thead>
<tbody>
<?php foreach ($designs as $d): ?>
<tr><td><?= htmlspecialchars($d['name']) ?></td><td><?= fmt(round($d['base_price'] * 0.1)) ?> per resale</td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="section-gap">Royalty shown here is illustrative &#8212; the real split still needs to be decided.</p>
<?php endif; ?>
</div>
<script>
// Similar designs already in the library for each claimed brief (api/brief-rooms.php).
(function(){
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  document.querySelectorAll('.fl-sim[data-brief]').forEach(async box => {
    try {
      const res = await fetch('../api/brief-rooms.php?brief_id=' + encodeURIComponent(box.dataset.brief), {credentials:'same-origin'});
      const data = await res.json();
      if(!res.ok || !data.ready) return;
      const tone = s => s > 70 ? 'high' : s >= 50 ? 'mid' : 'low';
      let html = '<h4>Designs already in our library that are similar to this brief</h4>';
      if(data.designs.length){
        html += '<p class="fl-warn">Your design must look noticeably different from these. Designs that look too similar will be sent back for changes.</p>'
          + '<div class="fl-cards">' + data.designs.map(d => '<div class="fl-card"><div class="sim-art">' + d.floor_plan_svg + '</div>'
          + '<strong>' + esc(d.name) + '</strong>'
          + '<span class="sim-score ' + tone(d.score) + '"><span class="sim-bar"><i style="width:' + d.score + '%"></i></span><b>' + d.score + '% similar</b></span>'
          + '<ul><li>' + esc(d.plot) + ' &middot; ' + esc(d.bhk) + ' &middot; ' + esc(d.floors) + '</li><li>Style: ' + esc(d.style) + '</li><li>Main door: ' + esc(d.entrance) + '</li></ul></div>').join('') + '</div>';
      } else {
        html += '<p class="fl-count">Nothing similar in the library yet.</p>';
      }
      if(data.similar_briefs > 0){
        html += '<p class="fl-count">There ' + (data.similar_briefs === 1 ? 'is 1 other brief' : 'are ' + data.similar_briefs + ' other briefs') + ' with similar requirements being worked on by other designers right now. Be creative!</p>';
      }
      box.innerHTML = html;
      box.hidden = false;
    } catch(e) { /* optional section */ }
  });
})();
</script>
</body>
</html>

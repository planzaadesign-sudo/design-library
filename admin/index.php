<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
requireStaff('admin');
$pdo = getDB();
$tab = $_GET['tab'] ?? 'orders';
$STAGES = ['new','design','structural','compliance','delivered'];
$STAGE_LABEL = ['new'=>'New','design'=>'Design','structural'=>'Structural','compliance'=>'Compliance','delivered'=>'Delivered'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_order'])) {
        $stmt = $pdo->prepare("UPDATE library_orders SET assigned_to = ? WHERE id = ?");
        $stmt->execute([$_POST['staff_id'] ?: null, $_POST['order_id']]);
    }
    if (isset($_POST['advance_order'])) {
        $stmt = $pdo->prepare("SELECT status FROM library_orders WHERE id = ?");
        $stmt->execute([$_POST['order_id']]);
        $current = $stmt->fetchColumn();
        $idx = array_search($current, $STAGES);
        if ($idx !== false && $idx < count($STAGES) - 1) {
            $next = $STAGES[$idx + 1];
            $pdo->prepare("UPDATE library_orders SET status = ? WHERE id = ?")->execute([$next, $_POST['order_id']]);
        }
    }
    header('Location: index.php?tab=' . urlencode($tab));
    exit;
}

function fmt($n) { return '&#8377;' . number_format((int)$n); }
$staffList = $pdo->query("SELECT * FROM staff ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css?v=20260925">
</head>
<body>
<div class="topbar"><div class="topbar-inner topnav">
  <div class="wordmark">planzaa<span>.</span> admin</div>
  <div class="who">Signed in as <?= htmlspecialchars($_SESSION['staff_name']) ?> &#183; <a href="../logout.php">Sign out</a></div>
</div></div>
<div class="wrap">
<?php
$stats = [
    'Active orders' => $pdo->query("SELECT COUNT(*) FROM library_orders WHERE status != 'delivered'")->fetchColumn(),
    'Open briefs' => $pdo->query("SELECT COUNT(*) FROM briefs WHERE status = 'open'")->fetchColumn(),
    'Pending review' => $pdo->query("SELECT COUNT(*) FROM submissions WHERE review_status = 'pending'")->fetchColumn(),
    'Published designs' => $pdo->query("SELECT COUNT(*) FROM designs WHERE is_active = 1")->fetchColumn(),
];
?>
<h1>Admin</h1>
<p class="section-gap">Full visibility and control across orders, briefs, the design library, and the team.</p>
<div class="stats-row">
<?php foreach ($stats as $label => $num): ?>
  <div class="stat-card"><div class="stat-num"><?= $num ?></div><div class="stat-label"><?= $label ?></div></div>
<?php endforeach; ?>
</div>
<div class="subtabs">
  <a class="subtab <?= $tab==='orders'?'active':'' ?>" href="?tab=orders">Orders</a>
  <a class="subtab <?= $tab==='briefs'?'active':'' ?>" href="?tab=briefs">Briefs</a>
  <a class="subtab <?= $tab==='library'?'active':'' ?>" href="?tab=library">Design Library</a>
  <a class="subtab <?= $tab==='team'?'active':'' ?>" href="?tab=team">Team</a>
</div>

<?php if ($tab === 'orders'):
  $orders = $pdo->query("SELECT o.*, s.name AS staff_name FROM library_orders o LEFT JOIN staff s ON o.assigned_to = s.id ORDER BY o.id DESC")->fetchAll();
?>
<table class="admin-table">
<thead><tr><th>Order</th><th>Design</th><th>Customer</th><th>Stage</th><th>Assigned</th><th>Price</th><th></th></tr></thead>
<tbody>
<?php foreach ($orders as $o):
  $design = $pdo->prepare("SELECT name FROM designs WHERE id = ?"); $design->execute([$o['design_id']]); $dname = $design->fetchColumn();
?>
<tr>
<td><?= htmlspecialchars($o['order_code']) ?></td>
<td><?= htmlspecialchars($dname) ?></td>
<td><?= htmlspecialchars($o['customer_name']) ?></td>
<td><span class="badge b-blue"><?= $STAGE_LABEL[$o['status']] ?></span></td>
<td>
<form method="POST" style="display:inline">
<input type="hidden" name="order_id" value="<?= $o['id'] ?>">
<select name="staff_id" onchange="this.form.submit()">
<option value="">Unassigned</option>
<?php foreach ($staffList as $s): ?>
<option value="<?= $s['id'] ?>" <?= $o['assigned_to']==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
<?php endforeach; ?>
</select>
<input type="hidden" name="assign_order" value="1">
</form>
</td>
<td><?= fmt($o['total_price']) ?></td>
<td><?php if ($o['status'] !== 'delivered'): ?>
<form method="POST" style="display:inline"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><input type="hidden" name="advance_order" value="1"><button class="btn btn-small" type="submit">Advance &#8594;</button></form>
<?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php elseif ($tab === 'briefs'):
  $briefs = $pdo->query("SELECT b.*, f.name AS freelancer_name FROM briefs b LEFT JOIN freelancers f ON b.claimed_by = f.id ORDER BY b.id DESC")->fetchAll();
?>
<table class="admin-table">
<thead><tr><th>Brief</th><th>Plot</th><th>Status</th><th>Claimed by</th><th>Payout</th></tr></thead>
<tbody>
<?php foreach ($briefs as $b): ?>
<tr>
<td><?= htmlspecialchars($b['title']) ?></td>
<td><?= $b['plot_width'] ?>&#215;<?= $b['plot_length'] ?> ft, <?= htmlspecialchars($b['facing']) ?></td>
<td><span class="badge b-blue"><?= htmlspecialchars($b['status']) ?></span></td>
<td><?= htmlspecialchars($b['freelancer_name'] ?? '&#8212;') ?></td>
<td><?= fmt($b['payout']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="page-sub" style="color:var(--text-secondary); font-size:13px">New briefs are posted from the In-house team dashboard &#8212; admin sees every brief here across the platform.</p>

<?php elseif ($tab === 'library'):
  $designs = $pdo->query("SELECT * FROM designs ORDER BY id DESC")->fetchAll();
?>
<table class="admin-table">
<thead><tr><th>Design</th><th>Plot</th><th>Price</th><th>Source</th></tr></thead>
<tbody>
<?php foreach ($designs as $d): ?>
<tr>
<td><?= htmlspecialchars($d['name']) ?></td>
<td><?= $d['plot_width'] ?>&#215;<?= $d['plot_length'] ?> ft, <?= htmlspecialchars($d['facing']) ?></td>
<td><?= fmt($d['base_price']) ?></td>
<td><?= $d['source_submission_id'] ? 'Freelancer submission' : 'In-house' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php else:
  $freelancers = $pdo->query("SELECT * FROM freelancers ORDER BY name")->fetchAll();
?>
<table class="admin-table">
<thead><tr><th>Name</th><th>Email</th><th>Type</th></tr></thead>
<tbody>
<?php foreach ($staffList as $s): ?>
<tr><td><?= htmlspecialchars($s['name']) ?></td><td><?= htmlspecialchars($s['email']) ?></td><td><?= $s['role']==='admin'?'Admin':'In-house' ?></td></tr>
<?php endforeach; ?>
<?php foreach ($freelancers as $f): ?>
<tr><td><?= htmlspecialchars($f['name']) ?></td><td><?= htmlspecialchars($f['email']) ?></td><td>Freelancer &#183; <?= fmt($f['earnings']) ?> earned</td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</body>
</html>

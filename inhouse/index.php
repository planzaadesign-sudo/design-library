<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
requireStaff('inhouse'); // admins can open this page too (auth.php allows it)
require_once __DIR__ . '/../includes/dashboard.php';

$pdo = getDB();
$myId = (int)$_SESSION['staff_id'];
$TABS = [
    'dashboard' => ['Dashboard', 'dashboard'], 'orders' => ['My Orders', 'orders'], 'review' => ['Review Queue', 'review'],
    'standardize' => ['Standardize', 'standardize'], 'postbrief' => ['Post Brief', 'postbrief'], 'briefs' => ['My Briefs', 'briefs'],
];
$tab = $_GET['tab'] ?? 'dashboard';
if ($tab === 'assigned') $tab = 'orders'; // old link
if (!isset($TABS[$tab])) $tab = 'dashboard';
$problems = dash_setup_problems();

// =====================================================================================
// ACTIONS (every POST): CSRF check, validate, write, flash, redirect.
// =====================================================================================
if (!$problems && $_SERVER['REQUEST_METHOD'] === 'POST') {
    dash_csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'review') {
        // Same rules as the admin review: confirm "different enough" before approving,
        // and write notes before sending back.
        $subId = (int)($_POST['submission_id'] ?? 0);
        $outcome = (string)($_POST['outcome'] ?? '');
        $notes = trim((string)($_POST['review_notes'] ?? ''));
        $sub = sim_q("SELECT brief_id, review_status FROM submissions WHERE id = ?", [$subId])->fetch();
        if (!$sub || $sub['review_status'] !== 'pending') { dash_flash('This submission has already been reviewed.', 'err'); dash_redirect(['tab' => 'review']); }
        if (!in_array($outcome, ['approve', 'approve_edits', 'reject'], true)) { dash_flash('Please choose a review action.', 'err'); dash_redirect(['tab' => 'review']); }
        if ($outcome === 'reject' && $notes === '') { dash_flash('Please write what the designer should change before sending it back.', 'err'); dash_redirect(['tab' => 'review']); }
        if ($outcome !== 'reject' && empty($_POST['confirm_different'])) { dash_flash('Please check the similarity confirmation before approving.', 'err'); dash_redirect(['tab' => 'review']); }
        if ($outcome === 'approve_edits') $notes = trim('Approved. Our in-house team will make small fixes before publishing. ' . $notes);
        $status = $outcome === 'reject' ? 'rejected' : 'approved';
        sim_q("UPDATE submissions SET review_status = ?, reviewer_id = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?", [$status, $myId, $notes, $subId]);
        sim_q("UPDATE briefs SET status = ? WHERE id = ?", [$status === 'approved' ? 'approved' : 'needs_revision', (int)$sub['brief_id']]);
        dash_flash($status === 'approved' ? 'Approved. It is now in Standardize, ready to publish.' : 'Sent back to the designer with your notes.');
        dash_redirect(['tab' => 'review']);
    }

    if ($action === 'publish') {
        // Shared routine (includes/briefs.php): copies every brief parameter onto the new design.
        $designId = publish_submission((int)($_POST['submission_id'] ?? 0), $myId);
        if (!$designId) { dash_flash('Only approved submissions that are not yet published can be published.', 'err'); dash_redirect(['tab' => 'standardize']); }
        dash_flash('Published! The design is now live in the library.');
        dash_redirect(['tab' => 'standardize', 'published' => $designId]);
    }

    if ($action === 'post_brief') {
        [$d, $err] = validate_brief_input($_POST);
        if (!$err) $err = save_brief($d, $myId, !empty($_POST['confirm_similar']));
        if ($err) {
            dash_flash($err, 'err');
            $keep = $_POST;
            unset($keep['csrf']);
            $_SESSION['dash_old'] = $keep;
            dash_redirect(['tab' => 'postbrief']);
        }
        dash_flash('Brief posted. Designers can now claim it.');
        dash_redirect(['tab' => 'briefs']);
    }

    dash_flash('Unknown action.', 'err');
    dash_redirect(['tab' => 'dashboard']);
}

$flash = dash_take_flash();
$old = $_SESSION['dash_old'] ?? [];
unset($_SESSION['dash_old']);
$one = function ($sql, $params = []) { return (int)sim_q($sql, $params)->fetchColumn(); };

$nav = [];
$pendingCount = $problems ? 0 : $one("SELECT COUNT(*) FROM submissions WHERE review_status = 'pending'");
$readyCount = $problems ? 0 : $one("SELECT COUNT(*) FROM submissions WHERE review_status = 'approved' AND published = 0");
foreach ($TABS as $k => [$label, $icon]) $nav[$k] = [$label, $icon, $k === 'review' ? $pendingCount : ($k === 'standardize' ? $readyCount : 0)];

echo dash_layout_start('in-house', $TABS[$tab][0], $nav, $tab, $_SESSION['staff_name']);
echo dash_flash_html($flash);

if ($problems):
?>
  <div class="adm-card adm-setup">
    <h2>Database update needed</h2>
    <p>This dashboard needs a few database changes that have not been run yet. Please ask your admin to run the SQL below in phpMyAdmin, then reload.</p>
    <ul><?php foreach ($problems as $p): ?><li><?= $p ?></li><?php endforeach; ?></ul>
  </div>
<?php
    echo dash_layout_end();
    exit;
endif;

// =====================================================================================
// DASHBOARD
// =====================================================================================
if ($tab === 'dashboard'):
    $stats = [
        ['My active orders', $one("SELECT COUNT(*) FROM library_orders WHERE assigned_to = ? AND status <> 'delivered'", [$myId]), 'assigned to you', 'accent', 'orders'],
        ['Pending reviews', $pendingCount, 'designs to check', 'amber', 'review'],
        ['Ready to standardize', $readyCount, 'approved, not published', 'green', 'standardize'],
        ['My posted briefs', $one("SELECT COUNT(*) FROM briefs WHERE created_by = ?", [$myId]), 'all time', 'accent', 'briefs'],
    ];
    $active = sim_q("SELECT o.id, o.order_code, o.customer_name, o.status, o.created_at, d.name AS design_name
                     FROM library_orders o JOIN designs d ON d.id = o.design_id
                     WHERE o.assigned_to = ? AND o.status <> 'delivered' ORDER BY o.id DESC LIMIT 10", [$myId])->fetchAll();
    $pending = sim_q("SELECT s.id, s.submitted_at, b.title, f.name AS freelancer_name FROM submissions s
                      JOIN briefs b ON b.id = s.brief_id JOIN freelancers f ON f.id = s.freelancer_id
                      WHERE s.review_status = 'pending' ORDER BY s.id LIMIT 8")->fetchAll();
    $overdue = sim_q("SELECT b.id, b.title, b.deadline, b.status FROM briefs b
                      WHERE b.created_by = ? AND b.deadline < CURDATE() AND b.status IN ('open', 'claimed', 'needs_revision')
                      ORDER BY b.deadline LIMIT 8", [$myId])->fetchAll();
?>
<div class="stat-grid">
  <?php foreach ($stats as [$label, $value, $hint, $tone, $link]): ?>
    <a class="stat tone-<?= $tone ?>" href="<?= dh(dash_url(['tab' => $link])) ?>"><span class="stat-value"><?= number_format($value) ?></span>
      <span class="stat-label"><?= dh($label) ?></span><span class="stat-hint"><?= dh($hint) ?></span></a>
  <?php endforeach; ?>
</div>
<div class="dash-cols">
  <section class="adm-card">
    <div class="card-head"><h2>Orders assigned to me</h2><a href="<?= dh(dash_url(['tab' => 'orders'])) ?>">All my orders &rarr;</a></div>
    <?php if (!$active): ?><p class="empty">No active orders assigned to you right now.</p><?php else: ?>
    <div class="table-wrap"><table class="adm-table compact">
      <thead><tr><th>Order</th><th>Design</th><th>Customer</th><th>Status</th><th>Date</th></tr></thead><tbody>
      <?php foreach ($active as $o): $link = dash_url(['tab' => 'orders', 'id' => $o['id']]); ?>
        <tr class="row-link" data-href="<?= dh($link) ?>"><td class="nowrap"><a href="<?= dh($link) ?>"><?= dh($o['order_code']) ?></a></td>
          <td><?= dh($o['design_name']) ?></td><td><?= dh($o['customer_name']) ?></td><td><?= dash_stage_badge($o['status']) ?></td><td class="nowrap"><?= dash_date($o['created_at']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="adm-card">
    <div class="card-head"><h2>Needs your attention</h2></div>
    <?php if (!$pending && !$overdue): ?><p class="empty">All clear &#8212; nothing is waiting for you.</p><?php endif; ?>
    <?php if ($pending): ?>
      <h3 class="pend-head">Designs waiting for review</h3>
      <ul class="pend-list"><?php foreach ($pending as $p): ?>
        <li class="pend"><a class="pend-main" href="<?= dh(dash_url(['tab' => 'review'])) ?>#sub<?= (int)$p['id'] ?>"><strong><?= dh($p['title']) ?></strong>
          <span>by <?= dh($p['freelancer_name']) ?> &#183; <?= dash_date($p['submitted_at']) ?></span></a></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($overdue): ?>
      <h3 class="pend-head">Your briefs past their deadline</h3>
      <ul class="pend-list"><?php foreach ($overdue as $b): ?>
        <li class="pend late"><a class="pend-main" href="<?= dh(dash_url(['tab' => 'briefs', 'id' => $b['id']])) ?>"><strong><?= dh($b['title']) ?></strong>
          <span>Was due <?= dash_date($b['deadline']) ?> &#183; <?= dh(DASH_BRIEF_STATUS[$b['status']] ?? $b['status']) ?></span></a></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>
<?php

// =====================================================================================
// MY ORDERS (only orders assigned to this person)
// =====================================================================================
elseif ($tab === 'orders' && !empty($_GET['id'])):
    $o = sim_q("SELECT o.*, d.name AS design_name, d.plot_width AS d_width, d.plot_length AS d_length, d.facing AS d_facing,
                       d.floors AS d_floors, d.bhk AS d_bhk, d.base_price AS d_price
                FROM library_orders o JOIN designs d ON d.id = o.design_id
                WHERE o.id = ? AND o.assigned_to = ?", [(int)$_GET['id'], $myId])->fetch();
    if (!$o):
        echo '<div class="adm-card"><p>This order is not assigned to you. <a href="' . dh(dash_url(['tab' => 'orders'])) . '">Back to my orders</a></p></div>';
    else:
        $type = dash_order_type($o);
        $modIds = array_values(array_filter(array_map('intval', (array)json_decode((string)$o['modifications'], true))));
        $mods = [];
        if ($modIds) {
            foreach (sim_q("SELECT * FROM modifications WHERE id IN (" . implode(',', array_fill(0, count($modIds), '?')) . ") ORDER BY tier, id", $modIds)->fetchAll() as $m) $mods[(int)$m['id']] = $m;
        }
        $details = [];
        foreach (sim_q("SELECT omd.*, dr.room_name FROM order_modification_details omd LEFT JOIN design_rooms dr ON dr.id = omd.room_id
                        WHERE omd.order_id = ? ORDER BY omd.id", [(int)$o['id']])->fetchAll() as $d) $details[(int)$d['modification_id']][] = $d;
        $structural = !empty($o['structural_included']) || !empty($o['structural_addon']);
        $plotDiffers = ($o['plot_width'] && (int)$o['plot_width'] !== (int)$o['d_width']) || ($o['plot_length'] && (int)$o['plot_length'] !== (int)$o['d_length']) || ($o['facing'] && $o['facing'] !== $o['d_facing']);
?>
<a class="back-link" href="<?= dh(dash_url(['tab' => 'orders'])) ?>">&larr; My orders</a>
<div class="detail-head"><div>
  <div class="eyebrow">Order</div><h2 class="detail-title"><?= dh($o['order_code']) ?></h2>
  <div class="detail-sub"><?= dash_type_badge($o) ?> <?= dash_stage_badge($o['status']) ?>
    <?php if (!empty($o['needs_manual_review'])): ?><span class="badge b-rust">Final price not confirmed yet</span><?php endif; ?>
    <span class="muted">Placed <?= dash_date($o['created_at'], true) ?> &#183; view only</span></div>
</div></div>
<?php if ($type === 'call'): ?>
  <div class="callout call"><?= dash_svg('phone') ?><div><strong>The customer asked for a phone call to discuss changes.</strong>
    Call <a href="tel:+91<?= dh($o['customer_phone']) ?>"><?= dh($o['customer_phone']) ?></a> to understand what they want.
    <?php if (!empty($o['callback_notes'])): ?><blockquote><?= nl2br(dh($o['callback_notes'])) ?></blockquote><?php endif; ?></div></div>
<?php endif; ?>
<div class="detail-grid">
  <div class="detail-col">
    <section class="adm-card"><h3>Customer</h3><dl class="kv">
      <dt>Name</dt><dd><?= dh($o['customer_name']) ?></dd>
      <dt>Phone</dt><dd><a class="tel" href="tel:+91<?= dh($o['customer_phone']) ?>"><?= dash_svg('phone') ?><?= dh($o['customer_phone']) ?></a></dd>
      <dt>City</dt><dd><?= dh(($o['customer_city'] ?? '') ?: '—') ?></dd>
      <dt>District</dt><dd><?= dh(($o['customer_district'] ?? '') ?: '—') ?></dd>
      <dt>State</dt><dd><?= dh(($o['customer_state'] ?? '') ?: '—') ?></dd>
    </dl></section>
    <section class="adm-card"><h3>Design</h3><dl class="kv">
      <dt>Design</dt><dd><?= dh($o['design_name']) ?></dd>
      <dt>Plot</dt><dd><?= (int)$o['d_width'] ?> &times; <?= (int)$o['d_length'] ?> ft, <?= dh($o['d_facing']) ?> facing</dd>
      <dt>Floors / BHK</dt><dd><?= dh($o['d_floors']) ?> &#183; <?= (int)$o['d_bhk'] ?> BHK</dd>
      <?php if ($plotDiffers): ?><dt>Customer's plot</dt><dd class="hl"><?= $o['plot_width'] ? (int)$o['plot_width'] : '?' ?> &times; <?= $o['plot_length'] ? (int)$o['plot_length'] : '?' ?> ft<?= $o['facing'] ? ', ' . dh($o['facing']) . ' facing' : '' ?></dd><?php endif; ?>
    </dl></section>
  </div>
  <div class="detail-col">
    <section class="adm-card"><h3>What they ordered</h3>
      <p><?= $structural ? '<span class="badge b-green">Building safety drawings: Yes</span>' : '<span class="badge badge-neutral">Building safety drawings: No</span>' ?></p>
      <?php if ($type === 'call'): ?><p class="muted">Nothing picked yet &#8212; the customer will explain on the call.</p>
      <?php elseif (!$mods): ?><p>The design as it is.</p>
      <?php else: ?><ul class="ordered">
        <?php foreach ($mods as $id => $m): ?><li><strong><?= dh($m['label']) ?></strong>
          <?php if (!empty($details[$id])): ?><ul><?php foreach ($details[$id] as $d): ?><li><?= dash_detail_text($d, $m['detail_type'] ?? null) ?></li><?php endforeach; ?></ul><?php endif; ?></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
      <p class="muted small-note">Total: <strong><?= dash_inr($o['total_price']) ?></strong><?= !empty($o['needs_manual_review']) ? ' (estimate &#8212; the admin confirms the final price)' : '' ?></p>
    </section>
    <section class="adm-card"><h3>Stage</h3><?= dash_stepper($o['status']) ?>
      <p class="muted small-note">The admin moves orders between stages.</p></section>
  </div>
</div>
<?php
    endif;

elseif ($tab === 'orders'):
    $orders = sim_q("SELECT o.*, d.name AS design_name FROM library_orders o JOIN designs d ON d.id = o.design_id
                     WHERE o.assigned_to = ? ORDER BY (o.status = 'delivered'), o.id DESC", [$myId])->fetchAll();
?>
<?php if (!$orders): ?>
  <div class="adm-card"><p class="empty">No orders are assigned to you yet. The admin assigns orders from their dashboard.</p></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>Order</th><th>Customer</th><th>Phone</th><th>City</th><th>Design</th><th>Type</th><th>Status</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($orders as $o): $link = dash_url(['tab' => 'orders', 'id' => $o['id']]); ?>
    <tr class="row-link<?= dash_order_type($o) === 'call' && $o['status'] === 'new' ? ' flag' : '' ?>" data-href="<?= dh($link) ?>">
      <td class="nowrap"><a href="<?= dh($link) ?>"><?= dh($o['order_code']) ?></a></td>
      <td><?= dh($o['customer_name']) ?></td>
      <td class="nowrap"><a href="tel:+91<?= dh($o['customer_phone']) ?>"><?= dh($o['customer_phone']) ?></a></td>
      <td><?= dh(($o['customer_city'] ?? '') ?: (($o['customer_district'] ?? '') ?: '—')) ?></td>
      <td><?= dh($o['design_name']) ?></td><td><?= dash_type_badge($o) ?></td><td><?= dash_stage_badge($o['status']) ?></td>
      <td class="nowrap"><?= dash_date($o['created_at']) ?></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php

// =====================================================================================
// REVIEW QUEUE (shared by the whole in-house team)
// =====================================================================================
elseif ($tab === 'review'):
    $subs = sim_q("SELECT s.*, b.title, b.requirements, b.differentiation_notes, f.name AS freelancer_name FROM submissions s
                   JOIN briefs b ON s.brief_id = b.id JOIN freelancers f ON s.freelancer_id = f.id
                   WHERE s.review_status = 'pending' ORDER BY s.id")->fetchAll();
    if (!$subs): ?>
  <div class="adm-card empty-card"><p class="empty">No submissions waiting for review right now.</p></div>
<?php else: foreach ($subs as $s):
        $brief = brief_params($s['brief_id']);
        // The comparison shows the preview image when there is one, otherwise the CAD file.
        $sim = $brief ? render_similarity_review($brief, array_merge($s, ['cad_file_path' => ($s['preview_path'] ?? '') ?: $s['cad_file_path']]), '../') : ['html' => '', 'matches' => []];
?>
  <section class="adm-card sub-card" id="sub<?= (int)$s['id'] ?>">
    <div class="review-top"><div><h2 class="card-title"><?= dh($s['title']) ?></h2>
      <span class="muted">by <strong><?= dh($s['freelancer_name']) ?></strong> &#183; submitted <?= dash_date($s['submitted_at'], true) ?></span></div>
      <span class="badge b-amber">Pending review</span></div>
    <details class="brief-text"><summary>What the brief asked for</summary>
      <?= $brief ? dash_brief_facts($brief) : '' ?>
      <?php if (!empty($s['differentiation_notes'])): ?><div class="diff-callout"><strong>What should be different</strong><?= nl2br(dh($s['differentiation_notes'])) ?></div><?php endif; ?>
    </details>
    <div class="file-row">
      <?php if ($s['cad_file_path']): ?><a class="btn btn-small" href="../<?= dh($s['cad_file_path']) ?>" download>Download CAD file</a><?php else: ?><span class="muted">No CAD file attached</span><?php endif; ?>
      <?php if (!empty($s['preview_path'])): ?><a class="btn btn-small" href="../<?= dh($s['preview_path']) ?>" target="_blank" rel="noopener">Open preview image</a><?php endif; ?>
    </div>
    <?php if ($s['notes']): ?><p class="note-text"><span class="muted">Designer's note:</span> <?= nl2br(dh($s['notes'])) ?></p><?php endif; ?>
    <?= $sim['html'] ?>
    <form method="post" class="review-form" data-saving>
      <?= dash_csrf_field() ?>
      <input type="hidden" name="action" value="review"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
      <?= render_review_extras($sim['matches']) ?>
      <label for="rn<?= (int)$s['id'] ?>">Notes for the designer <span class="muted">(needed when sending back)</span></label>
      <textarea id="rn<?= (int)$s['id'] ?>" name="review_notes" rows="3" maxlength="2000"></textarea>
      <div class="adm-actions left">
        <button class="btn btn-primary" name="outcome" value="approve" data-needs-confirm>Approve</button>
        <button class="btn" name="outcome" value="approve_edits" data-needs-confirm>Approve with in-house edits</button>
        <button class="btn btn-warn" name="outcome" value="reject" data-needs-notes>Send back for changes</button>
      </div>
    </form>
  </section>
<?php endforeach; endif;

// =====================================================================================
// STANDARDIZE (approved, not yet published)
// =====================================================================================
elseif ($tab === 'standardize'):
    $published = !empty($_GET['published']) ? sim_q("SELECT * FROM designs WHERE id = ?", [(int)$_GET['published']])->fetch() : null;
    if ($published): ?>
  <section class="adm-card published-card">
    <div class="review-top"><div><div class="eyebrow">Just published</div><h2 class="card-title"><?= dh($published['name']) ?></h2></div>
      <a class="btn btn-small" href="../design.php?id=<?= (int)$published['id'] ?>" target="_blank" rel="noopener">View on the website &rarr;</a></div>
    <p class="muted">Price <?= dash_inr($published['base_price']) ?> &#183; ready in <?= (int)$published['delivery_days'] ?> days. Ask the admin to add its rooms so customers can change it room by room.</p>
    <?= dash_brief_facts($published) ?>
  </section>
<?php endif;
    $subs = sim_q("SELECT s.*, b.title, f.name AS freelancer_name, st.name AS reviewer_name FROM submissions s
                   JOIN briefs b ON s.brief_id = b.id JOIN freelancers f ON f.id = s.freelancer_id LEFT JOIN staff st ON st.id = s.reviewer_id
                   WHERE s.review_status = 'approved' AND s.published = 0 ORDER BY s.reviewed_at")->fetchAll();
    if (!$subs): ?>
  <div class="adm-card empty-card"><p class="empty">Nothing waiting to be standardized.</p></div>
<?php else: foreach ($subs as $s): ?>
  <section class="adm-card sub-card">
    <div class="review-top"><div><h2 class="card-title"><?= dh($s['title']) ?></h2>
      <span class="muted">by <?= dh($s['freelancer_name']) ?> &#183; approved <?= dash_date($s['reviewed_at']) ?><?= $s['reviewer_name'] ? ' by ' . dh($s['reviewer_name']) : '' ?></span></div>
      <span class="badge b-green">Approved</span></div>
    <?php if ($s['review_notes']): ?><p class="note-text"><?= nl2br(dh($s['review_notes'])) ?></p><?php endif; ?>
    <form method="post" data-saving><?= dash_csrf_field() ?>
      <input type="hidden" name="action" value="publish"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
      <div class="adm-actions left"><button class="btn btn-primary" type="submit">Standardize &amp; publish</button></div>
    </form>
  </section>
<?php endforeach; endif;

// =====================================================================================
// POST BRIEF (the shared expanded form with the similarity check)
// =====================================================================================
elseif ($tab === 'postbrief'): ?>
<section class="adm-card form-card">
  <h2>Post a new brief</h2>
  <p class="muted">Answer each question so we can check the library for similar designs before a designer starts work.</p>
  <?= render_brief_form($old, [
      'hidden' => dash_csrf_field() . '<input type="hidden" name="action" value="post_brief">',
      'api' => '../api/similarity-check.php',
      'design_url' => '../design.php?id=',
      'cancel' => dash_url(['tab' => 'briefs']),
  ]) ?>
</section>
<?php

// =====================================================================================
// MY BRIEFS (briefs this person posted)
// =====================================================================================
elseif ($tab === 'briefs' && !empty($_GET['id'])):
    $b = sim_q("SELECT b.*, f.name AS freelancer_name FROM briefs b LEFT JOIN freelancers f ON f.id = b.claimed_by
                WHERE b.id = ? AND b.created_by = ?", [(int)$_GET['id'], $myId])->fetch();
    if (!$b):
        echo '<div class="adm-card"><p>This brief was not posted by you. <a href="' . dh(dash_url(['tab' => 'briefs'])) . '">Back to my briefs</a></p></div>';
    else:
        $subs = sim_q("SELECT s.*, f.name AS freelancer_name, st.name AS reviewer_name FROM submissions s
                       JOIN freelancers f ON f.id = s.freelancer_id LEFT JOIN staff st ON st.id = s.reviewer_id
                       WHERE s.brief_id = ? ORDER BY s.id DESC", [(int)$b['id']])->fetchAll();
?>
<a class="back-link" href="<?= dh(dash_url(['tab' => 'briefs'])) ?>">&larr; My briefs</a>
<div class="detail-head"><div><div class="eyebrow">Brief #<?= (int)$b['id'] ?></div><h2 class="detail-title"><?= dh($b['title']) ?></h2>
  <div class="detail-sub"><?= dash_brief_badge($b['status']) ?> <?= in_array($b['status'], ['open', 'claimed', 'needs_revision'], true) ? dash_deadline_badge($b['deadline']) : '' ?>
    <span class="muted">Payout <?= dash_inr($b['payout']) ?> &#183; due <?= dash_date($b['deadline']) ?> &#183; <?= $b['freelancer_name'] ? 'claimed by ' . dh($b['freelancer_name']) : 'not claimed yet' ?></span></div></div></div>
<section class="adm-card"><h3>What the brief asks for</h3><?= dash_brief_facts($b) ?>
  <?php if (!empty($b['differentiation_notes'])): ?><div class="diff-callout"><strong>What should be different</strong><?= nl2br(dh($b['differentiation_notes'])) ?></div><?php endif; ?>
  <details class="brief-text"><summary>Full brief text</summary><p class="note-text"><?= nl2br(dh($b['requirements'])) ?></p></details>
</section>
<section class="adm-card"><h3>Submissions (<?= count($subs) ?>)</h3>
  <?php if (!$subs): ?><p class="empty">No work submitted yet.</p><?php endif; ?>
  <?php foreach ($subs as $s): ?>
    <div class="mini-sub"><div><strong><?= dh($s['freelancer_name']) ?></strong> <span class="muted"><?= dash_date($s['submitted_at'], true) ?></span></div>
      <?= dash_review_badge($s) ?>
      <?php if ($s['review_notes']): ?><p class="note-text"><span class="muted">Review notes<?= $s['reviewer_name'] ? ' by ' . dh($s['reviewer_name']) : '' ?>:</span> <?= nl2br(dh($s['review_notes'])) ?></p><?php endif; ?>
      <?php if ($s['review_status'] === 'pending'): ?><a href="<?= dh(dash_url(['tab' => 'review'])) ?>#sub<?= (int)$s['id'] ?>">Review it now &rarr;</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php
    endif;

elseif ($tab === 'briefs'):
    $status = array_key_exists($_GET['status'] ?? '', DASH_BRIEF_STATUS) ? $_GET['status'] : '';
    $briefs = sim_q("SELECT b.*, f.name AS freelancer_name FROM briefs b LEFT JOIN freelancers f ON f.id = b.claimed_by
                     WHERE b.created_by = ?" . ($status !== '' ? ' AND b.status = ?' : '') . " ORDER BY b.id DESC",
                    $status !== '' ? [$myId, $status] : [$myId])->fetchAll();
?>
<div class="toolbar">
  <div class="filter-rows inline"><div><span>Status</span>
    <a class="fpill<?= $status === '' ? ' on' : '' ?>" href="<?= dh(dash_url(['tab' => 'briefs'])) ?>">All</a>
    <?php foreach (DASH_BRIEF_STATUS as $k => $label): ?><a class="fpill<?= $status === $k ? ' on' : '' ?>" href="<?= dh(dash_url(['tab' => 'briefs', 'status' => $k])) ?>"><?= dh($label) ?></a><?php endforeach; ?>
  </div></div>
  <a class="btn btn-primary" href="<?= dh(dash_url(['tab' => 'postbrief'])) ?>">+ Post a brief</a>
</div>
<?php if (!$briefs): ?>
  <div class="adm-card empty-card"><p class="empty"><?= $status !== '' ? 'No briefs with this status.' : 'You have not posted any briefs yet.' ?></p></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>Title</th><th>Plot</th><th>Status</th><th>Claimed by</th><th class="num">Payout</th><th>Deadline</th></tr></thead><tbody>
  <?php foreach ($briefs as $b): $link = dash_url(['tab' => 'briefs', 'id' => $b['id']]); ?>
    <tr class="row-link" data-href="<?= dh($link) ?>">
      <td><a href="<?= dh($link) ?>"><?= dh($b['title']) ?></a></td>
      <td class="nowrap"><?= $b['plot_width'] ? (int)$b['plot_width'] . ' &times; ' . (int)$b['plot_length'] : '—' ?><?= $b['facing'] ? ', ' . dh($b['facing']) : '' ?></td>
      <td><?= dash_brief_badge($b['status']) ?></td>
      <td><?= $b['freelancer_name'] ? dh($b['freelancer_name']) : '<span class="muted">—</span>' ?></td>
      <td class="num"><?= dash_inr($b['payout']) ?></td>
      <td class="nowrap"><?= dash_date($b['deadline']) ?><?= in_array($b['status'], ['open', 'claimed', 'needs_revision'], true) ? '<br>' . dash_deadline_badge($b['deadline']) : '' ?></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif;
endif;

echo dash_layout_end();

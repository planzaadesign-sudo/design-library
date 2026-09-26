<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
requireFreelancer();
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/freelancer_profile.php';

$pdo = getDB();
$myId = (int)$_SESSION['freelancer_id'];
$TABS = [
    'dashboard' => ['Dashboard', 'dashboard'], 'open' => ['Open Briefs', 'open'], 'work' => ['My Work', 'work'],
    'submissions' => ['My Submissions', 'submissions'], 'earnings' => ['Earnings', 'earnings'],
];
$profileReady = phase8_ready();
if ($profileReady) $TABS['profile'] = ['My Profile', 'expert'];
// The sign-in page only lets active accounts in; this covers someone suspended while signed in.
$myStatus = $profileReady ? (string)sim_q("SELECT status FROM freelancers WHERE id = ?", [$myId])->fetchColumn() : 'active';
$canWork = $myStatus === 'active';
$inactiveMsg = $myStatus === 'pending' ? 'Your account is still under review. You can claim briefs once our team approves it.'
    : 'Your account is not active right now, so you cannot claim or send in work. Contact us at ' . PLANZAA_CONTACT_EMAIL . '.';
$tab = $_GET['tab'] ?? 'dashboard';
if ($tab === 'mine') $tab = 'work'; // old link
if (!isset($TABS[$tab])) $tab = 'dashboard';
$ready = !dash_setup_problems();

// Allowed uploads. CAD: the file the team works from. Preview: an image shown to reviewers.
const CAD_TYPES = ['dwg', 'dxf', 'pdf', 'zip'];
const PREVIEW_TYPES = ['jpg', 'jpeg', 'png', 'webp'];
const CAD_MAX_MB = 25;
const PREVIEW_MAX_MB = 5;

/**
 * Checks one uploaded file and moves it into uploads/ under a random name.
 * Returns [relative path or null, error or null]. The type is checked by the file's
 * contents, not only its name; uploads/.htaccess also blocks running any script there.
 */
function take_upload($field, array $allowed, $maxMb, $briefId, $required) {
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return [null, $required ? 'Please choose your CAD file (.dwg, .dxf, .pdf or .zip).' : null];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) return [null, 'That file is too big for the server. Please make it smaller (or zip it) and try again.'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return [null, 'The upload did not finish. Please try again.'];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return [null, 'Please upload a ' . implode(', ', array_map(function ($e) { return '.' . $e; }, $allowed)) . ' file.'];
    if ($f['size'] > $maxMb * 1024 * 1024) return [null, 'Files must be ' . $maxMb . ' MB or smaller.'];
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 2048);
    $okContent = [
        'pdf' => strncmp($head, '%PDF', 4) === 0,
        'zip' => strncmp($head, "PK\x03\x04", 4) === 0,
        'dwg' => strncmp($head, 'AC10', 4) === 0 || strncmp($head, 'AC1', 3) === 0,
        'dxf' => stripos($head, 'SECTION') !== false || strncmp($head, 'AutoCAD Binary DXF', 18) === 0,
    ][$ext] ?? null;
    if ($okContent === null) { // images
        $info = @getimagesize($f['tmp_name']);
        $okContent = $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }
    if (!$okContent) return [null, 'That file does not look like a real .' . $ext . ' file. Please check it and try again.'];
    $dir = __DIR__ . '/../uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'sub_' . (int)$briefId . '_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return [null, 'We could not save the file. Please try again.'];
    return ['uploads/' . $name, null];
}

// =====================================================================================
// ACTIONS (every POST): CSRF check, only ever on this designer's own briefs.
// =====================================================================================
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // A file bigger than post_max_size empties $_POST entirely (so the token is missing too).
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        dash_flash('Those files are too big to upload. Please make them smaller and try again.', 'err');
        dash_redirect(['tab' => 'work']);
    }
    dash_csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // ---- My Profile ----
    if ($action === 'profile_save' && $profileReady) {
        [$d, $errs] = validate_profile_input($_POST, false);
        if ($errs) {
            $_SESSION['fl_old'] = array_intersect_key($_POST, array_flip(['name', 'phone', 'city', 'qualification', 'qualification_other', 'experience', 'about_me', 'portfolio_link']));
            $_SESSION['fl_errors'] = $errs;
            dash_flash('Please fix the highlighted ' . (count($errs) === 1 ? 'field' : 'fields') . '.', 'err');
            dash_redirect(['tab' => 'profile']);
        }
        sim_q("UPDATE freelancers SET name = ?, phone = ?, city = ?, qualification = ?, qualification_other = ?, experience = ?, about_me = ?, portfolio_link = ? WHERE id = ?",
            [$d['name'], $d['phone'], $d['city'], $d['qualification'], $d['qualification_other'], $d['experience'], $d['about_me'], $d['portfolio_link'], $myId]);
        $_SESSION['freelancer_name'] = $d['name'];
        dash_flash('Your profile has been saved.');
        dash_redirect(['tab' => 'profile']);
    }

    if ($action === 'password_change') {
        $err = dash_change_password('freelancers', $myId);
        dash_flash($err ?: 'Your password has been changed.', $err ? 'err' : 'ok');
        header('Location: ' . dash_url(['tab' => 'profile']) . '#passwordForm');
        exit;
    }

    // Accounts that are no longer active (suspended while signed in) keep their session
    // but cannot take or send in new work.
    if (!$canWork && in_array($action, ['claim', 'submit'], true)) {
        dash_flash($inactiveMsg, 'err');
        dash_redirect(['tab' => 'dashboard']);
    }
    if ($action === 'claim') {
        $briefId = (int)($_POST['brief_id'] ?? 0);
        // The atomic part: this UPDATE only succeeds if the brief is STILL 'open'
        // at the exact moment it runs. If two designers click claim within the
        // same instant, only one of these statements actually changes a row --
        // the database itself resolves the race, not application logic that
        // could lose to a timing coincidence.
        $st = sim_q("UPDATE briefs SET status = 'claimed', claimed_by = ? WHERE id = ? AND status = 'open'", [$myId, $briefId]);
        if ($st->rowCount() === 0) {
            dash_flash('Someone else just claimed this brief a moment before you.', 'err');
            dash_redirect(['tab' => 'open']);
        }
        dash_flash('Brief claimed! It is now in My Work. Check the similar designs before you start.');
        dash_redirect(['tab' => 'work', 'brief' => $briefId]);
    }

    if ($action === 'submit') {
        $briefId = (int)($_POST['brief_id'] ?? 0);
        // Only a brief this designer claimed, and only while it is waiting for their work.
        $brief = sim_q("SELECT id FROM briefs WHERE id = ? AND claimed_by = ? AND status IN ('claimed', 'needs_revision')", [$briefId, $myId])->fetch();
        if (!$brief) { dash_flash('You can only submit work for a brief you have claimed.', 'err'); dash_redirect(['tab' => 'work']); }
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (mb_strlen($notes) > 3000) { dash_flash('Please keep your notes shorter.', 'err'); dash_redirect(['tab' => 'work', 'brief' => $briefId]); }
        [$cad, $err] = take_upload('cad_file', CAD_TYPES, CAD_MAX_MB, $briefId, true);
        if ($err) { dash_flash($err, 'err'); dash_redirect(['tab' => 'work', 'brief' => $briefId]); }
        [$preview, $err] = take_upload('preview_file', PREVIEW_TYPES, PREVIEW_MAX_MB, $briefId, false);
        if ($err) {
            @unlink(__DIR__ . '/../' . $cad);
            dash_flash($err, 'err');
            dash_redirect(['tab' => 'work', 'brief' => $briefId]);
        }
        sim_q("INSERT INTO submissions (brief_id, freelancer_id, cad_file_path, preview_path, notes) VALUES (?, ?, ?, ?, ?)",
            [$briefId, $myId, $cad, $preview, $notes === '' ? null : $notes]);
        sim_q("UPDATE briefs SET status = 'in_review' WHERE id = ? AND claimed_by = ?", [$briefId, $myId]);
        dash_flash('Design submitted! Our team will review it and you will see their feedback in My Submissions.');
        dash_redirect(['tab' => 'submissions']);
    }

    dash_flash('Unknown action.', 'err');
    dash_redirect(['tab' => 'dashboard']);
}

$flash = dash_take_flash();
$one = function ($sql, $params = []) { return (int)sim_q($sql, $params)->fetchColumn(); };
$me = sim_q("SELECT name, earnings FROM freelancers WHERE id = ?", [$myId])->fetch();

$workCount = $ready ? $one("SELECT COUNT(*) FROM briefs WHERE claimed_by = ? AND status IN ('claimed', 'needs_revision')", [$myId]) : 0;
$nav = [];
foreach ($TABS as $k => [$label, $icon]) $nav[$k] = [$label, $icon, $k === 'work' ? $workCount : 0];

echo dash_layout_start('creator studio', $TABS[$tab][0], $nav, $tab, $_SESSION['freelancer_name']);
echo dash_flash_html($flash);
if ($ready && !$canWork) echo '<div class="adm-flash err" role="alert">' . dh($inactiveMsg) . '</div>';

if (!$ready):
?>
  <div class="adm-card adm-setup"><h2>We're updating your dashboard</h2>
    <p>The Creator Studio is being upgraded. Please check back in a little while. Your claimed briefs and submissions are safe.</p></div>
<?php
    echo dash_layout_end();
    exit;
endif;

// Library designs, loaded once and compared in PHP (same engine as the rest of the site).
$library = null;
$library_rows = function () use (&$library) {
    if ($library === null) $library = sim_q("SELECT d.name, d.variant, " . sim_select_cols('d') . " FROM designs d WHERE d.is_active = 1")->fetchAll();
    return $library;
};

// A claimed brief's full card (used in My Work).
function brief_head(array $b) {
    return '<div class="brief-head"><div><h2 class="card-title">' . dh($b['title']) . '</h2>'
        . '<span class="muted">' . (int)$b['plot_width'] . ' × ' . (int)$b['plot_length'] . ' ft &#183; ' . dh($b['floors'] ?? '') . ' &#183; ' . (int)$b['bhk'] . ' BHK</span></div>'
        . '<div class="brief-money"><span class="payout">' . dash_inr($b['payout']) . '</span>' . dash_deadline_badge($b['deadline'])
        . '<small>Due ' . dash_date($b['deadline']) . '</small></div></div>';
}

// =====================================================================================
// DASHBOARD
// =====================================================================================
if ($tab === 'dashboard'):
    $stats = [
        ['Open briefs', $one("SELECT COUNT(*) FROM briefs WHERE status = 'open'"), 'ready to claim', 'accent', 'open', false],
        ['My active work', $workCount, 'claimed, not yet submitted', 'amber', 'work', false],
        ['Waiting for review', $one("SELECT COUNT(*) FROM submissions WHERE freelancer_id = ? AND review_status = 'pending'", [$myId]), 'your submissions', 'accent', 'submissions', false],
        ['Total earned', (int)$me['earnings'], 'from published designs', 'green', 'earnings', true],
    ];
    $active = sim_q("SELECT * FROM briefs WHERE claimed_by = ? AND status IN ('claimed', 'needs_revision') ORDER BY deadline", [$myId])->fetchAll();
    $feedback = sim_q("SELECT s.*, b.title FROM submissions s JOIN briefs b ON b.id = s.brief_id
                       WHERE s.freelancer_id = ? AND s.review_status <> 'pending' AND s.review_notes IS NOT NULL AND s.review_notes <> ''
                       ORDER BY s.reviewed_at DESC LIMIT 5", [$myId])->fetchAll();
?>
<p class="welcome">Hello <?= dh($me['name']) ?>. Here is where your work stands today.</p>
<div class="stat-grid">
  <?php foreach ($stats as [$label, $value, $hint, $tone, $link, $money]): ?>
    <a class="stat tone-<?= $tone ?>" href="<?= dh(dash_url(['tab' => $link])) ?>"><span class="stat-value"><?= $money ? dash_inr($value) : number_format($value) ?></span>
      <span class="stat-label"><?= dh($label) ?></span><span class="stat-hint"><?= dh($hint) ?></span></a>
  <?php endforeach; ?>
</div>
<div class="dash-cols">
  <section class="adm-card">
    <div class="card-head"><h2>Your active work</h2><a href="<?= dh(dash_url(['tab' => 'work'])) ?>">Open My Work &rarr;</a></div>
    <?php if (!$active): ?><p class="empty">Nothing claimed right now. <a href="<?= dh(dash_url(['tab' => 'open'])) ?>">Find an open brief</a> to start.</p>
    <?php else: ?><ul class="pend-list"><?php foreach ($active as $b): [, $tone] = dash_deadline($b['deadline']); ?>
      <li class="pend<?= $tone === 'late' ? ' late' : '' ?>"><a class="pend-main" href="<?= dh(dash_url(['tab' => 'work', 'brief' => $b['id']])) ?>">
        <strong><?= dh($b['title']) ?><?= $b['status'] === 'needs_revision' ? ' <span class="badge b-rust">Needs changes</span>' : '' ?></strong>
        <span>Due <?= dash_date($b['deadline']) ?> &#183; <?= dash_inr($b['payout']) ?></span></a>
        <span class="pend-due"><?= dash_deadline_badge($b['deadline']) ?></span></li>
    <?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="adm-card">
    <div class="card-head"><h2>Recent feedback</h2><a href="<?= dh(dash_url(['tab' => 'submissions'])) ?>">All submissions &rarr;</a></div>
    <?php if (!$feedback): ?><p class="empty">No feedback yet. It will show here after our team reviews your work.</p>
    <?php else: foreach ($feedback as $s): ?>
      <div class="mini-sub"><div><strong><?= dh($s['title']) ?></strong><?= dash_review_badge($s) ?></div>
        <p class="note-text"><?= nl2br(dh($s['review_notes'])) ?></p></div>
    <?php endforeach; endif; ?>
  </section>
</div>
<?php

// =====================================================================================
// OPEN BRIEFS
// =====================================================================================
elseif ($tab === 'open'):
    $f = [
        'sort' => in_array($_GET['sort'] ?? '', ['payout', 'deadline'], true) ? $_GET['sort'] : 'new',
        'floors' => in_array($_GET['floors'] ?? '', BRIEF_FLOORS, true) ? $_GET['floors'] : '',
        'bhk' => in_array((int)($_GET['bhk'] ?? 0), [1, 2, 3, 4, 5], true) ? (int)$_GET['bhk'] : 0,
        'pay' => in_array($_GET['pay'] ?? '', ['under3', '3to6', 'over6'], true) ? $_GET['pay'] : '',
    ];
    $where = ["status = 'open'"];
    $params = [];
    if ($f['floors'] !== '') { $where[] = 'floors = ?'; $params[] = $f['floors']; }
    if ($f['bhk']) { $where[] = $f['bhk'] === 5 ? 'bhk >= 5' : 'bhk = ?'; if ($f['bhk'] !== 5) $params[] = $f['bhk']; }
    if ($f['pay'] === 'under3') $where[] = 'payout < 3000';
    if ($f['pay'] === '3to6') $where[] = 'payout BETWEEN 3000 AND 6000';
    if ($f['pay'] === 'over6') $where[] = 'payout > 6000';
    $order = ['new' => 'id DESC', 'payout' => 'payout DESC, id DESC', 'deadline' => 'deadline ASC, id DESC'][$f['sort']];
    $briefs = sim_q("SELECT * FROM briefs WHERE " . implode(' AND ', $where) . " ORDER BY $order", $params)->fetchAll();
    $pill = function ($key, $value, $label) use ($f) {
        $on = (string)$f[$key] === (string)$value;
        return '<a class="fpill' . ($on ? ' on' : '') . '" href="' . dh(dash_url(array_merge(['tab' => 'open'], $f, [$key => $value]))) . '">' . dh($label) . '</a>';
    };
?>
<div class="filter-rows">
  <div><span>Sort</span><?= $pill('sort', 'new', 'Newest first') . $pill('sort', 'payout', 'Highest payout') . $pill('sort', 'deadline', 'Deadline soonest') ?></div>
  <div><span>Floors</span><?= $pill('floors', '', 'All') ?><?php foreach (BRIEF_FLOORS as $x) echo $pill('floors', $x, $x); ?></div>
  <div><span>BHK</span><?= $pill('bhk', 0, 'All') ?><?php foreach ([1, 2, 3, 4] as $x) echo $pill('bhk', $x, $x . ' BHK'); ?><?= $pill('bhk', 5, '5+ BHK') ?></div>
  <div><span>Payout</span><?= $pill('pay', '', 'All') . $pill('pay', 'under3', 'Under ₹3,000') . $pill('pay', '3to6', '₹3,000 – ₹6,000') . $pill('pay', 'over6', 'Over ₹6,000') ?></div>
</div>
<p class="result-count"><?= count($briefs) ?> open brief<?= count($briefs) === 1 ? '' : 's' ?></p>
<?php if (!$briefs): ?>
  <div class="adm-card empty-card"><p class="empty">No open briefs match right now. Check back soon &#8212; new briefs are posted every week.</p></div>
<?php elseif (!$canWork): ?>
  <div class="adm-card empty-card"><p class="empty">Open briefs are shown once your account is active.</p></div>
<?php else: foreach ($briefs as $i => $b):
        $similar = count(sim_rank($b, $library_rows(), 1000, SIM_WARN));
?>
  <article class="adm-card brief-card" style="--i:<?= $i ?>">
    <div class="brief-head"><div><h2 class="card-title"><?= dh($b['title']) ?></h2>
      <span class="muted">Posted <?= dash_date($b['created_at']) ?></span></div>
      <div class="brief-money"><span class="payout"><?= dash_inr($b['payout']) ?></span><?= dash_deadline_badge($b['deadline']) ?><small>Due <?= dash_date($b['deadline']) ?></small></div></div>
    <?= dash_brief_facts($b) ?>
    <?php if (!empty($b['differentiation_notes'])): ?><div class="diff-callout"><strong>What should be different about this design</strong><?= nl2br(dh($b['differentiation_notes'])) ?></div><?php endif; ?>
    <details class="brief-text"><summary>Full brief text</summary><p class="note-text"><?= nl2br(dh($b['requirements'])) ?></p></details>
    <div class="brief-foot">
      <span class="sim-count<?= $similar ? ' has' : '' ?>"><?= $similar ? $similar . ' similar design' . ($similar === 1 ? '' : 's') . ' already in the library' : 'Nothing similar in the library yet &#8212; a great chance to add something new' ?></span>
      <form method="post" data-saving><?= dash_csrf_field() ?>
        <input type="hidden" name="action" value="claim"><input type="hidden" name="brief_id" value="<?= (int)$b['id'] ?>">
        <button class="btn btn-primary" type="submit">Claim this brief</button></form>
    </div>
  </article>
<?php endforeach; endif;

// =====================================================================================
// MY WORK (claimed briefs: the workspace)
// =====================================================================================
elseif ($tab === 'work'):
    $briefs = sim_q("SELECT * FROM briefs WHERE claimed_by = ? AND status IN ('claimed', 'needs_revision') ORDER BY deadline", [$myId])->fetchAll();
    if (!$briefs): ?>
  <div class="adm-card empty-card"><p class="empty">You have not claimed any briefs yet. <a href="<?= dh(dash_url(['tab' => 'open'])) ?>">Browse open briefs</a> and claim one to start.</p></div>
<?php else: foreach ($briefs as $b):
        $similar = sim_rank($b, $library_rows(), 3, 0);
        $others = count(findSimilarBriefs($b, $b['id'], 1000, SIM_WARN, ['claimed', 'in_review', 'needs_revision']));
        $lastReview = $b['status'] === 'needs_revision'
            ? sim_q("SELECT review_notes, reviewed_at FROM submissions WHERE brief_id = ? AND freelancer_id = ? AND review_status = 'rejected' ORDER BY id DESC LIMIT 1", [$b['id'], $myId])->fetch()
            : null;
        $focus = (int)($_GET['brief'] ?? 0) === (int)$b['id'];
?>
  <article class="adm-card work-card<?= $focus ? ' focus' : '' ?>" id="brief<?= (int)$b['id'] ?>">
    <?= brief_head($b) ?>
    <?php if ($lastReview): ?>
      <div class="feedback-box"><strong>Our team asked for changes<?= $lastReview['reviewed_at'] ? ' on ' . dash_date($lastReview['reviewed_at']) : '' ?>:</strong>
        <?= nl2br(dh($lastReview['review_notes'])) ?></div>
    <?php endif; ?>

    <h3 class="work-h">The brief</h3>
    <?= dash_brief_facts($b) ?>
    <?php if (!empty($b['differentiation_notes'])): ?><div class="diff-callout"><strong>What should be different about this design</strong><?= nl2br(dh($b['differentiation_notes'])) ?></div><?php endif; ?>
    <details class="brief-text"><summary>Full brief text</summary><p class="note-text"><?= nl2br(dh($b['requirements'])) ?></p></details>

    <h3 class="work-h">Designs already in our library that are similar</h3>
    <p class="fl-warn">Your design must look noticeably different from these. Designs that look too similar will be sent back for changes.</p>
    <?php if ($similar): ?>
      <div class="fl-cards"><?php foreach ($similar as $m): $r = $m['row']; ?>
        <div class="fl-card"><div class="sim-art"><?= floorPlanArt((int)$r['variant']) ?></div>
          <strong><?= dh($r['name']) ?></strong><?= sim_bar($m['score']) ?>
          <ul><li><?= (int)$r['plot_width'] ?> × <?= (int)$r['plot_length'] ?> ft &#183; <?= (int)$r['bhk'] ?> BHK &#183; <?= dh($r['floors']) ?></li>
            <li>Style: <?= dh(param_label('elevation_style', $r['elevation_style'])) ?></li>
            <li>Main door: <?= dh(param_label('entrance_position', $r['entrance_position'])) ?></li></ul></div>
      <?php endforeach; ?></div>
    <?php else: ?><p class="fl-count">Nothing similar in the library yet.</p><?php endif; ?>
    <?php if ($others): ?><p class="fl-count">There <?= $others === 1 ? 'is 1 other brief' : 'are ' . $others . ' other briefs' ?> with similar requirements being worked on by other designers right now. Be creative!</p><?php endif; ?>

    <h3 class="work-h"><?= $lastReview ? 'Send your updated design' : 'Submit my design' ?></h3>
    <form method="post" enctype="multipart/form-data" class="upload-form" data-saving>
      <?= dash_csrf_field() ?>
      <input type="hidden" name="action" value="submit"><input type="hidden" name="brief_id" value="<?= (int)$b['id'] ?>">
      <div class="form-grid-adm">
        <label>CAD file <small>.dwg, .dxf, .pdf or .zip &#8212; up to <?= CAD_MAX_MB ?> MB</small>
          <input type="file" name="cad_file" accept=".dwg,.dxf,.pdf,.zip" required></label>
        <label>Preview image <small>.jpg, .png or .webp &#8212; up to <?= PREVIEW_MAX_MB ?> MB (helps our team review faster)</small>
          <input type="file" name="preview_file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></label>
        <label class="span-3">Notes for our team <small>(optional)</small><textarea name="notes" rows="3" maxlength="3000" placeholder="For example: I moved the courtyard to the back and made the living room double height."></textarea></label>
      </div>
      <div class="adm-actions left"><button class="btn btn-primary" type="submit">Submit my design</button></div>
    </form>
  </article>
<?php endforeach; endif;

// =====================================================================================
// MY SUBMISSIONS
// =====================================================================================
elseif ($tab === 'submissions'):
    $subs = sim_q("SELECT s.*, b.title, (SELECT d.id FROM designs d WHERE d.source_submission_id = s.id LIMIT 1) AS design_id
                   FROM submissions s JOIN briefs b ON b.id = s.brief_id WHERE s.freelancer_id = ? ORDER BY s.id DESC", [$myId])->fetchAll();
    if (!$subs): ?>
  <div class="adm-card empty-card"><p class="empty">No submissions yet. When you submit a design from My Work, it will show here with our team's feedback.</p></div>
<?php else: foreach ($subs as $s): ?>
  <article class="adm-card sub-card<?= $s['review_status'] === 'rejected' ? ' rejected' : '' ?>">
    <div class="review-top"><div><h2 class="card-title"><?= dh($s['title']) ?></h2>
      <span class="muted">Submitted <?= dash_date($s['submitted_at'], true) ?></span></div><?= dash_review_badge($s) ?></div>
    <?php if (!empty($s['published'])): ?>
      <p class="state-note ok">Published! This design is now live in the library.
        <?php if ($s['design_id']): ?><a href="../design.php?id=<?= (int)$s['design_id'] ?>" target="_blank" rel="noopener">See it on the website &rarr;</a><?php endif; ?></p>
    <?php elseif ($s['review_status'] === 'approved'): ?>
      <p class="state-note ok">Approved &#8212; waiting for the team to finalize and publish.</p>
    <?php elseif ($s['review_status'] === 'pending'): ?>
      <p class="state-note">Waiting for our team to review it.</p>
    <?php endif; ?>
    <?php if ($s['review_notes']): ?>
      <div class="<?= $s['review_status'] === 'rejected' ? 'feedback-box' : 'note-text' ?>"><strong>Feedback from our team:</strong> <?= nl2br(dh($s['review_notes'])) ?>
        <?php if ($s['review_status'] === 'rejected'): ?><br><a href="<?= dh(dash_url(['tab' => 'work', 'brief' => $s['brief_id']])) ?>">Make the changes in My Work &rarr;</a><?php endif; ?></div>
    <?php endif; ?>
  </article>
<?php endforeach; endif;

// =====================================================================================
// EARNINGS
// =====================================================================================
elseif ($tab === 'earnings'):
    $designs = sim_q("SELECT d.id, d.name, d.base_price, d.created_at, d.is_active,
                             (SELECT COUNT(*) FROM library_orders o WHERE o.design_id = d.id) AS sold
                      FROM designs d JOIN submissions s ON s.id = d.source_submission_id
                      WHERE s.freelancer_id = ? ORDER BY d.id DESC", [$myId])->fetchAll();
?>
<section class="adm-card earn-hero">
  <span class="muted">Total earned</span>
  <span class="earn-total"><?= dash_inr($me['earnings']) ?></span>
  <span class="muted"><?= count($designs) ?> published design<?= count($designs) === 1 ? '' : 's' ?></span>
</section>
<?php if (!$designs): ?>
  <div class="adm-card empty-card"><p class="empty">No published designs yet. Claim an open brief and submit your first design to start earning!</p>
    <a class="btn btn-primary" href="<?= dh(dash_url(['tab' => 'open'])) ?>">See open briefs</a></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>Design</th><th>Published</th><th class="num">Design price</th><th class="num">Your royalty</th><th>Times sold</th><th class="num">Earned from it</th></tr></thead><tbody>
  <?php foreach ($designs as $d): $royalty = (int)round($d['base_price'] * 0.1); ?>
    <tr>
      <td><?= $d['is_active'] ? '<a href="../design.php?id=' . (int)$d['id'] . '" target="_blank" rel="noopener">' . dh($d['name']) . '</a>' : dh($d['name']) . ' <span class="badge badge-neutral">Hidden</span>' ?></td>
      <td class="nowrap"><?= dash_date($d['created_at']) ?></td>
      <td class="num"><?= dash_inr($d['base_price']) ?></td>
      <td class="num"><?= dash_inr($royalty) ?> <small class="muted">(10%)</small></td>
      <td><?= (int)$d['sold'] ?></td>
      <td class="num"><?= dash_inr($royalty) ?></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
<p class="muted small-note">Royalty rate is illustrative. Right now your royalty is credited once, when the design is published. How repeat sales are paid is still being decided.</p>
<?php endif;

// =====================================================================================
// MY PROFILE (their own details + password; email cannot be changed here)
// =====================================================================================
elseif ($tab === 'profile'):
    $p = sim_q("SELECT * FROM freelancers WHERE id = ?", [$myId])->fetch();
    $old = $_SESSION['fl_old'] ?? []; unset($_SESSION['fl_old']);
    $perr = $_SESSION['fl_errors'] ?? []; unset($_SESSION['fl_errors']);
    $val = function ($k) use ($old, $p) { return dh(array_key_exists($k, $old) ? $old[$k] : ($p[$k] ?? '')); };
    $fe = function ($k) use ($perr) { return isset($perr[$k]) ? '<small class="field-err" role="alert">' . dh($perr[$k]) . '</small>' : ''; };
    $inv = function ($k) use ($perr) { return isset($perr[$k]) ? ' aria-invalid="true" class="invalid"' : ''; };
    $qual = $old['qualification'] ?? ($p['qualification'] ?? '');
    $exp = $old['experience'] ?? ($p['experience'] ?? '');
?>
<section class="adm-card form-card profile-card" id="profileForm">
  <h2>Your profile</h2>
  <p class="muted small-note">This is what our team sees about you. Keep it up to date.</p>
  <form method="post" data-saving>
    <?= dash_csrf_field() ?><input type="hidden" name="action" value="profile_save">
    <div class="form-grid-adm">
      <label>Name<input name="name" required maxlength="100" autocomplete="name" value="<?= $val('name') ?>"<?= $inv('name') ?>><?= $fe('name') ?></label>
      <label>Email<input value="<?= dh($p['email']) ?>" readonly aria-readonly="true" class="readonly"><small>Your email is how you sign in. To change it, write to <a href="mailto:<?= PLANZAA_CONTACT_EMAIL ?>"><?= PLANZAA_CONTACT_EMAIL ?></a>.</small></label>
      <label>Mobile number<input name="phone" type="tel" inputmode="numeric" maxlength="14" required autocomplete="tel-national" value="<?= $val('phone') ?>"<?= $inv('phone') ?>><?= $fe('phone') ?></label>
      <label>City<input name="city" required maxlength="100" autocomplete="address-level2" value="<?= $val('city') ?>"<?= $inv('city') ?>><?= $fe('city') ?></label>
      <label>Qualification<select name="qualification" required id="profQual"<?= $inv('qualification') ?>>
        <option value="">Choose one</option>
        <?php foreach (QUALIFICATIONS as $k => $label): ?><option value="<?= $k ?>"<?= $qual === $k ? ' selected' : '' ?>><?= dh($label) ?></option><?php endforeach; ?>
      </select><?= $fe('qualification') ?></label>
      <label id="profQualOther"<?= $qual === 'other' ? '' : ' hidden' ?>>Please specify your qualification<input name="qualification_other" maxlength="100" value="<?= $val('qualification_other') ?>"<?= $inv('qualification_other') ?>><?= $fe('qualification_other') ?></label>
      <label>Years of experience<select name="experience" required<?= $inv('experience') ?>>
        <option value="">Choose one</option>
        <?php foreach (EXPERIENCE_LEVELS as $k => $label): ?><option value="<?= $k ?>"<?= $exp === $k ? ' selected' : '' ?>><?= dh($label) ?></option><?php endforeach; ?>
      </select><?= $fe('experience') ?></label>
      <label>Portfolio link <span class="muted">(optional)</span><input name="portfolio_link" type="url" inputmode="url" maxlength="255" placeholder="https://behance.net/yourname" value="<?= $val('portfolio_link') ?>"<?= $inv('portfolio_link') ?>><?= $fe('portfolio_link') ?></label>
      <label class="span-all">Tell us about yourself<textarea name="about_me" rows="6" minlength="<?= ABOUT_MIN ?>" maxlength="<?= ABOUT_MAX ?>" required<?= $inv('about_me') ?>><?= $val('about_me') ?></textarea>
        <small>What kind of designs are you good at? What's your design style? At least <?= ABOUT_MIN ?> characters.</small><?= $fe('about_me') ?></label>
    </div>
    <div class="adm-actions left"><button class="btn btn-primary" type="submit">Save changes</button></div>
  </form>
</section>
<?= dash_password_form() ?>
<script>
(function () {
  var q = document.getElementById('profQual'), box = document.getElementById('profQualOther');
  if (!q || !box) return;
  q.addEventListener('change', function () { box.hidden = q.value !== 'other'; box.querySelector('input').required = q.value === 'other'; });
})();
</script>
<?php
endif;

echo dash_layout_end();

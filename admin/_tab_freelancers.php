<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$fid = (int)($_GET['id'] ?? 0);

function fl_status_badge($status) {
    $cls = ['pending' => 'b-amber', 'active' => 'b-green', 'rejected' => 'b-rust', 'suspended' => 'badge-neutral'][$status] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . h(FREELANCER_STATUS[$status] ?? $status) . '</span>';
}

// Everything the designer told us about themselves (read-only: the profile is theirs to edit).
function fl_profile_list(array $f) {
    $link = $f['portfolio_link'] ? '<a class="btn btn-small portfolio-btn" href="' . h($f['portfolio_link']) . '" target="_blank" rel="noopener noreferrer">Open portfolio &#8599;</a> <span class="muted small break">' . h($f['portfolio_link']) . '</span>' : '<span class="muted">Not provided</span>';
    return '<dl class="kv profile-kv">'
        . '<dt>Email</dt><dd><a href="mailto:' . h($f['email']) . '">' . h($f['email']) . '</a></dd>'
        . '<dt>Phone</dt><dd>' . ($f['phone'] ? '<a href="tel:+91' . h($f['phone']) . '">' . h(substr($f['phone'], 0, 5) . ' ' . substr($f['phone'], 5)) . '</a>' : '—') . '</dd>'
        . '<dt>City</dt><dd>' . h($f['city'] ?: '—') . '</dd>'
        . '<dt>Qualification</dt><dd>' . h(qualification_label($f)) . '</dd>'
        . '<dt>Experience</dt><dd>' . h(experience_label($f)) . '</dd>'
        . '<dt>Portfolio</dt><dd>' . $link . '</dd>'
        . '<dt>Registered</dt><dd>' . fdate($f['created_at'], true) . '</dd>'
        . '</dl>'
        . '<h4 class="about-h">About them</h4>'
        . ($f['about_me'] ? '<p class="about-text">' . nl2br(h($f['about_me'])) . '</p>' : '<p class="muted">Added by the admin before designers could register, so there is no profile text.</p>');
}

// Approve (green) and Reject (red). The reason box only opens when Reject is clicked.
function fl_review_actions(array $f) {
    $id = (int)$f['id'];
    return '<div class="review-actions">'
        . '<form method="post" data-saving>' . csrf_field() . return_field()
        . '<input type="hidden" name="action" value="freelancer_approve"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn btn-approve" type="submit">Approve</button></form>'
        . ($f['status'] === 'pending'
            ? '<details class="reject-box"><summary class="btn btn-reject">Reject</summary>'
              . '<form method="post" data-saving>' . csrf_field() . return_field()
              . '<input type="hidden" name="action" value="freelancer_reject"><input type="hidden" name="id" value="' . $id . '">'
              . '<label for="reason' . $id . '">Why are we not approving ' . h($f['name']) . '? <span class="muted">(this is emailed to them)</span></label>'
              . '<textarea id="reason' . $id . '" name="reason" rows="3" maxlength="1000" required placeholder="For example: We need at least one year of residential design experience. Please apply again with your portfolio."></textarea>'
              . '<button class="btn btn-danger" type="submit">Send rejection</button></form></details>'
            : '')
        . '</div>';
}

// ---- One freelancer --------------------------------------------------------------------
if ($fid):
    $fr = q("SELECT f.*, st.name AS reviewer_name FROM freelancers f LEFT JOIN staff st ON st.id = f.reviewed_by WHERE f.id = ?", [$fid])->fetch();
    if (!$fr):
        echo '<div class="adm-card"><p>Freelancer not found. <a href="' . h(url(['tab' => 'freelancers'])) . '">Back to freelancers</a></p></div>';
        return;
    endif;
    $briefs = q("SELECT * FROM briefs WHERE claimed_by = ? ORDER BY id DESC", [$fid])->fetchAll();
    $subs = q(SUBMISSION_SELECT . " WHERE s.freelancer_id = ? ORDER BY s.id DESC", [$fid])->fetchAll();
    $designs = q("SELECT d.id, d.name, d.base_price, d.is_active, d.created_at,
                         (SELECT COUNT(*) FROM library_orders o WHERE o.design_id = d.id) AS sold
                  FROM designs d JOIN submissions s ON s.id = d.source_submission_id
                  WHERE s.freelancer_id = ? ORDER BY d.id DESC", [$fid])->fetchAll();
?>
<a class="back-link" href="<?= h(url(['tab' => 'freelancers'])) ?>">&larr; All freelancers</a>
<div class="detail-head"><div>
  <div class="eyebrow">Freelancer</div>
  <h2 class="detail-title"><?= h($fr['name']) ?></h2>
  <div class="detail-sub"><?= fl_status_badge($fr['status']) ?> <?= h($fr['email']) ?> <span class="muted">&#183; joined <?= fdate($fr['created_at']) ?></span></div>
</div></div>

<section class="adm-card fl-status-card status-<?= h($fr['status']) ?>">
  <?php if ($fr['status'] === 'pending'): ?>
    <h3>Waiting for your review</h3>
    <p class="muted">They cannot sign in until you approve them.</p>
    <?= fl_review_actions($fr) ?>
  <?php elseif ($fr['status'] === 'active'): ?>
    <h3>Active</h3>
    <p class="muted">They can sign in, claim briefs and send in designs.<?= $fr['reviewer_name'] ? ' Approved by ' . h($fr['reviewer_name']) . ' on ' . fdate($fr['reviewed_at']) . '.' : '' ?></p>
    <form method="post" data-saving data-confirm="Suspend <?= h($fr['name']) ?>? They will not be able to sign in until you reactivate them. Their past work stays as it is.">
      <?= csrf_field() ?><?= return_field() ?>
      <input type="hidden" name="action" value="freelancer_suspend"><input type="hidden" name="id" value="<?= (int)$fr['id'] ?>">
      <button class="btn btn-danger-ghost" type="submit">Suspend</button></form>
  <?php elseif ($fr['status'] === 'suspended'): ?>
    <h3>Suspended</h3>
    <p class="muted">They cannot sign in.<?= $fr['reviewer_name'] ? ' Suspended by ' . h($fr['reviewer_name']) . ' on ' . fdate($fr['reviewed_at']) . '.' : '' ?></p>
    <form method="post" data-saving>
      <?= csrf_field() ?><?= return_field() ?>
      <input type="hidden" name="action" value="freelancer_reactivate"><input type="hidden" name="id" value="<?= (int)$fr['id'] ?>">
      <button class="btn btn-approve" type="submit">Reactivate</button></form>
  <?php else: ?>
    <h3>Not approved</h3>
    <p class="muted"><?= $fr['reviewer_name'] ? 'Rejected by ' . h($fr['reviewer_name']) . ' on ' . fdate($fr['reviewed_at']) . '.' : '' ?></p>
    <?php if ($fr['rejection_reason']): ?><p class="note-text"><strong>Reason:</strong> <?= nl2br(h($fr['rejection_reason'])) ?></p><?php endif; ?>
    <p class="muted small-note">Changed your mind? You can still approve them.</p>
    <?= fl_review_actions($fr) ?>
  <?php endif; ?>
</section>

<div class="stat-grid four">
  <div class="stat tone-green"><span class="stat-value"><?= inr($fr['earnings']) ?></span><span class="stat-label">Total earnings</span><span class="stat-hint">royalties credited so far</span></div>
  <div class="stat"><span class="stat-value"><?= count($briefs) ?></span><span class="stat-label">Briefs claimed</span></div>
  <div class="stat"><span class="stat-value"><?= count($subs) ?></span><span class="stat-label">Submissions</span></div>
  <div class="stat"><span class="stat-value"><?= count($designs) ?></span><span class="stat-label">Published designs</span></div>
</div>

<div class="detail-grid">
  <div class="detail-col">
    <section class="adm-card">
      <h3>Profile</h3>
      <?= fl_profile_list($fr) ?>
      <p class="muted small-note">Only the designer can change their profile (from their own dashboard).</p>
    </section>
    <section class="adm-card">
      <h3>Claimed briefs</h3>
      <?php if (!$briefs): ?><p class="empty">None.</p><?php else: ?>
      <div class="table-wrap"><table class="adm-table compact"><thead><tr><th>Brief</th><th>Status</th><th>Deadline</th></tr></thead><tbody>
        <?php foreach ($briefs as $b): ?><tr><td><a href="<?= h(url(['tab' => 'briefs', 'id' => $b['id']])) ?>"><?= h($b['title']) ?></a></td><td><?= brief_badge($b['status']) ?></td><td class="nowrap"><?= fdate($b['deadline']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
  <div class="detail-col">
    <section class="adm-card">
      <h3>Submissions</h3>
      <?php if (!$subs): ?><p class="empty">None yet.</p><?php endif; ?>
      <?php foreach ($subs as $s): ?>
        <div class="mini-sub">
          <div><a href="<?= h(url(['tab' => 'submissions', 'id' => $s['id']])) ?>"><?= h($s['title']) ?></a> <span class="muted"><?= fdate($s['submitted_at']) ?></span></div>
          <?= review_badge($s) ?>
          <?php if ($s['review_notes']): ?><p class="note-text"><?= nl2br(h($s['review_notes'])) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
    <section class="adm-card">
      <h3>Earnings breakdown</h3>
      <?php if (!$designs): ?><p class="empty">No published designs yet.</p><?php else: ?>
      <div class="table-wrap"><table class="adm-table compact"><thead><tr><th>Published design</th><th class="num">Price</th><th class="num">Royalty per sale</th><th>Orders</th></tr></thead><tbody>
        <?php foreach ($designs as $d): ?><tr><td><?= h($d['name']) ?><?= $d['is_active'] ? '' : ' <span class="badge badge-neutral">Hidden</span>' ?></td><td class="num"><?= inr($d['base_price']) ?></td><td class="num"><?= inr(round($d['base_price'] * 0.1)) ?></td><td><?= (int)$d['sold'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="muted small-note">Royalty is the same placeholder 10% used elsewhere. The total above is what has actually been credited (on publishing).</p>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php
    return;
endif;

// ---- List, pending registrations and add form ----------------------------------------------
$adding = !empty($_GET['new']);
$filter = isset(FREELANCER_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$statusCounts = array_fill_keys(array_keys(FREELANCER_STATUS), 0);
foreach (q("SELECT status, COUNT(*) AS n FROM freelancers GROUP BY status")->fetchAll() as $r) $statusCounts[$r['status']] = (int)$r['n'];
$pending = q("SELECT * FROM freelancers WHERE status = 'pending' ORDER BY created_at, id")->fetchAll();
$list = $filter !== ''
    ? q("SELECT * FROM freelancers WHERE status = ? ORDER BY (status = 'pending') DESC, name", [$filter])->fetchAll()
    : q("SELECT * FROM freelancers ORDER BY (status = 'pending') DESC, name")->fetchAll();
$pill = function ($value, $label, $n) use ($filter) {
    $on = $filter === $value;
    return '<a class="fpill' . ($on ? ' on' : '') . '" href="' . h(url(['tab' => 'freelancers', 'status' => $value ?: null])) . '"' . ($on ? ' aria-current="page"' : '') . '>'
        . h($label) . ' <span class="pill-n">' . (int)$n . '</span></a>';
};
?>
<?php if ($pending && ($filter === '' || $filter === 'pending')): ?>
<section class="pending-block" aria-labelledby="pendingHead">
  <h2 id="pendingHead"><?= count($pending) ?> new registration<?= count($pending) === 1 ? '' : 's' ?> waiting for review</h2>
  <p class="muted">Approve a designer to let them sign in and claim briefs. They get an email either way.</p>
  <?php foreach ($pending as $p): ?>
  <article class="adm-card pending-card" id="fl<?= (int)$p['id'] ?>">
    <div class="pending-top">
      <div><h3><?= h($p['name']) ?></h3><span class="muted">Registered <?= fdate($p['created_at'], true) ?></span></div>
      <?= fl_status_badge('pending') ?>
    </div>
    <?= fl_profile_list($p) ?>
    <?= fl_review_actions($p) ?>
  </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="toolbar">
  <div class="filter-pills" role="navigation" aria-label="Filter by status">
    <?= $pill('', 'All', array_sum($statusCounts)) ?>
    <?php foreach (['pending' => 'Pending', 'active' => 'Active', 'rejected' => 'Rejected', 'suspended' => 'Suspended'] as $k => $label) echo $pill($k, $label, $statusCounts[$k]); ?>
  </div>
  <a class="btn btn-primary" href="<?= h(url(['tab' => 'freelancers', 'new' => 1])) ?>#flForm">+ Add freelancer</a>
</div>

<?php if ($adding): ?>
<section class="adm-card form-card" id="flForm">
  <h2>Add a freelancer</h2>
  <p class="muted small-note">Designers can also register themselves at <a href="../register.php" target="_blank" rel="noopener">register.php</a>. Accounts you add here are active straight away.</p>
  <form method="post" data-saving autocomplete="off">
    <?= csrf_field() ?><?= return_field() ?>
    <input type="hidden" name="action" value="freelancer_add">
    <div class="form-grid-adm">
      <label>Name<input name="name" required maxlength="100" value="<?= h(old('name')) ?>"></label>
      <label>Email<input name="email" type="email" required maxlength="150" value="<?= h(old('email')) ?>"></label>
      <label>Password<input name="password" type="password" required minlength="8" autocomplete="new-password"><small>At least 8 characters.</small></label>
      <label>Type the password again<input name="password2" type="password" required minlength="8" autocomplete="new-password"></label>
    </div>
    <div class="adm-actions">
      <a class="btn" href="<?= h(url(['tab' => 'freelancers'])) ?>">Cancel</a>
      <button class="btn btn-primary" type="submit">Add freelancer</button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if (!$list): ?>
  <div class="adm-card"><p class="empty"><?= $filter ? 'No ' . h(strtolower(FREELANCER_STATUS[$filter])) . ' freelancers.' : 'No freelancers yet.' ?></p></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>City</th><th>Qualification</th><th>Experience</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($list as $fr): $link = url(['tab' => 'freelancers', 'id' => $fr['id']]); ?>
    <tr class="row-link<?= $fr['status'] === 'pending' ? ' flag' : '' ?>" data-href="<?= h($link) ?>">
      <td><a href="<?= h($link) ?>"><?= h($fr['name']) ?></a></td>
      <td class="break"><?= h($fr['email']) ?></td>
      <td class="nowrap"><?= $fr['phone'] ? h(substr($fr['phone'], 0, 5) . ' ' . substr($fr['phone'], 5)) : '<span class="muted">—</span>' ?></td>
      <td><?= h($fr['city'] ?: '—') ?></td>
      <td><?= $fr['qualification'] ? h(qualification_label($fr)) : '<span class="muted">—</span>' ?></td>
      <td class="nowrap"><?= $fr['experience'] ? h(experience_label($fr)) : '<span class="muted">—</span>' ?></td>
      <td><?= fl_status_badge($fr['status']) ?></td>
      <td class="nowrap"><?= fdate($fr['created_at']) ?></td>
      <td class="nowrap"><a class="btn btn-small<?= $fr['status'] === 'pending' ? ' btn-primary' : '' ?>" href="<?= h($fr['status'] === 'pending' && ($filter === '' || $filter === 'pending') ? url(['tab' => 'freelancers', 'status' => $filter ?: null]) . '#fl' . (int)$fr['id'] : $link) ?>"><?= $fr['status'] === 'pending' ? 'Review' : 'View' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

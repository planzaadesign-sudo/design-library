<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$subId = (int)($_GET['id'] ?? 0);

// ---- One submission -------------------------------------------------------------------
if ($subId):
    $s = q(SUBMISSION_SELECT . " WHERE s.id = ?", [$subId])->fetch();
    if (!$s):
        echo '<div class="adm-card"><p>Submission not found. <a href="' . h(url(['tab' => 'submissions'])) . '">Back to submissions</a></p></div>';
        return;
    endif;
    $brief = q("SELECT * FROM briefs WHERE id = ?", [$s['brief_id']])->fetch();
?>
<a class="back-link" href="<?= h(url(['tab' => 'submissions'])) ?>">&larr; All submissions</a>
<div class="detail-head"><div>
  <div class="eyebrow">Submission #<?= (int)$s['id'] ?></div>
  <h2 class="detail-title"><?= h($s['title']) ?></h2>
  <div class="detail-sub"><a href="<?= h(url(['tab' => 'briefs', 'id' => $s['brief_id']])) ?>">Open the brief &rarr;</a></div>
</div></div>
<div>
  <div>
    <section class="adm-card">
      <h3>What was asked</h3>
      <p class="note-text"><?= nl2br(h($brief['requirements'] ?? '')) ?></p>
      <dl class="kv">
        <dt>Plot</dt><dd><?= !empty($brief['plot_width']) ? (int)$brief['plot_width'] . ' &times; ' . (int)$brief['plot_length'] . ' ft' : '—' ?><?= !empty($brief['facing']) ? ', ' . h($brief['facing']) . ' facing' : '' ?></dd>
        <dt>House type</dt><dd><?= h(($brief['house_type'] ?? '') ?: '—') ?></dd>
        <dt>Payout</dt><dd><?= inr($brief['payout'] ?? 0) ?></dd>
      </dl>
    </section>
  </div>
</div>
<section class="adm-card"><h3>Review</h3><?= review_block($s) ?></section>
<?php
    return;
endif;

// ---- List ---------------------------------------------------------------------------------
$FILTERS = ['pending' => 'Pending', 'rejected' => 'Sent back', 'approved' => 'Approved', 'published' => 'Published'];
$status = array_key_exists($_GET['status'] ?? '', $FILTERS) ? $_GET['status'] : '';
$cond = [
    '' => '',
    'pending' => "WHERE s.review_status = 'pending'",
    'rejected' => "WHERE s.review_status = 'rejected'",
    'approved' => "WHERE s.review_status = 'approved' AND s.published = 0",
    'published' => "WHERE s.published = 1",
][$status];
$subs = q(SUBMISSION_SELECT . " $cond ORDER BY (s.review_status = 'pending') DESC, s.id DESC")->fetchAll();
?>
<div class="toolbar">
  <div class="filter-rows inline"><div><span>Status</span>
    <a class="fpill<?= $status === '' ? ' on' : '' ?>" href="<?= h(url(['tab' => 'submissions'])) ?>">All</a>
    <?php foreach ($FILTERS as $k => $label): ?><a class="fpill<?= $status === $k ? ' on' : '' ?>" href="<?= h(url(['tab' => 'submissions', 'status' => $k])) ?>"><?= h($label) ?></a><?php endforeach; ?>
  </div></div>
</div>

<?php if (!$subs): ?>
  <div class="adm-card"><p class="empty">No submissions<?= $status !== '' ? ' here' : ' yet' ?>.</p></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>ID</th><th>Brief</th><th>Design Creator</th><th>Submitted</th><th>Status</th><th>Reviewer</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($subs as $s): $link = url(['tab' => 'submissions', 'id' => $s['id']]); $pending = $s['review_status'] === 'pending'; ?>
    <tr class="row-link<?= $pending ? ' flag' : '' ?>" data-href="<?= h($link) ?>">
      <td><?= (int)$s['id'] ?></td>
      <td><a href="<?= h($link) ?>"><?= h($s['title']) ?></a></td>
      <td><?= h($s['freelancer_name']) ?></td>
      <td class="nowrap"><?= fdate($s['submitted_at']) ?></td>
      <td><?= review_badge($s) ?></td>
      <td><?= $s['reviewer_name'] ? h($s['reviewer_name']) : '<span class="muted">—</span>' ?></td>
      <td><a class="btn btn-small<?= $pending ? ' btn-primary' : '' ?>" href="<?= h($link) ?>"><?= $pending ? 'Review' : ($s['review_status'] === 'approved' && !$s['published'] ? 'Publish' : 'Open') ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

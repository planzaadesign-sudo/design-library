<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$briefId = (int)($_GET['id'] ?? 0);

// ---- Brief detail -------------------------------------------------------------------
if ($briefId):
    $b = q("SELECT b.*, f.name AS freelancer_name, st.name AS creator_name FROM briefs b
            LEFT JOIN freelancers f ON f.id = b.claimed_by LEFT JOIN staff st ON st.id = b.created_by WHERE b.id = ?", [$briefId])->fetch();
    if (!$b):
        echo '<div class="adm-card"><p>Brief not found. <a href="' . h(url(['tab' => 'briefs'])) . '">Back to briefs</a></p></div>';
        return;
    endif;
    $subs = q(SUBMISSION_SELECT . " WHERE s.brief_id = ? ORDER BY s.id DESC", [$briefId])->fetchAll();
    $late = $b['deadline'] < date('Y-m-d') && in_array($b['status'], ['open', 'claimed'], true);
?>
<a class="back-link" href="<?= h(url(['tab' => 'briefs'])) ?>">&larr; All briefs</a>
<div class="detail-head"><div>
  <div class="eyebrow">Brief #<?= (int)$b['id'] ?></div>
  <h2 class="detail-title"><?= h($b['title']) ?></h2>
  <div class="detail-sub"><?= brief_badge($b['status']) ?><?= $late ? ' <span class="badge b-rust">Past deadline</span>' : '' ?></div>
</div></div>
<div class="detail-grid">
  <div class="detail-col">
    <section class="adm-card">
      <h3>Requirements</h3>
      <p class="note-text"><?= nl2br(h($b['requirements'])) ?></p>
      <?php if (!empty($b['differentiation_notes'])): ?>
        <h3 class="param-head">What should be different</h3>
        <p class="note-text"><?= nl2br(h($b['differentiation_notes'])) ?></p>
      <?php endif; ?>
      <dl class="kv">
        <dt>Plot</dt><dd><?= $b['plot_width'] ? (int)$b['plot_width'] . ' &times; ' . (int)$b['plot_length'] . ' ft' : '—' ?><?= $b['facing'] ? ', ' . h($b['facing']) . ' facing' : '' ?></dd>
        <dt>House type</dt><dd><?= h($b['house_type'] ?: '—') ?></dd>
        <dt>Payout</dt><dd><?= inr($b['payout']) ?></dd>
        <dt>Deadline</dt><dd><?= fdate($b['deadline']) ?></dd>
        <dt>Posted by</dt><dd><?= h($b['creator_name'] ?? '—') ?> on <?= fdate($b['created_at']) ?></dd>
        <dt>Claimed by</dt><dd><?= $b['freelancer_name'] ? h($b['freelancer_name']) : '<span class="muted">Nobody yet</span>' ?></dd>
      </dl>
      <p class="muted small-note">The date a brief was claimed is not recorded yet.</p>
    </section>
  </div>
</div>
<section class="adm-card">
  <h3>Submissions (<?= count($subs) ?>)</h3>
  <?php if (!$subs): ?><p class="empty">No work submitted yet.</p><?php endif; ?>
  <?php foreach ($subs as $s) echo review_block($s); ?>
</section>
<?php
    return;
endif;

// ---- Post a brief -------------------------------------------------------------------
$status = array_key_exists($_GET['status'] ?? '', BRIEF_STATUS) ? $_GET['status'] : '';
$showForm = !empty($_GET['new']);
?>
<div class="toolbar">
  <div class="filter-rows inline"><div><span>Status</span>
    <a class="fpill<?= $status === '' ? ' on' : '' ?>" href="<?= h(url(['tab' => 'briefs'])) ?>">All</a>
    <?php foreach (BRIEF_STATUS as $k => $label): ?><a class="fpill<?= $status === $k ? ' on' : '' ?>" href="<?= h(url(['tab' => 'briefs', 'status' => $k])) ?>"><?= h($label) ?></a><?php endforeach; ?>
  </div></div>
  <a class="btn btn-primary" href="<?= h(url(['tab' => 'briefs', 'new' => 1])) ?>#briefFormCard">+ Post new brief</a>
</div>

<?php if ($showForm): ?>
<section class="adm-card form-card" id="briefFormCard">
  <h2>Post a new brief</h2>
  <p class="muted">Answer each question so we can check the library for similar designs before a Design Creator starts work.</p>
  <?= render_brief_form($OLD, [
      'hidden' => csrf_field() . return_field() . '<input type="hidden" name="action" value="brief_save">',
      'api' => '../api/similarity-check.php',
      'design_url' => '../design.php?id=',
      'brief_url' => 'index.php?tab=briefs&id=',
      'cancel' => url(['tab' => 'briefs']),
  ]) ?>
</section>
<?php endif;

// ---- Brief list ---------------------------------------------------------------------
$briefs = q("SELECT b.*, f.name AS freelancer_name, st.name AS creator_name,
                    (SELECT COUNT(*) FROM submissions s WHERE s.brief_id = b.id) AS sub_count
             FROM briefs b LEFT JOIN freelancers f ON f.id = b.claimed_by LEFT JOIN staff st ON st.id = b.created_by
             " . ($status !== '' ? 'WHERE b.status = ?' : '') . " ORDER BY b.id DESC", $status !== '' ? [$status] : [])->fetchAll();
?>
<?php if (!$briefs): ?>
  <div class="adm-card"><p class="empty">No briefs<?= $status !== '' ? ' with this status' : '' ?>.</p></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>ID</th><th>Title</th><th>Plot</th><th>Status</th><th>Claimed by</th><th class="num">Payout</th><th>Deadline</th><th>Posted by</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($briefs as $b): $link = url(['tab' => 'briefs', 'id' => $b['id']]); $late = $b['deadline'] < date('Y-m-d') && in_array($b['status'], ['open', 'claimed'], true); ?>
    <tr class="row-link" data-href="<?= h($link) ?>">
      <td><?= (int)$b['id'] ?></td>
      <td><a href="<?= h($link) ?>"><?= h($b['title']) ?></a><?= $b['sub_count'] ? '<small class="muted block">' . (int)$b['sub_count'] . ' submission' . ((int)$b['sub_count'] === 1 ? '' : 's') . '</small>' : '' ?></td>
      <td class="nowrap"><?= $b['plot_width'] ? (int)$b['plot_width'] . ' &times; ' . (int)$b['plot_length'] : '—' ?><?= $b['facing'] ? ', ' . h($b['facing']) : '' ?></td>
      <td><?= brief_badge($b['status']) ?></td>
      <td><?= $b['freelancer_name'] ? h($b['freelancer_name']) : '<span class="muted">—</span>' ?></td>
      <td class="num"><?= inr($b['payout']) ?></td>
      <td class="nowrap<?= $late ? ' late' : '' ?>"><?= fdate($b['deadline']) ?></td>
      <td><?= h($b['creator_name'] ?? '—') ?></td>
      <td><a class="btn btn-small" href="<?= h($link) ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

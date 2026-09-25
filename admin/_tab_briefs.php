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
  <div class="detail-col">
    <section class="adm-card">
      <h3>Submissions (<?= count($subs) ?>)</h3>
      <?php if (!$subs): ?><p class="empty">No work submitted yet.</p><?php endif; ?>
      <?php foreach ($subs as $s) echo review_block($s); ?>
    </section>
  </div>
</div>
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
  <a class="btn btn-primary" href="<?= h(url(['tab' => 'briefs', 'new' => 1])) ?>#briefForm">+ Post new brief</a>
</div>

<?php if ($showForm): ?>
<section class="adm-card form-card" id="briefForm">
  <h2>Post a new brief</h2>
  <form method="post" data-saving>
    <?= csrf_field() ?><?= return_field() ?>
    <input type="hidden" name="action" value="brief_save">
    <div class="form-grid-adm">
      <label class="span-2">Title<input name="title" required maxlength="150" value="<?= h(old('title')) ?>"></label>
      <label>Plot width (ft)<input name="plot_width" type="number" min="1" value="<?= h(old('plot_width')) ?>"></label>
      <label>Plot length (ft)<input name="plot_length" type="number" min="1" value="<?= h(old('plot_length')) ?>"></label>
      <label>Facing<select name="facing"><option value="">Any</option><?php foreach (FACINGS as $x): ?><option <?= old('facing') === $x ? 'selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></label>
      <label>House type<input name="house_type" maxlength="50" placeholder="e.g. G+1, 3BHK" value="<?= h(old('house_type')) ?>"></label>
      <label>Payout (&#8377;)<input name="payout" type="number" min="1" required value="<?= h(old('payout')) ?>"></label>
      <label>Deadline<input name="deadline" type="date" required value="<?= h(old('deadline')) ?>"></label>
      <label class="span-3">Requirements<textarea name="requirements" rows="4" required placeholder="Layout, vastu notes, style &#8212; anything a freelancer needs to know"><?= h(old('requirements')) ?></textarea></label>
    </div>
    <div class="adm-actions">
      <a class="btn" href="<?= h(url(['tab' => 'briefs'])) ?>">Cancel</a>
      <button class="btn btn-primary" type="submit">Post brief</button>
    </div>
  </form>
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

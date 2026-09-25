<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$fid = (int)($_GET['id'] ?? 0);

// ---- One freelancer --------------------------------------------------------------------
if ($fid):
    $fr = q("SELECT * FROM freelancers WHERE id = ?", [$fid])->fetch();
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
  <div class="detail-sub"><?= h($fr['email']) ?> <span class="muted">&#183; joined <?= fdate($fr['created_at']) ?></span></div>
</div></div>

<div class="stat-grid four">
  <div class="stat tone-green"><span class="stat-value"><?= inr($fr['earnings']) ?></span><span class="stat-label">Total earnings</span><span class="stat-hint">royalties credited so far</span></div>
  <div class="stat"><span class="stat-value"><?= count($briefs) ?></span><span class="stat-label">Briefs claimed</span></div>
  <div class="stat"><span class="stat-value"><?= count($subs) ?></span><span class="stat-label">Submissions</span></div>
  <div class="stat"><span class="stat-value"><?= count($designs) ?></span><span class="stat-label">Published designs</span></div>
</div>

<div class="detail-grid">
  <div class="detail-col">
    <section class="adm-card">
      <h3>Claimed briefs</h3>
      <?php if (!$briefs): ?><p class="empty">None.</p><?php else: ?>
      <table class="adm-table compact"><thead><tr><th>Brief</th><th>Status</th><th>Deadline</th></tr></thead><tbody>
        <?php foreach ($briefs as $b): ?><tr><td><a href="<?= h(url(['tab' => 'briefs', 'id' => $b['id']])) ?>"><?= h($b['title']) ?></a></td><td><?= brief_badge($b['status']) ?></td><td class="nowrap"><?= fdate($b['deadline']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </section>
    <section class="adm-card">
      <h3>Earnings breakdown</h3>
      <?php if (!$designs): ?><p class="empty">No published designs yet.</p><?php else: ?>
      <table class="adm-table compact"><thead><tr><th>Published design</th><th class="num">Price</th><th class="num">Royalty per sale</th><th>Orders</th></tr></thead><tbody>
        <?php foreach ($designs as $d): ?><tr><td><?= h($d['name']) ?><?= $d['is_active'] ? '' : ' <span class="badge badge-neutral">Hidden</span>' ?></td><td class="num"><?= inr($d['base_price']) ?></td><td class="num"><?= inr(round($d['base_price'] * 0.1)) ?></td><td><?= (int)$d['sold'] ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <p class="muted small-note">Royalty is the same placeholder 10% used elsewhere. The total above is what has actually been credited (on publishing).</p>
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
  </div>
</div>
<?php
    return;
endif;

// ---- List and add form -------------------------------------------------------------------
$adding = !empty($_GET['new']);
$list = q("SELECT f.*,
              (SELECT COUNT(*) FROM briefs b WHERE b.claimed_by = f.id) AS claimed,
              (SELECT COUNT(*) FROM submissions s WHERE s.freelancer_id = f.id) AS submitted,
              (SELECT COUNT(*) FROM submissions s WHERE s.freelancer_id = f.id AND s.review_status = 'approved') AS approved
           FROM freelancers f ORDER BY f.name")->fetchAll();
?>
<div class="toolbar">
  <p class="muted">Freelancers claim open briefs and submit designs from their own dashboard.</p>
  <a class="btn btn-primary" href="<?= h(url(['tab' => 'freelancers', 'new' => 1])) ?>#flForm">+ Add freelancer</a>
</div>

<?php if ($adding): ?>
<section class="adm-card form-card" id="flForm">
  <h2>Add a freelancer</h2>
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
  <div class="adm-card"><p class="empty">No freelancers yet.</p></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>Name</th><th>Email</th><th class="num">Earnings</th><th>Briefs claimed</th><th>Submissions</th><th>Approved</th><th>Joined</th></tr></thead>
  <tbody>
  <?php foreach ($list as $fr): $link = url(['tab' => 'freelancers', 'id' => $fr['id']]); ?>
    <tr class="row-link" data-href="<?= h($link) ?>">
      <td><a href="<?= h($link) ?>"><?= h($fr['name']) ?></a></td>
      <td><?= h($fr['email']) ?></td>
      <td class="num"><?= inr($fr['earnings']) ?></td>
      <td><?= (int)$fr['claimed'] ?></td>
      <td><?= (int)$fr['submitted'] ?></td>
      <td><?= (int)$fr['approved'] ?></td>
      <td class="nowrap"><?= fdate($fr['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

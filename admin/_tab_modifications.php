<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$tierFilter = (int)($_GET['tier'] ?? 0);
if ($tierFilter < 1 || $tierFilter > 4) $tierFilter = 0;
$editId = $_GET['edit'] ?? '';

$mods = $tierFilter
    ? q("SELECT * FROM modifications WHERE tier = ? ORDER BY tier, id", [$tierFilter])->fetchAll()
    : q("SELECT * FROM modifications ORDER BY tier, id")->fetchAll();
$editing = null;
if ($editId !== '' && $editId !== 'new') {
    $editing = q("SELECT * FROM modifications WHERE id = ?", [(int)$editId])->fetch() ?: null;
}
$showForm = $editId === 'new' || $editing;
$v = function ($key, $default = '') use ($editing) {
    $o = old($key, null);
    if ($o !== null) return $o;
    return $editing ? ($editing[$key] ?? $default) : $default;
};
$TIER_NAMES = [1 => 'Simple changes', 2 => 'Room changes', 3 => 'Big changes', 4 => 'Very big changes (team gives price)'];
?>
<div class="toolbar">
  <div class="filter-rows inline"><div><span>Tier</span>
    <a class="fpill<?= !$tierFilter ? ' on' : '' ?>" href="<?= h(url(['tab' => 'modifications'])) ?>">All</a>
    <?php for ($t = 1; $t <= 4; $t++): ?><a class="fpill<?= $tierFilter === $t ? ' on' : '' ?>" href="<?= h(url(['tab' => 'modifications', 'tier' => $t])) ?>"><?= $t ?></a><?php endfor; ?>
  </div></div>
  <a class="btn btn-primary" href="<?= h(url(['tab' => 'modifications', 'edit' => 'new'])) ?>#modForm">+ Add a change</a>
</div>
<p class="muted">These are the changes customers can pick when they customise a design. Labels are shown to customers exactly as written &#8212; keep them simple. Price changes apply to new orders only.</p>

<?php if ($showForm): $tierNow = (int)$v('tier', 1); ?>
<section class="adm-card form-card" id="modForm">
  <h2><?= $editing ? 'Edit change #' . (int)$editing['id'] : 'Add a change' ?></h2>
  <form method="post" data-saving>
    <?= csrf_field() ?><?= return_field() ?>
    <input type="hidden" name="action" value="mod_save">
    <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
    <div class="form-grid-adm">
      <label class="span-3">Label (what customers see)<input name="label" required maxlength="200" value="<?= h($v('label')) ?>" placeholder="e.g. Add a bathroom attached to a bedroom"></label>
      <label>Tier<select name="tier" id="modTier"><?php foreach ($TIER_NAMES as $n => $name): ?><option value="<?= $n ?>" <?= $tierNow === $n ? 'selected' : '' ?>><?= $n ?> &#8212; <?= h($name) ?></option><?php endforeach; ?></select></label>
      <label data-tier="fixed">Price (&#8377;)<input name="price" type="number" min="0" value="<?= h($v('price', 0)) ?>"></label>
      <label data-tier="fixed">Structural part (&#8377;)<input name="struct_portion" type="number" min="0" value="<?= h($v('struct_portion', 0)) ?>"><small>Taken off when the customer skips safety drawings.</small></label>
      <label data-tier="range">Lowest price (&#8377;)<input name="price_min" type="number" min="0" value="<?= h($v('price_min')) ?>"></label>
      <label data-tier="range">Highest price (&#8377;)<input name="price_max" type="number" min="0" value="<?= h($v('price_max')) ?>"></label>
      <label>Adds days to delivery<input name="added_days" type="number" min="0" max="120" value="<?= h($v('added_days', 0)) ?>"></label>
      <label>What the customer fills in<select name="detail_type"><?php foreach (DETAIL_TYPES as $k => $name): ?><option value="<?= h($k) ?>" <?= (string)$v('detail_type', '') === $k ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select><small>Room pickers charge the price once per room.</small></label>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= $v('is_active', 1) ? 'checked' : '' ?>> Offer to customers</label>
    </div>
    <div class="adm-actions">
      <a class="btn" href="<?= h(url(['tab' => 'modifications'])) ?>">Cancel</a>
      <button class="btn btn-primary" type="submit">Save change</button>
    </div>
  </form>
</section>
<?php endif; ?>

<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>ID</th><th>Label</th><th>Tier</th><th class="num">Price</th><th class="num">Structural part</th><th>Days</th><th>Customer fills in</th><th>Offered</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($mods as $m): ?>
    <tr class="<?= $m['is_active'] ? '' : 'dim' ?>">
      <td><?= (int)$m['id'] ?></td>
      <td><?= h($m['label']) ?></td>
      <td><span class="tier-dot t<?= (int)$m['tier'] ?>"></span><?= (int)$m['tier'] ?></td>
      <td class="num nowrap"><?= (int)$m['tier'] === 4 ? inr($m['price_min']) . ' – ' . inr($m['price_max']) : inr($m['price']) ?><?= in_array($m['detail_type'], ROOM_KINDS, true) ? '<small class="muted block">per room</small>' : '' ?></td>
      <td class="num"><?= (int)$m['tier'] === 4 ? '—' : inr($m['struct_portion']) ?></td>
      <td><?= (int)$m['added_days'] ?></td>
      <td><?= h(DETAIL_TYPES[(string)$m['detail_type']] ?? $m['detail_type']) ?></td>
      <td>
        <form method="post" class="toggle-form" data-saving>
          <?= csrf_field() ?><?= return_field() ?>
          <input type="hidden" name="action" value="mod_toggle"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <button type="submit" class="switch-btn<?= $m['is_active'] ? ' on' : '' ?>" role="switch" aria-checked="<?= $m['is_active'] ? 'true' : 'false' ?>" aria-label="Offer this change to customers"><span></span></button>
        </form>
      </td>
      <td><a class="btn btn-small" href="<?= h(url(['tab' => 'modifications', 'tier' => $tierFilter ?: null, 'edit' => $m['id']])) ?>#modForm">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

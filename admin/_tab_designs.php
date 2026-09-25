<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$editId = $_GET['edit'] ?? '';
$roomsFor = (int)($_GET['rooms'] ?? 0);
$roomEdit = (int)($_GET['room'] ?? 0);

$designs = q("SELECT d.*, f.name AS freelancer_name,
                     (SELECT COUNT(*) FROM design_rooms r WHERE r.design_id = d.id) AS room_count,
                     (SELECT COUNT(*) FROM library_orders o WHERE o.design_id = d.id) AS order_count
              FROM designs d
              LEFT JOIN submissions s ON s.id = d.source_submission_id
              LEFT JOIN freelancers f ON f.id = s.freelancer_id
              ORDER BY d.id DESC")->fetchAll();

$editing = null;
if ($editId !== '' && $editId !== 'new') {
    foreach ($designs as $d) if ((int)$d['id'] === (int)$editId) $editing = $d;
}
$showForm = $editId === 'new' || $editing;
$v = function ($key, $default = '') use ($editing) {
    $o = old($key, null);
    if ($o !== null) return $o;
    return $editing ? $editing[$key] : $default;
};
?>
<div class="toolbar">
  <p class="muted">Hidden designs disappear from the customer pages right away. Orders that already use them keep working.</p>
  <a class="btn btn-primary" href="<?= h(url(['tab' => 'designs', 'edit' => 'new'])) ?>#designForm">+ Add new design</a>
</div>

<?php if ($showForm): ?>
<section class="adm-card form-card" id="designForm">
  <h2><?= $editing ? 'Edit ' . h($editing['name']) : 'Add a new design' ?></h2>
  <form method="post" data-saving>
    <?= csrf_field() ?><?= return_field() ?>
    <input type="hidden" name="action" value="design_save">
    <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
    <div class="form-grid-adm">
      <label class="span-2">Name<input name="name" required maxlength="100" value="<?= h($v('name')) ?>"></label>
      <label>Plot width (ft)<input name="plot_width" type="number" min="5" max="500" required value="<?= h($v('plot_width')) ?>"></label>
      <label>Plot length (ft)<input name="plot_length" type="number" min="5" max="500" required value="<?= h($v('plot_length')) ?>"></label>
      <label>Facing<select name="facing" required><?php foreach (FACINGS as $x): ?><option <?= $v('facing') === $x ? 'selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></label>
      <label>Floors<select name="floors" required><?php foreach (FLOOR_OPTIONS as $x): ?><option <?= $v('floors', 'G+1') === $x ? 'selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></label>
      <label>BHK<input name="bhk" type="number" min="1" max="10" required value="<?= h($v('bhk', 3)) ?>"></label>
      <label>Base price (&#8377;)<input name="base_price" type="number" min="1000" required value="<?= h($v('base_price')) ?>"></label>
      <label>Delivery days<input name="delivery_days" type="number" min="1" max="365" required value="<?= h($v('delivery_days', 12)) ?>"></label>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= $v('is_active', 1) ? 'checked' : '' ?>> Show to customers</label>
    </div>
    <div class="adm-actions">
      <a class="btn" href="<?= h(url(['tab' => 'designs'])) ?>">Cancel</a>
      <button class="btn btn-primary" type="submit">Save design</button>
    </div>
  </form>
</section>
<?php endif; ?>

<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>ID</th><th>Name</th><th>Plot</th><th>Facing</th><th>Floors</th><th>BHK</th><th class="num">Price</th><th>Days</th><th>Source</th><th>Rooms</th><th>Visible</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($designs as $d): $open = $roomsFor === (int)$d['id']; ?>
    <tr class="<?= $d['is_active'] ? '' : 'dim' ?><?= $open ? ' open' : '' ?>">
      <td><?= (int)$d['id'] ?></td>
      <td><strong><?= h($d['name']) ?></strong><?= $d['order_count'] ? '<small class="muted block">' . (int)$d['order_count'] . ' orders</small>' : '' ?></td>
      <td class="nowrap"><?= (int)$d['plot_width'] ?> &times; <?= (int)$d['plot_length'] ?></td>
      <td><?= h($d['facing']) ?></td>
      <td><?= h($d['floors']) ?></td>
      <td><?= (int)$d['bhk'] ?></td>
      <td class="num nowrap"><?= inr($d['base_price']) ?></td>
      <td><?= (int)$d['delivery_days'] ?></td>
      <td><?= $d['source_submission_id'] ? 'Freelancer: ' . h($d['freelancer_name'] ?? '?') : 'In-house' ?></td>
      <td><a href="<?= h($open ? url(['tab' => 'designs']) : url(['tab' => 'designs', 'rooms' => $d['id']]) . '#rooms') ?>"><?= (int)$d['room_count'] ?> room<?= (int)$d['room_count'] === 1 ? '' : 's' ?> <?= $open ? '&#9650;' : '&#9660;' ?></a></td>
      <td>
        <form method="post" class="toggle-form" data-saving>
          <?= csrf_field() ?><?= return_field() ?>
          <input type="hidden" name="action" value="design_toggle"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button type="submit" class="switch-btn<?= $d['is_active'] ? ' on' : '' ?>" role="switch" aria-checked="<?= $d['is_active'] ? 'true' : 'false' ?>" aria-label="Show <?= h($d['name']) ?> to customers"><span></span></button>
        </form>
      </td>
      <td><a class="btn btn-small" href="<?= h(url(['tab' => 'designs', 'edit' => $d['id']])) ?>#designForm">Edit</a></td>
    </tr>
    <?php if ($open):
        $rooms = q("SELECT r.*, (SELECT COUNT(*) FROM order_modification_details m WHERE m.room_id = r.id) AS used
                    FROM design_rooms r WHERE r.design_id = ? ORDER BY FIELD(r.floor, 'Ground Floor', 'First Floor', 'Second Floor', 'Third Floor'), r.id", [$d['id']])->fetchAll();
        $er = null;
        foreach ($rooms as $r) if ((int)$r['id'] === $roomEdit) $er = $r;
        $rv = function ($key, $default = '') use ($er) { $o = old($key, null); return $o !== null ? $o : ($er ? $er[$key] : $default); };
    ?>
    <tr class="rooms-row" id="rooms"><td colspan="12">
      <div class="rooms-panel">
        <h3>Rooms in <?= h($d['name']) ?></h3>
        <p class="muted">Customers pick from these rooms when they change this design (bigger rooms, new bathrooms, doors and windows).</p>
        <?php if ($rooms): ?>
        <table class="adm-table compact">
          <thead><tr><th>Room</th><th>Type</th><th>Size (sq ft)</th><th>Floor</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rooms as $r): ?>
            <tr<?= $er && (int)$er['id'] === (int)$r['id'] ? ' class="editing"' : '' ?>>
              <td><?= h($r['room_name']) ?></td><td><?= h(ucfirst($r['room_type'])) ?></td>
              <td><?= $r['current_size_sqft'] !== null ? (int)$r['current_size_sqft'] : '—' ?></td><td><?= h($r['floor']) ?></td>
              <td class="row-actions">
                <a class="btn btn-small" href="<?= h(url(['tab' => 'designs', 'rooms' => $d['id'], 'room' => $r['id']])) ?>#roomForm">Edit</a>
                <?php if ($r['used']): ?>
                  <span class="muted small" title="Used in orders, so it cannot be deleted">In orders</span>
                <?php else: ?>
                <form method="post" data-confirm="Delete the room &ldquo;<?= h($r['room_name']) ?>&rdquo;? This cannot be undone." data-saving>
                  <?= csrf_field() ?><input type="hidden" name="action" value="room_delete"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-small btn-danger-ghost" type="submit">Delete</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <p class="empty">No rooms yet. Until you add some, customers describe the rooms in words.</p>
        <?php endif; ?>

        <form method="post" class="room-form" id="roomForm" data-saving>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="room_save">
          <input type="hidden" name="design_id" value="<?= (int)$d['id'] ?>">
          <input type="hidden" name="room_id" value="<?= $er ? (int)$er['id'] : 0 ?>">
          <h4><?= $er ? 'Edit ' . h($er['room_name']) : 'Add a room' ?></h4>
          <div class="form-grid-adm">
            <label>Room name<input name="room_name" required maxlength="100" placeholder="e.g. Bedroom 2" value="<?= h($rv('room_name')) ?>"></label>
            <label>Type<select name="room_type"><?php foreach (ROOM_TYPES as $t): ?><option value="<?= $t ?>" <?= $rv('room_type', 'bedroom') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></label>
            <label>Size (sq ft)<input name="current_size_sqft" type="number" min="1" max="5000" value="<?= h($rv('current_size_sqft')) ?>"></label>
            <label>Floor<select name="floor"><?php foreach (ROOM_FLOORS as $fl): ?><option <?= $rv('floor', 'Ground Floor') === $fl ? 'selected' : '' ?>><?= $fl ?></option><?php endforeach; ?></select></label>
          </div>
          <div class="adm-actions">
            <?php if ($er): ?><a class="btn" href="<?= h(url(['tab' => 'designs', 'rooms' => $d['id']])) ?>#rooms">Cancel</a><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= $er ? 'Save room' : '+ Add room' ?></button>
          </div>
        </form>
      </div>
    </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table></div>

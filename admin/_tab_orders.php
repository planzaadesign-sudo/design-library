<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$orderId = (int)($_GET['id'] ?? 0);

// =====================================================================================
// ORDER DETAIL
// =====================================================================================
if ($orderId):
    $o = q("SELECT o.*, d.name AS design_name, d.plot_width AS d_width, d.plot_length AS d_length, d.facing AS d_facing,
                   d.floors AS d_floors, d.bhk AS d_bhk, d.base_price AS d_price, d.is_active AS d_active, d.design_code AS d_code, s.name AS staff_name
            FROM library_orders o
            JOIN designs d ON d.id = o.design_id
            LEFT JOIN staff s ON s.id = o.assigned_to
            WHERE o.id = ?", [$orderId])->fetch();
    if (!$o):
        echo '<div class="adm-card"><p>Order not found. <a href="' . h(url(['tab' => 'orders'])) . '">Back to orders</a></p></div>';
        return;
    endif;

    $type = order_type($o);
    $staffList = q("SELECT id, name, role FROM staff ORDER BY name")->fetchAll();

    // What they ordered: modification ids (JSON) + room-level details.
    $modIds = array_values(array_filter(array_map('intval', (array)json_decode((string)$o['modifications'], true))));
    $mods = [];
    if ($modIds) {
        $ph = implode(',', array_fill(0, count($modIds), '?'));
        foreach (q("SELECT * FROM modifications WHERE id IN ($ph) ORDER BY tier, id", $modIds)->fetchAll() as $m) $mods[(int)$m['id']] = $m;
    }
    $detailsByMod = [];
    foreach (q("SELECT omd.*, dr.room_name FROM order_modification_details omd
                LEFT JOIN design_rooms dr ON dr.id = omd.room_id WHERE omd.order_id = ? ORDER BY omd.id", [$orderId])->fetchAll() as $d) {
        $detailsByMod[(int)$d['modification_id']][] = $d;
    }

    // Price breakdown at today's catalog prices (the saved total is what the customer was shown).
    $structural = (bool)$o['structural_included'];
    $base = (int)$o['d_price'];
    $lines = [];
    $calcMin = $base; $calcMax = $base;
    if ($type === 'asis' && $o['structural_addon']) {
        $addon = (int)(round($base * 0.4 / 100) * 100);
        $lines[] = ['Building safety drawings package', '', $addon, $addon, 0];
        $calcMin += $addon; $calcMax += $addon;
    }
    foreach ($mods as $id => $m) {
        $kind = $m['detail_type'] ?? null;
        $entries = $detailsByMod[$id] ?? [];
        $qty = in_array($kind, ROOM_KINDS, true) ? max(1, count($entries)) : 1;
        if ((int)$m['tier'] === 4) {
            $lines[] = [$m['label'], '', (int)$m['price_min'], (int)$m['price_max'], 0];
            $calcMin += (int)$m['price_min']; $calcMax += (int)$m['price_max'];
        } else {
            $unit = (int)$m['price'] - ($structural ? 0 : (int)$m['struct_portion']);
            $structPart = $structural ? (int)$m['struct_portion'] * $qty : 0;
            $lines[] = [$m['label'], $qty > 1 ? $qty . ' rooms &times; ' . inr($unit) : '', $unit * $qty, $unit * $qty, $structPart];
            $calcMin += $unit * $qty; $calcMax += $unit * $qty;
        }
    }
    $pricesDrifted = !$o['needs_manual_review'] && $type !== 'call' && $calcMin === $calcMax && $calcMin !== (int)$o['total_price'];
    $plotDiffers = ($o['plot_width'] && (int)$o['plot_width'] !== (int)$o['d_width'])
        || ($o['plot_length'] && (int)$o['plot_length'] !== (int)$o['d_length'])
        || ($o['facing'] && $o['facing'] !== $o['d_facing']);
    $stageIdx = array_search($o['status'], STAGES, true);
    $back = url(['tab' => 'orders']);
?>
<a class="back-link" href="<?= h($back) ?>">&larr; All orders</a>
<div class="detail-head">
  <div>
    <div class="eyebrow">Order</div>
    <h2 class="detail-title"><?= h($o['order_code']) ?></h2>
    <div class="detail-sub"><?= type_badge($o) ?> <?= stage_badge($o['status']) ?>
      <?php if ($o['needs_manual_review']): ?><span class="badge b-rust">Needs a final price</span><?php endif; ?>
      <span class="muted">Placed <?= fdate($o['created_at'], true) ?></span></div>
  </div>
</div>

<?php if ($type === 'call'): ?>
  <div class="callout call">
    <?= svg('phone') ?>
    <div><strong>Customer asked for a phone call to discuss changes.</strong>
      Call <a href="tel:+91<?= h($o['customer_phone']) ?>"><?= h($o['customer_phone']) ?></a>, understand what they want, then confirm the final price below.
      <?php if ($o['callback_notes']): ?><blockquote><?= nl2br(h($o['callback_notes'])) ?></blockquote><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="detail-grid">
  <div class="detail-col">
    <section class="adm-card">
      <h3>Customer</h3>
      <dl class="kv">
        <dt>Name</dt><dd><?= h($o['customer_name']) ?></dd>
        <dt>Phone</dt><dd><a href="tel:+91<?= h($o['customer_phone']) ?>" class="tel"><?= svg('phone') ?><?= h($o['customer_phone']) ?></a></dd>
        <dt>City</dt><dd><?= h($o['customer_city'] ?: '—') ?></dd>
        <dt>District</dt><dd><?= h($o['customer_district'] ?: '—') ?></dd>
        <dt>State</dt><dd><?= h($o['customer_state'] ?: '—') ?></dd>
        <dt>Contact</dt><dd><?= $type === 'call' ? '<span class="badge b-amber">Wants a call</span>' : '<span class="badge badge-neutral">Self-configured</span>' ?></dd>
      </dl>
      <?php if ($o['callback_notes'] && $type !== 'call'): ?><p class="note-text"><?= nl2br(h($o['callback_notes'])) ?></p><?php endif; ?>
    </section>

    <section class="adm-card">
      <h3>Design</h3>
      <dl class="kv">
        <dt>Design</dt><dd><?= h($o['design_name']) ?><?= $o['d_active'] ? '' : ' <span class="badge badge-neutral">Hidden from customers</span>' ?></dd>
        <dt>Design code</dt><dd><a href="<?= h(url(['tab' => 'designs', 'edit' => $o['design_id']])) ?>#history"><?= h($o['d_code'] ?: '—') ?></a></dd>
        <dt>Plot</dt><dd><?= (int)$o['d_width'] ?> &times; <?= (int)$o['d_length'] ?> ft, <?= h($o['d_facing']) ?> facing</dd>
        <dt>Floors</dt><dd><?= h($o['d_floors']) ?></dd>
        <dt>BHK</dt><dd><?= (int)$o['d_bhk'] ?></dd>
        <?php if ($plotDiffers): ?>
          <dt>Customer's plot</dt><dd class="hl"><?= $o['plot_width'] ? (int)$o['plot_width'] : '?' ?> &times; <?= $o['plot_length'] ? (int)$o['plot_length'] : '?' ?> ft<?= $o['facing'] ? ', ' . h($o['facing']) . ' facing' : '' ?></dd>
        <?php endif; ?>
      </dl>
    </section>

    <section class="adm-card">
      <h3>What they ordered</h3>
      <p><?= ($structural || $o['structural_addon']) ? '<span class="badge b-green">Building safety drawings: Yes</span>' : '<span class="badge badge-neutral">Building safety drawings: No</span>' ?></p>
      <?php if ($type === 'call'): ?>
        <p class="muted">Nothing picked yet &#8212; the customer will explain on the call.</p>
      <?php elseif (!$mods): ?>
        <p>The design as it is<?= $o['structural_addon'] ? ', with the building safety drawings package' : '' ?>.</p>
      <?php else: ?>
        <ul class="ordered">
        <?php foreach ($mods as $id => $m): $entries = $detailsByMod[$id] ?? []; ?>
          <li><strong><?= h($m['label']) ?></strong>
            <?php if ($entries): ?>
              <ul><?php foreach ($entries as $d): ?><li><?= detail_text($d, $m['detail_type'] ?? null) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <div class="detail-col">
    <section class="adm-card">
      <h3>Price</h3>
      <table class="price-table">
        <tr><td>Design price</td><td></td><td class="num"><?= inr($base) ?></td></tr>
        <?php foreach ($lines as [$label, $qtyText, $min, $max, $structPart]): ?>
          <tr><td><?= h($label) ?><?= $structPart ? '<small>includes ' . inr($structPart) . ' for safety drawings</small>' : '' ?></td>
            <td class="muted"><?= $qtyText ?></td>
            <td class="num"><?= $min === $max ? inr($min) : inr($min) . ' – ' . inr($max) ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Saved total</td><td></td><td class="num"><?= inr($o['total_price']) ?><?= $o['needs_manual_review'] ? '<small>estimate</small>' : '' ?></td></tr>
      </table>
      <?php if ($pricesDrifted): ?><p class="muted small-note">Catalog prices have changed since this order. The saved total is what the customer agreed to.</p><?php endif; ?>

      <?php if ($o['needs_manual_review']): ?>
        <form method="post" class="confirm-price" data-saving>
          <?= csrf_field() ?><?= return_field() ?>
          <input type="hidden" name="action" value="order_confirm_price">
          <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
          <label for="finalPrice">Final confirmed price (&#8377;)</label>
          <div class="inline-form">
            <input id="finalPrice" name="price" type="number" min="1" max="<?= 10 * $base - 1 ?>" step="1" required value="<?= (int)$o['total_price'] ?>">
            <button class="btn btn-primary" type="submit">Confirm price</button>
          </div>
          <p class="hint">
            <?php if ($type === 'call'): ?>Agree the changes on the call, then enter the price here.
            <?php elseif ($calcMin !== $calcMax): ?>Estimate from today's catalog: <?= inr($calcMin) ?> – <?= inr($calcMax) ?>.
            <?php else: ?>Estimate from today's catalog: <?= inr($calcMin) ?>.<?php endif; ?>
            Must be more than &#8377;0 and less than <?= inr(10 * $base) ?>.</p>
        </form>
      <?php endif; ?>
    </section>

    <section class="adm-card">
      <h3>Stage and assignment</h3>
      <?= stepper($o['status']) ?>
      <div class="stage-actions">
        <?php if ($stageIdx > 0): ?>
          <form method="post" data-saving><?= csrf_field() ?><?= return_field() ?>
            <input type="hidden" name="action" value="order_stage"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="dir" value="prev">
            <button class="btn" type="submit">&larr; Back to <?= h(STAGE_LABEL[STAGES[$stageIdx - 1]]) ?></button></form>
        <?php endif; ?>
        <?php if ($stageIdx < count(STAGES) - 1): ?>
          <form method="post" data-saving><?= csrf_field() ?><?= return_field() ?>
            <input type="hidden" name="action" value="order_stage"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="dir" value="next">
            <button class="btn btn-primary" type="submit">Move to <?= h(STAGE_LABEL[STAGES[$stageIdx + 1]]) ?> &rarr;</button></form>
        <?php endif; ?>
      </div>
      <form method="post" class="assign-form" data-saving>
        <?= csrf_field() ?><?= return_field() ?>
        <input type="hidden" name="action" value="order_assign"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
        <label for="assignTo">Assigned to</label>
        <div class="inline-form">
          <select id="assignTo" name="staff_id" data-autosubmit>
            <option value="0">Nobody yet</option>
            <?php foreach ($staffList as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)$o['assigned_to'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?><?= $s['role'] === 'admin' ? ' (admin)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn" type="submit">Save</button>
        </div>
      </form>
      <?= render_assignment_note($o) ?>
    </section>

    <section class="adm-card">
      <h3>Timeline</h3>
      <ol class="timeline">
        <li><span><?= fdate($o['created_at'], true) ?></span>Order placed<?= $type === 'call' ? ' (call-back request)' : '' ?></li>
        <?php if ($o['assigned_to']): ?>
          <li><span><?= $o['assigned_at'] ? fdate($o['assigned_at'], true) : 'Date not recorded' ?></span>Assigned to <?= h($o['staff_name']) ?></li>
        <?php endif; ?>
        <li><span>Now</span>At stage: <?= h(STAGE_LABEL[$o['status']] ?? $o['status']) ?></li>
      </ol>
    </section>
  </div>
</div>
<div class="detail-grid">
  <section class="adm-card" id="notes">
    <h3>Internal notes</h3>
    <p class="muted small-note">Never shown to the customer. For call-back orders, write down what was agreed on the call.</p>
    <?= render_order_notes($o, csrf_field() . return_field()) ?>
  </section>
  <section class="adm-card" id="orderFiles">
    <h3>Order files</h3>
    <p class="muted small-note">The modified design files made for this customer.</p>
    <?= render_order_files($o, csrf_field() . return_field(), '../') ?>
  </section>
</div>
<?php
    return;
endif;

// =====================================================================================
// ORDER LIST
// =====================================================================================
$f = [
    'tab' => 'orders',
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => in_array($_GET['status'] ?? '', STAGES, true) ? $_GET['status'] : '',
    'type' => in_array($_GET['type'] ?? '', ['asis', 'modified', 'call'], true) ? $_GET['type'] : '',
    'review' => in_array($_GET['review'] ?? '', ['needs', 'confirmed'], true) ? $_GET['review'] : '',
];
$SORTS = [
    'code' => 'o.order_code', 'customer' => 'o.customer_name', 'phone' => 'o.customer_phone', 'place' => 'o.customer_state',
    'design' => 'd.name', 'type' => 'o.contact_preference', 'status' => "FIELD(o.status, 'new', 'design', 'structural', 'compliance', 'delivered')",
    'assigned' => 's.name', 'price' => 'o.total_price', 'date' => 'o.id',
];
$sort = isset($SORTS[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'date';
$dir = ($_GET['dir'] ?? ($sort === 'date' ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';
$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = '(o.order_code LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($f['status'] !== '') { $where[] = 'o.status = ?'; $params[] = $f['status']; }
$noMods = "(o.modifications IS NULL OR o.modifications = '' OR o.modifications = '[]')";
$isCall = "COALESCE(o.contact_preference, 'self') = 'call'";
if ($f['type'] === 'call') $where[] = $isCall;
if ($f['type'] === 'modified') $where[] = "NOT $isCall AND NOT $noMods";
if ($f['type'] === 'asis') $where[] = "NOT $isCall AND $noMods";
if ($f['review'] === 'needs') $where[] = 'o.needs_manual_review = 1';
if ($f['review'] === 'confirmed') $where[] = 'o.needs_manual_review = 0';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from = "FROM library_orders o JOIN designs d ON d.id = o.design_id LEFT JOIN staff s ON s.id = o.assigned_to $whereSql";

$total = (int)q("SELECT COUNT(*) $from", $params)->fetchColumn();
$pages = max(1, (int)ceil($total / PER_PAGE));
$page = min($page, $pages);
$rows = q("SELECT o.*, d.name AS design_name, s.name AS staff_name $from ORDER BY {$SORTS[$sort]} " . strtoupper($dir) . ", o.id DESC LIMIT ? OFFSET ?",
    array_merge($params, [PER_PAGE, ($page - 1) * PER_PAGE]))->fetchAll();

$keep = array_merge($f, ['sort' => $sort, 'dir' => $dir]);
$pill = function ($key, $value, $label) use ($f, $keep) {
    $on = $f[$key] === $value;
    return '<a class="fpill' . ($on ? ' on' : '') . '" href="' . h(url(array_merge($keep, [$key => $value, 'page' => null]))) . '"' . ($on ? ' aria-current="true"' : '') . '>' . h($label) . '</a>';
};
?>
<form class="search-bar" method="get">
  <input type="hidden" name="tab" value="orders">
  <?php foreach (['status', 'type', 'review'] as $k): if ($f[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= h($f[$k]) ?>"><?php endif; endforeach; ?>
  <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Search by order code, customer name or phone" aria-label="Search orders">
  <button class="btn" type="submit">Search</button>
  <?php if ($f['q'] !== '' || $f['status'] !== '' || $f['type'] !== '' || $f['review'] !== ''): ?><a class="clear" href="<?= h(url(['tab' => 'orders'])) ?>">Clear</a><?php endif; ?>
</form>
<div class="filter-rows">
  <div><span>Status</span><?= $pill('status', '', 'All') ?><?php foreach (STAGE_LABEL as $k => $l) echo $pill('status', $k, $l); ?></div>
  <div><span>Type</span><?= $pill('type', '', 'All') . $pill('type', 'asis', 'As-is') . $pill('type', 'modified', 'Modified') . $pill('type', 'call', 'Call-back') ?></div>
  <div><span>Price</span><?= $pill('review', '', 'All') . $pill('review', 'needs', 'Needs review') . $pill('review', 'confirmed', 'Confirmed') ?></div>
</div>
<p class="result-count"><?= number_format($total) ?> order<?= $total === 1 ? '' : 's' ?></p>

<?php if (!$rows): ?>
  <div class="adm-card"><p class="empty">No orders match.</p></div>
<?php else: ?>
<div class="table-wrap"><table class="adm-table">
  <thead><tr>
    <?= sort_th('Order', 'code', $sort, $dir, $keep) ?><?= sort_th('Customer', 'customer', $sort, $dir, $keep) ?><?= sort_th('Phone', 'phone', $sort, $dir, $keep) ?>
    <?= sort_th('Place', 'place', $sort, $dir, $keep) ?><?= sort_th('Design', 'design', $sort, $dir, $keep) ?><?= sort_th('Type', 'type', $sort, $dir, $keep) ?>
    <?= sort_th('Status', 'status', $sort, $dir, $keep) ?><?= sort_th('Assigned', 'assigned', $sort, $dir, $keep) ?><?= sort_th('Price', 'price', $sort, $dir, $keep) ?>
    <?= sort_th('Date', 'date', $sort, $dir, $keep) ?>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $o): $link = url(['tab' => 'orders', 'id' => $o['id']]); ?>
    <tr class="row-link<?= $o['needs_manual_review'] && $o['status'] !== 'delivered' ? ' flag' : '' ?>" data-href="<?= h($link) ?>">
      <td class="nowrap"><a href="<?= h($link) ?>"><?= h($o['order_code']) ?></a></td>
      <td><?= h($o['customer_name']) ?></td>
      <td class="nowrap"><a href="tel:+91<?= h($o['customer_phone']) ?>"><?= h($o['customer_phone']) ?></a></td>
      <td><?= h(implode(', ', array_filter([$o['customer_city'] !== $o['customer_district'] ? $o['customer_city'] : null, $o['customer_district'], $o['customer_state']]))) ?: '—' ?></td>
      <td><?= h($o['design_name']) ?></td>
      <td><?= type_badge($o) ?></td>
      <td><?= stage_badge($o['status']) ?><?= $o['needs_manual_review'] ? ' <span class="badge b-rust" title="Needs a final price">Price</span>' : '' ?></td>
      <td><?= $o['staff_name'] ? h($o['staff_name']) : '<span class="muted">—</span>' ?></td>
      <td class="num nowrap"><?= inr($o['total_price']) ?></td>
      <td class="nowrap"><?= fdate($o['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= pager($total, $page, $keep) ?>
<?php endif; ?>

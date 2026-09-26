<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$one = function ($sql, $params = []) { return q($sql, $params)->fetchColumn(); };
$stats = [
    ['Total orders', (int)$one("SELECT COUNT(*) FROM library_orders"), 'all time', 'accent', url(['tab' => 'orders'])],
    ['Active orders', (int)$one("SELECT COUNT(*) FROM library_orders WHERE status <> 'delivered'"), 'not delivered yet', 'accent', url(['tab' => 'orders'])],
    ['Need a price', (int)$one("SELECT COUNT(*) FROM library_orders WHERE needs_manual_review = 1 AND status <> 'delivered'"), 'manual review', 'amber', url(['tab' => 'orders', 'review' => 'needs'])],
    ['Call-backs waiting', (int)$one("SELECT COUNT(*) FROM library_orders WHERE contact_preference = 'call' AND status = 'new'" . (phase10_ready() ? ' AND quotation_id IS NULL' : '')), 'customers to call', 'rust', url(['tab' => 'orders', 'type' => 'call', 'status' => 'new'])],
    ['Revenue', inr((int)$one("SELECT COALESCE(SUM(total_price), 0) FROM library_orders WHERE status = 'delivered'")), 'from delivered orders', 'green', url(['tab' => 'orders', 'status' => 'delivered'])],
    ['Published designs', (int)$one("SELECT COUNT(*) FROM designs WHERE is_active = 1"), 'visible to customers', 'green', url(['tab' => 'designs'])],
    ['Open briefs', (int)$one("SELECT COUNT(*) FROM briefs WHERE status = 'open'"), 'waiting for a Design Creator', 'accent', url(['tab' => 'briefs', 'status' => 'open'])],
    ['Active Design Creators', (int)$one("SELECT COUNT(*) FROM (
            SELECT claimed_by AS fid FROM briefs WHERE claimed_by IS NOT NULL AND status IN ('claimed', 'in_review', 'needs_revision')
            UNION SELECT freelancer_id FROM submissions WHERE submitted_at >= NOW() - INTERVAL 90 DAY
        ) x"), 'working now or submitted in 90 days', 'accent', url(['tab' => 'freelancers'])],
];

$recent = q("SELECT o.id, o.order_code, o.customer_name, o.customer_phone, o.total_price, o.status, o.created_at, o.needs_manual_review,
                    o.contact_preference, o.modifications, d.name AS design_name
             FROM library_orders o JOIN designs d ON d.id = o.design_id ORDER BY o.id DESC LIMIT 10")->fetchAll();

$callbacks = q("SELECT o.id, o.order_code, o.customer_name, o.customer_phone, o.customer_district, o.customer_state, o.created_at, d.name AS design_name
                FROM library_orders o JOIN designs d ON d.id = o.design_id
                WHERE o.contact_preference = 'call' AND o.status = 'new'" . (phase10_ready() ? ' AND o.quotation_id IS NULL' : '') . " ORDER BY o.id LIMIT 10")->fetchAll();
$paymentsPending = phase10_ready() ? q("SELECT o.id, o.order_code, o.customer_name, q.confirmed_at, q.total_price FROM library_orders o
                  JOIN quotations q ON q.id = o.quotation_id WHERE o.payment_status <> 'received' ORDER BY q.confirmed_at LIMIT 20")->fetchAll() : [];
$overloaded = q("SELECT o.id, o.order_code, o.customer_name, o.assignment_reason, s.name AS staff_name FROM library_orders o
                  LEFT JOIN staff s ON s.id = o.assigned_to WHERE o.overload_warning = 1 AND o.status <> 'delivered' ORDER BY o.id DESC LIMIT 10")->fetchAll();
$reviews = q("SELECT o.id, o.order_code, o.customer_name, o.total_price, d.name AS design_name
              FROM library_orders o JOIN designs d ON d.id = o.design_id
              WHERE o.needs_manual_review = 1 AND o.status <> 'delivered' AND COALESCE(o.contact_preference, 'self') <> 'call' ORDER BY o.id LIMIT 10")->fetchAll();
$pendingSubs = q("SELECT s.id, s.submitted_at, b.title, f.name AS freelancer_name FROM submissions s
                  JOIN briefs b ON b.id = s.brief_id JOIN freelancers f ON f.id = s.freelancer_id
                  WHERE s.review_status = 'pending' ORDER BY s.id LIMIT 10")->fetchAll();
$overdue = q("SELECT b.id, b.title, b.deadline, b.status, f.name AS freelancer_name FROM briefs b
              LEFT JOIN freelancers f ON f.id = b.claimed_by
              WHERE b.deadline < CURDATE() AND b.status IN ('open', 'claimed')
                AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.brief_id = b.id)
              ORDER BY b.deadline LIMIT 10")->fetchAll();
$nothingPending = !$callbacks && !$reviews && !$pendingSubs && !$overdue && !$overloaded && !$paymentsPending;
?>
<div class="stat-grid">
  <?php foreach ($stats as [$label, $value, $hint, $tone, $link]): ?>
    <a class="stat tone-<?= $tone ?>" href="<?= h($link) ?>">
      <span class="stat-value"><?= is_int($value) ? number_format($value) : $value ?></span>
      <span class="stat-label"><?= h($label) ?></span>
      <span class="stat-hint"><?= h($hint) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="dash-cols">
  <section class="adm-card">
    <div class="card-head"><h2>Recent orders</h2><a href="<?= h(url(['tab' => 'orders'])) ?>">All orders &rarr;</a></div>
    <?php if (!$recent): ?>
      <p class="empty">No orders yet.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="adm-table compact">
      <thead><tr><th>Order</th><th>Customer</th><th>Phone</th><th>Design</th><th class="num">Price</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $o): $link = url(['tab' => 'orders', 'id' => $o['id']]); ?>
        <tr class="row-link" data-href="<?= h($link) ?>">
          <td class="nowrap"><a href="<?= h($link) ?>"><?= h($o['order_code']) ?></a></td>
          <td><?= h($o['customer_name']) ?></td>
          <td class="nowrap"><?= h($o['customer_phone']) ?></td>
          <td><?= h($o['design_name']) ?></td>
          <td class="num"><?= inr($o['total_price']) ?><?= $o['needs_manual_review'] ? '<small class="muted"> est.</small>' : '' ?></td>
          <td><?= stage_badge($o['status']) ?></td>
          <td class="nowrap"><?= fdate($o['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="adm-card">
    <div class="card-head"><h2>Needs your attention</h2></div>
    <?php if ($nothingPending): ?>
      <p class="empty">All clear &#8212; nothing is waiting for you.</p>
    <?php endif; ?>

    <?php if ($callbacks): ?>
      <h3 class="pend-head">Customers waiting for a call</h3>
      <ul class="pend-list">
      <?php foreach ($callbacks as $c): ?>
        <li class="pend call">
          <a class="pend-phone" href="tel:+91<?= h($c['customer_phone']) ?>"><?= svg('phone') ?><?= h(substr($c['customer_phone'], 0, 5) . ' ' . substr($c['customer_phone'], 5)) ?></a>
          <a class="pend-main" href="<?= h(url(['tab' => 'orders', 'id' => $c['id']])) ?>">
            <strong><?= h($c['customer_name']) ?></strong>
            <span><?= h(trim($c['customer_district'] . ', ' . $c['customer_state'], ', ')) ?> &#183; <?= h($c['design_name']) ?> &#183; <?= fdate($c['created_at'], true) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($paymentsPending): ?>
      <h3 class="pend-head">Payment pending</h3>
      <ul class="pend-list">
      <?php foreach ($paymentsPending as $pp): ?>
        <li class="pend"><a class="pend-main" href="<?= h(url(['tab' => 'orders', 'id' => $pp['id']])) ?>#quote">
          <strong>Payment pending &#8212; <?= h($pp['customer_name']) ?> confirmed their quotation on <?= fdate($pp['confirmed_at']) ?></strong>
          <span><?= h($pp['order_code']) ?> &#183; <?= inr($pp['total_price']) ?> &#183; open it to confirm the payment</span></a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($overloaded): ?>
      <h3 class="pend-head">Overloaded assignments</h3>
      <p class="overload-warn">All team members have a lot of active orders right now. You might want to redistribute some work.</p>
      <ul class="pend-list">
      <?php foreach ($overloaded as $ov): ?>
        <li class="pend late"><a class="pend-main" href="<?= h(url(['tab' => 'orders', 'id' => $ov['id']])) ?>">
          <strong><?= h($ov['order_code']) ?> &#183; <?= h($ov['customer_name']) ?></strong>
          <span>With <?= h($ov['staff_name'] ?? 'nobody') ?> &#183; open it to reassign</span></a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($reviews): ?>
      <h3 class="pend-head">Orders waiting for a final price</h3>
      <ul class="pend-list">
      <?php foreach ($reviews as $r): ?>
        <li class="pend"><a class="pend-main" href="<?= h(url(['tab' => 'orders', 'id' => $r['id']])) ?>">
          <strong><?= h($r['order_code']) ?> &#183; <?= h($r['customer_name']) ?></strong>
          <span><?= h($r['design_name']) ?> &#183; estimate from <?= inr($r['total_price']) ?></span>
        </a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($pendingSubs): ?>
      <h3 class="pend-head">Design Creator work to review</h3>
      <ul class="pend-list">
      <?php foreach ($pendingSubs as $s): ?>
        <li class="pend"><a class="pend-main" href="<?= h(url(['tab' => 'submissions', 'id' => $s['id']])) ?>">
          <strong><?= h($s['title']) ?></strong>
          <span>by <?= h($s['freelancer_name']) ?> &#183; <?= fdate($s['submitted_at']) ?></span>
        </a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($overdue): ?>
      <h3 class="pend-head">Briefs past their deadline</h3>
      <ul class="pend-list">
      <?php foreach ($overdue as $b): ?>
        <li class="pend late"><a class="pend-main" href="<?= h(url(['tab' => 'briefs', 'id' => $b['id']])) ?>">
          <strong><?= h($b['title']) ?></strong>
          <span>Due <?= fdate($b['deadline']) ?> &#183; <?= $b['freelancer_name'] ? 'claimed by ' . h($b['freelancer_name']) : 'nobody has claimed it' ?></span>
        </a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

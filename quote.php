<?php
// The customer's quotation (public, no login): quote.php?token=<lookup>.<secret>
// Staff can look at the same page without the confirm button: quote.php?preview=<quotation id>.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // session (for the confirm form token) + security headers
require_once __DIR__ . '/includes/quotes.php';

function qh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
header('Cache-Control: no-store');

$ready = phase10_ready();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$preview = !empty($_GET['preview']) && !empty($_SESSION['staff_id']);
$q = null;
if ($ready && $preview) {
    $q = qt_q("SELECT * FROM quotations WHERE id = ?", [(int)$_GET['preview']])->fetch() ?: null;
    if ($q) $q = quote_refresh($q);
} elseif ($ready && $token !== '') {
    $q = quote_find_by_token($token);
}
$error = '';

// ---- Confirm ----
if ($q && !$preview && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sec_form_ok('quote')) {
        $error = 'This page was open for a long time. Please press the button again.';
    } else {
        [$ok, $why] = quote_confirm($q['id']);
        if ($ok) $_SESSION['quote_done'][(int)$q['id']] = true;
        header('Location: quote.php?token=' . rawurlencode($token)); // a refresh cannot confirm twice
        exit;
    }
}

// First time the customer opens it: let the team know.
if ($q && !$preview && $q['status'] === 'sent') {
    qt_q("UPDATE quotations SET status = 'viewed', viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ? AND status = 'sent'", [(int)$q['id']]);
    $q['status'] = 'viewed';
}

$order = $q ? qt_q("SELECT * FROM library_orders WHERE id = ?", [(int)$q['order_id']])->fetch() : null;
$design = $q ? qt_q("SELECT * FROM designs WHERE id = ?", [(int)$q['design_id']])->fetch() : null;
$justConfirmed = $q && !$preview && $q['status'] === 'confirmed' && !empty($_SESSION['quote_done'][(int)$q['id']]);
$lines = $q ? (json_decode((string)$q['line_items'], true) ?: []) : [];
$isRange = $q && (int)$q['total_price_max'] > (int)$q['total_price'];
$floorsText = ['G' => 'Ground floor only', 'G+1' => 'Ground + 1 floor', 'G+2' => 'Ground + 2 floors', 'G+3' => 'Ground + 3 floors'];
$date = function ($ts) { return $ts ? date('j F Y', strtotime($ts)) : ''; };
$tel = 'tel:+918920218394';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $design ? 'Quotation — ' . qh($design['name']) : 'Your design quotation' ?> &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260927a">
<link rel="stylesheet" href="assets/quote.css?v=1">
</head>
<body class="site quote-body">
<main class="quote-wrap page-enter">
  <header class="quote-brand"><a class="brand" href="index.php">planzaa<span>.</span></a><span class="quote-kicker">Your design quotation</span></header>

<?php if ($preview && $q): ?>
  <div class="quote-preview-bar">Team preview &#8212; this is what the customer sees<?= $q['status'] !== 'viewed' && $q['status'] !== 'sent' ? ' (status: ' . qh(QUOTE_STATUS_LABEL[$q['status']] ?? $q['status']) . ')' : '' ?>. The confirm button is hidden here.</div>
<?php endif; ?>

<?php if (!$q): ?>
  <section class="quote-card quote-msg">
    <h1>We could not find this quotation</h1>
    <p>Please check that you opened the full link from your email or message. If it still does not work, call us at <a href="<?= $tel ?>"><?= PLANZAA_PHONE ?></a>.</p>
  </section>

<?php elseif ($justConfirmed): ?>
  <section class="quote-card success quote-done" role="status">
    <div class="confetti" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
    <svg class="check-anim" viewBox="0 0 80 80" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="40" cy="40" r="36" transform="rotate(-90 40 40)"/><path d="M25 41l10 10 20-22"/></svg>
    <h1>Your order is confirmed!</h1>
    <p class="lead">Order code: <strong class="code"><?= qh($order['order_code']) ?></strong></p>
    <p class="lead">Total: <strong><?= qh(quote_money($q['total_price'])) ?><?= $isRange ? ' – ' . qh(quote_money($q['total_price_max'])) : '' ?></strong></p>
    <p>Our team will contact you within 24 hours to arrange payment and start working on your design.</p>
    <div class="cta-row"><a class="btn btn-primary" href="track.php?code=<?= rawurlencode($order['order_code']) ?>">Track your order</a></div>
  </section>

<?php elseif (!$preview && $q['status'] === 'confirmed'): ?>
  <section class="quote-card quote-msg">
    <h1>This quotation has already been confirmed</h1>
    <p>Your order code is <strong><?= qh($order['order_code']) ?></strong>. <a href="track.php?code=<?= rawurlencode($order['order_code']) ?>">Track your order here</a>.</p>
  </section>

<?php elseif (!$preview && $q['status'] === 'expired'): ?>
  <section class="quote-card quote-msg">
    <h1>This quotation has expired</h1>
    <p>Please contact us at <a href="<?= $tel ?>"><?= PLANZAA_PHONE ?></a> for an updated quote.</p>
  </section>

<?php elseif (!$preview && !in_array($q['status'], ['sent', 'viewed'], true)): ?>
  <section class="quote-card quote-msg">
    <h1>This quotation is no longer available</h1>
    <p>Please contact us at <a href="<?= $tel ?>"><?= PLANZAA_PHONE ?></a> for a new quote.</p>
  </section>

<?php else: $previews = function_exists('preview_urls') ? preview_urls($design['id']) : []; ?>
  <article class="quote-card quote-doc">
    <div class="quote-top">
      <div>
        <h1>Your design quotation</h1>
        <p class="quote-for">Prepared for <strong><?= qh($order['customer_name']) ?></strong></p>
      </div>
      <dl class="quote-meta">
        <div><dt>Quotation</dt><dd><?= qh($order['order_code']) ?>-Q<?= (int)$q['id'] ?></dd></div>
        <div><dt>Date</dt><dd><?= qh($date($q['sent_at'])) ?></dd></div>
        <div><dt>Valid until</dt><dd><?= qh($date($q['expires_at'])) ?></dd></div>
      </dl>
    </div>

    <section class="quote-sec">
      <h2>Your design</h2>
      <div class="quote-design">
        <div class="quote-art">
          <?php if (!empty($previews['preview_elevation']) || !empty($previews['preview_plan'])): ?>
            <?php if (!empty($previews['preview_elevation'])): ?><img src="<?= qh($previews['preview_elevation']) ?>" alt="Front view of <?= qh($design['name']) ?>"><?php endif; ?>
            <?php if (!empty($previews['preview_plan'])): ?><img src="<?= qh($previews['preview_plan']) ?>" alt="Floor plan of <?= qh($design['name']) ?>"><?php endif; ?>
          <?php else: ?>
            <div class="art-sketch"><?= elevationArt() ?></div><div class="art-sketch"><?= floorPlanArt((int)$design['variant']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <h3><?= qh($design['name']) ?></h3>
          <?php if (!empty($design['design_code'])): ?><p class="quote-code">Design code <?= qh($design['design_code']) ?></p><?php endif; ?>
          <dl class="quote-facts">
            <div><dt>Plot size</dt><dd><?= (int)$design['plot_width'] ?> × <?= (int)$design['plot_length'] ?> ft</dd></div>
            <div><dt>Bedrooms</dt><dd><?= (int)$design['bhk'] ?> BHK</dd></div>
            <div><dt>Floors</dt><dd><?= qh($floorsText[$design['floors']] ?? $design['floors']) ?></dd></div>
            <div><dt>Facing</dt><dd><?= qh($design['facing']) ?></dd></div>
          </dl>
        </div>
      </div>
    </section>

    <section class="quote-sec">
      <h2>What's included</h2>
      <ul class="quote-list">
        <li class="yes">Front and side views of the house</li>
        <li class="yes">Floor plan with the size of every room</li>
        <li class="yes">Marking plan (helps workers mark the house on your plot)</li>
        <li class="yes">2–3 pictures of how the inside will look</li>
        <?php if ($q['structural_included']): ?>
          <li class="yes">Building safety drawings (column, beam &amp; foundation design) &#8212; <strong>Included</strong></li>
        <?php else: ?>
          <li class="no">Building safety drawings &#8212; <strong>Not included</strong> (you will need your own engineer to check safety)</li>
        <?php endif; ?>
      </ul>
    </section>

    <section class="quote-sec">
      <h2>Changes we'll make</h2>
      <?php if (!$lines): ?>
        <p class="muted">No changes &#8212; you get the design as it is.</p>
      <?php else: ?>
        <ul class="quote-changes">
          <?php foreach ($lines as $l): ?>
            <li><strong><?= qh($l['label']) ?></strong>
              <?php if (!empty($l['details'])): ?><ul><?php foreach ($l['details'] as $d): ?><li><?= qh($d === 'Our architect will choose the best option for this' ? 'Our architect will choose the best option for ' . lcfirst($l['label']) : $d) ?></li><?php endforeach; ?></ul><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if (!empty($q['notes_for_customer'])): ?>
    <section class="quote-sec">
      <h2>Notes from our team</h2>
      <blockquote class="quote-notes"><?= nl2br(qh($q['notes_for_customer'])) ?></blockquote>
    </section>
    <?php endif; ?>

    <section class="quote-sec">
      <h2>Price</h2>
      <table class="quote-prices">
        <tbody>
          <tr><td>Design price &#8212; <?= qh($design['name']) ?></td><td class="amt"><?= qh(quote_money($q['base_price'])) ?></td></tr>
          <?php foreach ($lines as $l): ?>
            <tr><td><?= qh($l['label']) ?><?= (int)$l['qty'] > 1 ? ' <span class="muted">(' . (int)$l['qty'] . ' rooms)</span>' : '' ?></td>
              <td class="amt"><?= !empty($l['price_later']) ? 'Included in estimate' : qh(quote_money($l['amount'])) . ($l['amount_max'] !== null && (int)$l['amount_max'] > (int)$l['amount'] ? ' – ' . qh(quote_money($l['amount_max'])) : '') ?></td></tr>
          <?php endforeach; ?>
          <?php if ((int)$q['structural_price'] > 0): ?>
            <tr><td>Building safety drawings (structural package)</td><td class="amt"><?= qh(quote_money($q['structural_price'])) ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot><tr><th>Total</th><td class="amt total"><?= qh(quote_money($q['total_price'])) ?><?= $isRange ? ' – ' . qh(quote_money($q['total_price_max'])) : '' ?></td></tr></tfoot>
      </table>
      <?php if ($q['needs_manual_pricing'] || $isRange): ?>
        <p class="quote-estimate">This is an estimated price. We may adjust it slightly based on the final design &#8212; we'll call you before making any changes.</p>
      <?php endif; ?>
      <p class="quote-delivery">Your design will be ready in approximately <strong><?= (int)$q['estimated_delivery_days'] ?> days</strong> after confirmation.</p>
    </section>

    <?php if (!$preview): ?>
    <section class="quote-action">
      <?php if ($error): ?><p class="form-error" role="alert"><?= qh($error) ?></p><?php endif; ?>
      <form method="post" action="quote.php" id="confirmForm">
        <input type="hidden" name="csrf" value="<?= qh(sec_form_token('quote')) ?>">
        <input type="hidden" name="token" value="<?= qh($token) ?>">
        <button class="btn-confirm" type="submit" id="confirmBtn">Confirm this quotation</button>
      </form>
      <p class="quote-fine">By confirming, you agree to proceed with this design and these changes at the quoted price.</p>
      <p class="quote-fine">Questions? Call us at <a href="<?= $tel ?>"><?= PLANZAA_PHONE ?></a></p>
    </section>
    <?php endif; ?>
    <footer class="quote-foot">Planzaa &#8212; Survey Design And Engineering Solutions &#183; <?= PLANZAA_PHONE ?> &#183; info@planzaa.in</footer>
  </article>
  <p class="quote-print"><button type="button" class="btn" onclick="window.print()">Print or save as PDF</button></p>
<?php endif; ?>
</main>
<script>
// One press only: stops a double tap from sending the form twice.
(function () {
  var f = document.getElementById('confirmForm');
  if (f) f.addEventListener('submit', function () { var b = document.getElementById('confirmBtn'); b.disabled = true; b.textContent = 'Confirming…'; });
})();
</script>
</body>
</html>

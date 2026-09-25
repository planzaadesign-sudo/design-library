<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Track Your Order &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925b">
</head>
<body>
<div class="topbar"><div class="topbar-inner topnav">
  <div class="wordmark"><a href="index.php">planzaa<span>.</span> design library</a></div>
  <nav class="navlinks"><a href="index.php">Designs</a><a href="track.php" class="active">Track order</a></nav>
</div></div>
<div class="wrap">
  <h1>Track your order</h1>
  <p class="section-gap">Enter the order code from your confirmation and the phone number you ordered with.</p>
  <form class="form-section track-form" id="trackForm" novalidate>
    <div class="form-row">
      <div class="field"><label for="code">Order code</label><input id="code" placeholder="PZL-XXXXXX" maxlength="10" autocomplete="off" style="text-transform:uppercase"></div>
      <div class="field"><label for="phone">Phone number</label><input id="phone" type="tel" inputmode="numeric" maxlength="10" autocomplete="tel-national"></div>
    </div>
    <button class="btn btn-primary" type="submit" id="trackBtn">Find my order</button>
    <div class="error-note" id="trackError" role="alert"></div>
  </form>
  <div id="result" class="track-result" aria-live="polite"></div>
</div>

<script src="assets/app.js?v=20260925b"></script>
<script>
const STAGES = [['new','New'], ['design','Design'], ['structural','Structural'], ['compliance','Compliance'], ['delivered','Delivered']];
const $ = id => document.getElementById(id);
$('code').value = new URLSearchParams(window.location.search).get('code') || '';
if($('code').value) $('phone').focus();
$('phone').addEventListener('input', () => { $('phone').value = $('phone').value.replace(/\D/g, '').slice(0, 10); });

$('trackForm').addEventListener('submit', async e => {
  e.preventDefault();
  $('trackError').textContent = '';
  $('result').innerHTML = '';
  const code = $('code').value.trim().toUpperCase();
  const phone = $('phone').value.trim();
  if(!/^PZL-[0-9A-F]{6}$/.test(code)){ $('trackError').textContent = 'Order codes look like PZL-1A2B3C.'; return; }
  if(!/^[0-9]{10}$/.test(phone)){ $('trackError').textContent = 'Enter the 10-digit phone number you ordered with.'; return; }

  $('trackBtn').disabled = true;
  try {
    const res = await fetch('api/track.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({order_code:code, phone})});
    const data = await res.json();
    if(!res.ok){ $('trackError').textContent = data.error || 'No order found with this code and phone number.'; return; }
    renderOrder(data);
  } catch(err) {
    $('trackError').textContent = 'We couldn’t reach the server. Please try again.';
  } finally {
    $('trackBtn').disabled = false;
  }
});

function renderOrder(o){
  const idx = STAGES.findIndex(s => s[0] === o.status);
  const date = new Date(String(o.created_at).replace(' ', 'T'));
  const dateLabel = isNaN(date) ? esc(o.created_at) : date.toLocaleDateString('en-IN', {day:'numeric', month:'long', year:'numeric'});
  const mods = o.modifications.length
    ? '<ul class="check-list">' + o.modifications.map(l => '<li>' + esc(l) + '</li>').join('') + '</ul>'
    : '<p class="muted" style="margin:0; font-size:14px">None — design purchased as-is.</p>';
  $('result').innerHTML =
    '<div class="form-section">'
    + '<div class="item-top"><div><div class="eyebrow">' + esc(o.order_code) + '</div><h2>' + esc(o.design_name) + '</h2></div>'
    +   '<span class="badge ' + (o.status === 'delivered' ? 'badge-match' : 'b-blue') + '">' + esc(STAGES[idx] ? STAGES[idx][1] : o.status) + '</span></div>'
    + '<ol class="stepper">' + STAGES.map((s, i) => '<li class="' + (i < idx || o.status === 'delivered' ? 'done' : i === idx ? 'current' : '') + '">' + s[1] + '</li>').join('') + '</ol>'
    + '<dl class="spec-list spec-3" style="margin-top:18px">'
    +   '<div><dt>Ordered on</dt><dd>' + dateLabel + '</dd></div>'
    +   '<div><dt>' + (o.needs_manual_review ? 'Estimate from' : 'Total') + '</dt><dd>' + fmt(o.total_price) + '</dd></div>'
    +   '<div><dt>Structural design</dt><dd>' + (o.structural ? 'Included' : 'Not included') + '</dd></div>'
    +   (o.estimated_delivery_days ? '<div><dt>Estimated delivery</dt><dd>' + o.estimated_delivery_days + ' days</dd></div>' : '')
    + '</dl>'
    + (o.needs_manual_review ? '<div class="review-note" style="margin-top:0">This order is in manual review — our team will confirm the final price with you.</div>' : '')
    + '<div class="section-title" style="margin-top:16px">Modifications</div>' + mods
    + '</div>';
}
</script>
</body>
</html>

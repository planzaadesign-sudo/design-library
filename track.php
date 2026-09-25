<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Track Your Order &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925c">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter">
  <form class="track-card" id="trackForm" novalidate>
    <h1>Track your order</h1>
    <p class="lead">Enter the order code and phone number you used when placing the order.</p>
    <div class="ff big" id="f_code"><input id="code" placeholder=" " maxlength="10" autocomplete="off" spellcheck="false"><label for="code">Order code (PZL-XXXXXX)</label><div class="ff-error" id="e_code"></div></div>
    <div class="ff" id="f_phone"><input id="phone" placeholder=" " type="tel" inputmode="numeric" maxlength="11" autocomplete="tel-national"><label for="phone">Phone number</label><div class="ff-error" id="e_phone"></div></div>
    <p class="form-error" id="trackError" role="alert"></p>
    <button class="btn btn-primary btn-submit" type="submit" id="trackBtn"><span class="btn-label">Track Order</span></button>
  </form>
  <div id="result" class="track-results" aria-live="polite"></div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
const STAGES = [['new','New'], ['design','Design'], ['structural','Structural'], ['compliance','Compliance'], ['delivered','Delivered']];
const $ = id => document.getElementById(id);
$('code').value = new URLSearchParams(window.location.search).get('code') || '';
if($('code').value) $('phone').focus();

$('phone').addEventListener('input', () => {
  const digits = $('phone').value.replace(/\D/g, '').slice(0, 10);
  $('phone').value = digits.length > 5 ? digits.slice(0, 5) + ' ' + digits.slice(5) : digits;
  setErr('phone', '');
});
$('code').addEventListener('input', () => setErr('code', ''));

function setErr(id, msg){
  $('f_' + id).classList.toggle('invalid', !!msg);
  $('e_' + id).textContent = msg;
}

function setLoading(on){
  $('trackBtn').disabled = on;
  const spinner = $('trackBtn').querySelector('.spinner');
  if(on && !spinner) $('trackBtn').insertAdjacentHTML('afterbegin', '<span class="spinner" aria-hidden="true"></span>');
  if(!on && spinner) spinner.remove();
  $('trackBtn').querySelector('.btn-label').textContent = on ? 'Looking up…' : 'Track Order';
}

$('trackForm').addEventListener('submit', async e => {
  e.preventDefault();
  $('trackError').textContent = '';
  const code = $('code').value.trim().toUpperCase();
  const phone = $('phone').value.replace(/\D/g, '');
  let ok = true;
  if(!/^PZL-[0-9A-F]{6}$/.test(code)){ setErr('code', 'Order codes look like PZL-1A2B3C.'); ok = false; }
  if(!/^[0-9]{10}$/.test(phone)){ setErr('phone', 'Enter the 10-digit phone number you ordered with.'); ok = false; }
  if(!ok) return;

  setLoading(true);
  try {
    const res = await fetch('api/track.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({order_code:code, phone})});
    const data = await res.json();
    if(!res.ok){ $('result').innerHTML = ''; $('trackError').textContent = data.error || 'No order found with this code and phone number.'; return; }
    renderOrder(data);
  } catch(err) {
    $('trackError').textContent = 'We couldn’t reach the server. Please try again.';
  } finally {
    setLoading(false);
  }
});

function renderOrder(o){
  const idx = STAGES.findIndex(s => s[0] === o.status);
  const delivered = o.status === 'delivered';
  const date = new Date(String(o.created_at).replace(' ', 'T'));
  const dateLabel = isNaN(date) ? esc(o.created_at) : date.toLocaleDateString('en-IN', {day:'numeric', month:'long', year:'numeric'});
  const steps = STAGES.map((s, i) => {
    const state = (i < idx || delivered) ? 'done' : i === idx ? 'current' : '';
    return '<li class="tstep ' + state + '"' + (state === 'current' ? ' aria-current="step"' : '') + '>'
      + '<span class="node">' + (state === 'done' ? icon('check') : '') + '</span><span>' + s[1] + '</span></li>';
  }).join('');
  const mods = o.modifications.length
    ? '<ul>' + o.modifications.map(l => '<li>' + esc(l) + '</li>').join('') + '</ul>'
    : 'None — purchased as-is';

  $('result').innerHTML =
    '<div class="status-card">'
    + '<div class="status-head"><div><div class="eyebrow">' + esc(o.order_code) + '</div><h2>' + esc(o.design_name) + '</h2></div>'
    +   '<span class="badge ' + (delivered ? 'badge-match' : 'b-blue') + '">' + esc(STAGES[idx] ? STAGES[idx][1] : o.status) + '</span></div>'
    + '<ol class="tstepper" aria-label="Order progress">' + steps + '</ol>'
    + (o.needs_manual_review ? '<div class="note note-danger">' + icon('info') + '<span>This order is in manual review — our team will confirm the final price with you.</span></div>' : '')
    + '<dl class="details">'
    +   '<div class="drow"><dt>Design</dt><dd>' + esc(o.design_name) + '</dd></div>'
    +   '<div class="drow"><dt>Ordered on</dt><dd>' + dateLabel + '</dd></div>'
    +   '<div class="drow"><dt>' + (o.needs_manual_review ? 'Estimate from' : 'Total price') + '</dt><dd>' + fmt(o.total_price) + '</dd></div>'
    +   '<div class="drow"><dt>Structural design</dt><dd>' + (o.structural ? 'Included' : 'Not included') + '</dd></div>'
    +   '<div class="drow"><dt>Modifications</dt><dd>' + mods + '</dd></div>'
    +   (o.estimated_delivery_days ? '<div class="drow"><dt>Estimated delivery</dt><dd>' + o.estimated_delivery_days + ' days</dd></div>' : '')
    + '</dl>'
    + '<div class="help-banner"><span class="ic-wrap">' + icon('phone', 'ic-lg') + '</span>'
    +   '<span><strong>Need help?</strong>Call <a href="' + CONTACT.tel + '">' + CONTACT.phone + '</a> or <a href="' + CONTACT.whatsapp + '" target="_blank" rel="noopener">WhatsApp us</a> with your order code.</span></div>'
    + '</div>';
  $('result').scrollIntoView({behavior:'smooth', block:'start'});
}
</script>
</body>
</html>

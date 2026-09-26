<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Track My Order &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260927a">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter">
  <form class="track-card" id="trackForm" novalidate>
    <h1>Track my order</h1>
    <p class="lead">Type the order number you got when you ordered, and your mobile number.</p>
    <div class="ff big" id="f_code"><input id="code" placeholder=" " maxlength="10" autocomplete="off" spellcheck="false"><label for="code">Order number (like PZL-1A2B3C)</label><div class="ff-error" id="e_code"></div></div>
    <div class="ff" id="f_phone"><input id="phone" placeholder=" " type="tel" inputmode="numeric" maxlength="11" autocomplete="tel-national"><label for="phone">Mobile number</label><div class="ff-error" id="e_phone"></div></div>
    <p class="form-error" id="trackError" role="alert"></p>
    <button class="btn btn-primary btn-submit" type="submit" id="trackBtn"><span class="btn-label">Track my order</span></button>
  </form>
  <div id="result" class="track-results" aria-live="polite"></div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
const STAGES = [['new','Order received'], ['design','Design work'], ['structural','Safety drawings'], ['compliance','Final checks'], ['delivered','Ready']];
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
  $('trackBtn').querySelector('.btn-label').textContent = on ? 'Looking for your order…' : 'Track my order';
}

$('trackForm').addEventListener('submit', async e => {
  e.preventDefault();
  $('trackError').textContent = '';
  const code = $('code').value.trim().toUpperCase();
  const phone = $('phone').value.replace(/\D/g, '');
  let ok = true;
  if(!/^PZL-[0-9A-F]{6}$/.test(code)){ setErr('code', 'Your order number looks like PZL-1A2B3C. Please check it.'); ok = false; }
  if(!/^[0-9]{10}$/.test(phone)){ setErr('phone', 'Please type the 10-digit mobile number you used to order.'); ok = false; }
  if(!ok) return;

  setLoading(true);
  try {
    const res = await fetch('api/track.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({order_code:code, phone})});
    const data = await res.json();
    if(!res.ok){ $('result').innerHTML = ''; $('trackError').textContent = data.error || 'We could not find an order with this order number and mobile number. Please check both and try again.'; return; }
    renderOrder(data);
  } catch(err) {
    $('trackError').textContent = 'We could not connect. Please check your internet and try again.';
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
  const mods = o.callback ? 'We will talk about them on the call'
    : o.modifications.length ? '<ul>' + o.modifications.map(l => '<li>' + esc(l) + '</li>').join('') + '</ul>'
    : 'No changes — bought as it is';
  const reviewNote = o.callback
    ? 'Our design expert will call you to understand your changes. After the call, we will tell you the price.'
    : 'Your changes are big, so our team is checking them. We will call you with the exact price.';

  // Call-back orders: until the payment arrives, say where the quotation is instead of showing the stages.
  const QUOTE = {
    preparing: ['Preparing your quotation', 'We\u2019re preparing your quotation. You\u2019ll receive it by email/SMS shortly.'],
    sent: ['Quotation sent', 'Your quotation has been sent! Check your email for the link to view and confirm it.'],
    confirmed: ['Order confirmed', 'Your order is confirmed! Our team will contact you to arrange payment.'],
    call_pending: ['Waiting for our call', 'Our team will call you soon at ' + esc(String(o.phone || '').replace(/^(\d{5})(\d{5})$/, '$1 $2')) + '. You can expect a call within a few hours.'],
    call_again: ['We will call again', 'Our team has spoken with you and will call again shortly.'],
    call_done: ['Preparing your quotation', 'We\u2019re preparing your quotation based on our call. You\u2019ll receive it by email soon.'],
    closed: ['Request closed', 'This request is closed. If you change your mind, call us on ' + CONTACT.phone + '.'],
  }[o.quote_state];

  $('result').innerHTML =
    '<div class="status-card">'
    + '<div class="status-head"><div><div class="eyebrow">' + esc(o.order_code) + '</div><h2>' + esc(o.design_name) + '</h2></div>'
    +   '<span class="badge ' + (delivered ? 'badge-match' : QUOTE ? 'badge-amber' : 'b-blue') + '">' + esc(QUOTE ? QUOTE[0] : STAGES[idx] ? STAGES[idx][1] : o.status) + '</span></div>'
    + (QUOTE ? '<div class="note note-warn">' + icon(o.quote_state === 'confirmed' ? 'check' : 'info') + '<span>' + QUOTE[1] + '</span></div>'
      : '<ol class="tstepper" aria-label="Order progress">' + steps + '</ol>'
        + (o.needs_manual_review ? '<div class="note note-danger">' + icon('info') + '<span>' + reviewNote + '</span></div>' : ''))
    + '<dl class="details">'
    +   '<div class="drow"><dt>Design</dt><dd>' + esc(o.design_name) + '</dd></div>'
    +   (o.design_code ? '<div class="drow"><dt>Design code</dt><dd>' + esc(o.design_code) + '</dd></div>' : '')
    +   '<div class="drow"><dt>Ordered on</dt><dd>' + dateLabel + '</dd></div>'
    +   '<div class="drow"><dt>' + (o.callback ? 'Design price' : o.needs_manual_review ? 'Price starts from' : 'Total price') + '</dt><dd>' + fmt(o.total_price) + '</dd></div>'
    +   (o.callback ? '' : '<div class="drow"><dt>' + STRUCT_NAME + '</dt><dd>' + (o.structural ? 'Included' : 'Not included') + '</dd></div>')
    +   '<div class="drow"><dt>Your changes</dt><dd>' + mods + '</dd></div>'
    +   (o.estimated_delivery_days ? '<div class="drow"><dt>Ready in about</dt><dd>' + o.estimated_delivery_days + ' days</dd></div>' : '')
    + '</dl>'
    + '<div class="help-banner"><span class="ic-wrap">' + icon('phone', 'ic-lg') + '</span>'
    +   '<span><strong>Need help?</strong>Call us on <a href="' + CONTACT.tel + '">' + CONTACT.phone + '</a> or <a href="' + CONTACT.whatsapp + '" target="_blank" rel="noopener">message us on WhatsApp</a>. Keep your order number ready.</span></div>'
    + '</div>';
  $('result').scrollIntoView({behavior:'smooth', block:'start'});
}
</script>
</body>
</html>

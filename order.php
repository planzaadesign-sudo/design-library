<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Place Order &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925c">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter"><div class="narrow" id="app"><p class="muted">Loading your order&#8230;</p></div></main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
const params = new URLSearchParams(window.location.search);
const designId = parseInt(params.get('id'), 10);
const isCustom = params.get('mode') === 'custom';
// As-is purchase: optional 40% structural package. Customised: structural choice from the configurator.
const structAddon = !isCustom && params.get('struct') === '1';
const structural = isCustom && params.get('structural') !== '0';
const modIds = isCustom ? (params.get('mods') || '').split(',').map(Number).filter(Boolean) : [];
const userPlot = {width:params.get('width') || '', length:params.get('length') || '', facing:params.get('facing') || ''};

let design = null;
let mods = [];
let q = null;

async function load(){
  const [dRes, mRes] = await Promise.all([
    designId ? fetch('api/design.php?id=' + designId) : null,
    isCustom ? fetch('api/modifications.php') : null,
  ]);
  if(!dRes || !dRes.ok || (mRes && !mRes.ok)){
    document.getElementById('app').innerHTML = '<h1>Design not found</h1><p class="lead">This design may have been removed.</p><a class="btn" href="index.php">← Back to designs</a>';
    return;
  }
  design = normDesign(await dRes.json());
  if(mRes){
    const all = {};
    (await mRes.json()).forEach(g => g.items.forEach(m => { all[m.id] = m; }));
    mods = modIds.map(id => all[id]).filter(Boolean).sort((a, b) => a.tier - b.tier || a.id - b.id);
  }
  if(isCustom && !mods.length){ window.location.replace('customize.php?id=' + design.id); return; }
  q = quote(design, mods, structural, structAddon);
  render();
}

function backLink(){
  const back = new URLSearchParams(plotQuery(userPlot));
  back.set('id', design.id);
  if(isCustom){
    back.set('structural', structural ? '1' : '0');
    back.set('mods', mods.map(m => m.id).join(','));
    return '<a class="back" href="customize.php?' + back + '">← Edit your changes</a>';
  }
  return '<a class="back" href="design.php?' + back + '">← Back to design</a>';
}

function summaryBody(){
  let rows = '<div class="sum-line"><span>Base design</span><span>' + fmt(design.base_price) + '</span></div>';
  if(isCustom){
    rows += '<div class="sum-line sub"><span>Structural design</span><span>' + (structural ? 'Included' : 'Not included') + '</span></div>'
      + '<div class="sum-mods">' + mods.map(m => '<div class="sum-line sub"><span>' + esc(m.label) + '</span><span>'
      + (m.tier === 4 ? fmt(m.price_min) + '–' + fmt(m.price_max) : fmt(effectiveModPrice(m, structural))) + '</span></div>').join('') + '</div>';
  } else {
    rows += '<div class="sum-line sub"><span>Structural design package</span><span>' + (structAddon ? fmt(structAddonPrice(design.base_price)) : 'Not added') + '</span></div>';
  }
  return rows
    + '<div class="sum-total"><span>' + (q.isRange ? 'Estimated' : 'Total') + '</span><span class="price' + (q.isRange ? ' range' : '') + '">' + totalLabel(q) + '</span></div>'
    + '<div class="sum-days">Estimated delivery: ' + q.days + ' days</div>'
    + (q.structuralWarning ? '<div class="note note-warn">' + icon('warning') + '<span>Some selected changes affect structural elements. We recommend including structural design.</span></div>' : '')
    + (q.needsReview ? '<div class="note note-danger">' + icon('info') + '<span>This combination needs a manual review. Our team will confirm the exact price within 24 hours.</span></div>' : '');
}

// Floating-label field: the label sits inside the input and floats up on focus or once filled.
function ff(id, label, attrs, value){
  return '<div class="ff" id="f_' + id + '"><input id="' + id + '" placeholder=" " ' + attrs + ' value="' + esc(value || '') + '">'
    + '<label for="' + id + '">' + label + '</label><div class="ff-error" id="e_' + id + '" aria-live="polite"></div></div>';
}

function render(){
  const facings = ['East', 'West', 'North', 'South'];
  const expanded = window.matchMedia('(min-width: 768px)').matches;
  document.getElementById('app').innerHTML =
    backLink()
    + '<h1>' + (q.needsReview ? 'Request your customised design' : 'Place your order') + '</h1>'
    + '<p class="lead">We’ll confirm the details with you by phone before any work begins.</p>'
    + '<div class="sum-card">'
    +   '<button type="button" class="sum-toggle" id="sumToggle" aria-expanded="' + expanded + '" aria-controls="sumBody">'
    +     '<span class="t-name"><small>Order summary</small><strong>' + esc(design.name) + '</strong></span>'
    +     '<span class="t-total">' + totalLabel(q) + '</span>' + icon('chevron', 'chev')
    +   '</button>'
    +   '<div class="sum-body" id="sumBody"' + (expanded ? '' : ' hidden') + '>' + summaryBody() + '</div>'
    + '</div>'
    + '<form class="form-card" id="orderForm" novalidate>'
    +   '<h2>Your details</h2>'
    +   ff('customer_name', 'Full name', 'autocomplete="name" maxlength="100" required')
    +   ff('customer_phone', 'Phone number', 'type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="11" required')
    +   ff('customer_city', 'City', 'autocomplete="address-level2" maxlength="100" required')
    +   '<h2 class="sub-head">Plot details <span class="muted" style="font-weight:400; font-size:13px">(optional)</span></h2>'
    +   '<div class="ff-row">'
    +     ff('plot_width', 'Width (ft)', 'type="number" min="1" max="1000" inputmode="numeric"', userPlot.width)
    +     ff('plot_length', 'Length (ft)', 'type="number" min="1" max="1000" inputmode="numeric"', userPlot.length)
    +     '<div class="ff always" id="f_facing"><select id="facing"><option value="">Not sure</option>'
    +       facings.map(f => '<option' + (f === userPlot.facing ? ' selected' : '') + '>' + f + '</option>').join('')
    +     '</select><label for="facing">Facing</label><div class="ff-error" id="e_facing"></div></div>'
    +   '</div>'
    +   '<p class="form-error" id="formError" role="alert"></p>'
    +   '<button class="btn btn-primary btn-submit" type="submit" id="submitBtn"><span class="btn-label">'
    +     (q.needsReview ? 'Submit for manual review' : 'Confirm &amp; Place Order') + '</span></button>'
    +   '<p class="fine-print">The final price is always confirmed by our server when you submit.</p>'
    + '</form>'
    + '<div class="trust">'
    +   '<span>' + icon('lock') + 'Secure &amp; encrypted</span>'
    +   '<span>' + icon('clock') + '24-hour response</span>'
    +   '<span>' + icon('shield') + '100% satisfaction guarantee</span>'
    + '</div>';

  const toggle = document.getElementById('sumToggle');
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.getElementById('sumBody').hidden = !open;
  });

  const phone = document.getElementById('customer_phone');
  phone.addEventListener('input', () => {
    // Digits only, shown as XXXXX XXXXX; keep the caret where the user is typing.
    const caretDigits = phone.value.slice(0, phone.selectionStart).replace(/\D/g, '').length;
    const digits = phone.value.replace(/\D/g, '').slice(0, 10);
    phone.value = digits.length > 5 ? digits.slice(0, 5) + ' ' + digits.slice(5) : digits;
    const pos = Math.min(caretDigits, 10) + (caretDigits > 5 ? 1 : 0);
    phone.setSelectionRange(pos, pos);
    if(digits.length === 10) setState('customer_phone', 'valid');
    else if(phone.dataset.touched) setState('customer_phone', 'invalid', 'Enter a valid 10-digit phone number.');
    else setState('customer_phone', '');
  });
  phone.addEventListener('blur', () => { if(phone.value){ phone.dataset.touched = '1'; checkPhone(); } });
  [['customer_name', 'Please enter your name.'], ['customer_city', 'Please enter your city.']].forEach(([id, msg]) => {
    const el = document.getElementById(id);
    el.addEventListener('input', () => { if(el.value.trim()) setState(id, 'valid'); });
    el.addEventListener('blur', () => setState(id, el.value.trim() ? 'valid' : (el.dataset.touched ? 'invalid' : ''), msg));
  });
  ['plot_width', 'plot_length', 'facing'].forEach(id => document.getElementById(id).addEventListener('input', () => setState(id, '')));
  document.getElementById('orderForm').addEventListener('submit', submitOrder);
}

function setState(id, state, msg){
  const f = document.getElementById('f_' + id);
  f.classList.toggle('valid', state === 'valid');
  f.classList.toggle('invalid', state === 'invalid');
  document.getElementById('e_' + id).textContent = state === 'invalid' ? (msg || '') : '';
}

function phoneDigits(){ return document.getElementById('customer_phone').value.replace(/\D/g, ''); }

function checkPhone(){
  const ok = /^[0-9]{10}$/.test(phoneDigits());
  setState('customer_phone', ok ? 'valid' : 'invalid', 'Enter a valid 10-digit phone number.');
  return ok;
}

function clientValidate(){
  let ok = true;
  [['customer_name', 'Please enter your name.'], ['customer_city', 'Please enter your city.']].forEach(([id, msg]) => {
    const el = document.getElementById(id);
    el.dataset.touched = '1';
    if(el.value.trim()) setState(id, 'valid'); else { setState(id, 'invalid', msg); ok = false; }
  });
  document.getElementById('customer_phone').dataset.touched = '1';
  return checkPhone() && ok;
}

function setLoading(on){
  const btn = document.getElementById('submitBtn');
  btn.disabled = on;
  btn.setAttribute('aria-busy', on ? 'true' : 'false');
  const spinner = btn.querySelector('.spinner');
  if(on && !spinner) btn.insertAdjacentHTML('afterbegin', '<span class="spinner" aria-hidden="true"></span>');
  if(!on && spinner) spinner.remove();
  btn.querySelector('.btn-label').textContent = on ? 'Placing order…' : (q.needsReview ? 'Submit for manual review' : 'Confirm & Place Order');
}

async function submitOrder(e){
  e.preventDefault();
  document.getElementById('formError').textContent = '';
  if(!clientValidate()){ document.querySelector('.ff.invalid input')?.focus(); return; }
  setLoading(true);

  const val = id => document.getElementById(id).value.trim();
  // No price is sent: api/order.php works the total out from the database.
  const payload = {
    design_id: design.id,
    customer_name: val('customer_name'),
    customer_phone: phoneDigits(),
    customer_city: val('customer_city'),
    plot_width: val('plot_width'), plot_length: val('plot_length'), facing: val('facing'),
    structural_addon: structAddon,
    structural_included: structural,
    modifications: mods.map(m => m.id),
  };
  let data = {}, res;
  try {
    res = await fetch('api/order.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
    data = await res.json();
  } catch(err) {
    res = {ok:false};
    data = {error:'We couldn’t reach the server. Check your connection and try again.'};
  }
  if(!res.ok){
    setLoading(false);
    if(data.errors){
      Object.entries(data.errors).forEach(([id, msg]) => { if(document.getElementById('f_' + id)) setState(id, 'invalid', msg); });
    }
    if(!data.errors || data.errors.modifications) document.getElementById('formError').textContent = data.error || 'Something went wrong. Please try again.';
    document.querySelector('.ff.invalid input, .ff.invalid select')?.focus();
    return;
  }
  showSuccess(data);
}

function showSuccess(data){
  const range = data.total_price_max && data.total_price_max !== data.total_price;
  document.getElementById('app').innerHTML =
    '<div class="success" role="status">'
    + '<div class="confetti" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>'
    + '<svg class="check-anim" viewBox="0 0 80 80" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    +   '<circle cx="40" cy="40" r="36" transform="rotate(-90 40 40)"/><path d="M25 41l10 10 20-22"/></svg>'
    + '<h1>' + (data.needs_manual_review ? 'Request received' : 'Order placed') + '</h1>'
    + '<p class="lead">' + esc(data.design_name) + '</p>'
    + '<div class="code-box"><code id="orderCode">' + esc(data.order_code) + '</code>'
    +   '<button type="button" class="copy-btn" id="copyBtn">' + icon('copy') + '<span>Copy</span></button></div>'
    + '<p class="small">Save this code — you’ll need it with your phone number to track your order.</p>'
    + '<div class="success-total"><div class="sum-total"><span>' + (range ? 'Estimated' : 'Total') + '</span><span class="price' + (range ? ' range' : '') + '">'
    +   (range ? fmt(data.total_price) + ' – ' + fmt(data.total_price_max) : fmt(data.total_price)) + '</span></div>'
    +   (data.estimated_delivery_days ? '<div class="sum-days">Estimated delivery: ' + data.estimated_delivery_days + ' days</div>' : '') + '</div>'
    + '<p style="margin:20px 0 0">Our team will contact you within 24 hours' + (data.needs_manual_review ? ' to confirm the exact price.' : '.') + '</p>'
    + '<div class="cta-row"><a class="btn btn-primary lift" href="track.php?code=' + encodeURIComponent(data.order_code) + '">Track your order</a><a class="btn" href="index.php">Browse more designs</a></div>'
    + '</div>';
  window.scrollTo(0, 0);

  document.getElementById('copyBtn').addEventListener('click', async () => {
    const label = document.querySelector('#copyBtn span');
    try {
      await navigator.clipboard.writeText(data.order_code);
    } catch(err) {
      // Older browsers / non-HTTPS: select the code so the customer can copy it.
      const range = document.createRange();
      range.selectNodeContents(document.getElementById('orderCode'));
      const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
      label.textContent = 'Press Ctrl+C';
      return;
    }
    label.textContent = 'Copied!';
    setTimeout(() => { label.textContent = 'Copy'; }, 1800);
  });
}

load();
</script>
</body>
</html>

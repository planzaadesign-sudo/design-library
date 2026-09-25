<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Place My Order &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925f">
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
let details = [];       // room-by-room choices from the configurator (via sessionStorage)
let entriesByMod = {};

async function load(){
  const [dRes, mRes] = await Promise.all([
    designId ? fetch('api/design.php?id=' + designId) : null,
    isCustom ? fetch('api/modifications.php') : null,
  ]);
  if(!dRes || !dRes.ok || (mRes && !mRes.ok)){
    document.getElementById('app').innerHTML = '<h1>We could not find this design</h1><p class="lead">It may have been removed. Please pick another design.</p><a class="btn" href="index.php">← Back to all designs</a>';
    return;
  }
  design = normDesign(await dRes.json());
  if(mRes){
    const all = {};
    (await mRes.json()).forEach(g => g.items.forEach(m => { all[m.id] = m; }));
    mods = modIds.map(id => all[id]).filter(Boolean).sort((a, b) => a.tier - b.tier || a.id - b.id);
    const cfg = readConfig();
    details = cfg && cfg.design_id === design.id ? (cfg.details || []).filter(d => mods.some(m => m.id === d.modification_id)) : [];
    details.forEach(d => { (entriesByMod[d.modification_id] = entriesByMod[d.modification_id] || []).push(d); });
    // Room choices missing (for example the link was opened in another browser): pick them again.
    if(mods.some(m => needsDetails(m) && !(entriesByMod[m.id] || []).length)){
      const back = new URLSearchParams(window.location.search);
      back.delete('mode');
      window.location.replace('customize.php?' + back);
      return;
    }
    mods = mods.map(m => ROOM_KINDS.includes(m.detail_type) ? Object.assign({}, m, {qty:entriesByMod[m.id].length}) : m);
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
    return '<a class="back" href="customize.php?' + back + '">← Change my choices</a>';
  }
  return '<a class="back" href="design.php?' + back + '">← Back to design</a>';
}

function summaryBody(){
  let rows = '<div class="sum-line"><span>Design price</span><span>' + fmt(design.base_price) + '</span></div>';
  if(isCustom){
    rows += '<div class="sum-line sub"><span>' + STRUCT_NAME + '</span><span>' + (structural ? 'Included' : 'Not included') + '</span></div>'
      + '<div class="sum-mods">' + summaryModLines(mods, entriesByMod, structural) + '</div>';
  } else {
    rows += '<div class="sum-line sub"><span>' + STRUCT_NAME + '</span><span>' + (structAddon ? fmt(structAddonPrice(design.base_price)) : 'Not added') + '</span></div>';
  }
  return rows
    + '<div class="sum-total"><span>' + (q.isRange ? 'Approx. price' : 'Total price') + '</span><span class="price' + (q.isRange ? ' range' : '') + '">' + totalLabel(q) + '</span></div>'
    + '<div class="sum-days">Ready in about ' + q.days + ' days</div>'
    + (q.structuralWarning ? '<div class="note note-warn">' + icon('warning') + '<span>Some of your changes affect the building structure. We suggest you add building safety drawings.</span></div>' : '')
    + (q.needsReview ? '<div class="note note-danger">' + icon('info') + '<span>These changes are big — our team will check and tell you the exact price within 24 hours.</span></div>' : '');
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
    + '<h1>' + (q.needsReview ? 'Send your changes to our team' : 'Place your order') + '</h1>'
    + '<p class="lead">' + (q.needsReview ? 'Our team will check your changes and call you with the exact price within 24 hours.' : 'We will call you to check everything before we start work.') + '</p>'
    + '<div class="sum-card">'
    +   '<button type="button" class="sum-toggle" id="sumToggle" aria-expanded="' + expanded + '" aria-controls="sumBody">'
    +     '<span class="t-name"><small>Your order</small><strong>' + esc(design.name) + '</strong></span>'
    +     '<span class="t-total">' + totalLabel(q) + '</span>' + icon('chevron', 'chev')
    +   '</button>'
    +   '<div class="sum-body" id="sumBody"' + (expanded ? '' : ' hidden') + '>' + summaryBody() + '</div>'
    + '</div>'
    + '<form class="form-card" id="orderForm" novalidate>'
    +   '<h2>Your details</h2>'
    +   ff('customer_name', 'Your full name', 'autocomplete="name" maxlength="100" required')
    +   ff('customer_phone', 'Mobile number', 'type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="11" required')
    +   ff('customer_city', 'Your city or town', 'autocomplete="address-level2" maxlength="100" required')
    +   '<h2 class="sub-head">Your plot <span class="muted" style="font-weight:400; font-size:13px">(you can skip this)</span></h2>'
    +   '<div class="ff-row">'
    +     ff('plot_width', 'Width (feet)', 'type="number" min="1" max="1000" inputmode="numeric"', userPlot.width)
    +     ff('plot_length', 'Length (feet)', 'type="number" min="1" max="1000" inputmode="numeric"', userPlot.length)
    +     '<div class="ff always" id="f_facing"><select id="facing"><option value="">Not sure</option>'
    +       facings.map(f => '<option' + (f === userPlot.facing ? ' selected' : '') + '>' + f + '</option>').join('')
    +     '</select><label for="facing">Plot direction</label><div class="ff-error" id="e_facing"></div></div>'
    +   '</div>'
    +   '<p class="form-error" id="formError" role="alert"></p>'
    +   '<button class="btn btn-primary btn-submit" type="submit" id="submitBtn"><span class="btn-label">'
    +     (q.needsReview ? 'Send to our team for pricing' : 'Place my order') + '</span></button>'
    +   '<p class="fine-print">You don’t pay anything on this page. We will call you first.</p>'
    + '</form>'
    + '<div class="trust">'
    +   '<span>' + icon('lock') + 'Your details are safe with us</span>'
    +   '<span>' + icon('clock') + 'We reply within 24 hours</span>'
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
    else if(phone.dataset.touched) setState('customer_phone', 'invalid', 'Please type your 10-digit mobile number.');
    else setState('customer_phone', '');
  });
  phone.addEventListener('blur', () => { if(phone.value){ phone.dataset.touched = '1'; checkPhone(); } });
  [['customer_name', 'Please type your name.'], ['customer_city', 'Please type your city or town.']].forEach(([id, msg]) => {
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
  setState('customer_phone', ok ? 'valid' : 'invalid', 'Please type your 10-digit mobile number.');
  return ok;
}

function clientValidate(){
  let ok = true;
  [['customer_name', 'Please type your name.'], ['customer_city', 'Please type your city or town.']].forEach(([id, msg]) => {
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
  btn.querySelector('.btn-label').textContent = on ? 'Sending…' : (q.needsReview ? 'Send to our team for pricing' : 'Place my order');
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
    modification_details: details.map(d => ({modification_id:d.modification_id, room_id:d.room_id, action:d.action, custom_note:d.custom_note})),
  };
  let data = {}, res;
  try {
    res = await fetch('api/order.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
    data = await res.json();
  } catch(err) {
    res = {ok:false};
    data = {error:'We could not connect. Please check your internet and try again.'};
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
    + '<h1>' + (data.needs_manual_review ? 'Sent to our team!' : 'Order placed!') + '</h1>'
    + '<p class="lead">' + esc(data.design_name) + '</p>'
    + '<div class="code-box"><code id="orderCode">' + esc(data.order_code) + '</code>'
    +   '<button type="button" class="copy-btn" id="copyBtn">' + icon('copy') + '<span>Copy</span></button></div>'
    + '<p class="small">This is your order number. Save it. You need it with your mobile number to check your order.</p>'
    + '<div class="success-total"><div class="sum-total"><span>' + (range ? 'Approx. price' : 'Total price') + '</span><span class="price' + (range ? ' range' : '') + '">'
    +   (range ? fmt(data.total_price) + ' – ' + fmt(data.total_price_max) : fmt(data.total_price)) + '</span></div>'
    +   (data.estimated_delivery_days ? '<div class="sum-days">Ready in about ' + data.estimated_delivery_days + ' days</div>' : '') + '</div>'
    + '<p style="margin:20px 0 0">Our team will call you within 24 hours' + (data.needs_manual_review ? ' to tell you the exact price.' : '.') + '</p>'
    + '<div class="cta-row"><a class="btn btn-primary lift" href="track.php?code=' + encodeURIComponent(data.order_code) + '">Track my order</a><a class="btn" href="index.php">See more designs</a></div>'
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
      label.textContent = 'Selected — copy it';
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

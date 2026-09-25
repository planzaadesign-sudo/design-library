<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Place Order &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925b">
</head>
<body>
<div class="topbar"><div class="topbar-inner topnav">
  <div class="wordmark"><a href="index.php">planzaa<span>.</span> design library</a></div>
  <nav class="navlinks"><a href="index.php" class="active">Designs</a><a href="track.php">Track order</a></nav>
</div></div>
<div class="wrap" id="app"><p>Loading...</p></div>

<script src="assets/app.js?v=20260925b"></script>
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
    document.getElementById('app').innerHTML = '<h1>Design not found</h1><p class="section-gap">This design may have been removed.</p><a class="btn" href="index.php">← Back to library</a>';
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
    return '<a class="back-link" href="customize.php?' + back + '">← Edit your changes</a>';
  }
  return '<a class="back-link" href="design.php?' + back + '">← Back to design</a>';
}

function summaryHtml(){
  let rows = '<div class="sum-line"><span>' + esc(design.name) + '</span><span>' + fmt(design.base_price) + '</span></div>';
  if(isCustom){
    rows += '<div class="sum-line sub"><span>Structural design</span><span>' + (structural ? 'Included' : 'Not included') + '</span></div>'
      + '<div class="sum-mods">' + mods.map(m => '<div class="sum-line sub"><span>' + esc(m.label) + '</span><span>'
      + (m.tier === 4 ? fmt(m.price_min) + '–' + fmt(m.price_max) : fmt(effectiveModPrice(m, structural))) + '</span></div>').join('') + '</div>';
  } else {
    rows += '<div class="sum-line sub"><span>Structural design package</span><span>' + (structAddon ? fmt(structAddonPrice(design.base_price)) : 'No') + '</span></div>';
  }
  return '<h3>Order summary</h3>' + rows
    + '<div class="sum-total"><span>' + (q.isRange ? 'Estimated' : 'Total') + '</span><span class="price' + (q.isRange ? ' range' : '') + '">' + totalLabel(q) + '</span></div>'
    + '<div class="sum-days">Estimated delivery: <strong>' + q.days + ' days</strong></div>'
    + (q.structuralWarning ? '<div class="warn">Some selected changes affect structural elements. We recommend including structural design.</div>' : '')
    + (q.needsReview ? '<div class="review-note">This combination needs a manual review. Our team will confirm the exact price within 24 hours.</div>' : '');
}

function field(id, label, attrs, value){
  return '<div class="field" id="f_' + id + '"><label for="' + id + '">' + label + '</label>'
    + '<input id="' + id + '" ' + attrs + ' value="' + esc(value || '') + '"><div class="field-error" id="e_' + id + '"></div></div>';
}

function render(){
  const facings = ['East', 'West', 'North', 'South'];
  document.getElementById('app').innerHTML =
    backLink()
    + '<h1 style="margin-top:14px">' + (q.needsReview ? 'Request your customised design' : 'Place your order') + '</h1>'
    + '<p class="section-gap">We’ll confirm the details with you by phone before any work begins.</p>'
    + '<div class="order-layout">'
    +   '<form id="orderForm" novalidate>'
    +     '<div class="form-section"><h2>Your details</h2>'
    +       field('customer_name', 'Full name', 'autocomplete="name" maxlength="100" required')
    +       '<div class="form-row">'
    +         field('customer_phone', 'Phone number (10 digits)', 'type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="10" required')
    +         field('customer_city', 'City', 'autocomplete="address-level2" maxlength="100" required')
    +       '</div>'
    +     '</div>'
    +     '<div class="form-section"><h2>Plot details <span class="muted" style="font-weight:400; font-size:13px">(optional)</span></h2>'
    +       '<div class="form-row">'
    +         field('plot_width', 'Width (ft)', 'type="number" min="1" max="1000"', userPlot.width)
    +         field('plot_length', 'Length (ft)', 'type="number" min="1" max="1000"', userPlot.length)
    +         '<div class="field" id="f_facing"><label for="facing">Facing</label><select id="facing"><option value="">Select</option>'
    +           facings.map(f => '<option' + (f === userPlot.facing ? ' selected' : '') + '>' + f + '</option>').join('')
    +         '</select><div class="field-error" id="e_facing"></div></div>'
    +       '</div>'
    +     '</div>'
    +     '<div class="error-note" id="formError" role="alert"></div>'
    +     '<button class="btn btn-primary btn-block" type="submit" id="submitBtn" style="padding:14px; font-size:16px">'
    +       (q.needsReview ? 'Submit for manual review' : 'Confirm &amp; Place Order') + '</button>'
    +     '<p class="muted" style="font-size:12px; text-align:center; margin-top:10px">The final price is always confirmed by our server when you submit.</p>'
    +   '</form>'
    +   '<aside class="summary">' + summaryHtml() + '</aside>'
    + '</div>';

  const phone = document.getElementById('customer_phone');
  phone.addEventListener('input', () => {
    phone.value = phone.value.replace(/\D/g, '').slice(0, 10);
    validatePhone(phone.value.length === 10 || phone.dataset.touched === '1');
  });
  phone.addEventListener('blur', () => { phone.dataset.touched = '1'; validatePhone(true); });
  ['customer_name', 'customer_city', 'plot_width', 'plot_length', 'facing'].forEach(id =>
    document.getElementById(id).addEventListener('input', () => setError(id, '')));
  document.getElementById('orderForm').addEventListener('submit', submitOrder);
}

function setError(id, msg){
  document.getElementById('e_' + id).textContent = msg;
  document.getElementById('f_' + id).classList.toggle('has-error', !!msg);
}

function validatePhone(show){
  const ok = /^[0-9]{10}$/.test(document.getElementById('customer_phone').value);
  setError('customer_phone', ok || !show ? '' : 'Enter a valid 10-digit phone number.');
  return ok;
}

function clientValidate(){
  let ok = validatePhone(true);
  [['customer_name', 'Please enter your name.'], ['customer_city', 'Please enter your city.']].forEach(([id, msg]) => {
    if(!document.getElementById(id).value.trim()){ setError(id, msg); ok = false; }
  });
  return ok;
}

async function submitOrder(e){
  e.preventDefault();
  document.getElementById('formError').textContent = '';
  if(!clientValidate()){ document.querySelector('.has-error input')?.focus(); return; }

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  const label = btn.innerHTML;
  btn.textContent = 'Placing order…';

  const val = id => document.getElementById(id).value.trim();
  // No price is sent: api/order.php works the total out from the database.
  const payload = {
    design_id: design.id,
    customer_name: val('customer_name'),
    customer_phone: val('customer_phone'),
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
    btn.disabled = false;
    btn.innerHTML = label;
    if(data.errors){
      Object.entries(data.errors).forEach(([id, msg]) => { if(document.getElementById('e_' + id)) setError(id, msg); });
    }
    if(!data.errors || data.errors.modifications) document.getElementById('formError').textContent = data.error || 'Something went wrong. Please try again.';
    document.querySelector('.has-error input, .has-error select')?.focus();
    return;
  }
  showSuccess(data);
}

function showSuccess(data){
  const range = data.total_price_max && data.total_price_max !== data.total_price;
  document.getElementById('app').innerHTML =
    '<div class="success-card">'
    + '<div class="tick">✓</div>'
    + '<h1>' + (data.needs_manual_review ? 'Request received' : 'Order placed') + '</h1>'
    + '<p class="muted" style="margin:6px 0 0">Your order code</p>'
    + '<div class="order-code">' + esc(data.order_code) + '</div>'
    + '<p class="muted" style="font-size:13px; margin:0 0 18px">Save this code — you’ll need it with your phone number to track your order.</p>'
    + '<div class="section-title" style="margin:0">' + esc(data.design_name) + '</div>'
    + '<div class="sum-total" style="max-width:340px; margin:10px auto 0; text-align:left"><span>' + (range ? 'Estimated' : 'Total') + '</span><span class="price">'
    +   (range ? fmt(data.total_price) + ' – ' + fmt(data.total_price_max) : fmt(data.total_price)) + '</span></div>'
    + (data.estimated_delivery_days ? '<div class="sum-days">Estimated delivery: <strong>' + data.estimated_delivery_days + ' days</strong></div>' : '')
    + '<p style="margin-top:20px">Our team will contact you within 24 hours'
    +   (data.needs_manual_review ? ' to confirm the exact price.' : '.') + '</p>'
    + '<div class="cta-row"><a class="btn btn-primary" href="track.php?code=' + encodeURIComponent(data.order_code) + '">Track this order</a><a class="btn" href="index.php">Browse more designs</a></div>'
    + '</div>';
  window.scrollTo(0, 0);
}

load();
</script>
</body>
</html>

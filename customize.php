<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customise Design &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925c">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter" id="app"><p class="muted">Loading configurator&#8230;</p></main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
const params = new URLSearchParams(window.location.search);
const designId = parseInt(params.get('id'), 10);
const userPlot = {width:params.get('width') || '', length:params.get('length') || '', facing:params.get('facing') || ''};

let design = null;
let groups = [];          // [{tier, items:[...]}] from api/modifications.php
let modsById = {};
// Structural choice from Step 1; drives every modification price below.
let structural = params.get('structural') !== '0';
// Coming back from the order page restores the previous selection.
const selected = new Set((params.get('mods') || '').split(',').map(Number).filter(Boolean));
let step = selected.size ? 2 : 1;
let lastTotal = null;
const shown = {warn:false, review:false}; // notes already on screen don't replay their entrance

async function load(){
  const [dRes, mRes] = await Promise.all([
    designId ? fetch('api/design.php?id=' + designId) : null,
    fetch('api/modifications.php'),
  ]);
  if(!dRes || !dRes.ok || !mRes.ok){
    document.getElementById('app').innerHTML = '<h1>Design not found</h1><p class="muted" style="margin:10px 0 20px">This design may have been removed.</p><a class="btn" href="index.php">← Back to designs</a>';
    return;
  }
  design = normDesign(await dRes.json());
  groups = await mRes.json();
  groups.forEach(g => g.items.forEach(m => { modsById[m.id] = m; }));
  [...selected].forEach(id => { if(!modsById[id]) selected.delete(id); });
  document.title = 'Customise ' + design.name + ' — Planzaa';
  renderShell();
  update();
}

function choiceCard(value, title, desc, badge){
  return '<button type="button" class="choice-card" data-structural="' + value + '" aria-pressed="false">'
    + '<span class="radio" aria-hidden="true"></span>'
    + '<div class="choice-title">' + title + (badge ? ' <span class="rec-badge">' + badge + '</span>' : '') + '</div>'
    + '<div class="choice-desc">' + desc + '</div></button>';
}

function renderShell(){
  const q = plotQuery(userPlot);
  let rowIndex = 0;
  const tiersHtml = groups.map(g => {
    const t = TIERS[g.tier] || {title:'Tier ' + g.tier, sub:'', cls:''};
    return '<div class="tier-group ' + t.cls + '">'
      + '<button type="button" class="tier-head" aria-expanded="true" aria-controls="tier' + g.tier + '">'
      +   '<span class="dot" aria-hidden="true"></span><strong>' + esc(t.title) + '</strong><span class="tier-sub">' + esc(t.sub) + '</span>'
      +   '<span class="tier-count" data-count-for="' + g.tier + '"></span>' + icon('chevron', 'chev')
      + '</button>'
      + '<div class="mod-list enter" id="tier' + g.tier + '">' + g.items.map(m =>
          '<label class="mod-row" data-id="' + m.id + '" style="--i:' + (rowIndex++) + '">'
          + '<input type="checkbox" value="' + m.id + '"' + (selected.has(m.id) ? ' checked' : '') + '>'
          + '<span><span class="mod-label">' + esc(m.label) + '</span>'
          + (m.added_days ? '<span class="mod-days">+' + m.added_days + ' day' + (m.added_days === 1 ? '' : 's') + ' delivery</span>' : '')
          + '</span>'
          + '<span class="mod-price" data-price-for="' + m.id + '"></span>'
          + '</label>').join('')
      + '</div></div>';
  }).join('');

  document.getElementById('app').innerHTML =
    '<nav class="breadcrumb" aria-label="Breadcrumb"><a href="design.php?id=' + design.id + (q ? '&' + q : '') + '">← Back to design</a><span aria-hidden="true">/</span><span class="current">Customise</span></nav>'
    + '<div class="config-head">'
    +   '<div class="thumb">' + design.floor_plan_svg + '</div>'
    +   '<div><div class="eyebrow">Customise</div><h1>' + esc(design.name) + '</h1>'
    +   '<div class="meta">' + design.plot_width + '×' + design.plot_length + ' ft · ' + esc(design.facing) + ' facing · ' + esc(design.floors) + ' · ' + design.bhk + ' BHK · base ' + fmt(design.base_price) + '</div></div>'
    + '</div>'
    + '<div class="steps-bar">'
    +   '<div class="steps-text" id="stepsText" aria-live="polite"></div>'
    +   '<div class="steps-track"><button type="button" class="steps-seg on" data-goto="step1" aria-label="Go to step 1"><i></i></button><button type="button" class="steps-seg" data-goto="step2" aria-label="Go to step 2"><i></i></button></div>'
    + '</div>'
    + '<div class="config-layout">'
    +   '<div>'
    +     '<div class="step-label" id="step1"><span class="step-num">1</span><h2>Choose your scope</h2></div>'
    +     '<div class="choice-grid">'
    +       choiceCard(1, 'Include structural design', 'Engineer-reviewed structural drawings for any changed elements.', 'Recommended')
    +       choiceCard(0, 'Architectural only', 'Lower price. You’ll arrange structural sign-off separately before construction.')
    +     '</div>'
    +     '<div class="step-label" id="step2"><span class="step-num">2</span><h2>Select modifications</h2></div>'
    +     '<p class="muted" style="font-size:14px; margin:-6px 0 14px">Prices update as you go. Everything is reviewed by our design team before work starts.</p>'
    +     tiersHtml
    +     '<div class="help-banner"><span class="ic-wrap">' + icon('phone', 'ic-lg') + '</span>'
    +       '<span><strong>Need help choosing?</strong>Call <a href="' + CONTACT.tel + '">' + CONTACT.phone + '</a> or <a href="' + CONTACT.whatsapp + '" target="_blank" rel="noopener">WhatsApp us</a>.</span></div>'
    +   '</div>'
    +   '<aside class="summary" id="summary" aria-live="polite"></aside>'
    + '</div>';

  document.querySelectorAll('.choice-card').forEach(btn => btn.addEventListener('click', () => {
    structural = btn.dataset.structural === '1';
    setStep(2);
    update();
  }));
  document.querySelectorAll('.mod-row input').forEach(cb => cb.addEventListener('change', () => {
    const id = Number(cb.value);
    cb.checked ? selected.add(id) : selected.delete(id);
    setStep(2);
    update();
  }));
  document.querySelectorAll('.tier-head').forEach(head => head.addEventListener('click', () => {
    const open = head.getAttribute('aria-expanded') !== 'true';
    head.setAttribute('aria-expanded', open ? 'true' : 'false');
    const list = document.getElementById(head.getAttribute('aria-controls'));
    list.hidden = !open;
    if(open){ list.classList.remove('enter'); void list.offsetWidth; list.classList.add('enter'); }
  }));
  document.querySelectorAll('[data-goto]').forEach(seg => seg.addEventListener('click', () =>
    document.getElementById(seg.dataset.goto).scrollIntoView({behavior:'smooth', block:'start'})));
  // Reaching the modification list also counts as moving on to step 2.
  window.addEventListener('scroll', () => {
    const top = document.getElementById('step2').getBoundingClientRect().top;
    if(top < window.innerHeight * 0.45) setStep(2);
  }, {passive:true});
  setStep(step);
}

function setStep(n){
  if(n < step) return;
  step = n;
  document.getElementById('stepsText').innerHTML = step === 1
    ? '<strong>Step 1 of 2</strong> — Choose your scope'
    : '<strong>Step 2 of 2</strong> — Select modifications';
  document.querySelectorAll('.steps-seg')[1].classList.toggle('on', step === 2);
}

function selectedMods(){
  // Keep display order stable: by tier, then list order.
  const out = [];
  groups.forEach(g => g.items.forEach(m => { if(selected.has(m.id)) out.push(m); }));
  return out;
}

function update(){
  document.querySelectorAll('.choice-card').forEach(btn => {
    const on = (btn.dataset.structural === '1') === structural;
    btn.classList.toggle('selected', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  document.querySelectorAll('.mod-row').forEach(row => row.classList.toggle('checked', selected.has(Number(row.dataset.id))));
  Object.values(modsById).forEach(m => {
    document.querySelector('[data-price-for="' + m.id + '"]').textContent = modPriceLabel(m, structural);
  });
  groups.forEach(g => {
    const n = g.items.filter(m => selected.has(m.id)).length;
    document.querySelector('[data-count-for="' + g.tier + '"]').textContent = n ? n + ' selected' : '';
  });

  const mods = selectedMods();
  const q = quote(design, mods, structural, false);
  const lines = mods.length
    ? mods.map(m => '<div class="sum-line sub"><span>' + esc(m.label) + '</span><span>' + (m.tier === 4 ? fmt(m.price_min) + '–' + fmt(m.price_max) : fmt(effectiveModPrice(m, structural))) + '</span></div>').join('')
    : '<div class="sum-empty">No changes selected yet.</div>';

  const next = new URLSearchParams(plotQuery(userPlot));
  next.set('id', design.id);
  next.set('mode', 'custom');
  next.set('structural', structural ? '1' : '0');
  next.set('mods', mods.map(m => m.id).join(','));

  const total = totalLabel(q);
  document.getElementById('summary').innerHTML =
    '<h3>Your order</h3>'
    + '<div class="sum-line"><span>' + esc(design.name) + '</span><span>' + fmt(design.base_price) + '</span></div>'
    + '<div class="sum-line sub"><span>Structural design</span><span>' + (structural ? 'Included' : 'Not included') + '</span></div>'
    + '<div class="sum-mods">' + lines + '</div>'
    + '<div class="sum-total"><span>' + (q.isRange ? 'Estimated' : 'Total') + '</span><span class="price flash' + (q.isRange ? ' range' : '') + '" id="sumTotal">' + total + '</span></div>'
    + '<div class="sum-days">Estimated delivery: ' + q.days + ' days</div>'
    + (q.structuralWarning ? '<div class="note note-warn' + (shown.warn ? ' still' : '') + '">' + icon('warning') + '<span>Some selected changes affect structural elements. We recommend including structural design.</span></div>' : '')
    + (q.needsReview ? '<div class="note note-danger' + (shown.review ? ' still' : '') + '">' + icon('info') + '<span>This combination needs a manual review. Our team will confirm the exact price within 24 hours.</span></div>' : '')
    + '<a class="btn btn-primary btn-block lift' + (mods.length ? '' : ' disabled') + '" href="order.php?' + next.toString() + '"' + (mods.length ? '' : ' aria-disabled="true" tabindex="-1"') + '>'
    +   (q.needsReview ? 'Submit for manual review' : 'Confirm &amp; pay deposit') + '</a>'
    + (mods.length ? '' : '<p class="helper">Select at least one change to continue, or <a href="design.php?id=' + design.id + (plotQuery(userPlot) ? '&' + plotQuery(userPlot) : '') + '">buy the design as-is</a>.</p>');

  shown.warn = q.structuralWarning; shown.review = q.needsReview;
  if(lastTotal !== null && lastTotal !== total) flash(document.getElementById('sumTotal'));
  lastTotal = total;
}

load();
</script>
</body>
</html>

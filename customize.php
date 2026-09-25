<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customise Design &#8212; Planzaa</title>
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
const userPlot = {width:params.get('width') || '', length:params.get('length') || '', facing:params.get('facing') || ''};

let design = null;
let groups = [];          // [{tier, items:[...]}] from api/modifications.php
let modsById = {};
// Structural choice from Step 1; drives every modification price below.
let structural = params.get('structural') !== '0';
// Coming back from the order page restores the previous selection.
const selected = new Set((params.get('mods') || '').split(',').map(Number).filter(Boolean));

async function load(){
  const [dRes, mRes] = await Promise.all([
    designId ? fetch('api/design.php?id=' + designId) : null,
    fetch('api/modifications.php'),
  ]);
  if(!dRes || !dRes.ok || !mRes.ok){
    document.getElementById('app').innerHTML = '<h1>Design not found</h1><p class="section-gap">This design may have been removed.</p><a class="btn" href="index.php">← Back to library</a>';
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
    + '<div class="choice-title">' + title + (badge ? ' <span class="rec-badge">' + badge + '</span>' : '') + '</div>'
    + '<div class="choice-desc">' + desc + '</div></button>';
}

function renderShell(){
  const q = plotQuery(userPlot);
  const tiersHtml = groups.map(g => {
    const t = TIERS[g.tier] || {title:'Tier ' + g.tier, sub:'', cls:''};
    return '<div class="tier-group ' + t.cls + '">'
      + '<div class="tier-head"><span class="dot"></span><strong>' + esc(t.title) + '</strong><span>' + esc(t.sub) + '</span></div>'
      + '<div class="mod-list">' + g.items.map(m =>
          '<label class="mod-row" data-id="' + m.id + '">'
          + '<input type="checkbox" value="' + m.id + '"' + (selected.has(m.id) ? ' checked' : '') + '>'
          + '<span><span class="mod-label">' + esc(m.label) + '</span>'
          + (m.added_days ? '<br><span class="mod-days">+' + m.added_days + ' day' + (m.added_days === 1 ? '' : 's') + '</span>' : '')
          + '</span>'
          + '<span class="mod-price" data-price-for="' + m.id + '"></span>'
          + '</label>').join('')
      + '</div></div>';
  }).join('');

  document.getElementById('app').innerHTML =
    '<a class="back-link" href="design.php?id=' + design.id + (q ? '&' + q : '') + '">← Back to design</a>'
    + '<div class="config-head" style="margin-top:14px">'
    +   '<div class="thumb">' + design.floor_plan_svg + '</div>'
    +   '<div><div class="eyebrow">Customise</div><h1>' + esc(design.name) + '</h1>'
    +   '<div class="muted" style="font-size:14px">' + design.plot_width + '×' + design.plot_length + ' ft · ' + esc(design.facing) + ' facing · ' + esc(design.floors) + ' · ' + design.bhk + ' BHK · base ' + fmt(design.base_price) + '</div></div>'
    + '</div>'
    + '<div class="config-layout">'
    +   '<div>'
    +     '<div class="step-label"><span class="step-num">1</span><h2>How should changes be engineered?</h2></div>'
    +     '<div class="choice-grid">'
    +       choiceCard(1, 'Include structural design', 'Engineer-reviewed structural drawings for any changed elements.', 'Recommended')
    +       choiceCard(0, 'Architectural only', 'Lower price. You’ll arrange structural sign-off separately before construction.')
    +     '</div>'
    +     '<div class="step-label"><span class="step-num">2</span><h2>Pick your changes</h2></div>'
    +     '<p class="muted" style="font-size:14px; margin:-4px 0 16px">Prices update as you go. Everything is reviewed by our design team before work starts.</p>'
    +     tiersHtml
    +   '</div>'
    +   '<aside class="summary" id="summary" aria-live="polite"></aside>'
    + '</div>';

  document.querySelectorAll('.choice-card').forEach(btn => btn.addEventListener('click', () => {
    structural = btn.dataset.structural === '1';
    update();
  }));
  document.querySelectorAll('.mod-row input').forEach(cb => cb.addEventListener('change', () => {
    const id = Number(cb.value);
    cb.checked ? selected.add(id) : selected.delete(id);
    update();
  }));
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

  document.getElementById('summary').innerHTML =
    '<h3>Your order</h3>'
    + '<div class="sum-line"><span>' + esc(design.name) + '</span><span>' + fmt(design.base_price) + '</span></div>'
    + '<div class="sum-line sub"><span>Structural design</span><span>' + (structural ? 'Included' : 'Not included') + '</span></div>'
    + '<div class="sum-mods">' + lines + '</div>'
    + '<div class="sum-total"><span>' + (q.isRange ? 'Estimated' : 'Total') + '</span><span class="price' + (q.isRange ? ' range' : '') + '">' + totalLabel(q) + '</span></div>'
    + '<div class="sum-days">Estimated delivery: <strong>' + q.days + ' days</strong></div>'
    + (q.structuralWarning ? '<div class="warn">Some selected changes affect structural elements. We recommend including structural design.</div>' : '')
    + (q.needsReview ? '<div class="review-note">This combination needs a manual review. Our team will confirm the exact price within 24 hours.</div>' : '')
    + '<a class="btn btn-primary btn-block' + (mods.length ? '' : ' disabled') + '" href="order.php?' + next.toString() + '"' + (mods.length ? '' : ' aria-disabled="true" tabindex="-1"') + '>'
    +   (q.needsReview ? 'Submit for manual review' : 'Confirm &amp; pay deposit') + '</a>'
    + (mods.length ? '' : '<p class="muted" style="font-size:12px; text-align:center; margin:8px 0 0">Select at least one change to continue, or <a href="design.php?id=' + design.id + (plotQuery(userPlot) ? '&' + plotQuery(userPlot) : '') + '">buy the design as-is</a>.</p>');
}

load();
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Design Detail &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925c">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter" id="app"><p class="muted">Loading design&#8230;</p></main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
const params = new URLSearchParams(window.location.search);
const designId = parseInt(params.get('id'), 10);
const userPlot = {width:params.get('width') || '', length:params.get('length') || '', facing:params.get('facing') || ''};
let design = null;
let structAddon = false;

async function load(){
  const res = designId ? await fetch('api/design.php?id=' + designId) : null;
  if(!res || !res.ok){
    document.getElementById('app').innerHTML = '<h1>Design not found</h1><p class="muted" style="margin:10px 0 20px">This design may have been removed.</p><a class="btn" href="index.php">← Back to designs</a>';
    return;
  }
  design = normDesign(await res.json());
  design.struct_addon_price = structAddonPrice(design.base_price);
  document.title = design.name + ' — Planzaa';
  render();
  loadSimilar();
}

function banner(){
  const hasPlot = userPlot.width && userPlot.length && userPlot.facing;
  if(!hasPlot) return '<div class="banner banner-neutral">Enter your plot on the <a href="index.php">designs page</a> to check whether this design fits exactly.</div>';
  const isMatch = Number(userPlot.width) === design.plot_width && Number(userPlot.length) === design.plot_length && userPlot.facing === design.facing;
  const yours = esc(userPlot.width) + '×' + esc(userPlot.length) + ' ft, ' + esc(userPlot.facing) + ' facing';
  return isMatch
    ? '<div class="banner banner-match"><strong>Exact match.</strong> This design fits your ' + yours + ' plot with no modification needed.</div>'
    : '<div class="banner banner-amber"><strong>Needs modification.</strong> Your plot (' + yours + ') differs from this design’s base size or facing. Use <em>Customise this design</em> to adapt it.</div>';
}

const PACKAGE = [
  ['grid', 'Column & beam layout'], ['foundation', 'Footing design'], ['rebar', 'Reinforcement details'],
  ['section', 'Cross-section drawings'], ['list', 'Bar bending schedule'], ['calc', 'Quantity estimate'],
];

function render(){
  const q = plotQuery(userPlot);
  const li = (ic, cls, text) => '<li>' + icon(ic, cls) + '<span>' + text + '</span></li>';
  document.getElementById('app').innerHTML =
    '<nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php' + (q ? '?' + q : '') + '">← Back to designs</a><span aria-hidden="true">/</span><span class="current">' + esc(design.name) + '</span></nav>'
    + '<div class="pdp">'
    + '<div class="pdp-media">'
    +   '<div class="art-card"><div class="art-label">Front elevation</div>' + design.elevation_svg + '</div>'
    +   '<div class="art-card"><div class="art-label">Floor plan</div>' + design.floor_plan_svg + '</div>'
    + '</div>'
    + '<div class="pdp-info">'
    +   '<div class="pdp-title"><div class="eyebrow">Library design</div><h1>' + esc(design.name) + '</h1></div>'
    +   banner()
    +   '<section class="info-card"><h2>Specifications</h2><dl class="spec-grid">'
    +     '<div><dt>Plot size</dt><dd>' + design.plot_width + ' × ' + design.plot_length + ' ft</dd></div>'
    +     '<div><dt>Facing</dt><dd>' + esc(design.facing) + '</dd></div>'
    +     '<div><dt>Floors</dt><dd>' + esc(design.floors) + '</dd></div>'
    +     '<div><dt>Configuration</dt><dd>' + design.bhk + ' BHK</dd></div>'
    +     '<div><dt>Delivery</dt><dd>' + design.delivery_days + ' days</dd></div>'
    +   '</dl></section>'
    +   '<section class="info-card"><h2>What’s included</h2><ul class="icon-list">'
    +     li('check', 'ic-ok', 'Front &amp; side elevation') + li('check', 'ic-ok', 'Dimensioned building plan')
    +     li('check', 'ic-ok', 'Grid layout') + li('check', 'ic-ok', '2–3 interior views')
    +   '</ul></section>'
    +   '<section class="callout-cta"><h2>What’s not included</h2><p>Structural drawings are sold separately — add them below.</p><ul class="icon-list">'
    +     li('x', 'ic-no', 'Column layout') + li('x', 'ic-no', 'Beam design') + li('x', 'ic-no', 'Footing detail')
    +     li('x', 'ic-no', 'Bar bending schedule') + li('x', 'ic-no', 'Quantity estimate')
    +   '</ul><div class="callout-art">' + design.struct_svg + '</div></section>'
    +   '<section class="info-card addon-card" id="addonCard">'
    +     '<label class="toggle-row"><input type="checkbox" id="structCheck"><span class="switch" aria-hidden="true"></span>'
    +       '<span><span class="toggle-title">Add structural design package (+' + fmt(design.struct_addon_price) + ')</span>'
    +       '<span class="toggle-sub">Engineer-prepared structural drawings for this design.</span></span></label>'
    +     '<button type="button" class="disclosure" id="pkgToggle" aria-expanded="false" aria-controls="pkgList">What’s in the structural package? ' + icon('chevron', 'chev') + '</button>'
    +     '<ul class="pkg-list" id="pkgList" hidden>' + PACKAGE.map((p, i) => '<li style="--i:' + i + '">' + icon(p[0]) + '<span>' + esc(p[1]) + '</span></li>').join('') + '</ul>'
    +   '</section>'
    +   '<div class="price-box"><span class="label">Total</span><span class="amount flash" id="total"></span></div>'
    +   '<div class="pdp-actions">'
    +     '<a class="btn btn-primary lift" id="buyBtn" href="#">Buy this design</a>'
    +     '<a class="btn btn-outline lift" href="customize.php?id=' + design.id + (q ? '&' + q : '') + '">Customise this design</a>'
    +   '</div>'
    +   '<p class="action-hint">Need a room resized, a washroom added or a different facing? Customise to see the price instantly.</p>'
    + '</div></div>'
    + '<section class="similar" id="similar" hidden><h2>You might also like</h2><div class="similar-row" id="similarRow"></div></section>';

  document.getElementById('structCheck').addEventListener('change', e => { structAddon = e.target.checked; update(true); });
  const pkgToggle = document.getElementById('pkgToggle');
  pkgToggle.addEventListener('click', () => {
    const open = pkgToggle.getAttribute('aria-expanded') !== 'true';
    pkgToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.getElementById('pkgList').hidden = !open;
  });
  update(false);
}

// Only the parts that change with the toggle, so focus stays on the switch.
function update(changed){
  document.getElementById('addonCard').classList.toggle('on', structAddon);
  const total = document.getElementById('total');
  total.textContent = fmt(quote(design, [], false, structAddon).min);
  if(changed) flash(total);
  const q = plotQuery(userPlot);
  document.getElementById('buyBtn').href = 'order.php?id=' + design.id + '&struct=' + (structAddon ? 1 : 0) + (q ? '&' + q : '');
}

// Same BHK or same floors, excluding this design; exact BHK+floors matches first.
async function loadSimilar(){
  try {
    const res = await fetch('api/designs.php?' + plotQuery(userPlot));
    if(!res.ok) return;
    const all = (await res.json()).map(normDesign);
    const score = d => (d.bhk === design.bhk ? 1 : 0) + (d.floors === design.floors ? 1 : 0);
    const picks = all.filter(d => d.id !== design.id && score(d) > 0).sort((a, b) => score(b) - score(a)).slice(0, 3);
    if(!picks.length) return;
    const q = plotQuery(userPlot);
    document.getElementById('similarRow').innerHTML = picks.map((d, i) => designCard(d, q, i, false)).join('');
    document.getElementById('similar').hidden = false;
  } catch(e) { /* optional section -- stay hidden */ }
}

load();
</script>
</body>
</html>

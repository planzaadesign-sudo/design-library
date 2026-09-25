<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Design Detail &#8212; Planzaa</title>
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
let structAddon = false;

async function load(){
  const res = designId ? await fetch('api/design.php?id=' + designId) : null;
  if(!res || !res.ok){
    document.getElementById('app').innerHTML = '<h1>Design not found</h1><p class="section-gap">This design may have been removed.</p><a class="btn" href="index.php">← Back to library</a>';
    return;
  }
  design = normDesign(await res.json());
  design.struct_addon_price = structAddonPrice(design.base_price);
  document.title = design.name + ' — Planzaa';
  render();
}

function banner(){
  const hasPlot = userPlot.width && userPlot.length && userPlot.facing;
  if(!hasPlot) return '<div class="banner banner-neutral">Enter your plot on the <a href="index.php">library page</a> to check whether this design fits exactly.</div>';
  const isMatch = Number(userPlot.width) === design.plot_width && Number(userPlot.length) === design.plot_length && userPlot.facing === design.facing;
  const yours = esc(userPlot.width) + '×' + esc(userPlot.length) + ' ft, ' + esc(userPlot.facing) + ' facing';
  return isMatch
    ? '<div class="banner banner-match"><strong>Exact match.</strong> This design fits your ' + yours + ' plot with no modification needed.</div>'
    : '<div class="banner banner-amber"><strong>Needs modification.</strong> Your plot (' + yours + ') differs from this design’s base size or facing. Use <em>Customise this design</em> to adapt it.</div>';
}

function render(){
  const q = plotQuery(userPlot);
  document.getElementById('app').innerHTML =
    '<a class="back-link" href="index.php' + (q ? '?' + q : '') + '">← Back to library</a>'
    + '<div class="detail-layout" style="margin-top:14px">'
    + '<div class="detail-art">'
    +   '<div class="art-block"><div class="art-label">Front elevation</div>' + design.elevation_svg + '</div>'
    +   '<div class="art-block"><div class="art-label">Floor plan</div>' + design.floor_plan_svg + '</div>'
    + '</div>'
    + '<div class="detail-panel">'
    +   '<div><div class="eyebrow">Library design</div><h1>' + esc(design.name) + '</h1></div>'
    +   banner()
    +   '<dl class="spec-list spec-3">'
    +     '<div><dt>Plot size</dt><dd>' + design.plot_width + ' × ' + design.plot_length + ' ft</dd></div>'
    +     '<div><dt>Facing</dt><dd>' + esc(design.facing) + '</dd></div>'
    +     '<div><dt>Floors</dt><dd>' + esc(design.floors) + '</dd></div>'
    +     '<div><dt>Configuration</dt><dd>' + design.bhk + ' BHK</dd></div>'
    +     '<div><dt>Delivery</dt><dd>' + design.delivery_days + ' days</dd></div>'
    +   '</dl>'
    +   '<div><div class="section-title">What’s included</div><ul class="check-list">'
    +     '<li>Front &amp; side elevation</li><li>Dimensioned building plan</li><li>Grid layout</li><li>2–3 interior views</li>'
    +   '</ul></div>'
    +   '<div class="callout"><div class="section-title">What’s not included</div>'
    +     '<p class="muted" style="font-size:13px; margin:0 0 8px">Structural drawings are sold separately:</p>'
    +     '<ul class="check-list"><li>Column layout</li><li>Beam design</li><li>Footing detail</li><li>Bar bending schedule</li><li>Quantity estimate</li></ul>'
    +     '<div class="callout-art">' + design.struct_svg + '</div>'
    +   '</div>'
    +   '<label class="toggle-card' + (structAddon ? ' on' : '') + '" id="structToggle">'
    +     '<input type="checkbox" id="structCheck"' + (structAddon ? ' checked' : '') + '><span class="switch" aria-hidden="true"></span>'
    +     '<span><span class="toggle-title">Add structural design package (+' + fmt(design.struct_addon_price) + ')</span>'
    +     '<span class="toggle-sub" style="display:block">Column &amp; beam layout, footing detail, bar bending schedule and quantity estimate for this design.</span></span>'
    +   '</label>'
    +   '<div class="total-box"><span>Total</span><span class="price" id="total"></span></div>'
    +   '<div class="action-row">'
    +     '<a class="btn btn-primary" id="buyBtn" href="#">Buy this design</a>'
    +     '<a class="btn" href="customize.php?id=' + design.id + (q ? '&' + q : '') + '">Customise this design</a>'
    +   '</div>'
    +   '<p class="action-hint">Need a room resized, a washroom added or a different facing? Customise to see the price instantly.</p>'
    + '</div></div>';

  document.getElementById('structCheck').addEventListener('change', e => { structAddon = e.target.checked; update(); });
  update();
}

// Only the parts that change with the toggle, so focus stays on the checkbox.
function update(){
  document.getElementById('structToggle').classList.toggle('on', structAddon);
  document.getElementById('total').textContent = fmt(quote(design, [], false, structAddon).min);
  const q = plotQuery(userPlot);
  document.getElementById('buyBtn').href = 'order.php?id=' + design.id + '&struct=' + (structAddon ? 1 : 0) + (q ? '&' + q : '');
}

load();
</script>
</body>
</html>

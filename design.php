<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Design Detail \u2014 Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925">
</head>
<body>
<div class="topbar"><div class="topbar-inner"><div class="wordmark">planzaa<span>.</span> design library</div></div></div>
<div class="wrap" id="app"><p>Loading...</p></div>

<script>
function fmt(n){ return '\u20b9' + Number(n).toLocaleString('en-IN'); }
const params = new URLSearchParams(window.location.search);
const designId = params.get('id');
const pWidth = params.get('width');
const pLength = params.get('length');
const pFacing = params.get('facing');
let design = null;
let structAddon = false;

async function load(){
  const res = await fetch('api/design.php?id=' + encodeURIComponent(designId));
  if(!res.ok){ document.getElementById('app').innerHTML = '<p>Design not found.</p>'; return; }
  design = await res.json();
  render();
}

function render(){
  const hasPlot = pWidth && pLength && pFacing;
  const isMatch = hasPlot && Number(pWidth) === Number(design.plot_width) && Number(pLength) === Number(design.plot_length) && pFacing === design.facing;
  const banner = !hasPlot
    ? '<div class="banner badge-neutral">Enter your plot on the library page to check whether this design fits exactly.</div>'
    : isMatch
      ? '<div class="banner banner-match">This design fits your plot exactly \u2014 no modification needed.</div>'
      : '<div class="banner banner-nomatch">Your plot differs from this design\u2019s base size or facing. Contact us to discuss a modification.</div>';

  const total = design.base_price + (structAddon ? design.struct_addon_price : 0);

  document.getElementById('app').innerHTML =
    '<div class="detail-layout">'
    + '<div class="detail-art">' + design.elevation_svg + '<div style="margin-top:16px">' + design.floor_plan_svg + '</div></div>'
    + '<div class="detail-panel">'
    + '<h1>' + design.name + '</h1>'
    + banner
    + '<dl class="spec-list">'
    + '<div><dt>Plot size</dt><dd>' + design.plot_width + ' \u00d7 ' + design.plot_length + ' ft</dd></div>'
    + '<div><dt>Facing</dt><dd>' + design.facing + '</dd></div>'
    + '<div><dt>Floors</dt><dd>' + design.floors + '</dd></div>'
    + '<div><dt>Configuration</dt><dd>' + design.bhk + ' BHK</dd></div>'
    + '<div><dt>Includes</dt><dd>Elevation, dimensioned plan, grid layout</dd></div>'
    + '<div><dt>Structural drawings</dt><dd>Not included \u2014 optional add-on</dd></div>'
    + '</dl>'
    + '<div class="detail-art" style="padding:16px;">'
    + '<div>' + design.struct_svg + '</div>'
    + '<label style="display:flex; gap:8px; align-items:center; margin-top:10px; font-size:14px; color:var(--text);">'
    + '<input type="checkbox" id="structCheck" ' + (structAddon ? 'checked' : '') + ' onchange="toggleStruct()">'
    + 'Add the structural design package (+' + fmt(design.struct_addon_price) + ') \u2014 column &amp; beam layout, footing detail, bar bending schedule.'
    + '</label>'
    + '</div>'
    + '<div class="price-row"><span>Total</span><span class="price">' + fmt(total) + '</span></div>'
    + '<div id="orderArea"></div>'
    + '<a class="btn" href="index.php">\u2190 Back to library</a>'
    + '</div></div>';

  document.getElementById('orderArea').innerHTML =
    '<div class="order-form">'
    + '<h3 style="margin-bottom:12px">Place this order</h3>'
    + '<div class="field"><label>Your name</label><input id="ordName"></div>'
    + '<div class="field"><label>Phone number (10 digits)</label><input id="ordPhone" maxlength="10"></div>'
    + '<div class="field"><label>City</label><input id="ordCity"></div>'
    + '<button class="btn btn-primary btn-block" onclick="submitOrder()">Confirm order</button>'
    + '<div id="orderMsg"></div>'
    + '</div>';
}

function toggleStruct(){ structAddon = !structAddon; render(); }

async function submitOrder(){
  const payload = {
    design_id: design.id,
    customer_name: document.getElementById('ordName').value.trim(),
    customer_phone: document.getElementById('ordPhone').value.trim(),
    customer_city: document.getElementById('ordCity').value.trim(),
    plot_width: pWidth, plot_length: pLength, facing: pFacing,
    structural_addon: structAddon,
  };
  const res = await fetch('api/order.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  const msg = document.getElementById('orderMsg');
  if(!res.ok){
    msg.innerHTML = '<div class="error-note">' + data.error + '</div>';
    return;
  }
  msg.innerHTML = '<div class="toast">Order placed! Your order code is <strong>' + data.order_code + '</strong> \u2014 total ' + fmt(data.total_price) + '. Our team will be in touch.</div>';
}

load();
</script>
</body>
</html>

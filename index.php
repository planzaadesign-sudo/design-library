<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Design Library &#8212; Planzaa</title>
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
<div class="wrap">
  <h1>Sample design library</h1>
  <p class="section-gap">Tell us your plot and we'll flag which designs fit exactly, and which would need a modification.</p>
  <div class="plotbar">
    <div class="plot-title">Your plot</div>
    <div class="field"><label for="pWidth">Plot width (ft)</label><input type="number" id="pWidth" min="1"></div>
    <div class="field"><label for="pLength">Plot length (ft)</label><input type="number" id="pLength" min="1"></div>
    <div class="field"><label for="pFacing">Facing</label>
      <select id="pFacing">
        <option value="">Select</option>
        <option>East</option><option>West</option><option>North</option><option>South</option>
      </select>
    </div>
  </div>
  <div class="filterbar">
    <div class="field"><label for="fBhk">Bedrooms</label>
      <select id="fBhk"><option value="">All</option><option value="2">2 BHK</option><option value="3">3 BHK</option><option value="4">4 BHK</option></select>
    </div>
    <div class="field"><label for="fFloors">Floors</label>
      <select id="fFloors"><option value="">All</option><option>G</option><option>G+1</option><option>G+2</option></select>
    </div>
    <div class="spacer"></div>
    <div class="field"><label for="fSort">Sort by</label>
      <select id="fSort"><option value="">Featured</option><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option><option value="delivery">Delivery: fastest</option></select>
    </div>
  </div>
  <div class="results-meta" id="resultsMeta"></div>
  <div class="grid" id="grid"><p>Loading designs...</p></div>
</div>

<script src="assets/app.js?v=20260925b"></script>
<script>
const $ = id => document.getElementById(id);
let designs = [];
let loadSeq = 0;

// Restore the plot when coming back from a design page.
const initial = new URLSearchParams(window.location.search);
$('pWidth').value = initial.get('width') || '';
$('pLength').value = initial.get('length') || '';
$('pFacing').value = initial.get('facing') || '';

function plot(){ return {width:$('pWidth').value, length:$('pLength').value, facing:$('pFacing').value}; }

async function loadDesigns(){
  const seq = ++loadSeq;
  const res = await fetch('api/designs.php?' + plotQuery(plot()));
  const data = await res.json();
  if(seq !== loadSeq) return; // a newer request has already been sent
  designs = data.map(normDesign);
  history.replaceState(null, '', plotQuery(plot()) ? '?' + plotQuery(plot()) : 'index.php');
  render();
}

function render(){
  const bhk = $('fBhk').value, floors = $('fFloors').value, sort = $('fSort').value;
  let list = designs.filter(d => (!bhk || d.bhk === Number(bhk)) && (!floors || d.floors === floors));
  if(sort === 'price-asc') list.sort((a, b) => a.base_price - b.base_price);
  if(sort === 'price-desc') list.sort((a, b) => b.base_price - a.base_price);
  if(sort === 'delivery') list.sort((a, b) => a.delivery_days - b.delivery_days);

  const matches = list.filter(d => d.match === true).length;
  $('resultsMeta').textContent = list.length + ' design' + (list.length === 1 ? '' : 's')
    + (list.length && list[0].match !== null ? ' · ' + matches + ' exact match' + (matches === 1 ? '' : 'es') + ' for your plot' : '');

  if(!list.length){
    $('grid').innerHTML = '<div class="empty-state">No designs match these filters. Try widening the bedroom or floor filter.</div>';
    return;
  }
  const q = plotQuery(plot());
  $('grid').innerHTML = list.map(d => {
    const badge = d.match === null ? '<span class="badge badge-neutral">Enter your plot to check fit</span>'
      : d.match ? '<span class="badge badge-match">Exact match</span>'
      : '<span class="badge badge-amber">Needs modification</span>';
    return '<a class="card" href="design.php?id=' + d.id + (q ? '&' + q : '') + '">'
      + '<div class="card-art">' + d.floor_plan_svg + '</div>'
      + '<div class="card-body">'
      + '<div class="card-name">' + esc(d.name) + '</div>'
      + '<div class="meta-row"><span>' + d.plot_width + '×' + d.plot_length + ' ft</span><span>' + esc(d.facing) + ' facing</span><span>' + esc(d.floors) + '</span><span>' + d.bhk + ' BHK</span></div>'
      + badge
      + '<div class="price-row"><span class="price">' + fmt(d.base_price) + '</span><span class="delivery">' + d.delivery_days + '-day delivery</span></div>'
      + '</div></a>';
  }).join('');
}

let debounce;
['pWidth', 'pLength'].forEach(id => $(id).addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(loadDesigns, 250); }));
$('pFacing').addEventListener('change', loadDesigns);
['fBhk', 'fFloors', 'fSort'].forEach(id => $(id).addEventListener('change', render));
loadDesigns();
</script>
</body>
</html>

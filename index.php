<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>House Designs &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925f">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter">
  <section class="hero">
    <div class="eyebrow">Planzaa house designs</div>
    <h1>Ready-made house designs for your plot</h1>
    <p>Type your plot size below. We will show you which designs fit your plot, and which ones we can change to fit.</p>
  </section>

  <section class="plot-card" aria-labelledby="plotHeading">
    <h2 id="plotHeading"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17L17 3l4 4L7 21z"/><path d="M7 13l2 2M10 10l2 2M13 7l2 2"/></svg>Enter your plot details</h2>
    <div class="plot-fields">
      <div class="field"><label for="pWidth">Plot width (feet)</label><input type="number" id="pWidth" min="1" inputmode="numeric" placeholder="For example 30"></div>
      <div class="field"><label for="pLength">Plot length (feet)</label><input type="number" id="pLength" min="1" inputmode="numeric" placeholder="For example 40"></div>
      <div class="field"><label for="pFacing">Plot direction (facing)</label>
        <select id="pFacing">
          <option value="">Choose</option>
          <option>East</option><option>West</option><option>North</option><option>South</option>
        </select>
      </div>
    </div>
  </section>

  <section class="filters" aria-label="Filter designs">
    <div class="filter-row"><span class="filter-label" id="lblBhk">Bedrooms</span>
      <div class="pills" role="group" aria-labelledby="lblBhk" data-group="bhk">
        <button type="button" class="pill active" data-value="">All</button><button type="button" class="pill" data-value="2">2 BHK</button><button type="button" class="pill" data-value="3">3 BHK</button><button type="button" class="pill" data-value="4">4 BHK</button>
      </div>
    </div>
    <div class="filter-row"><span class="filter-label" id="lblFloors">Floors</span>
      <div class="pills" role="group" aria-labelledby="lblFloors" data-group="floors">
        <button type="button" class="pill active" data-value="">All</button><button type="button" class="pill" data-value="G">Ground only</button><button type="button" class="pill" data-value="G+1">Ground + 1</button><button type="button" class="pill" data-value="G+2">Ground + 2</button>
      </div>
    </div>
    <div class="filter-row"><span class="filter-label" id="lblFacing">Direction</span>
      <div class="pills" role="group" aria-labelledby="lblFacing" data-group="facing">
        <button type="button" class="pill active" data-value="">All</button><button type="button" class="pill" data-value="East">East</button><button type="button" class="pill" data-value="West">West</button><button type="button" class="pill" data-value="North">North</button><button type="button" class="pill" data-value="South">South</button>
      </div>
    </div>
  </section>

  <div class="results-bar">
    <span id="resultsMeta" aria-live="polite">Loading designs&#8230;</span>
    <button type="button" class="clear-all" id="clearAll">Clear filters</button>
  </div>
  <div class="dgrid enter" id="grid">
    <div class="skeleton" style="--i:0"><div class="sk sk-art"></div><div class="sk sk-line"></div><div class="sk sk-line short"></div></div>
    <div class="skeleton" style="--i:1"><div class="sk sk-art"></div><div class="sk sk-line"></div><div class="sk sk-line short"></div></div>
    <div class="skeleton" style="--i:2"><div class="sk sk-art"></div><div class="sk sk-line"></div><div class="sk sk-line short"></div></div>
  </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
const $ = id => document.getElementById(id);
let designs = [];
let loadSeq = 0;
let firstRender = true;
const filters = {bhk:'', floors:'', facing:''};
const lastMatch = {}; // design id -> last badge state, so only changed badges animate

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
  render(firstRender);
  firstRender = false;
}

function render(animate){
  let list = designs.filter(d =>
    (!filters.bhk || d.bhk === Number(filters.bhk)) &&
    (!filters.floors || d.floors === filters.floors) &&
    (!filters.facing || d.facing === filters.facing));

  const active = filters.bhk || filters.floors || filters.facing;
  $('clearAll').classList.toggle('show', !!active);
  const matches = list.filter(d => d.match === true).length;
  $('resultsMeta').innerHTML = 'Showing <strong>' + list.length + '</strong> of ' + designs.length + ' designs'
    + (designs.length && designs[0].match !== null ? ' · ' + matches + ' fit' + (matches === 1 ? 's' : '') + ' your plot exactly' : '');

  const grid = $('grid');
  grid.classList.toggle('enter', !!animate);
  if(!list.length){
    grid.innerHTML = '<div class="empty-state"><strong>No designs match what you picked.</strong><br>Try another option, or <button type="button" class="clear-all show" onclick="clearFilters()">clear filters</button>.</div>';
    return;
  }
  const q = plotQuery(plot());
  grid.innerHTML = list.map((d, i) => {
    const changed = d.match !== null && lastMatch[d.id] !== d.match;
    lastMatch[d.id] = d.match;
    return designCard(d, q, i, changed && !animate);
  }).join('');
}

document.querySelectorAll('.pills').forEach(group => {
  group.querySelectorAll('.pill').forEach(p => p.setAttribute('aria-pressed', p.classList.contains('active') ? 'true' : 'false'));
  group.addEventListener('click', e => {
    const pill = e.target.closest('.pill');
    if(!pill) return;
    filters[group.dataset.group] = pill.dataset.value;
    syncPills();
    render(true);
  });
});

function syncPills(){
  document.querySelectorAll('.pills').forEach(group => {
    group.querySelectorAll('.pill').forEach(p => {
      const on = p.dataset.value === filters[group.dataset.group];
      p.classList.toggle('active', on);
      p.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  });
}

function clearFilters(){
  filters.bhk = filters.floors = filters.facing = '';
  syncPills();
  render(true);
}
$('clearAll').addEventListener('click', clearFilters);

let debounce;
['pWidth', 'pLength'].forEach(id => $(id).addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(loadDesigns, 250); }));
$('pFacing').addEventListener('change', loadDesigns);
loadDesigns();
</script>
</body>
</html>

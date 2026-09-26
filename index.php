<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>House Designs &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260927a">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter">
  <section class="hero">
    <div class="eyebrow">Planzaa house designs</div>
    <h1>Ready-made house designs for your plot</h1>
    <p>Type your plot size below. We will show you which designs fit your plot, and which ones we can change to fit.</p>
  </section>

  <section class="plot-card finder" aria-labelledby="plotHeading">
    <h2 id="plotHeading"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17L17 3l4 4L7 21z"/><path d="M7 13l2 2M10 10l2 2M13 7l2 2"/></svg>Find designs for your plot</h2>
    <div class="plot-fields two">
      <div class="field"><label for="pWidth">Plot width (feet)</label><input type="number" id="pWidth" min="1" inputmode="numeric" placeholder="For example 30"></div>
      <div class="field"><label for="pLength">Plot length (feet)</label><input type="number" id="pLength" min="1" inputmode="numeric" placeholder="For example 40"></div>
    </div>
    <div class="filters in-card" aria-label="Filter designs">
      <div class="filter-row"><span class="filter-label" id="lblFacing">Direction</span>
        <div class="pills" role="group" aria-labelledby="lblFacing" data-group="facing">
          <button type="button" class="pill active" data-value="">All</button><button type="button" class="pill" data-value="East">East</button><button type="button" class="pill" data-value="West">West</button><button type="button" class="pill" data-value="North">North</button><button type="button" class="pill" data-value="South">South</button>
        </div>
      </div>
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
    </div>
  </section>

  <div class="results-bar">
    <label class="sort-by" for="sortBy">Sort by
      <select id="sortBy">
        <option value="">Best match first</option>
        <option value="price_asc">Price: low to high</option>
        <option value="price_desc">Price: high to low</option>
        <option value="size_asc">Plot size: small to large</option>
        <option value="size_desc">Plot size: large to small</option>
      </select>
    </label>
    <span class="results-right"><span id="resultsMeta" aria-live="polite">Loading designs&#8230;</span><button type="button" class="clear-all" id="clearAll">Clear filters</button></span>
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
// The Direction pills are the plot's facing: they filter the list AND are sent to the server for the
// "Fits your plot exactly" check (the same value the old facing dropdown sent).
const FACINGS = ['East', 'West', 'North', 'South'];
if(FACINGS.includes(initial.get('facing'))) filters.facing = initial.get('facing');
if(['price_asc', 'price_desc', 'size_asc', 'size_desc'].includes(initial.get('sort'))) $('sortBy').value = initial.get('sort');

function plot(){ return {width:$('pWidth').value, length:$('pLength').value, facing:filters.facing}; }

// Sorting happens in the browser only. "Best match" keeps the server's order, exact fits first.
const SORTS = {
  '': (a, b) => (b.match === true) - (a.match === true),
  price_asc: (a, b) => a.base_price - b.base_price,
  price_desc: (a, b) => b.base_price - a.base_price,
  size_asc: (a, b) => a.plot_width * a.plot_length - b.plot_width * b.plot_length,
  size_desc: (a, b) => b.plot_width * b.plot_length - a.plot_width * a.plot_length,
};

async function loadDesigns(){
  const seq = ++loadSeq;
  const res = await fetch('api/designs.php?' + plotQuery(plot()));
  const data = await res.json();
  if(seq !== loadSeq) return; // a newer request has already been sent
  designs = data.map(normDesign);
  syncUrl();
  render(firstRender);
  firstRender = false;
}

// Keeps the plot, direction and sort in the address, so Back from a design page restores them.
function syncUrl(){
  const q = new URLSearchParams(plotQuery(plot()));
  if($('sortBy').value) q.set('sort', $('sortBy').value);
  history.replaceState(null, '', q.toString() ? '?' + q : 'index.php');
}

function render(animate){
  let list = designs.filter(d =>
    (!filters.bhk || d.bhk === Number(filters.bhk)) &&
    (!filters.floors || d.floors === filters.floors) &&
    (!filters.facing || d.facing === filters.facing));
  list = list.map((d, i) => [d, i]).sort((x, y) => SORTS[$('sortBy').value || ''](x[0], y[0]) || x[1] - y[1]).map(x => x[0]);

  const active = filters.bhk || filters.floors || filters.facing;
  $('clearAll').classList.toggle('show', !!active);
  const matches = list.filter(d => d.match === true).length;
  $('resultsMeta').innerHTML = 'Showing <strong>' + list.length + '</strong> of ' + designs.length + ' designs'
    + (designs.length && designs[0].match !== null ? ' · ' + matches + ' fit' + (matches === 1 ? 's' : '') + ' your plot exactly'
       : ($('pWidth').value && $('pLength').value && !filters.facing ? ' · Pick your plot direction to see exact fits' : ''));

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
    const key = group.dataset.group;
    if(filters[key] === pill.dataset.value) return;
    filters[key] = pill.dataset.value;
    syncPills();
    if(key === 'facing') loadDesigns(); // new direction = new exact-fit check
    else render(true);
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
  const hadFacing = !!filters.facing;
  filters.bhk = filters.floors = filters.facing = '';
  syncPills();
  if(hadFacing) loadDesigns(); else render(true);
}
$('clearAll').addEventListener('click', clearFilters);

let debounce;
['pWidth', 'pLength'].forEach(id => $(id).addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(loadDesigns, 250); }));
$('sortBy').addEventListener('change', () => { syncUrl(); render(true); });
syncPills();
loadDesigns();
</script>
</body>
</html>

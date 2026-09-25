<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Design Library \u2014 Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="topbar"><div class="topbar-inner"><div class="wordmark">planzaa<span>.</span> design library</div></div></div>
<div class="wrap">
  <h1>Sample design library</h1>
  <p class="section-gap">Tell us your plot and we'll flag which designs fit exactly, and which would need a modification.</p>
  <div class="plotbar">
    <div class="field"><label>Plot width (ft)</label><input type="number" id="pWidth"></div>
    <div class="field"><label>Plot length (ft)</label><input type="number" id="pLength"></div>
    <div class="field"><label>Facing</label>
      <select id="pFacing">
        <option value="">Select</option>
        <option>East</option><option>West</option><option>North</option><option>South</option>
      </select>
    </div>
  </div>
  <div class="grid" id="grid"><p>Loading designs...</p></div>
</div>

<script>
function fmt(n){ return '\u20b9' + Number(n).toLocaleString('en-IN'); }

async function loadDesigns(){
  const w = document.getElementById('pWidth').value;
  const l = document.getElementById('pLength').value;
  const f = document.getElementById('pFacing').value;
  const params = new URLSearchParams();
  if(w) params.set('width', w);
  if(l) params.set('length', l);
  if(f) params.set('facing', f);

  const res = await fetch('api/designs.php?' + params.toString());
  const designs = await res.json();
  const grid = document.getElementById('grid');

  grid.innerHTML = designs.map(d => {
    const badge = d.match === null ? '<span class="badge badge-neutral">Enter your plot to check fit</span>'
      : d.match ? '<span class="badge badge-match">Exact match</span>'
      : '<span class="badge badge-nomatch">Needs modification</span>';
    const link = 'design.php?id=' + d.id + (w ? '&width=' + w : '') + (l ? '&length=' + l : '') + (f ? '&facing=' + f : '');
    return '<a class="card" href="' + link + '">'
      + '<div class="card-art">' + d.floor_plan_svg + '</div>'
      + '<div class="card-body">'
      + '<div class="card-name">' + d.name + '</div>'
      + '<div class="meta-row"><span>' + d.plot_width + '\u00d7' + d.plot_length + ' ft</span><span>' + d.facing + ' facing</span><span>' + d.floors + '</span><span>' + d.bhk + ' BHK</span></div>'
      + badge
      + '<div class="price-row"><span class="price">' + fmt(d.base_price) + '</span><span>' + d.delivery_days + ' day delivery</span></div>'
      + '</div></a>';
  }).join('');
}

document.getElementById('pWidth').addEventListener('input', loadDesigns);
document.getElementById('pLength').addEventListener('input', loadDesigns);
document.getElementById('pFacing').addEventListener('change', loadDesigns);
loadDesigns();
</script>
</body>
</html>

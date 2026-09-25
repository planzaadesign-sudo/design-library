<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Change a Design &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260925e">
</head>
<body class="site">
<?php include __DIR__ . '/partials/nav.php'; ?>
<main class="wrap page-enter" id="app"><p class="muted">Loading&#8230;</p></main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
const params = new URLSearchParams(window.location.search);
const designId = parseInt(params.get('id'), 10);
const userPlot = {width:params.get('width') || '', length:params.get('length') || '', facing:params.get('facing') || ''};

let design = null;
let groups = [];          // [{tier, items:[...]}] from api/modifications.php
let modsById = {};
let floors = [];          // [{floor, rooms:[...]}] from api/rooms.php
let roomList = [];        // the same rooms, flat, in display order
// Structural choice from Step 1; drives every modification price below.
let structural = params.get('structural') !== '0';
// Coming back from the order page restores the previous selection.
const selected = new Set((params.get('mods') || '').split(',').map(Number).filter(Boolean));
// Per-change choices: {modId: {rooms:{roomId:{act, note, to, on}}, scheme, custom, text}}
let details = {};
let step = selected.size ? 2 : 1;
let lastTotal = null;
const shown = {warn:false, review:false}; // notes already on screen don't replay their entrance

async function load(){
  const [dRes, mRes, rRes] = await Promise.all([
    designId ? fetch('api/design.php?id=' + designId) : null,
    fetch('api/modifications.php'),
    designId ? fetch('api/rooms.php?design_id=' + designId).catch(() => null) : null,
  ]);
  if(!dRes || !dRes.ok || !mRes.ok){
    document.getElementById('app').innerHTML = '<h1>We could not find this design</h1><p class="muted" style="margin:10px 0 20px">It may have been removed. Please pick another design.</p><a class="btn" href="index.php">← Back to all designs</a>';
    return;
  }
  design = normDesign(await dRes.json());
  groups = await mRes.json();
  groups.forEach(g => g.items.forEach(m => { modsById[m.id] = m; }));
  // No room list (or rooms not set up yet) still works: the customer describes the rooms in words.
  try { floors = rRes && rRes.ok ? await rRes.json() : []; } catch(e) { floors = []; }
  roomList = [].concat(...floors.map(f => f.rooms));
  [...selected].forEach(id => { if(!modsById[id]) selected.delete(id); });
  const saved = readConfig();
  if(saved && saved.design_id === design.id && saved.state) details = saved.state;
  document.title = 'Change ' + design.name + ' — Planzaa';
  renderShell();
  selected.forEach(id => openPanel(id));
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
          + '<input type="checkbox" value="' + m.id + '"' + (selected.has(m.id) ? ' checked' : '') + (needsDetails(m) ? ' aria-controls="detail' + m.id + '"' : '') + '>'
          + '<span><span class="mod-label">' + esc(m.label) + '</span>'
          + (m.added_days ? '<span class="mod-days">Adds ' + m.added_days + ' day' + (m.added_days === 1 ? '' : 's') + ' to the work</span>' : '')
          + '</span>'
          + '<span class="mod-price" data-price-for="' + m.id + '"></span>'
          + '</label>'
          + (needsDetails(m) ? '<div class="mod-detail" id="detail' + m.id + '" data-detail-for="' + m.id + '" hidden></div>' : '')).join('')
      + '</div></div>';
  }).join('');

  document.getElementById('app').innerHTML =
    '<nav class="breadcrumb" aria-label="Breadcrumb"><a href="design.php?id=' + design.id + (q ? '&' + q : '') + '">← Back to design</a><span aria-hidden="true">/</span><span class="current">Change this design</span></nav>'
    + '<div class="config-head">'
    +   '<div class="thumb">' + design.floor_plan_svg + '</div>'
    +   '<div><div class="eyebrow">Change this design</div><h1>' + esc(design.name) + '</h1>'
    +   '<div class="meta">' + design.plot_width + '×' + design.plot_length + ' ft plot · ' + esc(design.facing) + ' facing · ' + esc(floorsLabel(design.floors)) + ' · ' + design.bhk + ' BHK · Design price ' + fmt(design.base_price) + '</div></div>'
    + '</div>'
    + '<div class="steps-bar">'
    +   '<div class="steps-text" id="stepsText" aria-live="polite"></div>'
    +   '<div class="steps-track"><button type="button" class="steps-seg on" data-goto="step1" aria-label="Go to step 1"><i></i></button><button type="button" class="steps-seg" data-goto="step2" aria-label="Go to step 2"><i></i></button></div>'
    + '</div>'
    + '<div class="config-layout">'
    +   '<div>'
    +     '<div class="step-label" id="step1"><span class="step-num">1</span><h2>Do you want building safety drawings?</h2></div>'
    +     '<div class="choice-grid">'
    +       choiceCard(1, 'Yes, include building safety drawings', 'An engineer checks that your changes are safe. You get column (pillar), beam and foundation drawings for them.', 'Recommended')
    +       choiceCard(0, 'Design only — no building safety drawings', 'Costs less. You will need to get your own engineer to check safety before you start building.')
    +     '</div>'
    +     '<div class="step-label" id="step2"><span class="step-num">2</span><h2>What do you want to change?</h2></div>'
    +     '<p class="muted" style="font-size:14px; margin:-6px 0 14px">Tick the changes you want. For some changes you then pick the rooms. The price updates right away.</p>'
    +     tiersHtml
    +     '<div class="help-banner"><span class="ic-wrap">' + icon('phone', 'ic-lg') + '</span>'
    +       '<span><strong>Not sure what to pick?</strong>Call us on <a href="' + CONTACT.tel + '">' + CONTACT.phone + '</a> or <a href="' + CONTACT.whatsapp + '" target="_blank" rel="noopener">message us on WhatsApp</a>.</span></div>'
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
    if(cb.checked){ selected.add(id); openPanel(id, true); }
    else { selected.delete(id); closePanel(id); }
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

  // One listener for every room picker, colour card and note box.
  const app = document.getElementById('app');
  app.addEventListener('click', onDetailClick);
  app.addEventListener('input', onDetailInput);
  app.addEventListener('change', onDetailInput);
  setStep(step);
}

function setStep(n){
  if(n < step) return;
  step = n;
  document.getElementById('stepsText').innerHTML = step === 1
    ? '<strong>Step 1 of 2</strong> — Choose building safety drawings'
    : '<strong>Step 2 of 2</strong> — Pick your changes';
  document.querySelectorAll('.steps-seg')[1].classList.toggle('on', step === 2);
}

// ---- Room pickers -------------------------------------------------------------

function stateFor(id){
  if(!details[id]) details[id] = {rooms:{}, scheme:'', custom:'', text:''};
  return details[id];
}

function openPanel(id, focusFirst){
  const panel = document.querySelector('[data-detail-for="' + id + '"]');
  if(!panel) return;
  if(!panel.innerHTML) panel.innerHTML = panelHtml(modsById[id]);
  panel.hidden = false;
  panel.classList.remove('enter'); void panel.offsetWidth; panel.classList.add('enter');
  syncPanel(id);
  if(focusFirst){ const first = panel.querySelector('button, input, select, textarea'); if(first) first.focus({preventScroll:true}); }
}

function closePanel(id){
  const panel = document.querySelector('[data-detail-for="' + id + '"]');
  if(panel) panel.hidden = true;
}

function seg(options){
  return '<div class="seg" role="group">' + options.map(o => '<button type="button" data-act="' + o[0] + '" aria-pressed="false">' + o[1] + '</button>').join('') + '</div>';
}

function roomsByFloor(filter, rowHtml){
  return floors.map(f => {
    const rooms = f.rooms.filter(filter);
    if(!rooms.length) return '';
    return '<div class="floor-block"><div class="floor-name">' + esc(f.floor) + '</div>' + rooms.map(r =>
      '<div class="room-row" data-room="' + r.id + '"><div class="room-name">' + esc(r.room_name)
      + (r.current_size_sqft ? ' <span>' + r.current_size_sqft + ' sq ft</span>' : '') + '</div>' + rowHtml(r) + '</div>').join('') + '</div>';
  }).join('');
}

function panelHtml(m){
  const kind = m.detail_type;
  const per = '<span data-unit-for="' + m.id + '"></span>';
  if(ROOM_KINDS.includes(kind) && !roomList.length){
    return '<p class="detail-help">Tell us which rooms you want to change and how. Price: ' + per + ' per room.</p>'
      + '<textarea data-text rows="3" maxlength="1000" placeholder="For example: make the kitchen bigger and the bedroom on the first floor smaller"></textarea>';
  }
  if(kind === 'resize'){
    return '<p class="detail-help">Pick the rooms you want to change. You pay ' + per + ' for each room.</p>'
      + roomsByFloor(() => true, () => seg([['increase', 'Make it bigger'], ['decrease', 'Make it smaller'], ['other', 'Other']])
        + '<input class="room-note" data-note maxlength="300" placeholder="Tell us what you want" hidden>');
  }
  if(kind === 'partition'){
    return '<p class="detail-help">Type in the rooms where you want a wall moved. You pay ' + per + ' for each room.</p>'
      + roomsByFloor(() => true, () => '<input class="room-note" data-note maxlength="300" placeholder="Which wall and where should it move?">');
  }
  if(kind === 'washroom'){
    const bedrooms = roomList.filter(r => r.room_type === 'bedroom');
    if(!bedrooms.length) return '<p class="detail-help">This design has no bedrooms listed. Please tell us about it under “Any other changes you want”.</p>';
    return '<p class="detail-help">Pick the bedrooms that should get their own bathroom. You pay ' + per + ' for each bathroom.</p>'
      + roomsByFloor(r => r.room_type === 'bedroom', () => '<label class="room-check"><input type="checkbox" data-wash> Add a bathroom to this room</label>');
  }
  if(kind === 'opening'){
    return '<p class="detail-help">Pick the rooms that need a new door or window. You pay ' + per + ' for each room.</p>'
      + roomsByFloor(() => true, () => seg([['door', 'Add a door'], ['window', 'Add a window'], ['both', 'Both']])
        + '<input class="room-note" data-note maxlength="300" placeholder="Which wall? (for example: the wall facing the road)" hidden>');
  }
  if(kind === 'relabel'){
    return '<p class="detail-help">Choose a new use for any room. You pay ' + per + ' for each room.</p>'
      + roomsByFloor(() => true, r => '<div class="room-use"><span class="now">Now: ' + esc(ROOM_TYPE_LABEL[r.room_type] || r.room_type) + '</span>'
        + '<select data-to aria-label="New use for ' + esc(r.room_name) + '"><option value="">Keep as it is</option>'
        + ROOM_USES.map(u => '<option>' + u + '</option>').join('') + '</select></div>');
  }
  if(kind === 'colour'){
    return '<p class="detail-help">Pick a colour scheme for the outside of your house.</p>'
      + '<div class="swatch-grid">' + COLOUR_SCHEMES.map(s =>
          '<button type="button" class="swatch" data-scheme="' + esc(s.name) + '" aria-pressed="false">'
          + '<span class="swatch-bars">' + s.colors.map(c => '<i style="background:' + c + '"></i>').join('') + '</span>'
          + '<span class="swatch-name">' + esc(s.name) + '</span></button>').join('') + '</div>'
      + '<label class="detail-label" for="custom' + m.id + '">Have something else in mind? Describe it here</label>'
      + '<input id="custom' + m.id + '" class="room-note" data-custom maxlength="300" placeholder="For example: light yellow walls with white borders">';
  }
  if(kind === 'other'){
    return '<label class="detail-label" for="other' + m.id + '">Tell us what else you’d like to change — we’ll review and let you know the cost</label>'
      + '<textarea id="other' + m.id + '" data-text rows="4" maxlength="1000" placeholder="For example: I want a bigger window in the living room and a shoe rack near the door"></textarea>';
  }
  return '';
}

// Put the saved choices back into a panel's buttons and fields.
function syncPanel(id){
  const panel = document.querySelector('[data-detail-for="' + id + '"]');
  if(!panel || !panel.innerHTML) return;
  const st = stateFor(id);
  panel.querySelectorAll('.room-row').forEach(row => {
    const s = st.rooms[row.dataset.room] || {};
    row.querySelectorAll('[data-act]').forEach(b => {
      const on = b.dataset.act === s.act;
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    const note = row.querySelector('[data-note]');
    if(note){
      if(note.value !== (s.note || '')) note.value = s.note || '';
      const kind = modsById[id].detail_type;
      if(kind === 'resize') note.hidden = s.act !== 'other';
      if(kind === 'opening') note.hidden = !s.act;
    }
    const wash = row.querySelector('[data-wash]');
    if(wash) wash.checked = !!s.on;
    const to = row.querySelector('[data-to]');
    if(to && to.value !== (s.to || '')) to.value = s.to || '';
    row.classList.toggle('picked', isPicked(modsById[id].detail_type, s));
  });
  panel.querySelectorAll('[data-scheme]').forEach(b => {
    const on = b.dataset.scheme === st.scheme;
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  const custom = panel.querySelector('[data-custom]');
  if(custom && custom.value !== st.custom) custom.value = st.custom;
  const text = panel.querySelector('[data-text]');
  if(text && text.value !== st.text) text.value = st.text;
}

function isPicked(kind, s){
  if(kind === 'resize' || kind === 'opening') return !!s.act;
  if(kind === 'partition') return !!(s.note || '').trim();
  if(kind === 'washroom') return !!s.on;
  if(kind === 'relabel') return !!s.to;
  return false;
}

function panelOf(el){
  const panel = el.closest('[data-detail-for]');
  return panel ? Number(panel.dataset.detailFor) : null;
}

function onDetailClick(e){
  const btn = e.target.closest('[data-act], [data-scheme]');
  if(!btn) return;
  const id = panelOf(btn);
  if(id === null) return;
  const st = stateFor(id);
  if(btn.dataset.scheme !== undefined){
    st.scheme = st.scheme === btn.dataset.scheme ? '' : btn.dataset.scheme;
  } else {
    const rid = btn.closest('.room-row').dataset.room;
    const s = st.rooms[rid] = st.rooms[rid] || {};
    s.act = s.act === btn.dataset.act ? '' : btn.dataset.act;
    if(s.act === 'other' || (modsById[id].detail_type === 'opening' && s.act)){
      syncPanel(id);
      const note = btn.closest('.room-row').querySelector('[data-note]');
      if(note && !note.hidden) note.focus();
    }
  }
  syncPanel(id);
  update();
}

function onDetailInput(e){
  const el = e.target;
  if(!el.matches('[data-note], [data-wash], [data-to], [data-custom], [data-text]')) return;
  const id = panelOf(el);
  if(id === null) return;
  const st = stateFor(id);
  const row = el.closest('.room-row');
  if(row){
    const s = st.rooms[row.dataset.room] = st.rooms[row.dataset.room] || {};
    if(el.matches('[data-note]')) s.note = el.value;
    if(el.matches('[data-wash]')) s.on = el.checked;
    if(el.matches('[data-to]')) s.to = el.value;
    row.classList.toggle('picked', isPicked(modsById[id].detail_type, s));
  }
  if(el.matches('[data-custom]')) st.custom = el.value;
  if(el.matches('[data-text]')) st.text = el.value;
  update();
}

// What the customer picked for one change, in the shape api/order.php expects
// (plus display text). Room-based changes give one entry per room.
function entriesFor(m){
  const st = stateFor(m.id);
  const kind = m.detail_type;
  const entry = (room, action, note, text, incomplete) => ({modification_id:m.id, room_id:room ? room.id : null, action, custom_note:note || null, text, incomplete:!!incomplete});
  if(ROOM_KINDS.includes(kind) && !roomList.length){
    const t = (st.text || '').trim();
    return t ? [entry(null, 'other', t, t)] : [];
  }
  if(ROOM_KINDS.includes(kind)){
    const out = [];
    roomList.forEach(r => {
      const s = st.rooms[r.id];
      if(!s || !isPicked(kind, s)) return;
      const note = (s.note || '').trim();
      if(kind === 'resize'){
        const what = s.act === 'increase' ? 'make it bigger' : s.act === 'decrease' ? 'make it smaller' : (note ? note : 'tell us what you want');
        out.push(entry(r, s.act, s.act === 'other' ? note : '', r.room_name + ' — ' + what, s.act === 'other' && !note));
      }
      if(kind === 'partition') out.push(entry(r, 'other', note, r.room_name + ' — ' + note));
      if(kind === 'washroom') out.push(entry(r, 'add', '', r.room_name + ' — add a bathroom'));
      if(kind === 'opening'){
        const what = s.act === 'door' ? 'Add a door' : s.act === 'window' ? 'Add a window' : 'Add a door and a window';
        const full = what + (note ? ' — wall: ' + note : '');
        out.push(entry(r, 'add', full, r.room_name + ' — ' + full.charAt(0).toLowerCase() + full.slice(1)));
      }
      if(kind === 'relabel') out.push(entry(r, 'other', 'Change to: ' + s.to, r.room_name + ' → ' + s.to));
    });
    return out;
  }
  if(kind === 'colour'){
    const custom = (st.custom || '').trim();
    const note = st.scheme ? st.scheme + (custom ? ' — ' + custom : '') : custom;
    return note ? [entry(null, null, note, 'Colours: ' + note)] : [];
  }
  if(kind === 'other'){
    const t = (st.text || '').trim();
    return t ? [entry(null, null, t, t)] : [];
  }
  return [];
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
    const unit = document.querySelector('[data-unit-for="' + m.id + '"]');
    if(unit) unit.textContent = fmt(effectiveModPrice(m, structural));
  });
  groups.forEach(g => {
    const n = g.items.filter(m => selected.has(m.id)).length;
    document.querySelector('[data-count-for="' + g.tier + '"]').textContent = n ? n + ' selected' : '';
  });

  const entriesByMod = {};
  const unfinished = [];
  const mods = selectedMods().map(m => {
    if(!needsDetails(m)) return m;
    const entries = entriesFor(m);
    entriesByMod[m.id] = entries;
    if(!entries.length || entries.some(e => e.incomplete)) unfinished.push(m);
    return ROOM_KINDS.includes(m.detail_type) ? Object.assign({}, m, {qty:entries.length}) : m;
  });
  const q = quote(design, mods, structural, false);

  const next = new URLSearchParams(plotQuery(userPlot));
  next.set('id', design.id);
  next.set('mode', 'custom');
  next.set('structural', structural ? '1' : '0');
  next.set('mods', mods.map(m => m.id).join(','));
  // The order page reads the room details from here (they are too long for the URL).
  saveConfig({
    design_id:design.id, structural, mods:mods.map(m => m.id), state:details,
    details:[].concat(...Object.values(entriesByMod)).filter(e => !e.incomplete),
  });

  const canGo = mods.length && !unfinished.length;
  const total = totalLabel(q);
  document.getElementById('summary').innerHTML =
    '<h3>Your order</h3>'
    + '<div class="sum-line"><span>' + esc(design.name) + ' (design price)</span><span>' + fmt(design.base_price) + '</span></div>'
    + '<div class="sum-line sub"><span>' + STRUCT_NAME + '</span><span>' + (structural ? 'Included' : 'Not included') + '</span></div>'
    + '<div class="sum-mods">' + summaryModLines(mods, entriesByMod, structural) + '</div>'
    + '<div class="sum-total"><span>' + (q.isRange ? 'Approx. price' : 'Total price') + '</span><span class="price flash' + (q.isRange ? ' range' : '') + '" id="sumTotal">' + total + '</span></div>'
    + '<div class="sum-days">Ready in about ' + q.days + ' days</div>'
    + (q.structuralWarning ? '<div class="note note-warn' + (shown.warn ? ' still' : '') + '">' + icon('warning') + '<span>Some of your changes affect the building structure. We suggest you add building safety drawings (Step 1).</span></div>' : '')
    + (q.needsReview ? '<div class="note note-danger' + (shown.review ? ' still' : '') + '">' + icon('info') + '<span>These changes are big — our team will check and tell you the exact price within 24 hours.</span></div>' : '')
    + '<a class="btn btn-primary btn-block lift' + (canGo ? '' : ' disabled') + '" href="order.php?' + next.toString() + '"' + (canGo ? '' : ' aria-disabled="true" tabindex="-1"') + '>'
    +   (q.needsReview ? 'Send to our team for pricing' : 'Place my order') + '</a>'
    + (!mods.length ? '<p class="helper">Tick at least one change to continue. Or <a href="design.php?id=' + design.id + (plotQuery(userPlot) ? '&' + plotQuery(userPlot) : '') + '">buy this design as it is</a>.</p>' : '')
    + (unfinished.length ? '<p class="helper todo">Please finish: ' + unfinished.map(m => esc(m.label)).join('; ') + '.</p>' : '');

  shown.warn = q.structuralWarning; shown.review = q.needsReview;
  if(lastTotal !== null && lastTotal !== total) flash(document.getElementById('sumTotal'));
  lastTotal = total;
}

load();
</script>
</body>
</html>

// Quotation builder for call-back orders (in-house order page).
// The same pickers as the customer's customize.php (structural choice, tiers, room-by-room changes,
// colours, "let our architect decide"), operated by the team member during/after the call.
// Uses the shared helpers in assets/app.js (quote, summaryModLines, icon, fmt, esc...).
// Prices shown here are for display only: the server prices the quotation again before saving it.
(function () {
  const root = document.getElementById('quoteBuilder');
  if (!root) return;
  const API = root.dataset.api;
  const design = normDesign(JSON.parse(root.dataset.design));
  const STORE = 'planzaa_quote_' + root.dataset.order + '_' + design.id;
  const form = document.getElementById('quoteForm');

  let groups = [], modsById = {}, floors = [], roomList = [];
  let structural = true;
  const selected = new Set();
  let details = {};
  let lastTotal = null;

  // Keep the work if the page reloads (for example after a server-side check fails).
  try {
    const saved = JSON.parse(sessionStorage.getItem(STORE) || 'null');
    if (saved) { structural = !!saved.structural; (saved.selected || []).forEach(id => selected.add(id)); details = saved.details || {}; }
  } catch (e) {}
  const persist = () => { try { sessionStorage.setItem(STORE, JSON.stringify({ structural, selected: [...selected], details })); } catch (e) {} };

  async function load() {
    try {
      const [mRes, rRes] = await Promise.all([fetch(API + 'modifications.php'), fetch(API + 'rooms.php?design_id=' + design.id).catch(() => null)]);
      groups = await mRes.json();
      try { floors = rRes && rRes.ok ? await rRes.json() : []; } catch (e) { floors = []; }
    } catch (e) {
      root.innerHTML = '<p class="form-error">Could not load the list of changes. Please reload the page.</p>';
      return;
    }
    groups.forEach(g => g.items.forEach(m => { modsById[m.id] = m; }));
    roomList = [].concat(...floors.map(f => f.rooms));
    [...selected].forEach(id => { if (!modsById[id]) selected.delete(id); });
    render();
    selected.forEach(id => openPanel(id));
    update();
  }

  function choiceCard(value, title, desc, badge) {
    return '<button type="button" class="choice-card" data-structural="' + value + '" aria-pressed="false">'
      + '<span class="radio" aria-hidden="true"></span>'
      + '<div class="choice-title">' + title + (badge ? ' <span class="rec-badge">' + badge + '</span>' : '') + '</div>'
      + '<div class="choice-desc">' + desc + '</div></button>';
  }

  function render() {
    const tiersHtml = groups.map(g => {
      const t = TIERS[g.tier] || { title: 'Tier ' + g.tier, sub: '', cls: '' };
      return '<div class="tier-group ' + t.cls + '">'
        + '<button type="button" class="tier-head" aria-expanded="true" aria-controls="qtier' + g.tier + '">'
        + '<span class="dot" aria-hidden="true"></span><strong>' + esc(t.title) + '</strong><span class="tier-sub">' + esc(t.sub) + '</span>'
        + '<span class="tier-count" data-count-for="' + g.tier + '"></span>' + icon('chevron', 'chev') + '</button>'
        + '<div class="mod-list" id="qtier' + g.tier + '">' + g.items.map(m =>
            '<label class="mod-row" data-id="' + m.id + '">'
            + '<input type="checkbox" value="' + m.id + '"' + (selected.has(m.id) ? ' checked' : '') + '>'
            + '<span><span class="mod-label">' + esc(m.label) + '</span>'
            + (m.added_days ? '<span class="mod-days">Adds ' + m.added_days + ' day' + (m.added_days === 1 ? '' : 's') + '</span>' : '') + '</span>'
            + '<span class="mod-price" data-price-for="' + m.id + '"></span></label>'
            + (needsDetails(m) ? '<div class="mod-detail" data-detail-for="' + m.id + '" hidden></div>' : '')).join('')
        + '</div></div>';
    }).join('');
    root.innerHTML =
      '<div class="step-label"><span class="step-num">2</span><h4>Building safety drawings</h4></div>'
      + '<div class="choice-grid">'
      + choiceCard(1, 'Include building safety drawings', 'Column (pillar), beam and foundation drawings from our engineer.', 'Recommended')
      + choiceCard(0, 'Design only (architectural)', 'Costs less. The customer arranges their own engineer.')
      + '</div>'
      + '<div class="step-label"><span class="step-num">3</span><h4>Changes the customer asked for</h4></div>'
      + '<p class="muted small-note">Tick what was agreed on the call. Leave everything unticked to quote the design as it is.</p>'
      + tiersHtml;

    root.querySelectorAll('.choice-card').forEach(b => b.addEventListener('click', () => { structural = b.dataset.structural === '1'; update(); }));
    root.querySelectorAll('.mod-row input').forEach(cb => cb.addEventListener('change', () => {
      const id = Number(cb.value);
      if (cb.checked) { selected.add(id); openPanel(id, true); } else { selected.delete(id); closePanel(id); }
      update();
    }));
    root.querySelectorAll('.tier-head').forEach(head => head.addEventListener('click', () => {
      const open = head.getAttribute('aria-expanded') !== 'true';
      head.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.getElementById(head.getAttribute('aria-controls')).hidden = !open;
    }));
    root.addEventListener('click', onDetailClick);
    root.addEventListener('input', onDetailInput);
    root.addEventListener('change', onDetailInput);
  }

  // ---- Room pickers (same behaviour as customize.php) ----
  const stateFor = id => details[id] || (details[id] = { rooms: {}, scheme: '', custom: '', text: '', architect: false });
  function openPanel(id, focusFirst) {
    const panel = root.querySelector('[data-detail-for="' + id + '"]');
    if (!panel) return;
    if (!panel.innerHTML) panel.innerHTML = panelHtml(modsById[id]);
    panel.hidden = false;
    syncPanel(id);
    if (focusFirst) { const first = panel.querySelector('button, input, select, textarea'); if (first) first.focus({ preventScroll: true }); }
  }
  function closePanel(id) { const p = root.querySelector('[data-detail-for="' + id + '"]'); if (p) p.hidden = true; }
  const seg = options => '<div class="seg" role="group">' + options.map(o => '<button type="button" data-act="' + o[0] + '" aria-pressed="false">' + o[1] + '</button>').join('') + '</div>';
  function roomsByFloor(filter, rowHtml) {
    return floors.map(f => {
      const rooms = f.rooms.filter(filter);
      if (!rooms.length) return '';
      return '<div class="floor-block"><div class="floor-name">' + esc(f.floor) + '</div>' + rooms.map(r =>
        '<div class="room-row" data-room="' + r.id + '"><div class="room-name">' + esc(r.room_name)
        + (r.current_size_sqft ? ' <span>' + r.current_size_sqft + ' sq ft</span>' : '') + '</div>' + rowHtml(r) + '</div>').join('') + '</div>';
    }).join('');
  }
  const archRow = (title, sub) => '<button type="button" class="arch-row" data-arch aria-pressed="false"><span class="arch-ic">' + icon('expert') + '</span>'
    + '<span class="arch-text"><strong>' + title + '</strong><small>' + sub + '</small></span><span class="arch-check" aria-hidden="true">' + icon('check') + '</span></button>';
  function panelHtml(m) {
    return (ROOM_KINDS.includes(m.detail_type) ? archRow('Customer wants our architect to decide', 'Charged as one room: <span data-unit-for="' + m.id + '"></span>.')
      : m.detail_type === 'colour' ? archRow('Our architect will suggest the colours', 'No need to pick below.') : '') + panelBody(m);
  }
  function panelBody(m) {
    const kind = m.detail_type, per = '<span data-unit-for="' + m.id + '"></span>';
    if (ROOM_KINDS.includes(kind) && !roomList.length) {
      return '<p class="detail-help">This design has no room list yet. Write which rooms change and how (' + per + ' per room).</p>'
        + '<textarea data-text rows="3" maxlength="1000" placeholder="For example: make the kitchen bigger"></textarea>';
    }
    if (kind === 'resize') return '<p class="detail-help">Rooms to change (' + per + ' each).</p>'
      + roomsByFloor(() => true, () => seg([['increase', 'Bigger'], ['decrease', 'Smaller'], ['other', 'Other']]) + '<input class="room-note" data-note maxlength="300" placeholder="What exactly?" hidden>');
    if (kind === 'partition') return '<p class="detail-help">Rooms where a wall moves (' + per + ' each).</p>'
      + roomsByFloor(() => true, () => '<input class="room-note" data-note maxlength="300" placeholder="Which wall and where should it move?">');
    if (kind === 'washroom') {
      if (!roomList.some(r => r.room_type === 'bedroom')) return '<p class="detail-help">This design has no bedrooms listed. Use “Any other changes” instead.</p>';
      return '<p class="detail-help">Bedrooms that get an attached bathroom (' + per + ' each).</p>'
        + roomsByFloor(r => r.room_type === 'bedroom', () => '<label class="room-check"><input type="checkbox" data-wash> Add a bathroom</label>');
    }
    if (kind === 'opening') return '<p class="detail-help">Rooms that need a new door or window (' + per + ' each).</p>'
      + roomsByFloor(() => true, () => seg([['door', 'Door'], ['window', 'Window'], ['both', 'Both']]) + '<input class="room-note" data-note maxlength="300" placeholder="Which wall?" hidden>');
    if (kind === 'relabel') return '<p class="detail-help">New use for a room (' + per + ' each).</p>'
      + roomsByFloor(() => true, r => '<div class="room-use"><span class="now">Now: ' + esc(ROOM_TYPE_LABEL[r.room_type] || r.room_type) + '</span>'
        + '<select data-to aria-label="New use for ' + esc(r.room_name) + '"><option value="">Keep as it is</option>' + ROOM_USES.map(u => '<option>' + u + '</option>').join('') + '</select></div>');
    if (kind === 'colour') return '<p class="detail-help">Outside colour scheme.</p><div class="swatch-grid">' + COLOUR_SCHEMES.map(s =>
        '<button type="button" class="swatch" data-scheme="' + esc(s.name) + '" aria-pressed="false"><span class="swatch-bars">' + s.colors.map(c => '<i style="background:' + c + '"></i>').join('') + '</span>'
        + '<span class="swatch-name">' + esc(s.name) + '</span></button>').join('') + '</div>'
      + '<label class="detail-label" for="qcustom' + m.id + '">Or describe the colours</label><input id="qcustom' + m.id + '" class="room-note" data-custom maxlength="300">';
    if (kind === 'other') return '<label class="detail-label" for="qother' + m.id + '">Other changes the customer asked for</label>'
      + '<textarea id="qother' + m.id + '" data-text rows="4" maxlength="1000"></textarea>';
    return '';
  }
  function isPicked(kind, s) {
    if (kind === 'resize' || kind === 'opening') return !!s.act;
    if (kind === 'partition') return !!(s.note || '').trim();
    if (kind === 'washroom') return !!s.on;
    if (kind === 'relabel') return !!s.to;
    return false;
  }
  function syncPanel(id) {
    const panel = root.querySelector('[data-detail-for="' + id + '"]');
    if (!panel || !panel.innerHTML) return;
    const st = stateFor(id);
    panel.classList.toggle('arch-on', !!st.architect);
    const arch = panel.querySelector('[data-arch]');
    if (arch) { arch.classList.toggle('on', !!st.architect); arch.setAttribute('aria-pressed', st.architect ? 'true' : 'false'); }
    panel.querySelectorAll('.room-row').forEach(row => {
      const s = st.rooms[row.dataset.room] || {};
      row.querySelectorAll('[data-act]').forEach(b => { const on = b.dataset.act === s.act; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      const note = row.querySelector('[data-note]');
      if (note) {
        if (note.value !== (s.note || '')) note.value = s.note || '';
        const kind = modsById[id].detail_type;
        if (kind === 'resize') note.hidden = s.act !== 'other';
        if (kind === 'opening') note.hidden = !s.act;
      }
      const wash = row.querySelector('[data-wash]'); if (wash) wash.checked = !!s.on;
      const to = row.querySelector('[data-to]'); if (to && to.value !== (s.to || '')) to.value = s.to || '';
      row.classList.toggle('picked', isPicked(modsById[id].detail_type, s));
    });
    panel.querySelectorAll('[data-scheme]').forEach(b => { const on = b.dataset.scheme === st.scheme; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    const custom = panel.querySelector('[data-custom]'); if (custom && custom.value !== st.custom) custom.value = st.custom;
    const text = panel.querySelector('[data-text]'); if (text && text.value !== st.text) text.value = st.text;
  }
  const panelOf = el => { const p = el.closest('[data-detail-for]'); return p ? Number(p.dataset.detailFor) : null; };
  function onDetailClick(e) {
    const btn = e.target.closest('[data-act], [data-scheme], [data-arch]');
    if (!btn) return;
    const id = panelOf(btn); if (id === null) return;
    const st = stateFor(id);
    st.architect = btn.matches('[data-arch]') ? !st.architect : false;
    if (btn.dataset.scheme !== undefined) st.scheme = st.scheme === btn.dataset.scheme ? '' : btn.dataset.scheme;
    else if (btn.dataset.act !== undefined) {
      const rid = btn.closest('.room-row').dataset.room;
      const s = st.rooms[rid] = st.rooms[rid] || {};
      s.act = s.act === btn.dataset.act ? '' : btn.dataset.act;
    }
    syncPanel(id);
    update();
  }
  function onDetailInput(e) {
    const el = e.target;
    if (!el.matches('[data-note], [data-wash], [data-to], [data-custom], [data-text]')) return;
    const id = panelOf(el); if (id === null) return;
    const st = stateFor(id);
    if (st.architect) { st.architect = false; syncPanel(id); }
    const row = el.closest('.room-row');
    if (row) {
      const s = st.rooms[row.dataset.room] = st.rooms[row.dataset.room] || {};
      if (el.matches('[data-note]')) s.note = el.value;
      if (el.matches('[data-wash]')) s.on = el.checked;
      if (el.matches('[data-to]')) s.to = el.value;
      row.classList.toggle('picked', isPicked(modsById[id].detail_type, s));
    }
    if (el.matches('[data-custom]')) st.custom = el.value;
    if (el.matches('[data-text]')) st.text = el.value;
    update();
  }

  // The same entries api/order.php would receive (room_id, action, custom_note), plus display text.
  function entriesFor(m) {
    const st = stateFor(m.id), kind = m.detail_type;
    const entry = (room, action, note, text, incomplete) => ({ modification_id: m.id, room_id: room ? room.id : null, action, custom_note: note || null, text, incomplete: !!incomplete });
    if (st.architect && ROOM_KINDS.includes(kind)) return [entry(null, 'other', ARCHITECT_NOTE, 'Our architect will decide')];
    if (st.architect && kind === 'colour') return [entry(null, null, COLOUR_ARCHITECT_NOTE, 'Our architect will suggest colours')];
    if (ROOM_KINDS.includes(kind) && !roomList.length) { const t = (st.text || '').trim(); return t ? [entry(null, 'other', t, t)] : []; }
    if (ROOM_KINDS.includes(kind)) {
      const out = [];
      roomList.forEach(r => {
        const s = st.rooms[r.id];
        if (!s || !isPicked(kind, s)) return;
        const note = (s.note || '').trim();
        if (kind === 'resize') out.push(entry(r, s.act, s.act === 'other' ? note : '', r.room_name + ' — ' + (s.act === 'increase' ? 'bigger' : s.act === 'decrease' ? 'smaller' : (note || 'say what is wanted')), s.act === 'other' && !note));
        if (kind === 'partition') out.push(entry(r, 'other', note, r.room_name + ' — ' + note));
        if (kind === 'washroom') out.push(entry(r, 'add', '', r.room_name + ' — add a bathroom'));
        if (kind === 'opening') {
          const what = s.act === 'door' ? 'Add a door' : s.act === 'window' ? 'Add a window' : 'Add a door and a window';
          const full = what + (note ? ' — wall: ' + note : '');
          out.push(entry(r, 'add', full, r.room_name + ' — ' + full.toLowerCase()));
        }
        if (kind === 'relabel') out.push(entry(r, 'other', 'Change to: ' + s.to, r.room_name + ' → ' + s.to));
      });
      return out;
    }
    if (kind === 'colour') { const c = (st.custom || '').trim(); const note = st.scheme ? st.scheme + (c ? ' — ' + c : '') : c; return note ? [entry(null, null, note, 'Colours: ' + note)] : []; }
    if (kind === 'other') { const t = (st.text || '').trim(); return t ? [entry(null, null, t, t)] : []; }
    return [];
  }

  let unfinished = [], payload = null;
  function update() {
    root.querySelectorAll('.choice-card').forEach(b => { const on = (b.dataset.structural === '1') === structural; b.classList.toggle('selected', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    root.querySelectorAll('.mod-row').forEach(row => row.classList.toggle('checked', selected.has(Number(row.dataset.id))));
    Object.values(modsById).forEach(m => {
      const p = root.querySelector('[data-price-for="' + m.id + '"]'); if (p) p.textContent = modPriceLabel(m, structural);
      root.querySelectorAll('[data-unit-for="' + m.id + '"]').forEach(u => { u.textContent = fmt(effectiveModPrice(m, structural)); });
    });
    groups.forEach(g => { const n = g.items.filter(m => selected.has(m.id)).length; const el = root.querySelector('[data-count-for="' + g.tier + '"]'); if (el) el.textContent = n ? n + ' selected' : ''; });

    const entriesByMod = {};
    unfinished = [];
    const mods = [];
    groups.forEach(g => g.items.forEach(m => { if (selected.has(m.id)) mods.push(m); }));
    const priced = mods.map(m => {
      if (!needsDetails(m)) return m;
      const entries = entriesFor(m);
      entriesByMod[m.id] = entries;
      if (!entries.length || entries.some(e => e.incomplete)) unfinished.push(m);
      return ROOM_KINDS.includes(m.detail_type) ? Object.assign({}, m, { qty: entries.length }) : m;
    });
    // With no changes, "include safety drawings" means the 40% package (same as buying as-is).
    const q = quote(design, priced, structural, !mods.length && structural);
    payload = {
      structural, modifications: mods.map(m => m.id),
      modification_details: [].concat(...Object.values(entriesByMod)).filter(e => !e.incomplete).map(e => ({ modification_id: e.modification_id, room_id: e.room_id, action: e.action, custom_note: e.custom_note })),
    };
    persist();
    const total = totalLabel(q);
    document.getElementById('qbSummary').innerHTML =
      '<div class="sum-line"><span>' + esc(design.name) + ' (design price)</span><span>' + fmt(design.base_price) + '</span></div>'
      + '<div class="sum-line sub"><span>' + STRUCT_NAME + '</span><span>' + (!mods.length && structural ? fmt(structAddonPrice(design.base_price)) : structural ? 'Included' : 'Not included') + '</span></div>'
      + '<div class="sum-mods">' + (mods.length ? summaryModLines(priced, entriesByMod, structural) : '<div class="sum-empty">No changes — the design as it is.</div>') + '</div>'
      + '<div class="sum-total"><span>' + (q.isRange ? 'Approx. price' : 'Total price') + '</span><span class="price' + (q.isRange ? ' range' : '') + '" id="qbTotal">' + total + '</span></div>'
      + '<div class="sum-days">Ready in about ' + q.days + ' days</div>'
      + (q.needsReview ? '<div class="note note-danger">' + icon('info') + '<span>Big changes: the quotation will say the price is an estimate.</span></div>' : '')
      + (unfinished.length ? '<p class="helper todo">Please finish: ' + unfinished.map(m => esc(m.label)).join('; ') + '.</p>' : '');
    const btn = document.getElementById('qbSend');
    if (btn) btn.disabled = unfinished.length > 0;
    if (lastTotal !== null && lastTotal !== total) flash(document.getElementById('qbTotal'));
    lastTotal = total;
  }

  form.addEventListener('submit', e => {
    if (unfinished.length || !payload) { e.preventDefault(); return; }
    form.querySelector('[name="config"]').value = JSON.stringify(payload);
  });
  // A successful send clears the saved work (the page says so with data-sent).
  if (root.dataset.clear === '1') { try { sessionStorage.removeItem(STORE); } catch (e) {} }

  load();
})();

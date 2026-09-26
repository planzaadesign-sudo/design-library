// Shared helpers for the customer pages.
// Prices computed here are for display only -- api/order.php recalculates
// everything from the database before an order is stored. Keep the two in step.

function fmt(n){ return '₹' + Number(n).toLocaleString('en-IN'); }

// Design names can come from freelancer submissions, so anything from the
// database is escaped before it goes into innerHTML.
function esc(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Carries the customer's plot between pages as URL params.
function plotQuery(p){
  const q = new URLSearchParams();
  if(p.width) q.set('width', p.width);
  if(p.length) q.set('length', p.length);
  if(p.facing) q.set('facing', p.facing);
  return q.toString();
}

// Depending on the PHP/MySQL driver version numbers can arrive as strings; normalise
// so arithmetic never turns into string concatenation.
function normDesign(d){
  ['id','plot_width','plot_length','bhk','base_price','delivery_days'].forEach(k => { d[k] = Number(d[k]); });
  return d;
}

function structAddonPrice(basePrice){ return Math.round(basePrice * 0.4 / 100) * 100; }

// Price a modification actually costs, given the structural choice. Tier 4 has no single price.
function effectiveModPrice(mod, structural){
  if(mod.tier === 4) return null;
  return mod.price - (structural ? 0 : mod.struct_portion);
}

function modPriceLabel(mod, structural){
  if(mod.tier === 4) return fmt(mod.price_min) + ' – ' + fmt(mod.price_max) + ' (approx.)';
  if(mod.detail_type === 'other') return 'Price later';
  return '+' + fmt(effectiveModPrice(mod, structural)) + (ROOM_KINDS.includes(mod.detail_type) ? ' per room' : '');
}

// Same rules as api/order.php:
//  - as-is purchase (no modifications): base + optional 40% structural package
//  - customised: base + each modification, minus struct_portion when architectural only
//  - room-based changes are charged once per room picked (m.qty)
//  - tier 4 items make the total a range; tier 4, more than 2 tier 3 items or
//    "any other changes" => manual review
function quote(design, mods, structural, structAddon){
  let min = design.base_price, max = design.base_price, days = design.delivery_days;
  let tier3 = 0, hasTier4 = false, hasOther = false;
  if(mods.length === 0 && structAddon){
    min += structAddonPrice(design.base_price);
    max = min;
  }
  mods.forEach(m => {
    days += m.added_days;
    if(m.tier === 4){ hasTier4 = true; min += m.price_min; max += m.price_max; }
    else {
      if(m.tier === 3) tier3++;
      if(m.detail_type === 'other') hasOther = true;
      const p = effectiveModPrice(m, structural) * (m.qty == null ? 1 : m.qty);
      min += p; max += p;
    }
  });
  return {
    min, max, days, tier3, hasTier4,
    isRange: max !== min,
    needsReview: hasTier4 || tier3 > 2 || hasOther,
    structuralWarning: !structural && tier3 > 0,
  };
}

function totalLabel(q){ return q.isRange ? fmt(q.min) + ' – ' + fmt(q.max) : fmt(q.min); }

// ---- Room-by-room changes ------------------------------------------------------
// modifications.detail_type decides which picker a change opens. These kinds are
// picked per room and charged once per room (same rule as api/order.php).
const ROOM_KINDS = ['resize', 'partition', 'washroom', 'opening', 'relabel'];
const ROOM_TYPE_LABEL = {bedroom:'Bedroom', bathroom:'Bathroom', kitchen:'Kitchen', living:'Living room', dining:'Dining', pooja:'Pooja room', balcony:'Balcony', parking:'Parking', staircase:'Staircase', store:'Store room', utility:'Utility room', other:'Other'};
const ROOM_USES = ['Bedroom', 'Bathroom', 'Kitchen', 'Living Room', 'Dining', 'Study', 'Store Room', 'Pooja Room', 'Home Office', 'Other'];
const COLOUR_SCHEMES = [
  {name:'Classic White & Grey', colors:['#F4F4F1', '#BFC2C4', '#6F7479']},
  {name:'Warm Beige & Brown', colors:['#EADCC3', '#C4A47E', '#6B4A2F']},
  {name:'Modern Charcoal & White', colors:['#FAFAF8', '#45494D', '#1F2124']},
  {name:'Earthy Terracotta', colors:['#E8D4BE', '#C0673E', '#7A3B22']},
  {name:'Cool Blue & White', colors:['#F7F9FB', '#A9C6DE', '#2F5D84']},
  {name:'Cream & Olive', colors:['#F3EBD3', '#A6A86C', '#5B5E33']},
  {name:'Sand & Dark Brown', colors:['#E3CFA8', '#A8875A', '#4A3222']},
  {name:'Ivory & Maroon', colors:['#FBF6EA', '#DCCBA9', '#7A2230']},
];

function needsDetails(mod){ return !!mod.detail_type; }

// "Let our architect decide" -- api/order.php checks for this exact note.
const ARCHITECT_NOTE = 'Customer requested architect to decide';
const COLOUR_ARCHITECT_NOTE = 'Architect to suggest colour scheme';

// Same list as api/order.php (states first, then union territories).
const INDIAN_STATES = [
  'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana',
  'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
  'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
  'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh',
  'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
];

// Summary rows for the picked changes. entriesByMod: {modId: [{text, incomplete}]}.
// mods must already carry qty (rooms picked) for room-based changes.
function summaryModLines(mods, entriesByMod, structural){
  if(!mods.length) return '<div class="sum-empty">You have not picked any changes yet.</div>';
  return mods.map(m => {
    const entries = entriesByMod[m.id] || [];
    const unit = m.tier === 4 ? null : effectiveModPrice(m, structural);
    let price;
    if(m.tier === 4) price = fmt(m.price_min) + '–' + fmt(m.price_max);
    else if(m.detail_type === 'other') price = 'Price later';
    else price = fmt(unit * (m.qty == null ? 1 : m.qty));
    let subs = '';
    if(ROOM_KINDS.includes(m.detail_type)){
      subs = entries.length
        ? entries.map(e => '<div class="sum-room' + (e.incomplete ? ' todo' : '') + '"><span>' + esc(e.text) + '</span>' + (e.room_id ? '<span>' + fmt(unit) + '</span>' : '') + '</div>').join('')
        : '<div class="sum-room todo"><span>Pick at least one room</span></div>';
    } else if(m.detail_type === 'colour' || m.detail_type === 'other'){
      subs = entries.length
        ? '<div class="sum-room"><span>' + esc(entries[0].text) + '</span></div>'
        : '<div class="sum-room todo"><span>' + (m.detail_type === 'colour' ? 'Pick a colour scheme' : 'Tell us what you want') + '</span></div>';
    }
    return '<div class="sum-group"><div class="sum-line sub"><span>' + esc(m.label) + '</span><span>' + price + '</span></div>' + subs + '</div>';
  }).join('');
}

// Passes the configurator's choices (including room details and notes) to the order page.
const CONFIG_KEY = 'planzaa_config';
function saveConfig(cfg){ try { sessionStorage.setItem(CONFIG_KEY, JSON.stringify(cfg)); } catch(e) {} }
function readConfig(){ try { return JSON.parse(sessionStorage.getItem(CONFIG_KEY) || 'null'); } catch(e) { return null; } }

// Customer-facing wording is deliberately plain: written for someone who has
// never hired an architect. Keep new text just as simple.
const TIERS = {
  1: {title:'Simple changes', sub:'No wall changes needed', cls:'tier-1'},
  2: {title:'Room changes', sub:'Moving walls inside', cls:'tier-2'},
  3: {title:'Big changes', sub:'Affects the building structure', cls:'tier-3'},
  4: {title:'Very big changes', sub:'Our team will give you a price', cls:'tier-4'},
};

const STRUCT_NAME = 'Building safety drawings';

// "G+1" means ground floor plus one floor above.
function floorsLabel(f){
  return ({'G':'Ground floor only', 'G+1':'Ground + 1 floor', 'G+2':'Ground + 2 floors'})[f] || f;
}

const CONTACT = {phone:'+91-8920218394', tel:'tel:+918920218394', whatsapp:'https://wa.me/918920218394'};

// Small single-colour inline icons (24-unit grid, drawn with currentColor).
const ICON_PATHS = {
  check:'<path d="M5 12.5l4.5 4.5L19 7.5"/>',
  x:'<path d="M7 7l10 10M17 7L7 17"/>',
  chevron:'<path d="M6 9l6 6 6-6"/>',
  arrow:'<path d="M5 12h14M13 6l6 6-6 6"/>',
  lock:'<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>',
  clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  shield:'<path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/>',
  phone:'<path d="M5 4h3l2 5-2.5 1.5a11 11 0 005 5L14 13l5 2v3a2 2 0 01-2 2A15 15 0 013 6a2 2 0 012-2"/>',
  warning:'<path d="M12 3.5l9 16H3z"/><path d="M12 10v4M12 17v.01"/>',
  info:'<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
  grid:'<rect x="3" y="3" width="18" height="18" rx="1"/><path d="M3 12h18M12 3v18"/>',
  foundation:'<path d="M9 3v7M15 3v7M5 10h14v5H5zM3 20h18"/>',
  rebar:'<path d="M5 19L19 5M5 12l7-7M12 19l7-7"/>',
  section:'<rect x="4" y="4" width="16" height="16" rx="1"/><path d="M4 9h16M9 9v11"/>',
  list:'<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
  calc:'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01M16 16h.01"/>',
  copy:'<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 012-2h8"/>',
  ruler:'<path d="M3 17L17 3l4 4L7 21z"/><path d="M7 13l2 2M10 10l2 2M13 7l2 2"/>',
  expert:'<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
  checklist:'<path d="M10 6h10M10 12h10M10 18h10"/><path d="M3.5 6l1.5 1.5L7.5 5M3.5 12l1.5 1.5 2.5-2.5M3.5 18l1.5 1.5 2.5-2.5"/>',
};
function icon(name, cls){
  return '<svg class="ic' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON_PATHS[name] + '</svg>';
}

// Restart a one-shot CSS animation (used to flash totals when they change).
function flash(el){
  if(!el) return;
  el.classList.remove('go');
  void el.offsetWidth;
  el.classList.add('go');
}

function matchBadge(match, pop){
  const cls = pop ? ' pop' : '';
  if(match === null || match === undefined) return '<span class="badge badge-neutral' + cls + '">Enter your plot size and direction above to see which designs match</span>';
  return match
    ? '<span class="badge badge-match' + cls + '">Fits your plot exactly</span>'
    : '<span class="badge badge-amber' + cls + '">Can be changed to fit your plot</span>';
}

// Design card shared by the library grid and "You might also like".
function designCard(d, query, i, pop){
  return '<a class="dcard" style="--i:' + i + '" href="design.php?id=' + d.id + (query ? '&' + query : '') + '">'
    + '<div class="dcard-art' + (d.preview_plan_url ? ' photo' : '') + '">' + (d.preview_plan_url ? '<img src="' + esc(d.preview_plan_url) + '" alt="Floor plan of ' + esc(d.name) + '" loading="lazy">' : d.floor_plan_svg) + '</div>'
    + '<div class="dcard-body">'
    +   '<div class="dcard-name">' + esc(d.name) + '</div>'
    +   (d.design_code ? '<div class="dcard-code">' + esc(d.design_code) + '</div>' : '')
    +   '<div class="tags"><span class="tag">' + d.plot_width + '×' + d.plot_length + ' ft plot</span><span class="tag">' + esc(d.facing) + ' facing</span><span class="tag">' + esc(floorsLabel(d.floors)) + '</span><span class="tag">' + d.bhk + ' BHK</span></div>'
    +   matchBadge(d.match, pop)
    +   '<div class="dcard-foot"><div><span class="dprice">' + fmt(d.base_price) + '</span><span class="ddays">Ready in ' + d.delivery_days + ' days</span></div>'
    +   '<span class="view-btn">See this design <span class="arrow" aria-hidden="true">→</span></span></div>'
    + '</div></a>';
}

// Sticky nav: shadow once the page scrolls, hamburger menu under 768px, active link.
(function initNav(){
  const nav = document.getElementById('siteNav');
  if(!nav) return;
  const toggle = document.getElementById('navToggle');
  const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 4);
  onScroll();
  window.addEventListener('scroll', onScroll, {passive:true});

  const setOpen = open => {
    nav.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  };
  toggle.addEventListener('click', () => setOpen(!nav.classList.contains('open')));
  document.addEventListener('keydown', e => { if(e.key === 'Escape') setOpen(false); });
  document.addEventListener('click', e => { if(!nav.contains(e.target)) setOpen(false); });

  const page = (location.pathname.split('/').pop() || 'index.php').replace(/\.php$/, '') || 'index';
  nav.querySelectorAll('[data-pages]').forEach(a => {
    if(a.dataset.pages.split(' ').includes(page)){ a.classList.add('active'); a.setAttribute('aria-current', 'page'); }
  });
})();

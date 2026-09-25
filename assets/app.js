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
  if(mod.tier === 4) return fmt(mod.price_min) + ' – ' + fmt(mod.price_max) + ' (est.)';
  return '+' + fmt(effectiveModPrice(mod, structural));
}

// Same rules as api/order.php:
//  - as-is purchase (no modifications): base + optional 40% structural package
//  - customised: base + each modification, minus struct_portion when architectural only
//  - tier 4 items make the total a range; tier 4 or more than 2 tier 3 items => manual review
function quote(design, mods, structural, structAddon){
  let min = design.base_price, max = design.base_price, days = design.delivery_days;
  let tier3 = 0, hasTier4 = false;
  if(mods.length === 0 && structAddon){
    min += structAddonPrice(design.base_price);
    max = min;
  }
  mods.forEach(m => {
    days += m.added_days;
    if(m.tier === 4){ hasTier4 = true; min += m.price_min; max += m.price_max; }
    else {
      if(m.tier === 3) tier3++;
      const p = effectiveModPrice(m, structural);
      min += p; max += p;
    }
  });
  return {
    min, max, days, tier3, hasTier4,
    isRange: max !== min,
    needsReview: hasTier4 || tier3 > 2,
    structuralWarning: !structural && tier3 > 0,
  };
}

function totalLabel(q){ return q.isRange ? fmt(q.min) + ' – ' + fmt(q.max) : fmt(q.min); }

const TIERS = {
  1: {title:'Cosmetic', sub:'No structural change', cls:'tier-1'},
  2: {title:'Space planning', sub:'Rearranging within the existing structure', cls:'tier-2'},
  3: {title:'Structural change', sub:'Touches walls, beams or the footprint', cls:'tier-3'},
  4: {title:'Major', sub:'Needs a manual quote', cls:'tier-4'},
};

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
  if(match === null || match === undefined) return '<span class="badge badge-neutral' + cls + '">Enter your plot to check fit</span>';
  return match
    ? '<span class="badge badge-match' + cls + '">Exact match</span>'
    : '<span class="badge badge-amber' + cls + '">Needs modification</span>';
}

// Design card shared by the library grid and "You might also like".
function designCard(d, query, i, pop){
  return '<a class="dcard" style="--i:' + i + '" href="design.php?id=' + d.id + (query ? '&' + query : '') + '">'
    + '<div class="dcard-art">' + d.floor_plan_svg + '</div>'
    + '<div class="dcard-body">'
    +   '<div class="dcard-name">' + esc(d.name) + '</div>'
    +   '<div class="tags"><span class="tag">' + d.plot_width + '×' + d.plot_length + ' ft</span><span class="tag">' + esc(d.facing) + ' facing</span><span class="tag">' + esc(d.floors) + '</span><span class="tag">' + d.bhk + ' BHK</span></div>'
    +   matchBadge(d.match, pop)
    +   '<div class="dcard-foot"><div><span class="dprice">' + fmt(d.base_price) + '</span><span class="ddays">' + d.delivery_days + '-day delivery</span></div>'
    +   '<span class="view-btn">View Details <span class="arrow" aria-hidden="true">→</span></span></div>'
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

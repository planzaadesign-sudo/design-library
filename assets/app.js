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

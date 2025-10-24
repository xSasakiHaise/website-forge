// ui/components.js — UI helpers (v120)
export function forgeCard(title, version = '') {
  const wrap = document.createElement('div');
  wrap.className = 'forge-card';
  wrap.style.cssText = `width:100%;margin:24px 0;padding:18px 24px;background:rgba(35,20,12,.92);
    border:1px solid rgba(255,155,74,.38);border-radius:12px;color:#ffc37a;`;
  const h = document.createElement('div');
  h.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;';
  const t = document.createElement('h2'); t.textContent = title; t.style.cssText='margin:0;color:#ff9b4a;font-weight:700;';
  const v = document.createElement('span'); v.textContent = version; v.style.cssText='font-size:12px;opacity:.65;';
  h.append(t,v); wrap.append(h);
  return wrap;
}
export function padBox(e) { e.style.padding='10px'; e.style.boxSizing='border-box'; e.style.width='100%'; return e; }

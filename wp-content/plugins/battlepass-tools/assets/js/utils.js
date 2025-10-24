// utils.js — forge debug + helpers (v120)
export function dbgInit() {
  if (document.getElementById('hbpt-debug')) return;
  const box = document.createElement('div');
  box.id = 'hbpt-debug';
  box.style.cssText = `position:fixed;right:10px;bottom:10px;z-index:9999;background:rgba(25,10,5,.9);
    color:#ffae65;font-family:ui-monospace,Consolas,monospace;font-size:12px;padding:8px 10px;border:1px solid #ff9b4a;
    border-radius:8px;max-height:40vh;overflow:auto;min-width:280px;`;
  box.innerHTML = `<b>HBPT Debug</b> <span style="opacity:.6">module:app.js start</span><br>`;
  const row = document.createElement('div'); row.style.margin='6px 0';
  const clear = document.createElement('button');
  clear.textContent = 'Clear';
  clear.style.cssText = 'margin-right:4px;font-size:11px;background:#220; color:#ffae65;border:1px solid #553;border-radius:6px;padding:3px 8px;';
  const hide = document.createElement('button');
  hide.textContent = 'Hide';
  hide.style.cssText = 'font-size:11px;background:#220;color:#ffae65;border:1px solid #553;border-radius:6px;padding:3px 8px;';
  clear.onclick = () => { box.querySelectorAll('.log').forEach(l => l.remove()); };
  hide.onclick = () => { box.style.display = 'none'; };
  row.append(clear, hide);
  box.append(row);
  document.body.appendChild(box);
}

export function dbgLog(...msg) {
  const box = document.getElementById('hbpt-debug');
  const line = document.createElement('div');
  line.className = 'log';
  const text = msg.map(x => (typeof x === 'string' ? x : (x && (x.stack || JSON.stringify(x))))).join(' ');
  line.textContent = `[${new Date().toTimeString().slice(0,8)}] ${text}`;
  (box||document.body).appendChild(line);
  if (box) box.scrollTop = box.scrollHeight;
  else console.log('[HBPT]', text);
}

export function el(tag, cls = '', text = '') {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text) e.textContent = text;
  return e;
}

export const pretty = (o) => JSON.stringify(o, null, 2);

export function download(filename, text) {
  const blob = new Blob([text], {type:'application/json'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  setTimeout(()=>URL.revokeObjectURL(a.href), 3000);
}

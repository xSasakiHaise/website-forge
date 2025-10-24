// ui/app.js — main layout logic (v120)
import { el, dbgLog, pretty, download } from '../utils.js';
import { forgeCard, padBox } from './components.js';
import { BPState } from '../state.js';

(async () => {
  const lj = await import('../ops/lenient-json.v120.js?v=120');
  const { validateBP } = await import('../ops/validate.js?v=120');
  const { renumber }   = await import('../ops/renumer.js?v=120');
  const { copyLevels } = await import('../ops/copy-levels.js?v=120');
  const { copyTrackRange } = await import('../ops/copy-track-range.js?v=120');

  BPState.lenientVersion = lj.LJ_VERSION || 'unknown';
  dbgLog('deps loaded; lenient-json version', BPState.lenientVersion);

  const root = document.querySelector('[data-battlepass-root]') || document.body;

  const card1 = forgeCard('Battlepass Editor', 'HBPT 1.2.0');
  root.appendChild(card1);

  const grid = el('div');
  grid.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:16px;';
  const inp = padBox(el('textarea')); inp.style.minHeight='200px'; inp.placeholder='Paste your rewards JSON/config...';
  const out = padBox(el('textarea')); out.style.minHeight='200px'; out.placeholder='Result JSON will appear here...';
  grid.append(inp,out);
  card1.append(grid);

  const btnRow = el('div'); btnRow.style.cssText='display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;';
  function mkBtn(label, cb){
    const b = el('button'); b.textContent=label;
    b.style.cssText='background:#2a160e;color:#ffb97a;border:1px solid #734024;border-radius:8px;padding:8px 12px;cursor:pointer;';
    b.onclick = cb; return b;
  }
  const loadStrict = mkBtn('Load (strict JSON)…', ()=>{
    try { BPState.dataText = inp.value; BPState.json = JSON.parse(BPState.dataText); out.value = pretty(BPState.json); dbgLog('Loaded strict JSON'); }
    catch(e){ dbgLog('Strict load error', e.message); alert('Strict JSON parse error: '+e.message); }
  });
  const loadLen = mkBtn('Load (lenient config)…', ()=>{
    try { BPState.dataText = inp.value; BPState.json = lj.lenientParse(BPState.dataText); out.value = pretty(BPState.json); dbgLog('Loaded lenient JSON'); }
    catch(e){ dbgLog('Lenient load error', e.message); alert('Lenient parse error: '+e.message); }
  });
  const validateBtn = mkBtn('Validate', ()=>{
    if (!BPState.json){ alert('Load JSON first'); return; }
    const errs = validateBP(BPState.json);
    if (errs.length){ alert('Validation issues:\n- '+errs.join('\n- ')); }
    else alert('Looks good.');
  });
  const renumBtn = mkBtn('Renumber', ()=>{
    if (!BPState.json){ alert('Load JSON first'); return; }
    BPState.json = renumber(BPState.json, 1);
    out.value = pretty(BPState.json);
  });
  const formatBtn = mkBtn('Format', ()=>{ try { const tmp = JSON.parse(out.value); out.value = pretty(tmp); } catch(e){ alert('Cannot format: '+e.message);} });
  const ioToIn  = mkBtn('Load Output → Input', ()=>{ inp.value = out.value; });
  const dl = mkBtn('Download result.json', ()=> download('result.json', out.value));

  btnRow.append(loadStrict, loadLen, validateBtn, renumBtn, formatBtn, ioToIn, dl);
  card1.append(btnRow);

  // Card 2
  const card2 = forgeCard('Battlepass Editor', 'HBPT 1.2.0');
  root.appendChild(card2);

  function mkField(label, def=''){
    const wrap = el('div'); wrap.style.margin='8px 0';
    const lab = el('div','',label); lab.style.cssText='font-size:13px;margin-bottom:4px;color:#ffae65;';
    const inpX = padBox(el('input')); inpX.type='text'; inpX.value = def;
    wrap.append(lab, inpX); return {wrap, inp:inpX};
  }
  const r1 = mkField('Range (X:Y or X..Y)', '1:10');
  const s1 = mkField('Start levels (CSV)', '101,501,901');
  const flags1 = el('div'); flags1.style.cssText='display:flex;gap:16px;margin-top:8px;';
  function mkCheck(label, checked=false){ const w = el('label'); const c = el('input'); c.type='checkbox'; c.checked=checked; w.append(c, document.createTextNode(' '+label)); w.style.cursor='pointer'; return {wrap:w,box:c}; }
  const f_over = mkCheck('Overwrite', true);
  const f_merge= mkCheck('Merge tracks if not overwriting', false);
  const f_ren  = mkCheck('Auto rename', true);
  flags1.append(f_over.wrap, f_merge.wrap, f_ren.wrap);

  const runCopyLevels = mkBtn('Run Copy Levels', ()=>{
    if (!BPState.json){ alert('Load JSON first'); return; }
    try{
      BPState.json = copyLevels(BPState.json, r1.inp.value, s1.inp.value, { overwrite:f_over.box.checked, mergeTracks:f_merge.box.checked, autoRename:f_ren.box.checked });
      out.value = pretty(BPState.json);
      dbgLog('copyLevels done');
    }catch(e){ alert('copyLevels error: '+e.message); dbgLog(e); }
  });
  const rowA = el('div'); rowA.style.cssText='display:grid;grid-template-columns:1fr 1fr;gap:16px;';
  rowA.append(r1.wrap, s1.wrap);
  card2.append(rowA, flags1, el('div'));
  card2.lastChild.append(runCopyLevels);

  // Card 3
  const card3 = forgeCard('Battlepass Editor', 'HBPT 1.2.0');
  root.appendChild(card3);

  const src = mkField('Source track', 'premium');
  const dst = mkField('Dest track', 'free');
  const rng = mkField('Range', '1..10');
  const stz = mkField('Start Z', '200');
  const rpt = mkField('Repeat to (optional)', '1000');
  const flags2 = el('div'); flags2.style.cssText='display:flex;gap:16px;margin-top:8px;';
  const f2_over = mkCheck('Overwrite', true);
  const f2_ren  = mkCheck('Auto rename', true);
  flags2.append(f2_over.wrap, f2_ren.wrap);

  const runCopyTR = mkBtn('Run Copy Track Range', ()=>{
    if (!BPState.json){ alert('Load JSON first'); return; }
    try{
      const rep = rpt.inp.value.trim()==='' ? undefined : parseInt(rpt.inp.value,10);
      BPState.json = copyTrackRange(BPState.json, src.inp.value.trim(), dst.inp.value.trim(), rng.inp.value.trim(), parseInt(stz.inp.value,10), rep, { overwrite:f2_over.box.checked, autoRename:f2_ren.box.checked });
      out.value = pretty(BPState.json);
      dbgLog('copyTrackRange done');
    }catch(e){ alert('copyTrackRange error: '+e.message); dbgLog(e); }
  });
  const grid3 = el('div'); grid3.style.cssText='display:grid;grid-template-columns:1fr 1fr;gap:16px;';
  grid3.append(src.wrap, dst.wrap, rng.wrap, stz.wrap, rpt.wrap);
  card3.append(grid3, flags2, el('div'));
  card3.lastChild.append(runCopyTR);

  dbgLog('UI ready');
})();

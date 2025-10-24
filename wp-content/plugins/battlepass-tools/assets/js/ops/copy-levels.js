// copy-levels.js — copy a range across all tracks to new level numbers (v120)
export function copyLevels(obj, rangeStr, startsCsv, opts={}){
  if (!obj || !Array.isArray(obj.rewards)) throw new Error('Invalid object');
  const m = String(rangeStr).match(/^(\d+)\s*(?:[:.][.:]?)\s*(\d+)$/);
  if (!m) throw new Error('Bad range');
  const [_, a, b] = m; const from = Math.min(+a, +b), to = Math.max(+a,+b);
  const window = obj.rewards.filter(l => l.index>=from && l.index<=to).map(l => JSON.parse(JSON.stringify(l)));
  const starts = String(startsCsv).split(',').map(s=>parseInt(s.trim(),10)).filter(n=>Number.isFinite(n));
  const result = obj.rewards.map(x=>JSON.parse(JSON.stringify(x)));
  for (const start of starts){
    for (let i=0;i<window.length;i++){
      const src = JSON.parse(JSON.stringify(window[i]));
      src.index = start + i;
      if (opts.autoRename){
        for (const track of Object.keys(src.rewards||{})){
          const disp = src.rewards[track]?.display;
          if (disp && typeof disp.name === 'string'){
            disp.name = `Level ${src.index}` + (track!=='free' ? ` ${track[0].toUpperCase()+track.slice(1)}` : '');
          }
        }
      }
      const existingIdx = result.findIndex(l => l.index === src.index);
      if (existingIdx >= 0){
        if (opts.overwrite){
          result[existingIdx] = src;
        } else if (opts.mergeTracks){
          const dest = result[existingIdx];
          for (const [t,body] of Object.entries(src.rewards||{})){
            if (!dest.rewards[t]) dest.rewards[t]=body;
          }
        }
      } else {
        result.push(src);
      }
    }
  }
  result.sort((a,b)=>a.index-b.index);
  return { ...obj, rewards: result };
}

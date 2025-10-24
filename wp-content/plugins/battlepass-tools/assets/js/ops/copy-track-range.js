// copy-track-range.js — copy levels from one track to another in a range (v120)
export function copyTrackRange(obj, srcTrack, dstTrack, rangeStr, startZ, repeatTo, opts={}){
  if (!obj || !Array.isArray(obj.rewards)) throw new Error('Invalid object');
  const m = String(rangeStr).match(/^(\d+)\s*(?:[:.][.:]?)\s*(\d+)$/);
  if (!m) throw new Error('Bad range');
  const [_, a, b] = m; const from = Math.min(+a, +b), to = Math.max(+a,+b);
  const srcSlice = obj.rewards.filter(l=>l.index>=from && l.index<=to).map(l=>({idx:l.index, node:l.rewards?.[srcTrack]}));
  const out = obj.rewards.map(x=>JSON.parse(JSON.stringify(x)));
  let cursor = +startZ;
  const limit = repeatTo ? +repeatTo : cursor + (to-from);
  while (cursor <= limit){
    for (const item of srcSlice){
      const at = cursor + (item.idx - from);
      let level = out.find(l=>l.index===at);
      if (!level){ level = { index: at, rewards: {} }; out.push(level); }
      if (opts.overwrite || !level.rewards[dstTrack]){
        level.rewards[dstTrack] = JSON.parse(JSON.stringify(item.node||{}));
        if (opts.autoRename){
          const disp = level.rewards[dstTrack]?.display;
          if (disp){ disp.name = `Level ${at}` + (dstTrack!=='free' ? ` ${dstTrack[0].toUpperCase()+dstTrack.slice(1)}` : ''); }
        }
      }
    }
    if (!repeatTo) break;
    cursor += (to-from+1);
  }
  out.sort((a,b)=>a.index-b.index);
  return { ...obj, rewards: out };
}

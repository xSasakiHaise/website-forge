// renumer.js — reindex levels sequentially (v120)
export function renumber(obj, start=1){
  if (!obj || !Array.isArray(obj.rewards)) return obj;
  let i = start;
  obj.rewards.forEach(l => { l.index = i++; });
  return obj;
}

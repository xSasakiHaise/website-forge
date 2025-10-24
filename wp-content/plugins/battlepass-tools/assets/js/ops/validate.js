// validate.js — basic sanity checks (v120)
export function validateBP(obj){
  const errs = [];
  if (!obj || typeof obj !== 'object') { errs.push('Root must be an object.'); return errs; }
  if (!Array.isArray(obj.rewards)) errs.push('Missing rewards array.');
  else {
    obj.rewards.forEach((lvl, idx)=>{
      if (typeof lvl.index !== 'number') errs.push(`Level[${idx}] missing numeric index`);
      if (!lvl.rewards || typeof lvl.rewards !== 'object') errs.push(`Level[${idx}] missing rewards map`);
    });
  }
  return errs;
}

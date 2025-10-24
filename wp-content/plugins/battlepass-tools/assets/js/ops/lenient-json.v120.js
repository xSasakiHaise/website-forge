// lenient-json.v120.js — safe state-machine lenient parser
export const LJ_VERSION = 'v120';
export function toStrictJSON(text) {
  if (text == null) return '';
  const s = String(text).replace(/\r\n?/g, '\n');
  let out = '', i = 0, n = s.length;
  let inDQ = false, inSL=false, inML=false, esc=false;
  const push = ch => out += ch;
  while (i<n) {
    const ch = s[i], next = i+1<n ? s[i+1] : '';
    if (!inDQ) {
      if (!inSL && !inML && ch==='/'&&next==='//'){inSL=true; i+=2; continue;}
      if (!inSL && !inML && ch==='/'&&next==='*'){inML=true; i+=2; continue;}
      if (inSL){ if (ch==='\n'){inSL=false; push('\n');} i++; continue; }
      if (inML){ if (ch==='*'&&next=== '/'){inML=false; i+=2;} else i++; continue; }
    }
    if (inDQ){ push(ch); if (esc) esc=false; else if (ch==='\\') esc=true; else if (ch==='"') inDQ=false; i++; continue; }
    if (ch === '"'){ inDQ=true; push(ch); i++; esc=false; continue; }
    if (ch === "'"){
      let j=i+1, acc='', escS=false;
      while (j<n){ const c2=s[j]; if (escS){acc+=c2; escS=false; j++; continue;}
        if (c2==='\\'){escS=true; acc+=c2; j++; continue;}
        if (c2=="'") break; acc+=c2; j++; }
      if (j>=n){ push(ch); i++; continue; }
      const dq = acc.replace(/\\/g,'\\\\').replace(/"/g,'\\"');
      push('"'+dq+'"'); i=j+1; continue;
    }
    if (ch===','){ let k=i+1; while(k<n && /\s/.test(s[k])) k++; if (k<n && (s[k]==='}'||s[k]===']')) {i++; continue;} }
    if (/[A-Za-z0-9_.$-]/.test(ch)){
      let k=i, key=''; while(k<n && /[A-Za-z0-9_.$-]/.test(s[k])){key+=s[k]; k++;}
      let t=k; while(t<n && /\s/.test(s[t])) t++; if (t<n && s[t]=== ':'){
        let p=i-1; while(p>=0 && /\s/.test(s[p])) p--; if (p<0 || s[p]==='{' || s[p]===','){ out+='"'+key+'"'; i=k; continue; }
      }
    }
    push(ch); i++;
  }
  return out.trim();
}
export function lenientParse(text){ return JSON.parse(toStrictJSON(text)); }
export function prettyJSON(obj){ return JSON.stringify(obj, null, 2); }

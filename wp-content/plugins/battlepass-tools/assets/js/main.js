// main.js — Battlepass Tools v1.2.0
import { dbgInit, dbgLog } from './utils.js';
dbgInit();
dbgLog('main -> app loading...');
(async () => {
  try {
    await import('./state.js?v=120');
    await import('./ui/app.js?v=120');
    dbgLog('main -> app loaded');
  } catch (err) {
    dbgLog('app import FAIL ' + (err && (err.message||err)));
    console.error(err);
  }
})();

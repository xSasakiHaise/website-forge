// =========================================================
// Hephaestus Forge — Global Embers (with plugin integration)
// =========================================================
(function () {
  function ready(fn){ if (document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  function ensureLayers(){
    if (!document.querySelector('.forge-bg-smoke')) {
      const s = document.createElement('div');
      s.className = 'forge-bg-smoke';
      s.setAttribute('aria-hidden','true');
      document.body.prepend(s);
    }
    if (!document.querySelector('.forge-ember-field')) {
      const f = document.createElement('div');
      f.className = 'forge-ember-field';
      f.id = 'forgeEmbers';
      f.setAttribute('aria-hidden','true');
      document.body.prepend(f);
    }
  }

  function registerWithEffects(api){
    try{
      if (typeof api.register === 'function') {
        api.register('smoke', {
          target: '.forge-bg-smoke',
          animation: ['forgeSmokeRise','forgeSmokeSwell']
        });
        api.register('embers', {
          container: '.forge-ember-field',
          particleClass: 'forge-ember',
          count: 36,
          rangeX: [-0.10, 1.10],  // fraction of viewport width
          drift: 0.08,            // sideways sway amplitude
          height: 120,            // vh climb
          size: [1.0, 3.6],       // px
          hue: [22, 34],          // warm orange/red
          light: [58, 70],        // %
          duration: [14, 26],     // seconds
          delay: [0, 1500],       // ms
          easing: [
            'cubic-bezier(.2,.0,.2,1)',
            'cubic-bezier(.3,.0,.2,1)',
            'cubic-bezier(.25,.1,.25,1)'
          ],
          twinkle: true
        });
        if (typeof api.enable === 'function') api.enable(['smoke','embers']);
        return true;
      }
    }catch(e){ /* noop */ }
    return false;
  }

  function fallbackSpawner(){
    const field = document.querySelector('.forge-ember-field');
    if (!field) return;

    const COUNT = 36;
    const DUR_MIN = 14, DUR_MAX = 26;
    const PAUSE_MIN = 120, PAUSE_MAX = 1500;
    const SIZE_MIN = 1, SIZE_MAX = 3.6;
    const HUES = [22,26,28,30,34];
    const EASINGS = [
      'cubic-bezier(.2,.0,.2,1)',
      'cubic-bezier(.3,.0,.2,1)',
      'cubic-bezier(.25,.1,.25,1)'
    ];
    const rand = (a,b)=> a + Math.random()*(b-a);
    const pick = arr => arr[(Math.random()*arr.length)|0];

    let paused = document.hidden;
    document.addEventListener('visibilitychange', ()=> { paused = document.hidden; });

    function spawn(){
      const e = document.createElement('div');
      e.className = 'forge-ember';
      field.appendChild(e);

      function schedule(){
        if (paused) return setTimeout(schedule, 500);

        const vw = window.innerWidth;
        const startX = rand(-vw*0.10, vw*1.10);
        const drift = () => startX + rand(-vw*0.08, vw*0.08);
        const path = [startX, drift(), drift(), drift(), drift()];
        const size = rand(SIZE_MIN, SIZE_MAX);
        const hue  = pick(HUES);
        const light= rand(58, 70);
        const dur  = rand(DUR_MIN, DUR_MAX);
        const ease = pick(EASINGS);
        const scale= rand(0.9, 1.2);

        e.style.setProperty('--x0', path[0]+'px');
        e.style.setProperty('--x1', path[1]+'px');
        e.style.setProperty('--x2', path[2]+'px');
        e.style.setProperty('--x3', path[3]+'px');
        e.style.setProperty('--x4', path[4]+'px');
        e.style.setProperty('--sz', size+'px');
        e.style.setProperty('--h',  hue);
        e.style.setProperty('--l',  light+'%');
        e.style.setProperty('--dur', dur+'s');
        e.style.setProperty('--sc', scale);
        e.style.setProperty('--easing', ease);

        e.style.animation = 'none'; void e.offsetWidth;
        e.style.animation = `emberFlight var(--dur) var(--easing) 1 both, emberTwinkle 2.2s ease-in-out infinite`;

        setTimeout(schedule, (dur*1000) + rand(PAUSE_MIN, PAUSE_MAX));
      }
      setTimeout(schedule, rand(0, 1500));
    }

    for (let i=0;i<COUNT;i++) spawn();
  }

  ready(function(){
    if (document.body.classList.contains('wp-admin')) return;

    // Ensure DOM containers exist even if theme doesn’t output them
    ensureLayers();

    // Integrate with existing effects core if available
    const api = window.ForgeEffects || window.HellasFX || window.HellasEffects || null;
    if (!registerWithEffects(api)) {
      fallbackSpawner();
    }
  });
})();

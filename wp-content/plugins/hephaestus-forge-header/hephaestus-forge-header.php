<?php
/**
 * Plugin Name: Hephaestus Forge Header
 * Description: Injects the Forge nav into the header on every page and removes older page-level copies.
 * Version: 1.0.0
 * Author: Hephaestus Forge
 */

if (!defined('ABSPATH')) exit;

function hfh_body_class(array $classes){
  if (is_page('privacy-policy')) {
    $classes[] = 'forge-privacy-page';
  }
  return $classes;
}
add_filter('body_class', 'hfh_body_class');

function hfh_wrap_privacy_content($content){
  if (!is_page('privacy-policy')) return $content;
  if (strpos($content, 'forge-privacy-wrap') !== false) return $content;
  return '<div class="forge-privacy-wrap"><div class="forge-privacy-inner">' . $content . '</div></div>';
}
add_filter('the_content', 'hfh_wrap_privacy_content', 20);

function hfh_privacy_styles(){
  if (!is_page('privacy-policy')) return;
  ?>
  <style>
    .forge-privacy-wrap{max-width:960px;margin:0 auto;padding:60px 24px 80px;}
    .forge-privacy-inner{background:rgba(12,10,9,.82);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:40px;box-shadow:0 18px 60px rgba(0,0,0,.45)}
    .forge-privacy-inner h1,.forge-privacy-inner h2,.forge-privacy-inner h3{color:#ffe9d6;letter-spacing:.4px}
    .forge-privacy-inner p,.forge-privacy-inner li{color:#f0e2cf;font-size:1.05rem;line-height:1.65}
    .forge-privacy-inner a{color:#ff944d;text-decoration:none;border-bottom:1px dashed currentColor}
    .forge-privacy-inner a:hover{filter:brightness(1.1)}
    @media(max-width:782px){.forge-privacy-inner{padding:26px}.forge-privacy-wrap{padding:40px 16px}}
  </style>
  <?php
}
add_action('wp_head', 'hfh_privacy_styles');

add_action('wp_body_open', function () {

  // --- styles (includes mobile tweaks + duplicate suppression) ---
  ?>
  <!-- HEPHAESTUS-FORGE-HEADER / auto-injected -->
  <style>
    /* put nav above floating logo on mobile */
    .forge-nav{ z-index:10050; }
    .logo-fixed{ z-index:1000; }

    /* hide any duplicate logos if page content still has them */
    .logo-fixed ~ .logo-fixed{ display:none !important; }

    /* ===================== CORE LAYOUT ===================== */
    .forge-nav { position: relative; z-index: 9000; background: transparent; isolation: isolate; }
    .forge-nav .bar { max-width: 1240px; margin: 0 auto; padding: 10px 18px; display: flex; align-items: center; gap: 20px; }

    /* ===================== BRAND GLOW ===================== */
    .forge-brand{ display:flex; align-items:center; gap:12px; text-decoration:none; font-weight:700; color:#f5f2ec; }
    .forge-brand-logo{ width:46px; height:46px; object-fit:contain; border-radius:12px; box-shadow:0 12px 28px rgba(0,0,0,.45); background:rgba(0,0,0,.35); padding:6px; }
    .forge-brand-text{
      font-weight:900; font-size:22px; letter-spacing:.25px;
      background: linear-gradient(90deg,#ff3d2e 0%,#ff5a22 25%,#ff7a1e 50%,#ff9c35 75%,#ffb347 100%);
      -webkit-background-clip:text; background-clip:text; color:transparent;
      text-shadow:0 0 12px rgba(255,60,20,.25),0 0 24px rgba(255,100,40,.18),0 0 48px rgba(255,120,48,.12);
      transition: filter .3s ease, text-shadow .3s ease;
    }
    .forge-brand:hover .forge-brand-text{ filter:brightness(1.15);
      text-shadow:0 0 18px rgba(255,80,20,.35),0 0 36px rgba(255,120,40,.25),0 0 64px rgba(255,150,60,.20);
    }

    /* ===================== MENU CORE ===================== */
    .forge-menu{ list-style:none; margin:0; padding:0; display:flex; gap:26px; align-items:center; }
    .forge-menu>li{ position:relative; }

    .forge-link,.dd-btn,.sub-dd-btn{
      appearance:none; -webkit-appearance:none; background:transparent; border:0; color:#f5f2ec; font:inherit; cursor:pointer;
      text-decoration:none; font-weight:650; padding:8px 8px; border-radius:10px; display:inline-flex; align-items:center; gap:6px;
    }
    .forge-link:hover,.dd-btn:hover,.sub-dd-btn:hover{ background:rgba(255,255,255,.06); }
    .caret{ opacity:.9; transform:translateY(1px); }

    /* ===================== DROPDOWN CORE ===================== */
    .dropdown{
      position:absolute; left:0; top:110%; min-width:260px; padding:10px;
      background:rgba(12,10,9,.88); backdrop-filter:saturate(140%) blur(8px); -webkit-backdrop-filter:saturate(140%) blur(8px);
      border:1px solid rgba(255,255,255,.10); border-radius:12px;
      box-shadow: 0 18px 36px rgba(0,0,0,.45), 0 0 24px rgba(255,120,48,.18);
      opacity:0; transform:translateY(8px) scale(.98); visibility:hidden; pointer-events:none;
      transition:opacity .18s ease, transform .18s ease, visibility .18s; z-index:10000;
    }
    .dropdown a{ display:block; padding:10px 12px; border-radius:8px; color:#fff; text-decoration:none; font-weight:700; }
    .dropdown a:hover{ background:rgba(255,255,255,.07); }
    .dropdown li{ position:relative; list-style:none; }
    .dropdown .dropdown{ left:100%; top:0; margin-left:8px; }
    .dropdown.dropdown--list{ max-height:65vh; overflow:auto; }
    .dropdown.dropdown--hellas{ display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:4px; }
    .dropdown.dropdown--hellas>li:first-child{ grid-column:1/-1; }
    .dropdown.dropdown--hellas a{ white-space:normal; }

    @media (hover:hover){
      .forge-menu>li:hover>.dropdown,
      .dropdown li:hover>.dropdown{
        opacity:1; transform:translateY(0) scale(1); visibility:visible; pointer-events:auto;
      }
    }

    /* ===================== RIGHT BUTTON ===================== */
    .spacer{ flex:1; }
    .right-link{
      text-decoration:none; font-weight:800; padding:9px 16px; border-radius:9999px; color:#120909;
      background:linear-gradient(135deg,#ff4d3b,#ff8a3a); box-shadow:0 6px 20px rgba(255,77,59,.35);
    }
    .right-link:hover{ filter:brightness(1.05); }

    /* ===================== STAFF VISIBILITY ===================== */
    .dropdown a.requires-login{ display:none; }
    body.logged-in .dropdown a.requires-login{ display:block; }

    /* ===================== MOBILE ===================== */
    .nav-toggle{
      margin-left:8px; background:transparent; border:1px solid rgba(255,255,255,.18); color:#f5f2ec;
      border-radius:10px; padding:8px 10px; display:none; cursor:pointer;
    }
    @media (max-width:900px){
      .nav-toggle{ display:inline-block; }
      .forge-menu{
        position:absolute; left:0; right:0; top:56px; flex-direction:column; gap:8px; padding:10px 14px;
        background:rgba(0,0,0,.55); backdrop-filter:blur(6px); border-top:1px solid rgba(255,255,255,.08); display:none;
      }
      .forge-menu.open{ display:flex; }
      .dropdown{
        position:static; margin-left:8px; opacity:1; transform:none; visibility:visible; pointer-events:auto; display:none;
        background:rgba(12,10,9,.92);
      }
      .forge-menu .open>.dropdown{ display:block; }
    }
    .forge-link:focus,.dd-btn:focus,.sub-dd-btn:focus,.dropdown a:focus{
      outline:2px solid rgba(255,138,58,.7); outline-offset:2px; border-radius:10px;
    }

    /* push first content down a bit on small screens to avoid logo overlap if present */
    @media (max-width:900px){
      .forge-hero,.forge-card:first-child,.about-wrap,.ha-wrap,.projects-wrap,.forge-container { margin-top:100px !important; }
    }
  </style>

  <?php
  $default_modules = function_exists('hfpm_default_suite_modules')
    ? hfpm_default_suite_modules()
    : [
        'hellasaudio'       => 'HellasAudio',
        'hellasbattlebuddy' => 'HellasBattlebuddy',
        'hellascontrol'     => 'HellasControl',
        'hellasdeck'        => 'HellasDeck',
        'hellaselo'         => 'HellasElo',
        'hellasforms'       => 'HellasForms',
        'hellasgardens'     => 'HellasGardens',
        'hellashelper'      => 'HellasHelper',
        'hellaslibrary'     => 'HellasLibrary',
        'hellasmineralogy'  => 'HellasMineralogy',
        'hellaspatcher'     => 'HellasPatcher',
        'hellastextures'    => 'HellasTextures',
        'hellaswilds'       => 'HellasWilds',
      ];

  $hellas_projects = [];
  foreach ($default_modules as $slug => $label) {
    $hellas_projects[] = [
      'label' => $label,
      'url'   => home_url('/projects/' . $slug . '/'),
    ];
  }

  $other_projects = [];

  if (function_exists('hfpm_projects_registry')) {
    $registry = hfpm_projects_registry(['include_private' => false]);
    $generated_hellas = [];
    $generated_other  = [];
    foreach ($registry as $slug => $row) {
      $status = $row['status'] ?? 'available';
      if ($status === 'private') continue;
      $label = $row['title'] ?? $slug;
      $side  = strtolower(trim((string)($row['side'] ?? '')));
      $url   = home_url('/projects/' . $slug . '/');
      if (in_array($side, ['other','external'], true)) {
        $generated_other[] = ['label'=>$label, 'url'=>$url];
      } else {
        $generated_hellas[] = ['label'=>$label, 'url'=>$url];
      }
    }
    if ($generated_hellas) {
      $hellas_projects = $generated_hellas;
    }
    if ($generated_other) {
      $other_projects = $generated_other;
    }
  }

  $site_name = get_bloginfo('name');
  if (!$site_name) $site_name = 'Hephaestus Forge';
  $logo_html = '';
  if (function_exists('get_theme_mod')) {
    $logo_id = get_theme_mod('custom_logo');
    if ($logo_id) {
      $logo_src = wp_get_attachment_image_src($logo_id, 'medium');
      if ($logo_src) {
        $logo_html = '<img class="forge-brand-logo" src="' . esc_url($logo_src[0]) . '" alt="' . esc_attr($site_name) . '">';
      }
    }
  }

  // --- HTML NAV (your baseline, with “Overview” -> /projects/) ---
  ?>
  <nav class="forge-nav" aria-label="Primary">
    <div class="bar">
      <a href="<?php echo esc_url(home_url('/')); ?>" class="forge-brand">
        <?php echo $logo_html; ?>
        <span class="forge-brand-text"><?php echo esc_html($site_name); ?></span>
      </a>

      <ul id="forgeMenu" class="forge-menu">
        <li><a class="forge-link" href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">Projects <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown dropdown--list">
            <li style="position:relative">
              <button class="sub-dd-btn" aria-expanded="false">Hellas Projects <span class="caret">▸</span></button>
              <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;right:-14px;width:14px;"></div>
              <ul class="dropdown dropdown--hellas dropdown--list">
                <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;left:-10px;width:10px;"></div>
                <li><a href="<?php echo esc_url(home_url('/projects/')); ?>">Overview</a></li>
                <?php foreach ($hellas_projects as $proj): ?>
                  <li><a href="<?php echo esc_url($proj['url']); ?>"><?php echo esc_html($proj['label']); ?></a></li>
                <?php endforeach; ?>
              </ul>
            </li>

            <?php if (!empty($other_projects)): ?>
              <li style="position:relative">
                <button class="sub-dd-btn" aria-expanded="false">Our Other Projects <span class="caret">▸</span></button>
                <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;right:-14px;width:14px;"></div>
                <ul class="dropdown dropdown--list">
                  <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;left:-10px;width:10px;"></div>
                  <?php foreach ($other_projects as $proj): ?>
                    <li><a href="<?php echo esc_url($proj['url']); ?>"><?php echo esc_html($proj['label']); ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </li>
            <?php endif; ?>
          </ul>
        </li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">License <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown dropdown--list">
            <li><a href="<?php echo esc_url(home_url('/license/')); ?>">My License</a></li>
            <li><a href="<?php echo esc_url(home_url('/license/downloads/')); ?>">Downloads</a></li>
          </ul>
        </li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">About <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown dropdown--list">
            <li><a href="<?php echo esc_url(home_url('/about/')); ?>">About the Forge</a></li>
            <li><a href="<?php echo esc_url(home_url('/legal/')); ?>">Legal</a></li>
            <li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">Privacy Policy</a></li>
            <li><a href="<?php echo esc_url(home_url('/terms/')); ?>">Terms</a></li>
          </ul>
        </li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">Staff <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown dropdown--list">
            <li><a href="<?php echo esc_url(home_url('/staff/')); ?>">Staff Info</a></li>
            <li><a href="<?php echo esc_url(home_url('/staff/login/')); ?>">Staff Login</a></li>
            <li><a class="requires-login" href="<?php echo esc_url(home_url('/staff/tickets/')); ?>">Staff Tickets</a></li>
          </ul>
        </li>
      </ul>

      <div class="spacer"></div>
      <a class="right-link" href="https://hellasregion.com" target="_blank" rel="noopener">Our Server</a>
      <button class="nav-toggle" aria-expanded="false" aria-controls="forgeMenu">Menu</button>
    </div>
  </nav>

  <script>
  (function(){
    // Mobile menu toggle
    const toggle=document.querySelector('.nav-toggle');
    const menu=document.getElementById('forgeMenu');
    toggle && toggle.addEventListener('click',()=>{
      const open=menu.classList.toggle('open');
      toggle.setAttribute('aria-expanded',open?'true':'false');
    });

    // Touch-friendly open/close for dropdowns
    document.querySelectorAll('.dd-btn, .sub-dd-btn').forEach(btn=>{
      const li=btn.parentElement;
      const panel=li.querySelector('.dropdown');
      btn.addEventListener('click',(e)=>{
        if (matchMedia('(hover:none)').matches || e.pointerType==='touch' || e.detail===0){
          e.preventDefault();
          const isOpen=li.classList.toggle('open');
          btn.setAttribute('aria-expanded',isOpen?'true':'false');
          if (window.matchMedia('(max-width:900px)').matches){
            panel.style.display=isOpen?'block':'none';
          }
        }
      });
    });

    // Close menus when clicking outside
    document.addEventListener('click',(e)=>{
      const bar=document.querySelector('.forge-nav .bar');
      if(!bar.contains(e.target)){
        menu.classList.remove('open');
        document.querySelectorAll('.forge-menu>li.open, .dropdown li.open').forEach(li=>li.classList.remove('open'));
        toggle && toggle.setAttribute('aria-expanded','false');
      }
    });

    // Remove old page-level copies (keep the first .forge-nav and first .logo-fixed)
    document.addEventListener('DOMContentLoaded', function(){
      const navs = document.querySelectorAll('.forge-nav');
      for (let i=1;i<navs.length;i++){ navs[i].remove(); }
      document.querySelectorAll('.logo-fixed, .custom-logo-link').forEach(function(node){
        if (!node.closest('.forge-nav')) {
          node.remove();
        }
      });
    });
  })();
  </script>
  <?php
});

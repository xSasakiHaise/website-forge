<?php
/**
 * Plugin Name: Hephaestus Forge Header
 * Description: Injects the Forge nav into the header on every page and removes older page-level copies.
 * Version: 1.0.0
 * Author: Hephaestus Forge
 */

if (!defined('ABSPATH')) exit;

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
    .forge-nav .bar { max-width: 1200px; margin: 0 auto; padding: 10px 16px; display: flex; align-items: center; gap: 18px; }

    /* ===================== BRAND GLOW ===================== */
    .forge-brand{
      font-weight: 900; font-size: 22px; letter-spacing: .25px; text-decoration: none;
      background: linear-gradient(90deg,#ff3d2e 0%,#ff5a22 25%,#ff7a1e 50%,#ff9c35 75%,#ffb347 100%);
      -webkit-background-clip: text; background-clip: text; color: transparent;
      text-shadow: 0 0 12px rgba(255,60,20,.25), 0 0 24px rgba(255,100,40,.18), 0 0 48px rgba(255,120,48,.12);
      transition: filter .3s ease, text-shadow .3s ease;
    }
    .forge-brand:hover{ filter:brightness(1.15);
      text-shadow: 0 0 18px rgba(255,80,20,.35), 0 0 36px rgba(255,120,40,.25), 0 0 64px rgba(255,150,60,.20);
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
  // --- HTML NAV (your baseline, with “Overview” -> /projects/) ---
  ?>
  <nav class="forge-nav" aria-label="Primary">
    <div class="bar">
      <a href="/" class="forge-brand">Hephaestus Forge</a>

      <ul id="forgeMenu" class="forge-menu">
        <li><a class="forge-link" href="/">Home</a></li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">Projects <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown">
            <li style="position:relative">
              <button class="sub-dd-btn" aria-expanded="false">Hellas Projects <span class="caret">▸</span></button>
              <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;right:-14px;width:14px;"></div>
              <ul class="dropdown">
                <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;left:-10px;width:10px;"></div>
                <li><a href="/projects/">Overview</a></li>
                <li><a href="/projects/hellasaudio/">HellasAudio</a></li>
                <li><a href="/projects/hellasbattlebuddy/">HellasBattlebuddy</a></li>
                <li><a href="/projects/hellascontrol/">HellasControl</a></li>
                <li><a href="/projects/hellasdeck/">HellasDeck</a></li>
                <li><a href="/projects/hellaselo/">HellasElo</a></li>
                <li><a href="/projects/hellasforms/">HellasForms</a></li>
                <li><a href="/projects/hellasgardens/">HellasGardens</a></li>
                <li><a href="/projects/hellashelper/">HellasHelper</a></li>
                <li><a href="/projects/hellasmineralogy/">HellasMineralogy</a></li>
                <li><a href="/projects/hellaspatcher/">HellasPatcher</a></li>
                <li><a href="/projects/hellastextures/">HellasTextures</a></li>
              </ul>
            </li>

            <li style="position:relative">
              <button class="sub-dd-btn" aria-expanded="false">Our Other Projects <span class="caret">▸</span></button>
              <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;right:-14px;width:14px;"></div>
              <ul class="dropdown">
                <div aria-hidden="true" style="position:absolute;top:-6px;bottom:-6px;left:-10px;width:10px;"></div>
                <li><a href="/projects/other/placeholder-1/">Unnamed Project 1</a></li>
                <li><a href="/projects/other/placeholder-2/">Unnamed Project 2</a></li>
                <li><a href="/projects/other/placeholder-3/">Unnamed Project 3</a></li>
              </ul>
            </li>
          </ul>
        </li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">License <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown">
            <li><a href="/license/">My License</a></li>
            <li><a href="/license/downloads/">Downloads</a></li>
          </ul>
        </li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">About <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown">
            <li><a href="/about/">About the Forge</a></li>
            <li><a href="/legal/">Legal</a></li>
            <li><a href="/privacy-policy/">Privacy Policy</a></li>
            <li><a href="/terms/">Terms</a></li>
          </ul>
        </li>

        <li style="position:relative">
          <button class="dd-btn" aria-expanded="false">Staff <span class="caret">▾</span></button>
          <div aria-hidden="true" style="position:absolute;left:0;right:0;top:100%;height:12px;"></div>
          <ul class="dropdown">
            <li><a href="/staff/">Staff Info</a></li>
            <li><a href="/staff/login/">Staff Login</a></li>
            <li><a class="requires-login" href="/staff/tickets/">Staff Tickets</a></li>
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
      const logos = document.querySelectorAll('.logo-fixed');
      for (let i=1;i<logos.length;i++){ logos[i].remove(); }
    });
  })();
  </script>
  <?php
});

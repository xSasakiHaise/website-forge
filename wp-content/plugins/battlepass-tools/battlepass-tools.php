<?php
/**
 * Plugin Name: Battlepass Tools
 * Description: Staff-only battlepass rewards editor (HBPT 1.2.0) — inline styles, lenient JSON.
 * Version: 1.2.0
 * Author: Hephaestus Forge
 */
if (!defined('ABSPATH')) exit;

function hbpt_staff_only() {
  if (function_exists('forge_staff_current')) return !!forge_staff_current();
  return current_user_can('manage_options');
}

add_shortcode('heph_battlepass_tools', function(){
  if (!hbpt_staff_only()) return '<div style="color:#ff9b4a;background:rgba(35,20,12,.9);border:1px solid rgba(255,155,74,.4);padding:16px;border-radius:10px;">Staff only.</div>';
  ob_start(); ?>
    <div data-battlepass-root style="max-width:1400px;margin:32px auto;padding:0 16px;"></div>
    <script type="module" src="<?php echo esc_url( plugins_url('assets/js/main.js?v=120', __FILE__) ); ?>"></script>
  <?php return ob_get_clean();
});

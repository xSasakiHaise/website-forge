<?php
/**
 * Plugin Name: Hephaestus Forge — Projects Meta
 * Description: Single source of truth for Hellas projects (cards + pricing + taxonomy). Adds unified "Hellas Suite" admin, layered cards, project pages, and adoption tools.
 * Version: 1.7.1
 * Author: xSasaki_Haise
 */

if (!defined('ABSPATH')) exit;

define('HFPM_SLUG',  'hf_projects_meta');
define('HFPM_VER',   '1.7.1');
define('HFPM_SUITE', 'hellas_suite_root'); // unified admin parent slug

/* -------------------------------------------------------------------------- */
/*  A) CPT + TAX                                                              */
/* -------------------------------------------------------------------------- */
add_action('init', function () {
  register_post_type('hellas_project', [
    'labels' => [
      'name'          => 'Projects',
      'singular_name' => 'Project',
      'add_new_item'  => 'Add Project',
      'edit_item'     => 'Edit Project'
    ],
    'public'       => true,
    'show_ui'      => true,
    // IMPORTANT: show CPT ONLY under Hellas Suite (no separate top-level menu)
    'show_in_menu' => HFPM_SUITE,
    'supports'     => ['title','editor','thumbnail','custom-fields'],
    'has_archive'  => false,
    'rewrite'      => ['slug' => 'project'],
    'show_in_rest' => true
  ]);

  register_taxonomy('hellas_project_tax', ['hellas_project','forge_ticket'], [
    'label'        => 'Project',
    'public'       => true,
    'hierarchical' => false,
    'rewrite'      => false,
    'show_ui'      => true,
    'show_in_rest' => true
  ]);
});

/* -------------------------------------------------------------------------- */
/*  B) Unified "Hellas Suite" admin menu                                      */
/* -------------------------------------------------------------------------- */
/*
  Other plugins can register submenus here without coupling by calling:

    do_action('hellas_suite_register_menu', [
      ['title'=>'Tickets', 'menu_title'=>'Tickets', 'cap'=>'manage_options', 'slug'=>'hf_redirect_tickets', 'target'=>'admin.php?page=YOUR_TICKETS_MENU_SLUG'],
      ['title'=>'License Checkup', 'menu_title'=>'License Checkup', 'cap'=>'manage_options', 'slug'=>'hf_redirect_license', 'target'=>'admin.php?page=YOUR_LICENSE_MENU_SLUG'],
    ]);
*/

add_action('admin_menu', function () {
  // Top-level hub
  add_menu_page('Hellas Suite', 'Hellas Suite', 'manage_options', HFPM_SUITE, 'hfpm_suite_dashboard', 'dashicons-hammer', 25);

  // Our meta table UI
  add_submenu_page(HFPM_SUITE, 'Projects (Meta List)', 'Projects (Meta)', 'manage_options', HFPM_SLUG, 'hfpm_admin_page');

  // Tools
  add_submenu_page(HFPM_SUITE, 'Adopt Existing Pages', 'Adopt Pages', 'manage_options', 'hfpm_adopt_pages', 'hfpm_adopt_pages_screen');

  // Dashboard content
  function hfpm_suite_dashboard(){
    echo '<div class="wrap"><h1>Hellas Suite</h1><p>Central hub for Hellas tools. Use the submenu on the left.</p></div>';
  }
}, 9);

// Allow external plugins to register themselves under Hellas Suite without tight coupling
add_action('hellas_suite_register_menu', function(array $items){
  foreach ($items as $it){
    $title = $it['title'] ?? 'Tool';
    $menu  = $it['menu_title'] ?? $title;
    $cap   = $it['cap'] ?? 'manage_options';
    $slug  = $it['slug'] ?? sanitize_title($title);
    $target= $it['target'] ?? admin_url();

    add_submenu_page(HFPM_SUITE, $title, $menu, $cap, $slug, function() use ($target){
      wp_safe_redirect( admin_url($target) );
      exit;
    });
  }
});

/* -------------------------------------------------------------------------- */
/*  C) Master list option (with pricing split)                                 */
/* -------------------------------------------------------------------------- */
function hfpm_get_projects_option(){ $list = get_option('hfpm_projects', []); return is_array($list)? $list:[]; }
function hfpm_set_projects_option($arr){ update_option('hfpm_projects', array_values($arr)); }

/* -------------------------------------------------------------------------- */
/*  D) Admin screen (meta table)                                              */
/* -------------------------------------------------------------------------- */
add_action('admin_menu', function () { /* already under Hellas Suite */ });

function hfpm_admin_page() {
  if (!current_user_can('manage_options')) return;

  // Save
  if (!empty($_POST['hfpm_nonce']) && wp_verify_nonce($_POST['hfpm_nonce'],'hfpm_save')) {
    $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : [];
    $clean = [];
    foreach ($rows as $r) {
      $title = trim(sanitize_text_field($r['title'] ?? ''));
      if ($title === '') continue;
      $slug  = sanitize_title($r['slug'] ?? $title);
      $clean[] = [
        'title'        => $title,
        'slug'         => $slug,
        'version'      => sanitize_text_field($r['version'] ?? ''),
        'side'         => sanitize_text_field($r['side'] ?? ''),
        'blurb'        => wp_kses_post($r['blurb'] ?? ''),
        'icon'         => esc_url_raw($r['icon'] ?? ''),
        'learn_more'   => esc_url_raw($r['learn_more'] ?? ''),
        // pricing split:
        'price_month'  => sanitize_text_field($r['price_month'] ?? ''),
        'price_year'   => sanitize_text_field($r['price_year'] ?? ''),
        'price_life'   => sanitize_text_field($r['price_life'] ?? ''),
        'status'       => sanitize_text_field($r['status'] ?? 'available'), // available|free|coming_soon|private
        'price_notes'  => sanitize_text_field($r['price_notes'] ?? ''),
      ];
    }
    usort($clean, fn($a,$b)=> strcasecmp($a['title'],$b['title']));
    hfpm_set_projects_option($clean);
    hfpm_sync_cpt_and_terms($clean);
    echo '<div class="updated"><p>Saved & synced.</p></div>';
  }

  $rows = hfpm_get_projects_option();
  ?>
  <div class="wrap">
    <h1>Hellas Projects (Meta)</h1>
    <form method="post">
      <?php wp_nonce_field('hfpm_save','hfpm_nonce'); ?>
      <p>Master list. Adding here auto-creates/updates Project posts and the shared taxonomy used by Tickets. Sorted A→Z.</p>

      <table class="widefat striped" id="hfpm-table">
        <thead>
          <tr>
            <th style="width:16%">Title</th>
            <th style="width:12%">Slug</th>
            <th style="width:10%">Version</th>
            <th style="width:10%">Side</th>
            <th>Blurb</th>
            <th style="width:18%">Icon URL</th>
            <th style="width:18%">Learn More URL</th>
          </tr>
          <tr>
            <th>€ Monthly</th>
            <th>€ Yearly</th>
            <th>€ Lifetime</th>
            <th>Status</th>
            <th colspan="3">Price notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i=>$r): ?>
            <tr>
              <td><input name="rows[<?php echo $i;?>][title]"  type="text" value="<?php echo esc_attr($r['title']);?>"  class="regular-text"></td>
              <td><input name="rows[<?php echo $i;?>][slug]"   type="text" value="<?php echo esc_attr($r['slug']);?>"   class="regular-text"></td>
              <td><input name="rows[<?php echo $i;?>][version]"type="text" value="<?php echo esc_attr($r['version']);?>"class="regular-text"></td>
              <td>
                <select name="rows[<?php echo $i;?>][side]">
                  <?php foreach (['client','server','both'] as $side): ?>
                    <option value="<?php echo $side;?>" <?php selected($r['side']??'', $side);?>><?php echo ucfirst($side);?></option>
                  <?php endforeach;?>
                </select>
              </td>
              <td><textarea name="rows[<?php echo $i;?>][blurb]" rows="2" style="width:100%"><?php echo esc_textarea($r['blurb'] ?? ''); ?></textarea></td>
              <td><input name="rows[<?php echo $i;?>][icon]"       type="url" value="<?php echo esc_attr($r['icon'] ?? '');?>" class="regular-text"></td>
              <td><input name="rows[<?php echo $i;?>][learn_more]" type="url" value="<?php echo esc_attr($r['learn_more'] ?? '');?>" class="regular-text"></td>
            </tr>
            <tr>
              <td><input name="rows[<?php echo $i; ?>][price_month]" type="text" value="<?php echo esc_attr($r['price_month'] ?? '');?>" class="regular-text"></td>
              <td><input name="rows[<?php echo $i; ?>][price_year]"  type="text" value="<?php echo esc_attr($r['price_year']  ?? '');?>" class="regular-text"></td>
              <td><input name="rows[<?php echo $i; ?>][price_life]"  type="text" value="<?php echo esc_attr($r['price_life']  ?? '');?>" class="regular-text"></td>
              <td>
                <select name="rows[<?php echo $i;?>][status]">
                  <?php foreach (['available'=>'Available','free'=>'Free','coming_soon'=>'Coming soon','private'=>'Private'] as $k=>$label): ?>
                    <option value="<?php echo $k;?>" <?php selected(($r['status']??'available'),$k);?>><?php echo $label;?></option>
                  <?php endforeach;?>
                </select>
              </td>
              <td colspan="3"><input name="rows[<?php echo $i;?>][price_notes]" type="text" value="<?php echo esc_attr($r['price_notes'] ?? '');?>" class="regular-text" style="width:100%"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p><button id="hfpm-add" class="button button-primary" type="button">+ Add Project</button></p>
      <p><button class="button button-primary" type="submit">Save</button></p>
    </form>
  </div>
  <style>
    #hfpm-table input, #hfpm-table select { width:100% }
    #hfpm-table td { vertical-align:top }
    #hfpm-table thead tr:nth-child(2) th { font-weight:600; }
    #hfpm-table tbody tr + tr { border-bottom: 1px solid #ddd; }
    .link-delete { color:#b00 }
  </style>
  <script>
    (function(){
      const tbody = document.querySelector('#hfpm-table tbody');
      const addBtn= document.getElementById('hfpm-add');
      addBtn?.addEventListener('click', ()=>{
        const i = Math.floor(tbody.querySelectorAll('tr').length / 2); // two rows per project
        const tpl = `
          <tr>
            <td><input name="rows[${i}][title]" type="text" class="regular-text"></td>
            <td><input name="rows[${i}][slug]" type="text" class="regular-text"></td>
            <td><input name="rows[${i}][version]" type="text" class="regular-text"></td>
            <td>
              <select name="rows[${i}][side]"><option value="client">Client</option><option value="server">Server</option><option value="both" selected>Both</option></select>
            </td>
            <td><textarea name="rows[${i}][blurb]" rows="2" style="width:100%"></textarea></td>
            <td><input name="rows[${i}][icon]" type="url" class="regular-text"></td>
            <td><input name="rows[${i}][learn_more]" type="url" class="regular-text"></td>
          </tr>
          <tr>
            <td><input name="rows[${i}][price_month]" type="text" class="regular-text"></td>
            <td><input name="rows[${i}][price_year]" type="text" class="regular-text"></td>
            <td><input name="rows[${i}][price_life]" type="text" class="regular-text"></td>
            <td>
              <select name="rows[${i}][status]"><option value="available">Available</option><option value="free">Free</option><option value="coming_soon">Coming soon</option><option value="private">Private</option></select>
            </td>
            <td colspan="3"><input name="rows[${i}][price_notes]" type="text" class="regular-text" style="width:100%"></td>
          </tr>`;
        const frag = document.createElement('tbody'); frag.innerHTML = tpl;
        while (frag.firstChild) tbody.appendChild(frag.firstChild);
      });
    })();
  </script>
  <?php
}

/* -------------------------------------------------------------------------- */
/*  E) Sync option → CPT + TAX + pricing meta                                  */
/* -------------------------------------------------------------------------- */
function hfpm_allow_delete(){ return apply_filters('hfpm_allow_delete', true); } // safety toggle

function hfpm_sync_cpt_and_terms(array $rows) {
  foreach ($rows as $r) {
    $slug  = $r['slug'];
    $title = $r['title'];
    $post  = get_page_by_path($slug, OBJECT, 'hellas_project');

    if (!$post) {
      $post_id = wp_insert_post([
        'post_type'   => 'hellas_project',
        'post_status' => 'publish',
        'post_title'  => $title,
        'post_name'   => $slug,
        'post_content'=> wp_kses_post($r['blurb'])
      ]);
    } else {
      $post_id = $post->ID;
      wp_update_post([
        'ID'          => $post_id,
        'post_title'  => $title,
        'post_content'=> wp_kses_post($r['blurb'])
      ]);
    }

    if ($post_id && !is_wp_error($post_id)) {
      update_post_meta($post_id, '_hfpm_version', $r['version'] ?? '');
      update_post_meta($post_id, '_hfpm_side',    $r['side']    ?? '');
      update_post_meta($post_id, '_hfpm_icon',    $r['icon']    ?? '');
      update_post_meta($post_id, '_hfpm_more',    $r['learn_more'] ?? '');
      // pricing split
      update_post_meta($post_id, '_hfpm_price_month',  $r['price_month'] ?? '');
      update_post_meta($post_id, '_hfpm_price_year',   $r['price_year']  ?? '');
      update_post_meta($post_id, '_hfpm_price_life',   $r['price_life']  ?? '');
      update_post_meta($post_id, '_hfpm_status',       $r['status']      ?? 'available');
      update_post_meta($post_id, '_hfpm_price_notes',  $r['price_notes'] ?? '');

      // taxonomy
      $term = term_exists($title, 'hellas_project_tax');
      if (!$term) $term = wp_insert_term($title, 'hellas_project_tax');
      if (!is_wp_error($term)) wp_set_object_terms($post_id, intval($term['term_id']), 'hellas_project_tax', false);
    }
  }

  if (hfpm_allow_delete()) {
    $keep_slugs  = array_map(fn($x)=>$x['slug'], $rows);
    $keep_titles = array_map(fn($x)=>$x['title'], $rows);

    $q = new WP_Query(['post_type'=>'hellas_project','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']);
    foreach ($q->posts as $pid) {
      $p = get_post($pid);
      if ($p && !in_array($p->post_name, $keep_slugs, true)) wp_delete_post($pid, true);
    }

    $terms = get_terms(['taxonomy'=>'hellas_project_tax','hide_empty'=>false]);
    foreach ($terms as $t) {
      if (!in_array($t->name, $keep_titles, true)) wp_delete_term($t->term_id, 'hellas_project_tax');
    }
  }
}

register_activation_hook(__FILE__, function(){
  hfpm_sync_cpt_and_terms(hfpm_get_projects_option());
});

/**
 * Auto-index Pages that are children of /projects/ into the Meta table.
 * When you create /projects/{slug} as a Page, this creates/updates the meta row
 * and then syncs the `hellas_project` post so it shows in the grid immediately.
 */
add_action('save_post_page', function($post_id){
  // Basic guards
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_page', $post_id)) return;
  $p = get_post($post_id);
  if (!$p || $p->post_status === 'auto-draft') return;

  // Must be an immediate child of the "projects" page
  $projects_parent = get_page_by_path('projects', OBJECT, 'page');
  if (!$projects_parent || intval($p->post_parent) !== intval($projects_parent->ID)) return;

  $slug  = sanitize_title($p->post_name ?: $p->post_title);
  $title = trim($p->post_title);

  // Load current list
  $rows = hfpm_get_projects_option();

  // Find existing row by slug
  $idx = -1;
  foreach ($rows as $i=>$r) {
    if (($r['slug'] ?? '') === $slug) { $idx = $i; break; }
  }

  // Defaults for new rows
  $defaults = [
    'title'       => $title ?: ucfirst($slug),
    'slug'        => $slug,
    'version'     => '',
    'side'        => 'both',
    'blurb'       => '',          // keep empty so you can set card/page texts separately below
    'icon'        => '',
    'learn_more'  => '',
    'price_month' => '',
    'price_year'  => '',
    'price_life'  => '',
    'status'      => 'available',
    'price_notes' => '',
  ];

  if ($idx === -1) {
    // Insert new meta row
    $rows[] = $defaults;
  } else {
    // Merge (don’t overwrite any non-empty values the user already set in Meta UI)
    $current = $rows[$idx];
    foreach ($defaults as $k=>$v) {
      if (!isset($current[$k]) || $current[$k] === '') {
        $current[$k] = $v;
      }
    }
    // Keep the (possibly updated) page title in sync if meta title was empty
    if (empty($current['title']) && $title) $current['title'] = $title;
    $rows[$idx] = $current;
  }

  // Sort A→Z and save + sync
  usort($rows, fn($a,$b)=> strcasecmp($a['title'],$b['title']));
  hfpm_set_projects_option($rows);
  hfpm_sync_cpt_and_terms($rows);
}, 20);

/* -------------------------------------------------------------------------- */
/*  F) Card Designer — per-Project metabox (image + desc + 5 layers)          */
/* -------------------------------------------------------------------------- */
add_action('add_meta_boxes', function(){
  add_meta_box('hfpm_card','Hellas Card Designer','hfpm_card_box','hellas_project','normal','high');
});

add_action('admin_enqueue_scripts', function($hook){
  if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
  $screen = get_current_screen();
  if (!$screen || $screen->post_type !== 'hellas_project') return;
  wp_enqueue_media();
  wp_add_inline_script('jquery-core', <<<JS
  (function($){
    function bindPicker(row){
      row.find('.hfpm-pick').off('click').on('click', function(e){
        e.preventDefault();
        const target = $(this).data('target');
        const input  = row.find('input[name="'+target+'"]');
        const imgEl  = row.find('img.hfpm-preview[data-target="'+target+'"]');
        const frame = wp.media({ title:'Select image', multiple:false, library:{type:'image'} });
        frame.on('select', ()=>{
          const sel = frame.state().get('selection').first().toJSON();
          input.val(sel.id);
          imgEl.attr('src', (sel.sizes && sel.sizes.medium ? sel.sizes.medium.url : sel.url)).show();
        });
        frame.open();
      });
      row.find('.hfpm-clear').off('click').on('click', function(e){
        e.preventDefault();
        const target = $(this).data('target');
        row.find('input[name="'+target+'"]').val('');
        row.find('img.hfpm-preview[data-target="'+target+'"]').attr('src','').hide();
      });
    }
    $(document).on('ready', function(){
      $('.hfpm-layer-row').each(function(){ bindPicker($(this)); });
      bindPicker($('.hfpm-main-image-row'));
    });
  })(jQuery);
JS
  );
});

function hfpm_card_box($post){
  // Main overrides
  $cta_label = get_post_meta($post->ID,'_hfpm_card_cta_label',true);
  $cta_url   = get_post_meta($post->ID,'_hfpm_card_cta_url',true) ?: get_post_meta($post->ID,'_hfpm_more',true);
  $badge     = get_post_meta($post->ID,'_hfpm_card_badge',true);
  $iconbg    = get_post_meta($post->ID,'_hfpm_card_icon_bg',true) ?: 'none';

  // Main image override + description override
  $main_img  = intval(get_post_meta($post->ID,'_hfpm_card_main_image',true)); // attachment ID
  $desc_over = get_post_meta($post->ID,'_hfpm_card_desc',true);

  // layered art (up to 5)
  $layers = get_post_meta($post->ID,'_hfpm_card_layers',true);
  $layers = is_array($layers) ? $layers : [];
  for ($i=0; $i<5; $i++){
    if (!isset($layers[$i])) $layers[$i] = ['id'=>'','opacity'=>'1','scale'=>'1','x'=>'0','y'=>'0'];
  }

  wp_nonce_field('hfpm_card_save','hfpm_card_nonce');

  // Helper to get preview URL
  $get_src = function($id){
    if (!$id) return '';
    $src = wp_get_attachment_image_src($id, 'medium');
    return $src ? $src[0] : '';
  };

  ?>
  <style>
    .hfpm-card-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    .hfpm-card-grid .box { background:#111; border:1px solid #2a2a2a; padding:12px; border-radius:8px; }
    .hfpm-flex { display:flex; gap:8px; align-items:center; }
    .hfpm-preview { width:72px; height:72px; object-fit:contain; display:block; background:#000; border:1px solid #333; border-radius:6px; }
    .hfpm-layer-row { border-top:1px dashed #333; padding-top:10px; margin-top:10px; }
    .small { font-size:12px; opacity:.8; }
  </style>

  <div class="hfpm-card-grid">
    <div class="box">
      <h3 style="margin-top:0">Card Basics</h3>
      <p><label>CTA Label<br><input type="text" name="hfpm_card_cta_label" value="<?php echo esc_attr($cta_label);?>" class="widefat" placeholder="Learn More →"></label></p>
      <p><label>CTA URL<br><input type="url" name="hfpm_card_cta_url" value="<?php echo esc_url($cta_url);?>" class="widefat" placeholder="https://..."></label></p>
      <p><label>Badge (optional)<br><input type="text" name="hfpm_card_badge" value="<?php echo esc_attr($badge);?>" class="widefat" placeholder="New"></label></p>
      <p><label>Icon adornment<br>
        <select name="hfpm_card_icon_bg" class="widefat">
          <?php foreach(['none'=>'None','ring'=>'Ring','glow'=>'Glow'] as $k=>$v): ?>
            <option value="<?php echo $k;?>" <?php selected($iconbg,$k);?>><?php echo $v;?></option>
          <?php endforeach;?>
        </select>
      </label></p>
    </div>

    <div class="box">
      <h3 style="margin-top:0">Card Image & Description</h3>
      <div class="hfpm-main-image-row hfpm-layer-row">
        <div class="hfpm-flex">
          <?php $src = $get_src($main_img); ?>
          <img class="hfpm-preview" data-target="_hfpm_card_main_image" src="<?php echo esc_url($src);?>" style="<?php echo $src?'':'display:none';?>">
          <div>
            <input type="hidden" name="_hfpm_card_main_image" value="<?php echo esc_attr($main_img);?>">
            <p class="small">Main card image (used if no layers, or as the bottom layer if layers exist)</p>
            <p>
              <button class="button hfpm-pick" data-target="_hfpm_card_main_image">Select image</button>
              <button class="button hfpm-clear" data-target="_hfpm_card_main_image">Clear</button>
            </p>
          </div>
        </div>
      </div>
      <p><label>Description override (shown on the card; falls back to post content if empty)<br>
        <textarea name="_hfpm_card_desc" rows="4" class="widefat" placeholder="Short, 1–3 sentences."><?php echo esc_textarea($desc_over);?></textarea>
      </label></p>
    </div>
  </div>

  <div class="box" style="margin-top:12px">
    <h3 style="margin-top:0">Layered Art (max 5; topmost = Layer 5)</h3>
    <p class="small">Each layer: image + <b>opacity</b> (0–1), <b>scale</b> (multiplier), <b>X/Y</b> offset (px). Layers render in order 1→5.</p>
    <?php for ($i=0; $i<5; $i++): $L=$layers[$i]; $src=$get_src(intval($L['id'])); ?>
      <div class="hfpm-layer-row">
        <strong>Layer <?php echo $i+1; ?></strong>
        <div class="hfpm-flex" style="margin-top:8px">
          <img class="hfpm-preview" data-target="hfpm_layers[<?php echo $i;?>][id]" src="<?php echo esc_url($src);?>" style="<?php echo $src?'':'display:none';?>">
          <div>
            <input type="hidden" name="hfpm_layers[<?php echo $i;?>][id]" value="<?php echo esc_attr($L['id']);?>">
            <p>
              <button class="button hfpm-pick" data-target="hfpm_layers[<?php echo $i;?>][id]">Select image</button>
              <button class="button hfpm-clear" data-target="hfpm_layers[<?php echo $i;?>][id]">Clear</button>
            </p>
          </div>
        </div>
        <div class="hfpm-flex" style="margin-top:6px">
          <label style="min-width:100px">Opacity
            <input type="number" step="0.05" min="0" max="1" name="hfpm_layers[<?php echo $i;?>][opacity]" value="<?php echo esc_attr($L['opacity']);?>" style="width:100%">
          </label>
          <label style="min-width:100px">Scale
            <input type="number" step="0.05" min="0.1" max="5" name="hfpm_layers[<?php echo $i;?>][scale]" value="<?php echo esc_attr($L['scale']);?>" style="width:100%">
          </label>
          <label style="min-width:100px">X (px)
            <input type="number" step="1" name="hfpm_layers[<?php echo $i;?>][x]" value="<?php echo esc_attr($L['x']);?>" style="width:100%">
          </label>
          <label style="min-width:100px">Y (px)
            <input type="number" step="1" name="hfpm_layers[<?php echo $i;?>][y]" value="<?php echo esc_attr($L['y']);?>" style="width:100%">
          </label>
        </div>
      </div>
    <?php endfor; ?>
  </div>
  <?php
}

add_action('save_post_hellas_project', function($post_id){
  if (!isset($_POST['hfpm_card_nonce']) || !wp_verify_nonce($_POST['hfpm_card_nonce'],'hfpm_card_save')) return;
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_post',$post_id)) return;

  update_post_meta($post_id,'_hfpm_card_cta_label', sanitize_text_field($_POST['hfpm_card_cta_label'] ?? ''));
  update_post_meta($post_id,'_hfpm_card_cta_url',   esc_url_raw($_POST['hfpm_card_cta_url'] ?? ''));
  update_post_meta($post_id,'_hfpm_card_badge',     sanitize_text_field($_POST['hfpm_card_badge'] ?? ''));
  update_post_meta($post_id,'_hfpm_card_icon_bg',   sanitize_text_field($_POST['hfpm_card_icon_bg'] ?? 'none'));

  update_post_meta($post_id,'_hfpm_card_main_image', intval($_POST['_hfpm_card_main_image'] ?? 0));
  update_post_meta($post_id,'_hfpm_card_desc',       wp_kses_post($_POST['_hfpm_card_desc'] ?? ''));

  // Sanitize layers array
  $in = $_POST['hfpm_layers'] ?? [];
  $out = [];
  if (is_array($in)) {
    for ($i=0; $i<5; $i++){
      $row = $in[$i] ?? [];
      $out[$i] = [
        'id'      => intval($row['id'] ?? 0),
        'opacity' => max(0,min(1, floatval($row['opacity'] ?? 1))),
        'scale'   => max(0.1,min(5, floatval($row['scale'] ?? 1))),
        'x'       => intval($row['x'] ?? 0),
        'y'       => intval($row['y'] ?? 0),
      ];
    }
  }
  update_post_meta($post_id,'_hfpm_card_layers', $out);
});

/* -------------------------------------------------------------------------- */
/*  G) Project Page Body (long description)                                    */
/* -------------------------------------------------------------------------- */
add_action('add_meta_boxes', function(){
  add_meta_box('hfpm_page_body','Project Page Body','hfpm_page_body_box','hellas_project','normal','default');
});
function hfpm_page_body_box($post){
  wp_nonce_field('hfpm_page_body_save','hfpm_page_body_nonce');
  $body = get_post_meta($post->ID,'_hfpm_page_body',true);
  wp_editor($body, 'hfpm_page_body', ['textarea_name'=>'hfpm_page_body','textarea_rows'=>12,'media_buttons'=>true]);
}
add_action('save_post_hellas_project', function($post_id){
  if (!isset($_POST['hfpm_page_body_nonce']) || !wp_verify_nonce($_POST['hfpm_page_body_nonce'],'hfpm_page_body_save')) return;
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_post',$post_id)) return;
  update_post_meta($post_id,'_hfpm_page_body', wp_kses_post($_POST['hfpm_page_body'] ?? ''));
});

/* -------------------------------------------------------------------------- */
/*  H) Shortcode — Projects Grid (with pricing chips & card settings)          */
/* -------------------------------------------------------------------------- */
add_shortcode('hellas_projects_grid', function($atts){
  $q = new WP_Query([
    'post_type'      => 'hellas_project',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'no_found_rows'  => true
  ]);
  if (!$q->have_posts()) return '<p>No projects yet.</p>';

  ob_start(); ?>
  <div class="projects-wrap">
    <div class="projects-grid">
      <?php while($q->have_posts()): $q->the_post();
        $pid   = get_the_ID();
        $ver   = get_post_meta($pid,'_hfpm_version',true);
        $side  = get_post_meta($pid,'_hfpm_side',true);
        $icon  = get_post_meta($pid,'_hfpm_icon',true);
        $more  = get_post_meta($pid,'_hfpm_more',true);

        $pm    = trim(get_post_meta($pid,'_hfpm_price_month',true));
        $py    = trim(get_post_meta($pid,'_hfpm_price_year',true));
        $pl    = trim(get_post_meta($pid,'_hfpm_price_life',true));
        $status= get_post_meta($pid,'_hfpm_status',true) ?: 'available';
        $pnote = get_post_meta($pid,'_hfpm_price_notes',true);

        $ctaL  = get_post_meta($pid,'_hfpm_card_cta_label',true) ?: 'Learn More →';
        $ctaU  = get_post_meta($pid,'_hfpm_card_cta_url',true)   ?: $more;
        $badge = get_post_meta($pid,'_hfpm_card_badge',true);
        $iconbg= get_post_meta($pid,'_hfpm_card_icon_bg',true) ?: 'none';

        // Layered art
        $main_img_id = intval(get_post_meta($pid,'_hfpm_card_main_image',true));
        $layers = get_post_meta($pid,'_hfpm_card_layers',true);
        $layers = is_array($layers) ? $layers : [];
        $render_layers = [];
        if ($main_img_id) {
          $src = wp_get_attachment_image_src($main_img_id, 'large');
          if ($src) $render_layers[] = ['src'=>$src[0],'opacity'=>1,'scale'=>1,'x'=>0,'y'=>0];
        }
        for ($i=0;$i<5;$i++){
          $L = $layers[$i] ?? null;
          if (!$L || empty($L['id'])) continue;
          $src = wp_get_attachment_image_src(intval($L['id']), 'large');
          if ($src) {
            $render_layers[] = [
              'src'     => $src[0],
              'opacity' => isset($L['opacity']) ? floatval($L['opacity']) : 1,
              'scale'   => isset($L['scale'])   ? floatval($L['scale'])   : 1,
              'x'       => isset($L['x'])       ? intval($L['x'])         : 0,
              'y'       => isset($L['y'])       ? intval($L['y'])         : 0,
            ];
          }
        }
        ?>
        <article class="project-card">
          <?php if (!empty($render_layers)): ?>
            <div class="pc-artboard">
              <?php foreach ($render_layers as $idx=>$L):
                $style = sprintf(
                  'opacity:%s; transform: translate(%dpx,%dpx) scale(%s);',
                  esc_attr($L['opacity']),
                  intval($L['x']), intval($L['y']),
                  esc_attr($L['scale'])
                );
              ?>
                <img class="pc-layer" src="<?php echo esc_url($L['src']);?>" alt="" style="<?php echo esc_attr($style);?>">
              <?php endforeach; ?>
              <?php if ($badge): ?><span class="pc-badge"><?php echo esc_html($badge);?></span><?php endif;?>
            </div>
          <?php elseif ($icon): ?>
            <div class="pc-icon pc-icon--<?php echo esc_attr($iconbg);?>">
              <img src="<?php echo esc_url($icon);?>" alt="">
              <?php if ($badge): ?><span class="pc-badge"><?php echo esc_html($badge);?></span><?php endif;?>
            </div>
          <?php endif; ?>

          <h3 class="pc-title"><?php the_title(); ?></h3>
          <div class="pc-meta">
            <?php if ($ver): ?><span class="pc-chip">v<?php echo esc_html($ver);?></span><?php endif; ?>
            <?php if ($side): ?><span class="pc-chip"><?php echo esc_html(ucfirst($side));?></span><?php endif; ?>
          </div>

          <?php
          // Pricing chips (split)
          if ($status !== 'private') {
            echo '<div class="pc-price">';
            if ($status === 'free') {
              echo '<span class="pc-chip" title="'.esc_attr($pnote).'">Free</span>';
            } elseif ($status === 'coming_soon') {
              echo '<span class="pc-chip" title="'.esc_attr($pnote).'">Coming soon</span>';
            } else {
              if ($pm !== '') echo '<span class="pc-chip" title="'.esc_attr($pnote).'">€'.esc_html($pm).'/mo</span>';
              if ($py !== '') echo '<span class="pc-chip" title="'.esc_attr($pnote).'">€'.esc_html($py).'/yr</span>';
              if ($pl !== '') echo '<span class="pc-chip" title="'.esc_attr($pnote).'">€'.esc_html($pl).' lifetime</span>';
              if ($pm==='' && $py==='' && $pl==='') echo '<span class="pc-chip">Contact for pricing</span>';
            }
            echo '</div>';
          }
          ?>

          <?php
            $desc_override = get_post_meta($pid,'_hfpm_card_desc',true);
            $blurb_html = $desc_override !== '' ? wp_kses_post($desc_override) : wp_kses_post(get_the_content());
          ?>
          <p class="pc-blurb"><?php echo $blurb_html; ?></p>

          <?php if ($ctaU): ?>
            <p><a class="pc-link" href="<?php echo esc_url($ctaU);?>"><?php echo esc_html($ctaL);?></a></p>
          <?php endif; ?>
        </article>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
  </div>
  <style>
    .projects-wrap{max-width:1200px;margin:0 auto;padding:24px}
    .projects-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:22px}
    .project-card{background:rgba(0,0,0,.35);border:1px solid rgba(255,180,90,.15);border-radius:14px;padding:16px;position:relative}
    .pc-artboard{position:relative;height:140px;overflow:hidden;border-radius:10px;background:rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;margin-bottom:10px}
    .pc-artboard .pc-layer{position:absolute;top:50%;left:50%;transform-origin: top left}
    .pc-icon{display:flex;justify-content:center;align-items:center;margin-bottom:10px;position:relative}
    .pc-icon--ring img{border-radius:999px;border:2px solid rgba(255,180,90,.35);padding:6px}
    .pc-icon--glow img{filter:drop-shadow(0 0 14px rgba(255,180,90,.35))}
    .pc-badge{position:absolute;top:2px;right:2px;font-size:.72rem;padding:.1rem .4rem;border-radius:999px;border:1px solid rgba(255,180,90,.25);background:rgba(0,0,0,.35)}
    .pc-icon img{width:96px;height:96px;object-fit:contain}
    .pc-title{margin:6px 0 8px;font-size:1.1rem;color:var(--forge-text,#f4e9dc)}
    .pc-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
    .pc-price{margin:6px 0 4px;display:flex;gap:8px;flex-wrap:wrap}
    .pc-chip{font-size:.8rem;padding:.12rem .5rem;border-radius:999px;border:1px solid rgba(255,180,90,.25)}
    .pc-blurb{margin:8px 0 10px;color:var(--forge-text,#decfb6)}
    .pc-link{color:var(--forge-accent,#ff944d);text-decoration:none;border-bottom:1px dashed currentColor}
    .pc-link:hover{filter:brightness(1.1)}
  </style>
  <?php
  return ob_get_clean();
});

/* -------------------------------------------------------------------------- */
/*  I) Shortcode — Pricing Table (Monthly / Yearly / Lifetime)                 */
/* -------------------------------------------------------------------------- */
add_shortcode('hellas_pricing_table', function($atts){
  $a = shortcode_atts([
    'only_status'  => '',
    'show_private' => '0',
  ], $atts);

  $statuses = array_filter(array_map('trim', explode(',', strtolower($a['only_status']))));
  $show_private = $a['show_private'] === '1';

  $q = new WP_Query([
    'post_type'=>'hellas_project',
    'posts_per_page'=>-1,
    'orderby'=>'title','order'=>'ASC',
    'no_found_rows'=>true
  ]);
  if(!$q->have_posts()) return '<p>No projects.</p>';

  ob_start(); ?>
  <div class="hfpm-pricing-wrap">
    <table class="hfpm-pricing">
      <thead>
        <tr>
          <th>Project</th>
          <th>Monthly</th>
          <th>Yearly</th>
          <th>Lifetime</th>
          <th>Status</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
      <?php while($q->have_posts()): $q->the_post();
        $pid   = get_the_ID();
        $title = get_the_title();

        $pm    = trim(get_post_meta($pid,'_hfpm_price_month',true));
        $py    = trim(get_post_meta($pid,'_hfpm_price_year',true));
        $pl    = trim(get_post_meta($pid,'_hfpm_price_life',true));
        $status= get_post_meta($pid,'_hfpm_status',true) ?: 'available';
        $notes = get_post_meta($pid,'_hfpm_price_notes',true);

        if(!$show_private && $status==='private') continue;
        if($statuses && !in_array($status,$statuses,true)) continue;

        $disp = function($v,$suffix){ return $v!=='' ? '€'.esc_html($v).$suffix : '—'; };
        ?>
        <tr>
          <td><?php echo esc_html($title); ?></td>
          <td><?php echo $status==='free' ? 'Free' : $disp($pm,'/mo'); ?></td>
          <td><?php echo $status==='free' ? 'Free' : $disp($py,'/yr'); ?></td>
          <td><?php echo $status==='free' ? 'Free' : $disp($pl,''); ?></td>
          <td><?php echo esc_html(['available'=>'Available','free'=>'Free','coming_soon'=>'Coming soon','private'=>'Private'][$status] ?? ucfirst($status)); ?></td>
          <td><?php echo esc_html($notes); ?></td>
        </tr>
      <?php endwhile; wp_reset_postdata(); ?>
      </tbody>
    </table>
  </div>
  <style>
    .hfpm-pricing-wrap{max-width:1000px;margin:0 auto;padding:12px}
    .hfpm-pricing{width:100%;border-collapse:separate;border-spacing:0 8px}
    .hfpm-pricing th,.hfpm-pricing td{text-align:left;padding:10px 12px}
    .hfpm-pricing thead th{border-bottom:1px solid rgba(255,180,90,.2)}
    .hfpm-pricing tbody tr{background:rgba(0,0,0,.35);border:1px solid rgba(255,180,90,.12)}
    .hfpm-pricing tbody tr td:first-child{font-weight:600}
  </style>
  <?php
  return ob_get_clean();
});

/* -------------------------------------------------------------------------- */
/*  J) Shortcode — Full Project Page (from meta)                               */
/* -------------------------------------------------------------------------- */
// [hellas_project_page slug="hellasforms"]
add_shortcode('hellas_project_page', function($atts){
  $a = shortcode_atts(['slug'=>'' ], $atts);
  if (!$a['slug']) return '<p>Project missing.</p>';

  $post = get_page_by_path(sanitize_title($a['slug']), OBJECT, 'hellas_project');
  if (!$post) return '<p>Project not found.</p>';
  $pid = $post->ID;

  $title = get_the_title($pid);
  $ver   = get_post_meta($pid,'_hfpm_version',true);
  $side  = get_post_meta($pid,'_hfpm_side',true);
  $icon  = get_post_meta($pid,'_hfpm_icon',true);
  $more  = get_post_meta($pid,'_hfpm_more',true);

  $pm    = trim(get_post_meta($pid,'_hfpm_price_month',true));
  $py    = trim(get_post_meta($pid,'_hfpm_price_year',true));
  $pl    = trim(get_post_meta($pid,'_hfpm_price_life',true));
  $status= get_post_meta($pid,'_hfpm_status',true) ?: 'available';
  $pnote = get_post_meta($pid,'_hfpm_price_notes',true);

  $ctaL  = get_post_meta($pid,'_hfpm_card_cta_label',true) ?: 'Get details →';
  $ctaU  = get_post_meta($pid,'_hfpm_card_cta_url',true)   ?: $more;
  $badge = get_post_meta($pid,'_hfpm_card_badge',true);
  $iconbg= get_post_meta($pid,'_hfpm_card_icon_bg',true) ?: 'none';

  $main_img_id = intval(get_post_meta($pid,'_hfpm_card_main_image',true));
  $layers = get_post_meta($pid,'_hfpm_card_layers',true);
  $layers = is_array($layers) ? $layers : [];

  $body  = get_post_meta($pid,'_hfpm_page_body',true); // long page text you edit in backend
  if ($body === '') $body = apply_filters('the_content', get_post_field('post_content', $pid));

  // build layered images
  $render_layers = [];
  if ($main_img_id) {
    $src = wp_get_attachment_image_src($main_img_id, 'full');
    if ($src) $render_layers[] = ['src'=>$src[0],'opacity'=>1,'scale'=>1,'x'=>0,'y'=>0];
  }
  for ($i=0;$i<5;$i++){
    $L = $layers[$i] ?? null;
    if (!$L || empty($L['id'])) continue;
    $src = wp_get_attachment_image_src(intval($L['id']), 'full');
    if ($src) $render_layers[] = [
      'src'=>$src[0],
      'opacity'=>isset($L['opacity'])?floatval($L['opacity']):1,
      'scale'=>isset($L['scale'])?floatval($L['scale']):1,
      'x'=>isset($L['x'])?intval($L['x']):0,
      'y'=>isset($L['y'])?intval($L['y']):0,
    ];
  }

  ob_start(); ?>
  <section class="hellas-project-page">
    <header class="hpp-head">
      <div class="hpp-media">
        <?php if ($render_layers): ?>
          <div class="hpp-artboard">
            <?php foreach ($render_layers as $L):
              $style = sprintf('opacity:%s; transform: translate(%dpx,%dpx) scale(%s);',
                esc_attr($L['opacity']), intval($L['x']), intval($L['y']), esc_attr($L['scale']));
            ?>
              <img class="hpp-layer" src="<?php echo esc_url($L['src']);?>" alt="" style="<?php echo esc_attr($style);?>">
            <?php endforeach; ?>
            <?php if ($badge): ?><span class="hpp-badge"><?php echo esc_html($badge);?></span><?php endif;?>
          </div>
        <?php elseif ($icon): ?>
          <div class="hpp-icon hpp-icon--<?php echo esc_attr($iconbg);?>">
            <img src="<?php echo esc_url($icon);?>" alt="">
            <?php if ($badge): ?><span class="hpp-badge"><?php echo esc_html($badge);?></span><?php endif;?>
          </div>
        <?php endif; ?>
      </div>
      <div class="hpp-meta">
        <h1 class="hpp-title"><?php echo esc_html($title);?></h1>
        <div class="hpp-chips">
          <?php if ($ver): ?><span class="hpp-chip">v<?php echo esc_html($ver);?></span><?php endif; ?>
          <?php if ($side): ?><span class="hpp-chip"><?php echo esc_html(ucfirst($side));?></span><?php endif; ?>
        </div>

        <div class="hpp-price">
          <?php if ($status !== 'private'): ?>
            <?php if ($status==='free'): ?>
              <span class="hpp-chip" title="<?php echo esc_attr($pnote);?>">Free</span>
            <?php elseif ($status==='coming_soon'): ?>
              <span class="hpp-chip" title="<?php echo esc_attr($pnote);?>">Coming soon</span>
            <?php else: ?>
              <?php if ($pm!==''): ?><span class="hpp-chip" title="<?php echo esc_attr($pnote);?>">€<?php echo esc_html($pm);?>/mo</span><?php endif; ?>
              <?php if ($py!==''): ?><span class="hpp-chip" title="<?php echo esc_attr($pnote);?>">€<?php echo esc_html($py);?>/yr</span><?php endif; ?>
              <?php if ($pl!==''): ?><span class="hpp-chip" title="<?php echo esc_attr($pnote);?>">€<?php echo esc_html($pl);?> lifetime</span><?php endif; ?>
              <?php if ($pm==='' && $py==='' && $pl===''): ?><span class="hpp-chip">Contact for pricing</span><?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if ($ctaU): ?>
          <p><a class="hpp-cta" href="<?php echo esc_url($ctaU);?>"><?php echo esc_html($ctaL);?></a></p>
        <?php endif; ?>
      </div>
    </header>

    <article class="hpp-body">
      <?php echo $body; ?>
    </article>
  </section>
  <style>
    .hellas-project-page{max-width:1100px;margin:0 auto;padding:22px}
    .hpp-head{display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:center}
    .hpp-artboard{position:relative;height:240px;background:rgba(0,0,0,.2);border:1px solid rgba(255,180,90,.15);border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center}
    .hpp-layer{position:absolute;top:50%;left:50%;transform-origin:top left}
    .hpp-badge{position:absolute;top:6px;right:6px;font-size:.75rem;padding:.12rem .5rem;border-radius:999px;border:1px solid rgba(255,180,90,.25);background:rgba(0,0,0,.35)}
    .hpp-icon img{width:180px;height:180px;object-fit:contain;filter:drop-shadow(0 0 14px rgba(255,180,90,.35))}
    .hpp-title{margin:0 0 6px;color:var(--forge-text,#f4e9dc)}
    .hpp-chips,.hpp-price{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0}
    .hpp-chip{font-size:.85rem;padding:.16rem .6rem;border-radius:999px;border:1px solid rgba(255,180,90,.25)}
    .hpp-cta{display:inline-block;margin-top:10px;color:var(--forge-accent,#ff944d);text-decoration:none;border-bottom:1px dashed currentColor}
    .hpp-cta:hover{filter:brightness(1.1)}
    .hpp-body{margin-top:20px;color:var(--forge-text,#decfb6)}
    @media (max-width:900px){.hpp-head{grid-template-columns:1fr}}
  </style>
  <?php
  return ob_get_clean();
});

/* -------------------------------------------------------------------------- */
/*  K) Pages → bind to project + auto shortcode                                */
/* -------------------------------------------------------------------------- */
// Bind a Page to a Hellas Project (optional; else we auto-use page slug)
add_action('add_meta_boxes', function(){
  add_meta_box('hfpm_bind_project','Bind to Hellas Project','hfpm_bind_project_box','page','side','high');
});
function hfpm_bind_project_box($post){
  wp_nonce_field('hfpm_bind_project_save','hfpm_bind_project_nonce');
  $bound = get_post_meta($post->ID,'_hfpm_project_slug',true);
  $q = new WP_Query(['post_type'=>'hellas_project','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','no_found_rows'=>true,'fields'=>'ids']);
  echo '<p><em>Select a Hellas Project this page should render.</em></p>';
  echo '<select name="_hfpm_project_slug" style="width:100%"><option value="">— Auto (use page slug) —</option>';
  foreach($q->posts as $pid){
    $slug = get_post_field('post_name',$pid);
    $title= get_the_title($pid);
    printf('<option value="%s" %s>%s (%s)</option>', esc_attr($slug), selected($bound,$slug,false), esc_html($title), esc_html($slug));
  }
  echo '</select>';
}
add_action('save_post_page', function($post_id){
  if (!isset($_POST['hfpm_bind_project_nonce']) || !wp_verify_nonce($_POST['hfpm_bind_project_nonce'],'hfpm_bind_project_save')) return;
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_page',$post_id)) return;
  update_post_meta($post_id,'_hfpm_project_slug', sanitize_title($_POST['_hfpm_project_slug'] ?? ''));
});

// [hellas_project_auto] — resolves slug via page meta or current page slug
add_shortcode('hellas_project_auto', function(){
  if (!is_page()) return '';
  $page = get_post();
  if (!$page) return '';
  $slug = get_post_meta($page->ID,'_hfpm_project_slug',true);
  if (!$slug) $slug = $page->post_name; // auto by page slug
  return do_shortcode('[hellas_project_page slug="'.esc_attr($slug).'"]');
});

/* -------------------------------------------------------------------------- */
/*  L) Auto-grid fallback for the /projects/ page                               */
/* -------------------------------------------------------------------------- */
// If the /projects/ page content is empty (and no grid shortcode), auto-render the grid.
add_filter('the_content', function($content){
  if (is_page()) {
    $page = get_post();
    if ($page && $page->post_name === 'projects') {
      $has_shortcode = (stripos($content, '[hellas_projects_grid') !== false);
      $is_empty = trim(strip_tags($content)) === '';
      if ($is_empty && !$has_shortcode) {
        return do_shortcode('[hellas_projects_grid]');
      }
    }
  }
  return $content;
}, 9);

/* -------------------------------------------------------------------------- */
/*  M) Admin tool — Adopt Existing Pages                                       */
/* -------------------------------------------------------------------------- */
function hfpm_adopt_pages_screen(){
  if (!current_user_can('manage_options')) return;

  // Find the parent "Projects" page by path
  $projects_parent = get_page_by_path('projects', OBJECT, 'page');
  $projects_parent_id = $projects_parent ? intval($projects_parent->ID) : 0;

  // Collect all hellas_project slugs for optional binding dropdowns
  $proj_q = new WP_Query([
    'post_type'=>'hellas_project','posts_per_page'=>-1,'fields'=>'ids',
    'orderby'=>'title','order'=>'ASC','no_found_rows'=>true
  ]);
  $project_choices = [];
  foreach($proj_q->posts as $pid){
    $project_choices[] = [
      'slug'  => get_post_field('post_name',$pid),
      'title' => get_the_title($pid),
    ];
  }

  // Gather ONLY pages whose parent is /projects/
  $candidates = [];
  if ($projects_parent_id){
    $pages = new WP_Query([
      'post_type'     => 'page',
      'post_parent'   => $projects_parent_id,
      'posts_per_page'=> -1,
      'orderby'       => 'menu_order title',
      'order'         => 'ASC',
      'no_found_rows' => true,
      'fields'        => 'ids',
    ]);
    $candidates = $pages->posts;
  }

  // Handle adoption
  if (!empty($_POST['hfpm_adopt_nonce']) && wp_verify_nonce($_POST['hfpm_adopt_nonce'],'hfpm_adopt_do') && !empty($_POST['adopt_ids'])){
    $adopted = 0;
    foreach(array_map('intval', $_POST['adopt_ids']) as $pgid){
      // Optional explicit binding from the table dropdown; else fall back to page slug
      $bind_key = 'bind_slug_'.$pgid;
      $bound_slug = isset($_POST[$bind_key]) ? sanitize_title($_POST[$bind_key]) : '';
      if ($bound_slug === '') $bound_slug = get_post_field('post_name', $pgid);

      update_post_meta($pgid,'_hfpm_project_slug', $bound_slug);

      // Ensure the page renders via shortcode (don’t double-insert)
      $content = get_post_field('post_content', $pgid) ?? '';
      if (strpos($content, '[hellas_project_auto]') === false && strpos($content, '[hellas_project_page') === false){
        wp_update_post(['ID'=>$pgid, 'post_content'=>'[hellas_project_auto]']);
      }
      $adopted++;
    }
    echo '<div class="updated"><p>Adopted '.$adopted.' page(s).</p></div>';
  }

  echo '<div class="wrap"><h1>Adopt Existing Pages</h1>';
  echo '<p>This lists every child Page of <code>/projects/</code>. Selecting Adopt will set the content to <code>[hellas_project_auto]</code> and bind it to a project (dropdown), or to the page’s own slug if you leave it on “Auto”.</p>';

  if (!$projects_parent_id){
    echo '<div class="notice notice-warning"><p><strong>Couldn’t find a parent page at /projects/.</strong> Create a page with the slug <code>projects</code> or use the manual bind per Page.</p></div></div>';
    return;
  }

  if (!$candidates){
    echo '<p><em>No child pages under /projects/.</em></p></div>';
    return;
  }

  echo '<form method="post">';
  wp_nonce_field('hfpm_adopt_do','hfpm_adopt_nonce');

  echo '<table class="widefat striped">';
  echo '<thead><tr><th style="width:40px"></th><th>Page</th><th>URL</th><th>Bind to Project</th></tr></thead><tbody>';

  foreach($candidates as $pgid){
    $slug = get_post_field('post_name',$pgid);
    $title= get_the_title($pgid);
    $url  = get_permalink($pgid);

    echo '<tr>';
    echo '<td><label><input type="checkbox" name="adopt_ids[]" value="'.intval($pgid).'" checked></label></td>';
    echo '<td>'.esc_html($title).'</td>';
    echo '<td><a href="'.esc_url($url).'" target="_blank">'.esc_html($url).'</a></td>';

    // Bind dropdown (Auto uses page slug)
    echo '<td><select name="bind_slug_'.intval($pgid).'" style="min-width:240px">';
    echo '<option value="">— Auto (use page slug: '.esc_html($slug).') —</option>';
    foreach($project_choices as $opt){
      printf(
        '<option value="%s">%s (%s)</option>',
        esc_attr($opt['slug']),
        esc_html($opt['title']),
        esc_html($opt['slug'])
      );
    }
    echo '</select></td>';

    echo '</tr>';
  }

  echo '</tbody></table>';
  echo '<p><button class="button button-primary" type="submit">Adopt Selected Pages</button></p>';
  echo '</form></div>';
}

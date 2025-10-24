<?php
/**
 * Plugin Name: Hellas Suite Downloads
 * Description: Serve licensed downloads from a mounted folder (e.g. /hellas_downloads) with entitlements + short-lived links.
 * Version: 2.2.0
 * Author: Hephaestus Forge
 */

if (!defined('ABSPATH')) exit;

final class Hellas_Suite_Downloads {
  /* === Options / constants === */
  const OPT_MAP        = 'hellas_dl_filemap';      // [ module => relpath ]
  const OPT_BASE       = 'hellas_dl_basedir';      // absolute path in container
  const OPT_VERIFIER   = 'hellas_dl_verifier_url'; // license verifier URL
  const PAGE_SLUG      = 'hellas-downloads';
  const TOKEN_PREFIX   = 'hellas_dl_';
  const ENTITLE_PREFIX = 'hellas_ent_';
  const TOKEN_TTL      = 300;   // 5 minutes
  const ENTITLE_TTL    = 300;   // 5 minutes

  // Canonical module keys used across the site
  private $modules = [
    'hellasaudio'       => 'HellasAudio',
    'hellasbattlebuddy' => 'HellasBattlebuddy',
    'hellascontrol'     => 'HellasControl',
    'hellasdeck'        => 'HellasDeck',
    'hellaselo'         => 'HellasElo',
    'hellasforms'       => 'HellasForms',
    'hellasgarden'      => 'HellasGardens',
    'hellashelper'      => 'HellasHelper',
    'hellasmineralogy'  => 'HellasMineralogy',
    'hellaspatcher'     => 'HellasPatcher',
    'hellastextures'    => 'HellasTextures',
  ];

  private $pagehook = '';

  public function __construct(){
    asort($this->modules, SORT_NATURAL | SORT_FLAG_CASE);
    add_action('admin_menu', [$this,'admin_menu']);
    add_action('admin_post_hellas_dl_save', [$this,'handle_save']);

    add_action('rest_api_init', [$this,'register_routes']);
    add_filter('query_vars', function($q){ $q[]='hellas_dl'; return $q; });
    add_action('template_redirect', [$this,'handle_token_download']);
  }

  /* ---------------- Admin UI ---------------- */

  private function detect_parent_slug(){
    global $menu;
    if (!is_array($menu)) return false;
    foreach ($menu as $m) {
      if (isset($m[0], $m[2]) && strip_tags($m[0]) === 'Hellas Licenses') return $m[2];
    }
    return false;
  }

  public function admin_menu(){
    $parent = $this->detect_parent_slug();
    if ($parent) {
      $this->pagehook = add_submenu_page(
        $parent, 'Downloads', 'Downloads', 'manage_options',
        self::PAGE_SLUG, [$this,'render_page'], 20
      );
    } else {
      add_menu_page('Hellas Licenses','Hellas Licenses','manage_options','hellas-licenses',function(){
        echo '<div class="wrap"><h1>Hellas Licenses</h1></div>';
      },'dashicons-lock',56);
      $this->pagehook = add_submenu_page(
        'hellas-licenses', 'Downloads', 'Downloads', 'manage_options',
        self::PAGE_SLUG, [$this,'render_page'], 20
      );
    }
    if ($this->pagehook){
      add_action('load-'.$this->pagehook, [$this,'admin_assets']);
    }
  }

  public function admin_assets(){
    wp_register_style('hellas-dl-admin', false);
    wp_enqueue_style('hellas-dl-admin');
    wp_add_inline_style('hellas-dl-admin', '
      .hellas-table td,.hellas-table th{padding:8px 10px;vertical-align:middle}
      .hellas-pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#232323;color:#fff;border:1px solid rgba(255,255,255,.12);font-weight:600}
      .file-note{opacity:.8}
      .small{font-size:12px;opacity:.8}
      .code{font-family:ui-monospace,Menlo,Consolas,monospace;background:#111;color:#eee;padding:2px 6px;border-radius:6px}
      input[type=text].wide{width:100%}
      .notice p code{background:#111; padding:2px 6px; border-radius:6px}
    ');
  }

  private function base_dir(){
    $d = get_option(self::OPT_BASE, '/hellas_downloads');
    return rtrim($d, '/');
  }
  private function verifier_url(){
    $u = trim((string) get_option(self::OPT_VERIFIER, home_url('/wp-json/hellas/v1/license/verify')));
    return $u ?: home_url('/wp-json/hellas/v1/license/verify');
  }

  private function scan_files($dir){
    $base = $this->base_dir();
    $root = $base . '/' . ltrim($dir, '/');
    $allow = ['zip','jar','tar','gz','bz2','xz','7z'];
    $out = [];
    if (!is_dir($root)) return $out;

    $rii = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
    $maxDepth = 3;
    foreach ($rii as $f){
      if ($rii->getDepth() > $maxDepth) continue;
      if (!$f->isFile()) continue;
      $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
      if (!in_array($ext, $allow, true)) continue;

      $abs = $f->getPathname();
      $rel = ltrim(str_replace($base, '', $abs), '/');
      $out[] = $rel;
    }
    $out = array_values(array_unique($out));
    sort($out, SORT_NATURAL|SORT_FLAG_CASE);
    return $out;
  }

  public function render_page(){
    if (!current_user_can('manage_options')) return;
    $base = $this->base_dir();
    $map  = get_option(self::OPT_MAP, []);
    $exists = is_dir($base);
    $verifier = $this->verifier_url();
    ?>
    <div class="wrap">
      <h1>Hellas Downloads (Server Folder)</h1>

      <?php if (!$exists): ?>
        <div class="notice notice-error"><p><strong>Base directory not found:</strong> <code><?php echo esc_html($base); ?></code>. Create it and/or mount it into the container.</p></div>
      <?php else: ?>
        <div class="notice notice-success"><p><strong>Base directory OK:</strong> <code><?php echo esc_html($base); ?></code></p></div>
      <?php endif; ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('hellas_dl_save','hellas_nonce'); ?>
        <input type="hidden" name="action" value="hellas_dl_save">

        <h2 class="title">Settings</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="basedir">Base directory</label></th>
            <td>
              <input id="basedir" class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_BASE); ?>" value="<?php echo esc_attr($base); ?>" />
              <p class="description">Absolute path inside the container (e.g. <code>/hellas_downloads</code>).</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="verifier">License verifier URL</label></th>
            <td>
              <input id="verifier" class="regular-text" type="text" name="<?php echo esc_attr(self::OPT_VERIFIER); ?>" value="<?php echo esc_attr($verifier); ?>" />
              <p class="description">Defaults to <code>/wp-json/hellas/v1/license/verify</code>. Must return JSON with <code>{"status":"valid"}</code> and <code>entitlements</code> (array) — see your License Manager.</p>
            </td>
          </tr>
        </table>

        <h2 class="title">Module → File mapping</h2>
        <p class="small">Put files in <code><?php echo esc_html($base); ?></code> (subfolders suggested: <code>/hellasforms</code>, <code>/hellasaudio</code>, …). Scanner lists up to 3 levels deep.</p>

        <table class="widefat fixed striped hellas-table">
          <thead><tr><th style="width:220px;">Module</th><th>File (relative to base)</th><th style="width:260px;">Picker</th></tr></thead>
          <tbody>
          <?php foreach ($this->modules as $key=>$label):
            $rel = isset($map[$key]) ? (string)$map[$key] : '';
            $choices = array_unique(array_merge(
              $this->scan_files('/'.$key),
              $this->scan_files('/')
            ));
          ?>
            <tr>
              <td><strong><?php echo esc_html($label); ?></strong><br><span class="hellas-pill"><?php echo esc_html($key); ?></span></td>
              <td>
                <input type="text" class="wide" name="<?php echo esc_attr(self::OPT_MAP.'['.$key.']'); ?>" value="<?php echo esc_attr($rel); ?>" placeholder="e.g. <?php echo esc_attr($key.'/package-1.0.0.jar'); ?>">
                <div class="file-note small">Current: <code><?php echo $rel ? esc_html($rel) : '—'; ?></code></div>
              </td>
              <td>
                <select onchange="this.form['<?php echo esc_js(self::OPT_MAP.'['.$key.']'); ?>'].value=this.value">
                  <option value="">— select from scan —</option>
                  <?php foreach ($choices as $c): ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected($rel, $c); ?>><?php echo esc_html($c); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php submit_button('Save Settings & Mapping'); ?>
      </form>

      <h2>API</h2>
      <p><code>POST /wp-json/hellas/v1/entitlements</code> → <code>{"modules":[{"id":"hellasforms","name":"HellasForms","path":"...","size":123,"mtime":1700000000}], "count":1}</code></p>
      <p><code>POST /wp-json/hellas/v1/download</code> → <code>{"url":"https://yoursite/?hellas_dl=TOKEN","ttl":<?php echo (int)self::TOKEN_TTL; ?>}</code></p>
    </div>
    <?php
  }

  public function handle_save(){
    if (!current_user_can('manage_options')) wp_die('Not allowed');
    check_admin_referer('hellas_dl_save','hellas_nonce');

    $base = isset($_POST[self::OPT_BASE]) ? rtrim((string)wp_unslash($_POST[self::OPT_BASE]), '/') : '/hellas_downloads';
    if ($base === '') $base = '/hellas_downloads';
    update_option(self::OPT_BASE, $base);

    $verifier = isset($_POST[self::OPT_VERIFIER]) ? trim((string)wp_unslash($_POST[self::OPT_VERIFIER])) : '';
    if ($verifier === '') $verifier = home_url('/wp-json/hellas/v1/license/verify');
    update_option(self::OPT_VERIFIER, $verifier);

    $incoming = isset($_POST[self::OPT_MAP]) && is_array($_POST[self::OPT_MAP]) ? $_POST[self::OPT_MAP] : [];
    $out = [];
    foreach ($this->modules as $key=>$label){
      $rel = isset($incoming[$key]) ? trim((string)wp_unslash($incoming[$key])) : '';
      $rel = ltrim($rel, '/');
      if ($rel !== '') $out[$key] = $rel;
    }
    update_option(self::OPT_MAP, $out);

    wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG,'updated'=>'1'], admin_url('admin.php')));
    exit;
  }

  /* ---------------- REST: download / entitlements ---------------- */

  public function register_routes(){
    register_rest_route('hellas/v1', '/download', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [$this,'create_download_link'],
      'args' => [
        'licenseId' => ['required'=>true, 'type'=>'string'],
        'machineId' => ['required'=>false, 'type'=>'string'],
        'module'    => ['required'=>true, 'type'=>'string'],
      ],
    ]);

    register_rest_route('hellas/v1', '/entitlements', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [$this,'get_entitlements'],
      'args' => [
        'licenseId' => ['required'=>true, 'type'=>'string'],
        'machineId' => ['required'=>false, 'type'=>'string'],
      ],
    ]);
  }

  private function call_verifier($licenseId, $machineId){
    // Bump cache version (v3) to invalidate older shapes
    $cache_key = self::ENTITLE_PREFIX . 'v3_' . md5($licenseId . '|' . $machineId);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $url = $this->verifier_url();
    $body = ['licenseId'=>$licenseId];
    if ($machineId !== '') $body['machineId'] = $machineId;

    $res = wp_remote_post($url, [
      'timeout' => 12,
      'headers' => ['Content-Type'=>'application/json'],
      'body'    => wp_json_encode($body),
    ]);
    $data = ['valid'=>false, 'modules'=>null];

    if (!is_wp_error($res)) {
      $json = json_decode(wp_remote_retrieve_body($res), true);

      if (is_array($json)) {
        // Treat various “valid” shapes as valid
        $is_valid = false;
        if (!empty($json['valid'])) $is_valid = true;
        if (!empty($json['ok'])) $is_valid = true;
        if (isset($json['status']) && strtolower((string)$json['status']) === 'valid') $is_valid = true;
        $data['valid'] = $is_valid;

        // Accept either "modules" or "entitlements"
        if (!empty($json['modules']) && is_array($json['modules'])) {
          $data['modules'] = array_values(array_unique(array_map('strval', $json['modules'])));
        } elseif (!empty($json['entitlements']) && is_array($json['entitlements'])) {
          $data['modules'] = array_values(array_unique(array_map('strval', $json['entitlements'])));
        }
      }
    }

    set_transient($cache_key, $data, self::ENTITLE_TTL);
    return $data;
  }

  private function entitled_modules($licenseId, $machineId){
    $ver = $this->call_verifier($licenseId, $machineId);
    if (!$ver['valid']) return [];

    // If verifier explicitly lists modules/entitlements, enforce it.
    if (is_array($ver['modules']) && $ver['modules']) {
      $allowed = array_intersect(array_keys($this->modules), $ver['modules']);
      return array_values($allowed);
    }
    // Fallback: valid but no explicit modules => allow all known modules
    return array_keys($this->modules);
  }

  private function resolve_file($module){
    $map = get_option(self::OPT_MAP, []);
    $base= $this->base_dir();
    if (empty($map[$module])) return [null, null];

    $rel = ltrim($map[$module], '/');
    $abs = $base . '/' . $rel;

    $real = realpath($abs);
    $realBase = realpath($base);

    if (!$real || !$realBase) return [null, null];
    if (strpos($real, $realBase) !== 0) return [null, null];
    if (!is_file($real) || !is_readable($real)) return [null, null];
    return [$real, basename($real)];
  }

  public function get_entitlements(WP_REST_Request $req){
    $licenseId = trim((string)$req->get_param('licenseId'));
    $machineId = trim((string)$req->get_param('machineId'));
    if ($licenseId === '') return new WP_REST_Response(['modules'=>[], 'count'=>0], 200);

    $allowed = $this->entitled_modules($licenseId, $machineId);
    $base = $this->base_dir();
    $realBase = realpath($base);
    $map  = get_option(self::OPT_MAP, []);
    $list = [];

    foreach ($allowed as $key){
      if (empty($map[$key])) continue;
      $rel = ltrim((string)$map[$key], '/');
      $abs = $base . '/' . $rel;
      $realAbs = realpath($abs);
      if ($realBase && $realAbs && is_file($realAbs) && strpos($realAbs, $realBase)===0){
        $list[] = [
          'id'    => $key,
          'name'  => $this->modules[$key],
          'path'  => $rel,
          'size'  => @filesize($realAbs) ?: 0,
          'mtime' => @filemtime($realAbs) ?: 0,
        ];
      }
    }

    return new WP_REST_Response(['modules'=>$list, 'count'=>count($list)], 200);
  }

  public function create_download_link(WP_REST_Request $req){
    $licenseId = trim((string)$req->get_param('licenseId'));
    $machineId = trim((string)$req->get_param('machineId'));
    $module    = trim((string)$req->get_param('module'));

    if ($licenseId === '' || $module === '') {
      return new WP_REST_Response(['error'=>'missing_params'], 400);
    }

    $allowed = $this->entitled_modules($licenseId, $machineId);
    if (!in_array($module, $allowed, true)) {
      return new WP_REST_Response(['error'=>'not_entitled'], 403);
    }

    list($file, $name) = $this->resolve_file($module);
    if (!$file) return new WP_REST_Response(['error'=>'file_not_mapped_or_missing'], 404);

    $token = wp_generate_password(48, false, false);
    $payload = [
      'm' => $module,
      'f' => $file,
      'n' => $name,
      'lic' => $licenseId,
      'exp' => time() + self::TOKEN_TTL,
      'once' => true,
    ];
    set_transient(self::TOKEN_PREFIX.$token, $payload, self::TOKEN_TTL);

    $url = add_query_arg(['hellas_dl'=>rawurlencode($token)], home_url('/'));
    return new WP_REST_Response(['url'=>$url,'ttl'=>self::TOKEN_TTL], 200);
  }

  /* ---------------- Token streaming ---------------- */

  public function handle_token_download(){
    $token = get_query_var('hellas_dl');
    if (!$token) return;

    $key = self::TOKEN_PREFIX.$token;
    $payload = get_transient($key);
    if (!$payload || !is_array($payload)){
      status_header(410);
      wp_die('This download link has expired.', 'Link expired', ['response'=>410]);
    }
    if (time() > intval($payload['exp'])){
      delete_transient($key);
      status_header(410);
      wp_die('This download link has expired.', 'Link expired', ['response'=>410]);
    }

    $base = realpath($this->base_dir());
    $file = $payload['f'] ?? '';
    $name = $payload['n'] ?? basename($file);
    $real = realpath($file);

    if (!$base || !$real || !is_file($real) || strpos($real, $base)!==0){
      delete_transient($key);
      status_header(404);
      wp_die('File not found.', 'Not found', ['response'=>404]);
    }

    if (!empty($payload['once'])) delete_transient($key);

    $mime = wp_check_filetype($name)['type'] ?: 'application/octet-stream';
    $size = @filesize($real) ?: 0;

    nocache_headers();
    header('Content-Description: File Transfer');
    header('Content-Type: '.$mime);
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('Content-Length: '.$size);
    header('X-Content-Type-Options: nosniff');

    @set_time_limit(0);
    $chunk = 1024 * 1024;
    $h = fopen($real, 'rb');
    if ($h){
      while (!feof($h)) { echo fread($h, $chunk); @ob_flush(); flush(); }
      fclose($h);
    }
    exit;
  }
}

/* Bootstrap */
new Hellas_Suite_Downloads();

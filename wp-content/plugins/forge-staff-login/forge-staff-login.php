<?php
/**
 * Plugin Name: Hephaestus Forge — Staff Auth
 * Description: Separate staff authentication (own DB + cookie) with temp-password enforcement, Dev flag, and a forge-styled login gate shortcode.
 * Version:     1.1.1
 * Author:      Hephaestus Forge
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

global $wpdb;
define('FORGE_STAFF_TABLE',      $wpdb->prefix . 'forge_staff');
define('FORGE_STAFF_SESS_TABLE', $wpdb->prefix . 'forge_staff_sessions');
define('FORGE_STAFF_COOKIE',     'forge_staff_token');
define('FORGE_STAFF_COOKIE_TTL', 60*60*24*7); // 7 days
define('FORGE_STAFF_SALT',       'forge_staff_v1'); // HMAC salt booster

/* -------------------------------------------------------------------------- */
/* Activation: create tables                                                  */
/* -------------------------------------------------------------------------- */
register_activation_hook(__FILE__, function () { forge_staff_install_tables(); });

/** Create/repair tables (safe to call anytime) */
function forge_staff_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE IF NOT EXISTS ".FORGE_STAFF_TABLE." (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      username VARCHAR(80) NOT NULL UNIQUE,
      display_name VARCHAR(120) NOT NULL,
      email VARCHAR(190) DEFAULT '' NOT NULL,
      pass_hash VARCHAR(255) NOT NULL,
      is_dev TINYINT(1) NOT NULL DEFAULT 0,
      require_pw_reset TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      last_login DATETIME NULL,
      PRIMARY KEY (id),
      KEY username_idx (username)
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS ".FORGE_STAFF_SESS_TABLE." (
      token CHAR(64) NOT NULL PRIMARY KEY,
      staff_id BIGINT UNSIGNED NOT NULL,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY staff_idx (staff_id),
      CONSTRAINT fk_staff FOREIGN KEY (staff_id)
        REFERENCES ".FORGE_STAFF_TABLE."(id) ON DELETE CASCADE
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
}
/* also ensure on admin hits */
add_action('admin_init', 'forge_staff_install_tables');

/* -------------------------------------------------------------------------- */
/* Utilities                                                                  */
/* -------------------------------------------------------------------------- */
function forge_staff_hash($password){ return password_hash($password, PASSWORD_DEFAULT); }
function forge_staff_verify($password,$hash){ return password_verify($password,$hash); }
function forge_staff_now_gmt(){ return gmdate('Y-m-d H:i:s'); }
function forge_staff_expires_gmt($ttl=FORGE_STAFF_COOKIE_TTL){ return gmdate('Y-m-d H:i:s', time()+$ttl); }
function forge_staff_sign($data){
    $key = wp_salt('auth') . wp_salt('secure_auth') . FORGE_STAFF_SALT;
    return hash_hmac('sha256', $data, $key);
}

/* -------------------------------------------------------------------------- */
/* Sessions (custom cookie)                                                   */
/* -------------------------------------------------------------------------- */
function forge_staff_current() {
    global $wpdb;
    if (empty($_COOKIE[FORGE_STAFF_COOKIE])) return null;
    $token = sanitize_text_field($_COOKIE[FORGE_STAFF_COOKIE]);

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT s.token, s.staff_id, s.expires_at,
                u.username, u.display_name, u.email, u.is_dev, u.require_pw_reset
         FROM ".FORGE_STAFF_SESS_TABLE." s
         JOIN ".FORGE_STAFF_TABLE." u ON u.id = s.staff_id
         WHERE s.token=%s AND s.expires_at > UTC_TIMESTAMP()", $token
    ), ARRAY_A);

    return $row ?: null;
}

function forge_staff_start_session($staff_id){
    global $wpdb;
    $raw   = wp_generate_password(32, true, true) . microtime(true) . $staff_id;
    $token = hash('sha256', $raw . forge_staff_sign($raw));

    $wpdb->insert(FORGE_STAFF_SESS_TABLE, [
        'token'     => $token,
        'staff_id'  => $staff_id,
        'expires_at'=> forge_staff_expires_gmt(),
    ], ['%s','%d','%s']);

    setcookie(FORGE_STAFF_COOKIE, $token, [
        'expires'  => time()+FORGE_STAFF_COOKIE_TTL,
        'path'     => COOKIEPATH ?: '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function forge_staff_end_session(){
    global $wpdb;
    if (!empty($_COOKIE[FORGE_STAFF_COOKIE])) {
        $token = sanitize_text_field($_COOKIE[FORGE_STAFF_COOKIE]);
        $wpdb->delete(FORGE_STAFF_SESS_TABLE, ['token'=>$token], ['%s']);
        setcookie(FORGE_STAFF_COOKIE, '', time()-3600, COOKIEPATH ?: '/', '', is_ssl(), true);
        unset($_COOKIE[FORGE_STAFF_COOKIE]);
    }
}

/* -------------------------------------------------------------------------- */
/* Admin UI: Staff Manager                                                    */
/* -------------------------------------------------------------------------- */
add_action('admin_menu', function(){
    add_menu_page('Forge Staff','Staff','manage_options','forge-staff', 'forge_staff_admin_page', 'dashicons-shield-alt', 58);
});

function forge_staff_admin_page(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;

    $notice = '';

    /* ---------- CREATE ---------- */
    if (isset($_POST['forge_staff_create'])) {
        check_admin_referer('forge_staff_admin');

        // normalize + validate
        $username = sanitize_user($_POST['username'] ?? '');
        $username = strtolower(trim($username));
        $username = preg_replace('/\s+/', '', $username); // no spaces
        $display  = sanitize_text_field($_POST['display_name'] ?? '');
        $email    = sanitize_email($_POST['email'] ?? '');
        $is_dev   = !empty($_POST['is_dev']) ? 1 : 0;

        $temp_pw  = wp_generate_password(12, false);
        $pass     = trim((string)($_POST['password'] ?? ''));
        if ($pass === '') $pass = $temp_pw;

        if ($username === '' || $display === '') {
            $notice = 'Please fill username and display name.';
        } else {
            forge_staff_install_tables(); // ensure tables

            // duplicate check
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM ".FORGE_STAFF_TABLE." WHERE username=%s LIMIT 1", $username
            ));

            if ($exists) {
                $notice = "Username already exists: <code>{$username}</code>";
            } else {
                $ok = $wpdb->insert(
                    FORGE_STAFF_TABLE,
                    [
                        'username'         => $username,
                        'display_name'     => $display,
                        'email'            => $email,
                        'pass_hash'        => forge_staff_hash($pass),
                        'is_dev'           => $is_dev,
                        'require_pw_reset' => 1,
                        'created_at'       => forge_staff_now_gmt(),
                    ],
                    ['%s','%s','%s','%s','%d','%d','%s']
                );

                if ($ok === false) {
                    $err = $wpdb->last_error ? ' DB: '.esc_html($wpdb->last_error) : '';
                    $notice = 'Error creating staff.' . $err;

                    // Try once more if table missing/just created
                    if (strpos($err, 'exist') !== false || strpos($err, 'table') !== false) {
                        forge_staff_install_tables();
                        $ok2 = $wpdb->insert(
                            FORGE_STAFF_TABLE,
                            [
                                'username'         => $username,
                                'display_name'     => $display,
                                'email'            => $email,
                                'pass_hash'        => forge_staff_hash($pass),
                                'is_dev'           => $is_dev,
                                'require_pw_reset' => 1,
                                'created_at'       => forge_staff_now_gmt(),
                            ],
                            ['%s','%s','%s','%s','%d','%d','%s']
                        );
                        if ($ok2 !== false) {
                            $notice = "Created staff '{$username}'. Temp PW: <code>{$pass}</code>";
                        }
                    }
                } else {
                    $notice = "Created staff '{$username}'. Temp PW: <code>{$pass}</code>";
                }
            }
        }
    }

    /* ---------- UPDATE ---------- */
    if (isset($_POST['forge_staff_update'])) {
        check_admin_referer('forge_staff_admin');
        $id      = intval($_POST['id'] ?? 0);
        $display = sanitize_text_field($_POST['display_name'] ?? '');
        $email   = sanitize_email($_POST['email'] ?? '');
        $is_dev  = !empty($_POST['is_dev']) ? 1 : 0;
        $req_rst = !empty($_POST['require_pw_reset']) ? 1 : 0;

        $data = [
            'display_name'=>$display,
            'email'=>$email,
            'is_dev'=>$is_dev,
            'require_pw_reset'=>$req_rst
        ];
        $fmt  = ['%s','%s','%d','%d'];

        if (!empty($_POST['new_password'])) {
            $data['pass_hash'] = forge_staff_hash((string)$_POST['new_password']);
            $fmt[] = '%s';
        }

        $ok = $wpdb->update(FORGE_STAFF_TABLE, $data, ['id'=>$id], $fmt, ['%d']);
        if ($ok === false) {
            $err = $wpdb->last_error ? ' DB: '.esc_html($wpdb->last_error) : '';
            $notice = 'Update failed.' . $err;
        } else {
            $notice = 'Updated.';
        }
    }

    /* ---------- DELETE ---------- */
    if (isset($_POST['forge_staff_delete'])) {
        check_admin_referer('forge_staff_admin');
        $id = intval($_POST['id'] ?? 0);
        $wpdb->delete(FORGE_STAFF_TABLE, ['id'=>$id], ['%d']);
        $notice = 'Deleted staff.';
    }

    $rows = $wpdb->get_results("SELECT * FROM ".FORGE_STAFF_TABLE." ORDER BY id DESC", ARRAY_A);
    ?>
    <div class="wrap">
      <h1>Forge Staff Manager</h1>
      <?php if ($notice): ?><div class="notice notice-info"><p><?php echo wp_kses_post($notice); ?></p></div><?php endif; ?>

      <h2>Add Staff</h2>
      <form method="post">
        <?php wp_nonce_field('forge_staff_admin'); ?>
        <table class="form-table">
          <tr><th>Username</th><td><input name="username" type="text" required></td></tr>
          <tr><th>Display Name</th><td><input name="display_name" type="text" required></td></tr>
          <tr><th>Email</th><td><input name="email" type="email"></td></tr>
          <tr><th>Password (temp)</th><td><input name="password" type="text" placeholder="Auto if empty"></td></tr>
          <tr><th>Developer</th><td><label><input name="is_dev" type="checkbox" value="1"> Is Dev</label></td></tr>
        </table>
        <p><button class="button button-primary" name="forge_staff_create" value="1">Create Staff</button></p>
      </form>

      <h2>Existing Staff</h2>
      <table class="widefat">
        <thead><tr><th>ID</th><th>User</th><th>Name</th><th>Email</th><th>Dev</th><th>Needs Reset</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo intval($r['id']); ?></td>
            <td><?php echo esc_html($r['username']); ?></td>
            <td><?php echo esc_html($r['display_name']); ?></td>
            <td><?php echo esc_html($r['email']); ?></td>
            <td><?php echo $r['is_dev'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo $r['require_pw_reset'] ? 'Yes' : 'No'; ?></td>
            <td>
              <details>
                <summary>Edit</summary>
                <form method="post" style="margin-top:8px;">
                  <?php wp_nonce_field('forge_staff_admin'); ?>
                  <input type="hidden" name="id" value="<?php echo intval($r['id']); ?>">
                  <p><label>Display Name <input type="text" name="display_name" value="<?php echo esc_attr($r['display_name']); ?>"></label></p>
                  <p><label>Email <input type="email" name="email" value="<?php echo esc_attr($r['email']); ?>"></label></p>
                  <p><label>Developer <input type="checkbox" name="is_dev" value="1" <?php checked($r['is_dev']); ?>></label></p>
                  <p><label>Require Password Reset <input type="checkbox" name="require_pw_reset" value="1" <?php checked($r['require_pw_reset']); ?>></label></p>
                  <p><label>Set New Password <input type="text" name="new_password" placeholder="leave empty to keep"></label></p>
                  <p>
                    <button class="button button-primary" name="forge_staff_update" value="1">Save</button>
                    <button class="button" name="forge_staff_delete" value="1" onclick="return confirm('Delete this staff?')">Delete</button>
                  </p>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/* -------------------------------------------------------------------------- */
/* Shortcode: [forge_staff_gate] (login/password-change/welcome)              */
/* -------------------------------------------------------------------------- */
add_shortcode('forge_staff_gate', function(){
    global $wpdb;

    // logout
    if (isset($_GET['forge_staff_logout']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'forge_staff_logout')) {
        forge_staff_end_session();
        wp_safe_redirect(remove_query_arg(['forge_staff_logout','_wpnonce'])); exit;
    }

    // login submit
    if (isset($_POST['forge_staff_login_action'])) {
        if (!wp_verify_nonce($_POST['forge_staff_nonce'] ?? '', 'forge_staff_login')) wp_die('Invalid request.');
        $username = sanitize_user($_POST['user_login'] ?? '');
        $username = strtolower(trim(preg_replace('/\s+/', '', $username)));
        $password = (string)($_POST['user_pass'] ?? '');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".FORGE_STAFF_TABLE." WHERE username=%s", $username), ARRAY_A);
        if ($row && forge_staff_verify($password, $row['pass_hash'])) {
            forge_staff_start_session((int)$row['id']);
            $wpdb->update(FORGE_STAFF_TABLE, ['last_login'=>forge_staff_now_gmt()], ['id'=>(int)$row['id']], ['%s'], ['%d']);
            wp_safe_redirect(add_query_arg('login', 'ok', esc_url_raw($_SERVER['REQUEST_URI']))); exit;
        } else {
            wp_safe_redirect(add_query_arg('login', 'failed', esc_url_raw($_SERVER['REQUEST_URI']))); exit;
        }
    }

    // forced password change submit
    if (isset($_POST['forge_staff_setpw_action'])) {
        if (!wp_verify_nonce($_POST['forge_staff_setpw_nonce'] ?? '', 'forge_staff_setpw')) wp_die('Invalid request.');
        $sess = forge_staff_current();
        if (!$sess) { wp_safe_redirect(esc_url_raw($_SERVER['REQUEST_URI'])); exit; }

        $pw1 = (string)($_POST['new_pass'] ?? '');
        $pw2 = (string)($_POST['new_pass2'] ?? '');
        if ($pw1 === '' || $pw1 !== $pw2 || strlen($pw1) < 8) {
            $msg = 'mismatch';
            if (strlen($pw1) < 8) $msg = 'short';
            wp_safe_redirect(add_query_arg('pw', $msg, esc_url_raw($_SERVER['REQUEST_URI']))); exit;
        }
        $wpdb->update(FORGE_STAFF_TABLE, [
            'pass_hash' => forge_staff_hash($pw1),
            'require_pw_reset' => 0
        ], ['id'=>(int)$sess['staff_id']], ['%s','%d'], ['%d']);
        wp_safe_redirect(add_query_arg('pw', 'ok', esc_url_raw($_SERVER['REQUEST_URI']))); exit;
    }

    /* render */
    ob_start();
    $sess = forge_staff_current();

    // not logged in -> login form (no extra wrapper; you style on page)
    if (!$sess) {
        $notice = (isset($_GET['login']) && $_GET['login']==='failed')
          ? '<p class="forge-sub" style="color:#ff8a3a">Login failed.</p>' : '';
        ?>
        <div>
          <?php echo $notice; ?>
          <label class="project-title" for="user_login">Username</label>
          <form method="post">
            <p><input id="user_login" name="user_login" type="text" required style="width:100%"></p>
            <label class="project-title" for="user_pass">Password</label>
            <p><input id="user_pass" name="user_pass" type="password" required style="width:100%"></p>
            <div class="project-meta" style="justify-content:flex-start;gap:12px">
              <button type="submit" class="project-btn">Sign In</button>
              <a class="pill" href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgot password?</a>
            </div>
            <input type="hidden" name="forge_staff_login_action" value="1">
            <input type="hidden" name="forge_staff_nonce" value="<?php echo esc_attr( wp_create_nonce('forge_staff_login') ); ?>">
          </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // logged in but needs new password
    if (!empty($sess['require_pw_reset'])) {
        $warn = '';
        if (isset($_GET['pw'])) {
            if ($_GET['pw']==='mismatch') $warn = '<p class="forge-sub" style="color:#ff8a3a">Passwords must match.</p>';
            if ($_GET['pw']==='short')    $warn = '<p class="forge-sub" style="color:#ff8a3a">Password must be at least 8 characters.</p>';
            if ($_GET['pw']==='ok')       $warn = '<p class="forge-sub" style="color:#71ffa0">Password updated. Welcome!</p>';
        } ?>
        <div>
          <p class="forge-sub">Welcome, <strong><?php echo esc_html($sess['display_name']); ?></strong>. Please set a new password.</p>
          <?php echo $warn; ?>
          <form method="post">
            <label class="project-title" for="new_pass">New Password</label>
            <p><input id="new_pass" name="new_pass" type="password" minlength="8" required style="width:100%"></p>
            <label class="project-title" for="new_pass2">Confirm Password</label>
            <p><input id="new_pass2" name="new_pass2" type="password" minlength="8" required style="width:100%"></p>
            <div class="project-meta" style="justify-content:flex-start;gap:12px">
              <button type="submit" class="project-btn">Save New Password</button>
              <a class="pill" href="<?php echo esc_url( wp_nonce_url( add_query_arg('forge_staff_logout','1'), 'forge_staff_logout') ); ?>">Log Out</a>
            </div>
            <input type="hidden" name="forge_staff_setpw_action" value="1">
            <input type="hidden" name="forge_staff_setpw_nonce" value="<?php echo esc_attr( wp_create_nonce('forge_staff_setpw') ); ?>">
          </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // logged in normal
    $ok = '';
    if (isset($_GET['login']) && $_GET['login']==='ok') $ok = '<p class="forge-sub" style="color:#71ffa0">Verified successfully.</p>';
    if (isset($_GET['pw']) && $_GET['pw']==='ok')       $ok = '<p class="forge-sub" style="color:#71ffa0">Password updated.</p>';
    ?>
    <div>
      <p class="forge-sub">Welcome, <strong><?php echo esc_html($sess['display_name']); ?></strong>.</p>
      <?php echo $ok; ?>
      <div class="project-meta" style="justify-content:flex-start;gap:12px">
        <a class="project-btn" href="<?php echo esc_url( home_url('/staff/tickets/') ); ?>">Go to Tickets</a>
        <a class="pill" href="<?php echo esc_url( wp_nonce_url( add_query_arg('forge_staff_logout','1'), 'forge_staff_logout') ); ?>">Log Out</a>
      </div>
    </div>
    <?php
    return ob_get_clean();
});

/* (Optional) convenience CSS injector for this page only
add_action('wp_head', function(){
    if (is_page('staff/login')) {
        echo '<style>#staff-login-wrap{min-height:80vh;display:flex;flex-direction:column;justify-content:center;}</style>';
    }
});
*/

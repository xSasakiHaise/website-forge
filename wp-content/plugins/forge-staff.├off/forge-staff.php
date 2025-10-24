<?php
/**
 * Plugin Name: Hephaestus Forge — Staff Login
 * Description: Separate front-end login for Forge Staff (role-restricted), plus dashboard & password reset.
 * Version:     1.0.0
 * Author:      Hephaestus Forge
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) { exit; }

define('FORGE_STAFF_LOGIN_VER', '1.0.0');
define('FORGE_STAFF_LOGIN_DIR', plugin_dir_path(__FILE__));
define('FORGE_STAFF_LOGIN_URL', plugin_dir_url(__FILE__));
define('FORGE_STAFF_ROLE', 'forge_staff');

/** Register role on activation */
register_activation_hook(__FILE__, function () {
    add_role(FORGE_STAFF_ROLE, 'Forge Staff', [
        'read' => true,
        // no edit caps; front-end only
    ]);
});

/** Cleanup on deactivation? (keep role by default) */
// register_deactivation_hook(__FILE__, function(){ remove_role(FORGE_STAFF_ROLE); });

/** Require files */
require_once FORGE_STAFF_LOGIN_DIR . 'includes/helpers.php';
require_once FORGE_STAFF_LOGIN_DIR . 'includes/class-forge-staff-shortcodes.php';

/** Block wp-admin for staff (except ajax) */
add_action('admin_init', function () {
    if (wp_doing_ajax()) return;
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (in_array(FORGE_STAFF_ROLE, (array)$user->roles, true)) {
        // Keep staff out of wp-admin, send to front-end dashboard
        if (is_admin()) {
            wp_safe_redirect(forge_staff_dashboard_url());
            exit;
        }
    }
});

/** Redirect after login: staff -> dashboard; others -> default */
add_filter('login_redirect', function ($redirect_to, $requested, $user) {
    if ($user instanceof WP_User && in_array(FORGE_STAFF_ROLE, (array)$user->roles, true)) {
        return forge_staff_dashboard_url();
    }
    return $redirect_to;
}, 10, 3);

/** Make lost password URL point to staff login page when coming from staff form */
add_filter('lostpassword_url', function($lostpassword_url, $redirect) {
    // Keep default, but if staff login exists, route back there
    $staff_login = forge_staff_login_url();
    if ($staff_login) {
        $args = [];
        if ($redirect) $args['redirect_to'] = $redirect;
        return add_query_arg($args, wp_lostpassword_url($staff_login));
    }
    return $lostpassword_url;
}, 10, 2);

/** Optional: add “Staff Accounts” quick links under Users (filters Users screen by role) */
add_action('admin_menu', function () {
    add_users_page(
        'Staff Accounts',
        'Staff Accounts',
        'list_users',
        'forge-staff-accounts',
        function () {
            $url = admin_url('users.php?role=' . urlencode(FORGE_STAFF_ROLE));
            echo '<div class="wrap"><h1>Staff Accounts</h1>';
            echo '<p><a class="button button-primary" href="'.esc_url($url).'">Open Users filtered to Staff</a></p>';
            echo '<p><strong>Tip:</strong> Use “Add New User” and set role to <em>Forge Staff</em>.</p>';
            echo '</div>';
        }
    );
});

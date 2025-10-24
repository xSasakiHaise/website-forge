<?php
/**
 * Plugin Name: Hellas License Manager
 * Description: Manual license issue + entitlement management with REST verify endpoint for HellasControl.
 * Version: 1.4.0
 * Author: Hephaestus Forge
 */

if (!defined('ABSPATH')) exit;

class Hellas_License_Manager {
    const MENU_SLUG = 'hellas-licenses';
    const TABLE     = 'hellas_licenses';
    const NS        = 'hellas/v1';
    const ROUTE     = '/license/verify';
    const NONCE_ACT = 'hellas_licenses';
    const NONCE_KEY = '_hellas_nonce';

    /**
     * Canonical module slugs => UI labels (A→Z by label)
     * These match the downloads plugin keys exactly.
     */
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

    /**
     * Legacy short names → canonical slugs (for seamless migration).
     */
    private $legacy_map = [
        'audio'      => 'hellasaudio',
        'deck'       => 'hellasdeck',
        'elo'        => 'hellaselo',
        'forms'      => 'hellasforms',
        'garden'     => 'hellasgarden',
        'helper'     => 'hellashelper',
        'mineralogy' => 'hellasmineralogy',
        'patcher'    => 'hellaspatcher',
        // (older installs never had these, but included for completeness)
        'battlebuddy'=> 'hellasbattlebuddy',
        'control'    => 'hellascontrol',
        'textures'   => 'hellastextures',
    ];

    public function __construct() {
        // Sort by label for consistent UI
        asort($this->modules, SORT_NATURAL | SORT_FLAG_CASE);

        add_action('admin_menu',    [$this, 'menu']);
        add_action('admin_init',    [$this, 'ensure_table']); // idempotent
        add_action('rest_api_init', [$this, 'rest']);
    }

    /** Resolve full table name with prefix */
    private function table() { global $wpdb; return $wpdb->prefix . self::TABLE; }

    /** Check if a column exists */
    private function column_exists($table, $col) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $col) );
    }

    /** Add a column if missing */
    private function ensure_column($table, $col, $definitionSql) {
        if (!$this->column_exists($table, $col)) {
            global $wpdb;
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN $definitionSql");
        }
    }

    /** Create/upgrade table schema (idempotent) */
    public function ensure_table() {
        global $wpdb;
        $table   = $this->table();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("
            CREATE TABLE IF NOT EXISTS `$table` (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              license_key   VARCHAR(64)  NOT NULL UNIQUE,
              customer_name VARCHAR(190) NULL,
              entitlements  TEXT         NULL,
              machine_id    VARCHAR(128) NULL,
              active        TINYINT(1)   NOT NULL DEFAULT 1,
              issued_at     DATETIME     NULL,
              expires_at    DATETIME     NULL,
              last_check    DATETIME     NULL,
              PRIMARY KEY (id),
              KEY idx_active (active),
              KEY idx_machine (machine_id)
            ) $charset;
        ");

        // Backfill columns for older installs (safe no-ops if already present)
        $this->ensure_column($table, 'customer_name', "customer_name VARCHAR(190) NULL AFTER license_key");
        $this->ensure_column($table, 'entitlements',  "entitlements  TEXT         NULL");
        $this->ensure_column($table, 'machine_id',    "machine_id    VARCHAR(128) NULL");
        $this->ensure_column($table, 'active',        "active        TINYINT(1)   NOT NULL DEFAULT 1");
        $this->ensure_column($table, 'issued_at',     "issued_at     DATETIME     NULL");
        $this->ensure_column($table, 'expires_at',    "expires_at    DATETIME     NULL");
        $this->ensure_column($table, 'last_check',    "last_check    DATETIME     NULL");
    }

    /** Admin menu */
    public function menu() {
        add_menu_page(
            'Hellas Licenses',
            'Hellas Licenses',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'page'],
            'dashicons-lock',
            58
        );
    }

    /** Nonce helpers */
    private function nonce_field() { wp_nonce_field(self::NONCE_ACT, self::NONCE_KEY); }
    private function check_nonce() {
        return isset($_POST[self::NONCE_KEY]) && wp_verify_nonce($_POST[self::NONCE_KEY], self::NONCE_ACT);
    }

    /** Normalize an incoming list (CSV or array) to canonical slugs */
    private function normalize_entitlements($value) : array {
        $list = is_array($value) ? $value : explode(',', (string)$value);
        $out  = [];
        foreach ($list as $raw) {
            $k = trim((string)$raw);
            if ($k === '') continue;
            // map legacy to canonical
            if (isset($this->legacy_map[$k])) $k = $this->legacy_map[$k];
            if (isset($this->modules[$k])) $out[] = $k;
        }
        // unique & sorted by label
        $out = array_values(array_unique($out));
        usort($out, function($a,$b){
            return strcasecmp($this->modules[$a], $this->modules[$b]);
        });
        return $out;
    }

    /** Convert checkbox POST array into CSV of canonical slugs */
    private function csv_from_checkboxes($key) {
        $mods = isset($_POST[$key]) && is_array($_POST[$key]) ? array_map('sanitize_text_field', wp_unslash($_POST[$key])) : [];
        $mods = $this->normalize_entitlements($mods);          // keep only valid slugs, map legacy if ever posted
        return implode(',', $mods);
    }

    /** Admin page renderer + action handlers */
    public function page() {
        if (!current_user_can('manage_options')) return;

        // Ensure schema now
        $this->ensure_table();

        global $wpdb;
        $table  = $this->table();
        $notice = '';

        // Handle actions securely
        if (!empty($_POST['hellas_action'])) {
            if (!$this->check_nonce()) {
                echo '<div class="error"><p>Security check failed. Refresh the page and try again.</p></div>';
            } else {
                $act = sanitize_text_field($_POST['hellas_action']);

                if ($act === 'issue') {
                    $key   = sanitize_text_field($_POST['license_key'] ?? '');
                    if ($key === '') $key = wp_generate_password(24, false);
                    $name  = sanitize_text_field($_POST['customer_name'] ?? '');
                    $days  = max(1, intval($_POST['days'] ?? 365));
                    $exp   = gmdate('Y-m-d H:i:s', time() + $days*86400);
                    $ents  = $this->csv_from_checkboxes('modules');

                    $wpdb->replace(
                        $table,
                        [
                            'license_key'   => $key,
                            'customer_name' => $name,
                            'entitlements'  => $ents,
                            'issued_at'     => current_time('mysql'),
                            'expires_at'    => $exp,
                            'active'        => 1,
                        ],
                        ['%s','%s','%s','%s','%s','%d']
                    );

                    if ($wpdb->last_error) {
                        echo '<div class="error"><p><strong>DB error issuing license:</strong> '
                           . esc_html($wpdb->last_error) . '</p></div>';
                    } else {
                        $notice = 'Issued/updated license <code>'.esc_html($key).'</code>';
                    }
                }

                if ($act === 'revoke' || $act === 'activate') {
                    $key = sanitize_text_field($_POST['license_key'] ?? '');
                    if ($key !== '') {
                        $wpdb->update($table, ['active' => ($act === 'activate' ? 1 : 0)], ['license_key'=>$key], ['%d'], ['%s']);
                        if ($wpdb->last_error) {
                            echo '<div class="error"><p><strong>DB error:</strong> '.esc_html($wpdb->last_error).'</p></div>';
                        } else {
                            $notice = ($act==='activate'?'Activated':'Revoked').' <code>'.esc_html($key).'</code>';
                        }
                    }
                }

                if ($act === 'unbind') {
                    $key = sanitize_text_field($_POST['license_key'] ?? '');
                    if ($key !== '') {
                        $wpdb->update($table, ['machine_id'=>null], ['license_key'=>$key], ['%s'], ['%s']);
                        if ($wpdb->last_error) {
                            echo '<div class="error"><p><strong>DB error:</strong> '.esc_html($wpdb->last_error).'</p></div>';
                        } else {
                            $notice = 'Unbound machine for <code>'.esc_html($key).'</code>';
                        }
                    }
                }

                if ($act === 'save_row') {
                    $key  = sanitize_text_field($_POST['license_key'] ?? '');
                    $name = sanitize_text_field($_POST['row_name'] ?? '');
                    $ents = $this->csv_from_checkboxes('row_modules');
                    $exp  = sanitize_text_field($_POST['row_expires'] ?? '');

                    $data = ['customer_name'=>$name, 'entitlements'=>$ents];
                    if ($exp !== '') $data['expires_at'] = $exp;

                    if ($key !== '') {
                        $wpdb->update($table, $data, ['license_key'=>$key], array_fill(0, count($data), '%s'), ['%s']);
                        if ($wpdb->last_error) {
                            echo '<div class="error"><p><strong>DB error:</strong> '.esc_html($wpdb->last_error).'</p></div>';
                        } else {
                            $notice = 'Updated license <code>'.esc_html($key).'</code>';
                        }
                    }
                }
            }
        }

        // Fetch rows
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        if ($wpdb->last_error) {
            echo '<div class="error"><p><strong>DB error selecting rows:</strong> '.esc_html($wpdb->last_error).'</p></div>';
        }

        echo '<div class="wrap"><h1>Hellas Licenses</h1>';
        if ($notice) echo '<div class="updated"><p>'.$notice.'</p></div>';
        echo '<p><em>Found '.intval(count($rows)).' license(s) in <code>'.esc_html($table).'</code>.</em></p>';

        // Issue / Update form
        echo '<h2>Issue / Update</h2>
        <form method="post">';
        $this->nonce_field();
        echo '<input type="hidden" name="hellas_action" value="issue" />
          <p><label><strong>Customer Name</strong><br>
          <input name="customer_name" class="regular-text" placeholder="e.g. My Server Owner"></label></p>
          <p><label><strong>License Key</strong> (leave empty to auto-generate)<br>
          <input name="license_key" class="regular-text" placeholder="HF-XXXX..."></label></p>
          <p><strong>Entitlements</strong><br>';
        foreach ($this->modules as $slug => $label) {
            echo '<label style="margin-right:12px"><input type="checkbox" name="modules[]" value="'.esc_attr($slug).'"> '.esc_html($label).'</label>';
        }
        echo '</p>
          <p><label><strong>Days Valid</strong> <input type="number" name="days" value="365" min="1" max="3650"></label></p>
          '.submit_button('Issue / Update License', 'primary', '', false).'
        </form><hr>';

        // Table of licenses
        echo '<h2>Licenses</h2>';
        if (empty($rows)) {
            echo '<p><em>No licenses yet. Use the form above to create one.</em></p>';
        } else {
            echo '<table class="widefat striped">
              <thead><tr>
                <th>Name</th><th>Key</th><th>Entitlements</th><th>Machine</th>
                <th>Active</th><th>Issued</th><th>Expires (UTC)</th><th>Last Check</th><th>Actions</th>
              </tr></thead><tbody>';

            foreach ($rows as $r) {
                $active = intval($r->active)===1;

                // Normalize any legacy CSV to canonical slugs for display & editing
                $existing = $this->normalize_entitlements($r->entitlements);

                echo '<tr>
                  <td>'.esc_html($r->customer_name ?: '—').'</td>
                  <td><code>'.esc_html($r->license_key).'</code></td>
                  <td>
                    <form method="post" style="display:inline">';
                $this->nonce_field();
                echo '  <input type="hidden" name="hellas_action" value="save_row">
                        <input type="hidden" name="license_key" value="'.esc_attr($r->license_key).'">
                        <div style="margin-bottom:6px">
                          <input type="text" name="row_name" value="'.esc_attr($r->customer_name).'" placeholder="Name" style="width:220px">
                          <input type="datetime-local" name="row_expires" value="'.esc_attr($r->expires_at ? gmdate('Y-m-d\TH:i:s', strtotime($r->expires_at.' UTC')) : '').'" style="margin-left:6px">
                        </div>';
                foreach ($this->modules as $slug => $label) {
                    $checked = in_array($slug, $existing, true) ? 'checked' : '';
                    echo '<label style="margin-right:10px;white-space:nowrap">
                            <input type="checkbox" name="row_modules[]" value="'.esc_attr($slug).'" '.$checked.'> '.esc_html($label).'
                          </label>';
                }
                echo '  <button class="button button-primary" style="margin-left:6px">Save</button>
                    </form>
                  </td>
                  <td>'.esc_html($r->machine_id ?: '—').'</td>
                  <td>'.($active?'Yes':'No').'</td>
                  <td>'.esc_html($r->issued_at ?: '—').'</td>
                  <td>'.esc_html($r->expires_at ?: '—').'</td>
                  <td>'.esc_html($r->last_check ?: '—').'</td>
                  <td>
                    <form method="post" style="display:inline">';
                $this->nonce_field();
                echo '  <input type="hidden" name="hellas_action" value="'.($active?'revoke':'activate').'">
                        <input type="hidden" name="license_key" value="'.esc_attr($r->license_key).'">
                        <button class="button">'.($active?'Revoke':'Activate').'</button>
                    </form>
                    <form method="post" style="display:inline;margin-left:6px">';
                $this->nonce_field();
                echo '  <input type="hidden" name="hellas_action" value="unbind">
                        <input type="hidden" name="license_key" value="'.esc_attr($r->license_key).'">
                        <button class="button">Unbind</button>
                    </form>
                  </td>
                </tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    /** REST endpoint the mod calls */
    public function rest() {
        register_rest_route(self::NS, self::ROUTE, [
            'methods'  => 'POST',
            'permission_callback' => '__return_true',
            'callback' => function(WP_REST_Request $req) {
                global $wpdb; 
                $table = $this->table();

                $licenseId = sanitize_text_field($req->get_param('licenseId'));
                $machineId = sanitize_text_field($req->get_param('machineId'));

                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE license_key=%s", $licenseId));
                if (!$row) {
                    return ['status'=>'not_found','licenseId'=>$licenseId,'message'=>'License not found','expires'=>null,'entitlements'=>[]];
                }

                // Bind on first use
                if (empty($row->machine_id)) {
                    $wpdb->update($table, ['machine_id'=>$machineId], ['id'=>$row->id], ['%s'], ['%d']);
                    $row->machine_id = $machineId;
                }

                if (intval($row->active)!==1) {
                    return ['status'=>'revoked','licenseId'=>$licenseId,'message'=>'License deactivated','expires'=>$row->expires_at,'entitlements'=>[]];
                }

                if ($row->machine_id !== $machineId) {
                    return ['status'=>'mismatch','licenseId'=>$licenseId,'message'=>'Machine ID mismatch','expires'=>$row->expires_at,'entitlements'=>[]];
                }

                // Update last check
                $wpdb->update($table, ['last_check'=>current_time('mysql')], ['id'=>$row->id], ['%s'], ['%d']);

                // Expiration
                if (!empty($row->expires_at) && strtotime($row->expires_at.' UTC') < time()) {
                    return ['status'=>'expired','licenseId'=>$licenseId,'message'=>'License expired','expires'=>$row->expires_at,'entitlements'=>[]];
                }

                // Normalize any legacy entitlements -> canonical slugs
                $ents = $this->normalize_entitlements($row->entitlements);

                return [
                    'status'       => 'valid',
                    'licenseId'    => $licenseId,
                    'message'      => 'OK',
                    'expires'      => $row->expires_at,
                    'entitlements' => $ents,
                ];
            }
        ]);
    }
}

new Hellas_License_Manager();

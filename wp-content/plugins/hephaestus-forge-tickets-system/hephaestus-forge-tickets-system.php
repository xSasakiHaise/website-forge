<?php
/**
 * Plugin Name: Hephaestus Forge — Tickets System
 * Description: Forge-styled internal ticket tracker using custom Staff Auth sessions. Creator can edit their own tickets; devs can edit/assign/status/target and archive tickets. DB migrations, dev-only archived toggle, full-bleed table card, sortable columns, staff display names, and PRG to prevent duplicates.
 * Version:     1.6.2
 * Author:      Hephaestus Forge
 */

if (!defined('ABSPATH')) exit;

global $wpdb;
define('FORGE_TICKETS_TABLE', $wpdb->prefix . 'forge_tickets');
define('FORGE_TICKETS_NONCE', 'forge_tickets_actions');

/* ============================================================
 * Schema + Column Migration
 * ============================================================ */

register_activation_hook(__FILE__, function () {
    forge_tickets_install_schema();
    forge_tickets_ensure_columns();
});
add_action('admin_init', function () {
    forge_tickets_install_schema();
    forge_tickets_ensure_columns();
});

function forge_tickets_install_schema() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS " . FORGE_TICKETS_TABLE . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        suite VARCHAR(64)  NOT NULL,
        pokemon VARCHAR(120) DEFAULT '',
        pokemon_form VARCHAR(120) DEFAULT '',
        item VARCHAR(120) DEFAULT '',
        move VARCHAR(120) DEFAULT '',
        ability VARCHAR(120) DEFAULT '',
        comments LONGTEXT,
        nr_pokemon TINYINT(1) DEFAULT 0,
        nr_form TINYINT(1) DEFAULT 0,
        nr_item TINYINT(1) DEFAULT 0,
        nr_move TINYINT(1) DEFAULT 0,
        nr_ability TINYINT(1) DEFAULT 0,
        nr_comments TINYINT(1) DEFAULT 0,
        target_version VARCHAR(64) DEFAULT '',
        status VARCHAR(32) DEFAULT 'requested',
        assignee VARCHAR(120) DEFAULT '',
        author VARCHAR(120) DEFAULT '',
        author_display VARCHAR(180) DEFAULT '',
        archived TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY suite_idx (suite),
        KEY status_idx (status),
        KEY archived_idx (archived),
        KEY created_idx (created_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/** Add missing columns explicitly (covers legacy tables). */
function forge_tickets_ensure_columns() {
    global $wpdb;
    $table = FORGE_TICKETS_TABLE;
    $existing = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0) ?: [];

    $adds = [
        'suite'          => "ALTER TABLE `$table` ADD COLUMN suite VARCHAR(64) NOT NULL AFTER title",
        'pokemon'        => "ALTER TABLE `$table` ADD COLUMN pokemon VARCHAR(120) NOT NULL DEFAULT ''",
        'pokemon_form'   => "ALTER TABLE `$table` ADD COLUMN pokemon_form VARCHAR(120) NOT NULL DEFAULT ''",
        'item'           => "ALTER TABLE `$table` ADD COLUMN item VARCHAR(120) NOT NULL DEFAULT ''",
        'move'           => "ALTER TABLE `$table` ADD COLUMN move VARCHAR(120) NOT NULL DEFAULT ''",
        'ability'        => "ALTER TABLE `$table` ADD COLUMN ability VARCHAR(120) NOT NULL DEFAULT ''",
        'comments'       => "ALTER TABLE `$table` ADD COLUMN comments LONGTEXT",
        'nr_pokemon'     => "ALTER TABLE `$table` ADD COLUMN nr_pokemon TINYINT(1) NOT NULL DEFAULT 0",
        'nr_form'        => "ALTER TABLE `$table` ADD COLUMN nr_form TINYINT(1) NOT NULL DEFAULT 0",
        'nr_item'        => "ALTER TABLE `$table` ADD COLUMN nr_item TINYINT(1) NOT NULL DEFAULT 0",
        'nr_move'        => "ALTER TABLE `$table` ADD COLUMN nr_move TINYINT(1) NOT NULL DEFAULT 0",
        'nr_ability'     => "ALTER TABLE `$table` ADD COLUMN nr_ability TINYINT(1) NOT NULL DEFAULT 0",
        'nr_comments'    => "ALTER TABLE `$table` ADD COLUMN nr_comments TINYINT(1) NOT NULL DEFAULT 0",
        'target_version' => "ALTER TABLE `$table` ADD COLUMN target_version VARCHAR(64) NOT NULL DEFAULT ''",
        'status'         => "ALTER TABLE `$table` ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'requested'",
        'assignee'       => "ALTER TABLE `$table` ADD COLUMN assignee VARCHAR(120) NOT NULL DEFAULT ''",
        'author'         => "ALTER TABLE `$table` ADD COLUMN author VARCHAR(120) NOT NULL DEFAULT ''",
        'author_display' => "ALTER TABLE `$table` ADD COLUMN author_display VARCHAR(180) NOT NULL DEFAULT ''",
        'archived'       => "ALTER TABLE `$table` ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0",
        'created_at'     => "ALTER TABLE `$table` ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at'     => "ALTER TABLE `$table` ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($adds as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            $wpdb->query($sql);
        }
    }
}

/* ============================================================
 * Helpers
 * ============================================================ */

function forge_tickets_current_staff() {
    if (!function_exists('forge_staff_current')) return null;
    $s = forge_staff_current();
    if (!$s || empty($s['username'])) return null;
    return [
        'username' => (string) $s['username'],
        'display'  => (string) ($s['display_name'] ?? $s['username']),
        'is_dev'   => !empty($s['is_dev']),
    ];
}

function forge_tickets_suites() {
    return [
        'HellasAudio','HellasBattlebuddy','HellasControl','HellasDeck','HellasElo',
        'HellasForms','HellasGardens','HellasHelper','HellasMineralogy','HellasPatcher',
        'HellasTextures','Other'
    ];
}
function forge_tickets_statuses() {
    // Added "rejected"
    return ['requested','seen','accepted','in dev','built','released','delayed','bugged (not live)','bugged (live)','rejected'];
}

/** Return list of WP users [ ['login'=>..., 'display'=>...] ] for assignee dropdown */
function forge_all_wp_users() {
    $users = get_users(['fields' => ['user_login','display_name']]);
    return array_map(function($u){
        return ['login'=>$u->user_login, 'display'=>$u->display_name ?: $u->user_login];
    }, $users);
}

/** Build current page URL without POST and with preserved query args */
function forge_current_page_url_preserve($args = []) {
    $scheme = is_ssl() ? 'https' : 'http';
    $url = home_url(add_query_arg(null, null), $scheme);
    if (!empty($args)) $url = add_query_arg($args, $url);
    return $url;
}

/* ============================================================
 * Shortcode
 * ============================================================ */

add_shortcode('forge_tickets', function () {
    forge_tickets_install_schema();
    forge_tickets_ensure_columns();

    global $wpdb;
    $me = forge_tickets_current_staff();
    if (!$me) {
        return '<section class="projects-wrap"><div class="projects-head"><h1 class="forge-h1">Forge Tickets</h1><p class="forge-sub">You must be verified.</p></div></section>';
    }

    // Dev-only archived toggle via query arg (?archived=1)
    $show_archived = $me['is_dev'] && isset($_GET['archived']) && $_GET['archived'] === '1';

    // Sorting: allow-list of columns and safe direction
    $allowed_sort = [
        'id'=>'id','title'=>'title','suite'=>'suite','pokemon'=>'pokemon','pokemon_form'=>'pokemon_form',
        'item'=>'item','move'=>'move','ability'=>'ability','status'=>'status','target_version'=>'target_version',
        'author_display'=>'author_display','assignee'=>'assignee','updated_at'=>'updated_at','created_at'=>'created_at'
    ];
    $sort = isset($_GET['sort']) && isset($allowed_sort[$_GET['sort']]) ? $allowed_sort[$_GET['sort']] : 'created_at';
    $dir  = (isset($_GET['dir']) && strtolower($_GET['dir'])==='asc') ? 'ASC' : 'DESC';

    $notice = '';
    $redirect_after_post = false;

    /* --------- CREATE --------- */
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['forge_ticket_create'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', FORGE_TICKETS_NONCE)) {
            $notice = '<p class="forge-sub" style="color:#ff8a3a">Invalid request.</p>';
        } else {
            $title = trim(sanitize_text_field($_POST['t_title'] ?? ''));
            $suite = sanitize_text_field($_POST['t_suite'] ?? '');
            if (!in_array($suite, forge_tickets_suites(), true)) $suite = 'Other';

            $fields = ['pokemon','form','item','move','ability','comments'];
            $d = [];
            foreach ($fields as $f) {
                $nr = !empty($_POST["nr_$f"]);
                if ($f === 'comments') {
                    $d[$f] = $nr ? '' : sanitize_textarea_field($_POST["t_$f"] ?? '');
                } else {
                    $d[$f] = $nr ? '' : sanitize_text_field($_POST["t_$f"] ?? '');
                }
                $d["nr_$f"] = $nr ? 1 : 0;
            }

            if ($title === '') {
                $notice = '<p class="forge-sub" style="color:#ff8a3a">Title is required.</p>';
            } else {
                $ok = $wpdb->insert(
                    FORGE_TICKETS_TABLE,
                    [
                        'title' => $title,
                        'suite' => $suite,
                        'pokemon'      => $d['pokemon'],
                        'pokemon_form' => $d['form'],
                        'item'         => $d['item'],
                        'move'         => $d['move'],
                        'ability'      => $d['ability'],
                        'comments'     => $d['comments'],
                        'nr_pokemon'   => $d['nr_pokemon'],
                        'nr_form'      => $d['nr_form'],
                        'nr_item'      => $d['nr_item'],
                        'nr_move'      => $d['nr_move'],
                        'nr_ability'   => $d['nr_ability'],
                        'nr_comments'  => $d['nr_comments'],
                        'status'       => 'requested',
                        'author'       => $me['username'],                 // store login
                        'author_display' => $me['display'],                // store staff display name
                        'created_at'   => current_time('mysql', 1),
                    ],
                    [
                        '%s','%s','%s','%s','%s','%s','%s','%s',
                        '%d','%d','%d','%d','%d','%d','%s','%s','%s','%s'
                    ]
                );
                if ($ok !== false) {
                    // PRG: Redirect to same page preserving view state to avoid duplicates on refresh
                    $redirect_after_post = true;
                } else {
                    $notice = '<p class="forge-sub" style="color:#ff8a3a">Failed to create ticket. DB: '.esc_html($wpdb->last_error).'</p>';
                }
            }
        }
    }

    /* --------- UPDATE / ARCHIVE --------- */
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['forge_ticket_update'])) {
        if (wp_verify_nonce($_POST['_wpnonce'] ?? '', FORGE_TICKETS_NONCE)) {
            $id = (int) ($_POST['u_id'] ?? 0);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".FORGE_TICKETS_TABLE." WHERE id=%d", $id));
            if ($row && ($me['is_dev'] || $row->author === $me['username'])) {
                $updates = [
                    'title'      => sanitize_text_field($_POST['u_title'] ?? $row->title),
                    'comments'   => sanitize_textarea_field($_POST['u_comments'] ?? $row->comments),
                    'updated_at' => current_time('mysql', 1),
                ];
                if ($me['is_dev']) {
                    $stat = sanitize_text_field($_POST['u_status'] ?? $row->status);
                    if (!in_array($stat, forge_tickets_statuses(), true)) $stat = 'requested';
                    $updates['status'] = $stat;
                    $updates['target_version'] = sanitize_text_field($_POST['u_target'] ?? $row->target_version);

                    // Assignee must be a valid WP login or blank
                    $assignee = sanitize_text_field($_POST['u_assignee'] ?? '');
                    if ($assignee !== '') {
                        $u = get_user_by('login', $assignee);
                        if ($u) $updates['assignee'] = $assignee;
                    } else {
                        $updates['assignee'] = '';
                    }

                    if (!empty($_POST['u_archive'])) $updates['archived'] = 1;
                }
                $ok = $wpdb->update(FORGE_TICKETS_TABLE, $updates, ['id'=>$id]);
                if ($ok !== false) {
                    $redirect_after_post = true; // PRG on update too
                } else {
                    $notice = '<p class="forge-sub" style="color:#ff8a3a">Update failed. DB: '.esc_html($wpdb->last_error).'</p>';
                }
            }
        }
    }

    // Perform PRG redirect if set
    if ($redirect_after_post) {
        $qs = [];
        if ($show_archived) $qs['archived'] = '1';
        if (!empty($_GET['sort'])) $qs['sort'] = sanitize_key($_GET['sort']);
        if (!empty($_GET['dir']))  $qs['dir']  = strtolower($_GET['dir'])==='asc' ? 'asc' : 'desc';
        wp_safe_redirect( forge_current_page_url_preserve($qs) );
        exit;
    }

    // Toggle links (dev-only)
    $base_url = remove_query_arg(['archived','sort','dir']);
    $show_url = esc_url(add_query_arg('archived', '1', $base_url));
    $hide_url = esc_url($base_url);

    // Build sort URLs helper
    $current_url_base = add_query_arg(['archived' => $show_archived ? '1' : null], $base_url);
    $sort_link = function($col) use ($current_url_base, $sort, $dir) {
        $next_dir = ($sort === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
        return esc_url(add_query_arg(['sort'=>$col, 'dir'=>$next_dir], $current_url_base));
    };

    /* --------- Fetch (based on toggle + sort) --------- */
    $where_arch = $show_archived ? 1 : 0;
    // $sort is allow-listed; $dir is ASC/DESC
    $tickets = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM ".FORGE_TICKETS_TABLE." WHERE archived=%d ORDER BY $sort $dir", $where_arch),
        ARRAY_A
    );

    /* --------- Render --------- */
    ob_start(); ?>

<style>
/* Base card */
.forge-card{
  background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(0,0,0,.10));
  border:1px solid rgba(255,255,255,.10);
  border-radius:16px;
  box-shadow:0 0 24px rgba(255,77,59,.28), inset 0 0 120px rgba(0,0,0,.25);
  margin:0 auto 24px;
}
.forge-card .card-body{padding:24px 22px}

/* New Ticket: compact, centered */
.forge-card--compact{max-width:900px;width:min(900px,96vw)}

/* Tickets Table card: full-bleed with fixed 20px gutters */
.forge-card--edge{
  max-width:none;
  width:calc(100vw - 40px);
  margin-left:calc(50% - 50vw + 20px);
  margin-right:calc(50% - 50vw + 20px);
}

/* Inner table */
.table-scroll{overflow-x:auto;width:100%}
.forge-table{width:100%;min-width:1900px;border-collapse:collapse;margin-top:12px;table-layout:auto}
.forge-table th,.forge-table td{
  padding:10px 14px;
  border-bottom:1px solid rgba(255,255,255,.08);
  text-align:left;
  vertical-align:top;
}
.forge-table th a{ color:inherit; text-decoration:none; }
.forge-table th a:hover{ text-decoration:underline; }

.forge-dim{opacity:.55}
.forge-pre{white-space:pre-wrap;line-height:1.35}

/* Form grid + subtle dividers */
.forge-form-grid{display:grid;grid-template-columns:280px 1fr;gap:16px 18px;align-items:center}
.forge-nr{display:inline-block;margin-top:6px;opacity:.9}
.forge-divider{border-top:1px solid rgba(255,255,255,.07);grid-column:1/-1;margin:6px 0}

/* Buttons / toolbar */
.project-btn.small{padding:5px 10px;font-size:.9em}
.forge-toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:8px}
</style>

<section class="projects-wrap" aria-labelledby="tickets-title">
  <header class="projects-head">
    <h1 id="tickets-title" class="forge-h1">Forge Tickets</h1>
    <p class="forge-sub">
      Logged in as <strong><?php echo esc_html($me['display']); ?></strong>
      <?php echo $me['is_dev'] ? ' — <span style="color:#71ffa0">Developer</span>' : ''; ?>
    </p>
    <?php echo $notice; ?>
  </header>

  <!-- New Ticket (compact card) -->
  <article class="forge-card forge-card--compact">
    <div class="card-body">
      <h2 class="project-title" style="margin-bottom:10px">New Ticket</h2>
      <form method="post">
        <?php wp_nonce_field(FORGE_TICKETS_NONCE); ?>

        <div class="forge-form-grid">
          <div><label class="forge-sub">Title *</label></div>
          <div><input name="t_title" type="text" required style="width:100%"></div>
          <div class="forge-divider"></div>

          <div><label class="forge-sub">Part of Hellas Suite *</label></div>
          <div>
            <select name="t_suite" required style="width:100%">
              <option value="" disabled selected>— Choose —</option>
              <?php foreach (forge_tickets_suites() as $s): ?>
                <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="forge-divider"></div>

          <?php
          $rows = [
            ['pokemon','Which Pokémon'],
            ['form','Which Form'],
            ['item','Which Item'],
            ['move','Which Move'],
            ['ability','Which Ability'],
            ['comments','Comments']
          ];
          foreach ($rows as [$key,$label]): ?>
            <div>
              <label class="forge-sub"><?php echo esc_html($label); ?></label><br>
              <label class="forge-nr"><input type="checkbox" name="nr_<?php echo esc_attr($key); ?>" value="1"> Not relevant</label>
            </div>
            <div>
              <?php echo $key==='comments'
                ? '<textarea name="t_'.$key.'" rows="3" style="width:100%"></textarea>'
                : '<input name="t_'.$key.'" type="text" style="width:100%">'; ?>
            </div>
            <div class="forge-divider"></div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:14px">
          <button class="project-btn" name="forge_ticket_create" value="1">Submit Ticket</button>
        </div>
      </form>
    </div>
  </article>

  <!-- Tickets Table (full-bleed lower card with 20px gutters) -->
  <article class="forge-card forge-card--edge">
    <div class="card-body">
      <div class="forge-toolbar">
        <h2 class="project-title" style="margin:0">
          <?php echo $show_archived ? 'Archived Tickets' : 'All Tickets'; ?>
        </h2>
        <?php if ($me['is_dev']): ?>
          <?php if ($show_archived): ?>
            <a href="<?php echo $hide_url; ?>" class="project-btn small">Hide Archived</a>
          <?php else: ?>
            <a href="<?php echo $show_url; ?>" class="project-btn small">Show Archived</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="table-scroll">
        <table class="forge-table">
          <thead>
            <tr class="forge-sub" style="opacity:.85">
              <th><a href="<?php echo $sort_link('id'); ?>">#</a></th>
              <th><a href="<?php echo $sort_link('title'); ?>">Title</a></th>
              <th><a href="<?php echo $sort_link('suite'); ?>">Suite</a></th>
              <th><a href="<?php echo $sort_link('pokemon'); ?>">Pokémon</a></th>
              <th><a href="<?php echo $sort_link('pokemon_form'); ?>">Form</a></th>
              <th><a href="<?php echo $sort_link('item'); ?>">Item</a></th>
              <th><a href="<?php echo $sort_link('move'); ?>">Move</a></th>
              <th><a href="<?php echo $sort_link('ability'); ?>">Ability</a></th>
              <th>Comments</th>
              <th><a href="<?php echo $sort_link('status'); ?>">Status</a></th>
              <th><a href="<?php echo $sort_link('target_version'); ?>">Target</a></th>
              <th><a href="<?php echo $sort_link('author_display'); ?>">Author</a></th>
              <th><a href="<?php echo $sort_link('assignee'); ?>">Assignee</a></th>
              <th><a href="<?php echo $sort_link('updated_at'); ?>"><?php echo $show_archived ? 'Archived' : 'Updated'; ?></a></th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$tickets): ?>
              <tr><td colspan="15"><span class="forge-sub">No tickets<?php echo $show_archived ? ' (archived)' : ''; ?>.</span></td></tr>
            <?php else:
              $all_users = forge_all_wp_users();
              foreach ($tickets as $t):
                $author_disp   = $t['author_display'] ?: $t['author'];
                $assignee_disp = $t['assignee'] ? ($t['assignee'] === $t['author'] && $t['author_display'] ? $t['author_display'] : $t['assignee']) : '';
              ?>
              <tr>
                <td><?php echo (int)$t['id']; ?></td>
                <td><?php echo esc_html($t['title']); ?></td>
                <td><?php echo esc_html($t['suite']); ?></td>
                <td><?php echo $t['nr_pokemon']  ? '<span class="forge-dim">—</span>' : esc_html($t['pokemon']); ?></td>
                <td><?php echo $t['nr_form']     ? '<span class="forge-dim">—</span>' : esc_html($t['pokemon_form']); ?></td>
                <td><?php echo $t['nr_item']     ? '<span class="forge-dim">—</span>' : esc_html($t['item']); ?></td>
                <td><?php echo $t['nr_move']     ? '<span class="forge-dim">—</span>' : esc_html($t['move']); ?></td>
                <td><?php echo $t['nr_ability']  ? '<span class="forge-dim">—</span>' : esc_html($t['ability']); ?></td>
                <td class="forge-pre"><?php echo $t['nr_comments'] ? '<span class="forge-dim">—</span>' : esc_html($t['comments']); ?></td>
                <td><?php echo esc_html($t['status']); ?></td>
                <td><?php echo esc_html($t['target_version']); ?></td>
                <td><?php echo esc_html($author_disp); ?></td>
                <td><?php echo esc_html($assignee_disp); ?></td>
                <td><?php $dt = $t['updated_at'] ?? $t['created_at']; echo esc_html(mysql2date('Y-m-d H:i', $dt, true)); ?></td>
                <td>
                  <details>
                    <summary class="forge-sub" style="cursor:pointer;opacity:.7">Edit</summary>
                    <form method="post" style="margin-top:8px;display:flex;flex-direction:column;gap:6px;min-width:320px;">
                      <?php wp_nonce_field(FORGE_TICKETS_NONCE); ?>
                      <input type="hidden" name="u_id" value="<?php echo (int)$t['id']; ?>">
                      <input type="text" name="u_title" value="<?php echo esc_attr($t['title']); ?>" placeholder="Title">
                      <textarea name="u_comments" rows="2" placeholder="Comments"><?php echo esc_textarea($t['comments']); ?></textarea>

                      <?php if ($me['is_dev'] && !$show_archived): ?>
                        <label class="forge-sub">Status</label>
                        <select name="u_status">
                          <?php foreach (forge_tickets_statuses() as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($t['status'], $s); ?>><?php echo esc_html($s); ?></option>
                          <?php endforeach; ?>
                        </select>

                        <input type="text" name="u_target" value="<?php echo esc_attr($t['target_version']); ?>" placeholder="Target Version">

                        <label class="forge-sub">Assignee</label>
                        <select name="u_assignee">
                          <option value="">— Unassigned —</option>
                          <?php foreach ($all_users as $u) {
                              $sel = selected($t['assignee'], $u['login'], false);
                              echo '<option value="'.esc_attr($u['login']).'" '.$sel.'>'.esc_html($u['display']).'</option>';
                          } ?>
                        </select>

                        <label class="forge-nr"><input type="checkbox" name="u_archive" value="1"> Archive</label>
                      <?php elseif ($me['is_dev'] && $show_archived): ?>
                        <div class="forge-sub forge-dim">Archived ticket — fields locked.</div>
                      <?php endif; ?>

                      <button class="project-btn small" name="forge_ticket_update" value="1">Save</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>
</section>
<?php
    return ob_get_clean();
});

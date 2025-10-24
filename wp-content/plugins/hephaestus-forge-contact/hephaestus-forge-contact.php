<?php
/**
 * Plugin Name: Hephaestus Forge — Contact
 * Description: Forge-styled contact form via shortcode [forge_contact]. Stores private admin-only messages and emails the site admin. Inline CSS, nonce, honeypot, and small attachment support.
 * Version: 1.0.0
 * Author: Hephaestus Forge
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('HF_CONTACT_MAX_UPLOAD', 5 * 1024 * 1024); // 5 MB

/*-----------------------------------------------------------------------------
 * 1) Register private CPT for storing messages (admin UI only)
 *---------------------------------------------------------------------------*/
add_action('init', function () {
  register_post_type('forge_contact', array(
    'labels' => array(
      'name' => 'Contact Messages',
      'singular_name' => 'Contact Message',
      'menu_name' => 'Contact Messages',
    ),
    'public'       => false,
    'has_archive'  => false,
    'show_ui'      => true,
    'show_in_menu' => true,
    'supports'     => array('title','editor','custom-fields'),
    'capability_type' => 'post',
  ));
});

/*-----------------------------------------------------------------------------
 * 2) Shortcode: [forge_contact title="..." show_logo="1|0"]
 *---------------------------------------------------------------------------*/
add_shortcode('forge_contact', function ($atts = []) {
  $atts = shortcode_atts(array(
    'title'     => 'Contact Hephaestus Forge',
    'show_logo' => '1', // 1 = show, 0 = hide
  ), $atts, 'forge_contact');

  ob_start();

  // success banner (on ?sent=ok)
  $sent = isset($_GET['sent']) && sanitize_text_field($_GET['sent']) === 'ok';
  if ($sent) {
    ?>
    <div style="max-width:900px;margin:16px auto;padding:12px 16px;border-radius:12px;background:#0f271c;border:1px solid rgba(72,199,142,.35);color:#b6f3d6;font-weight:700">
      Thanks! Your message has been sent.
    </div>
    <?php
  }

  ?>
  <style>
    /* Top-left logo (optional) */
    .hf-logo-fixed{position:fixed;top:22px;left:24px;z-index:9999;width:216px;height:216px;border-radius:24px;display:flex;align-items:center;justify-content:center;background:radial-gradient(circle at 50% 50%, rgba(255,120,48,.12), transparent 70%)}
    .hf-logo-fixed img{width:192px;height:192px;object-fit:contain;filter:drop-shadow(0 0 14px rgba(255,120,48,.3));transition:filter .25s ease, transform .25s ease}
    .hf-logo-fixed:hover img{filter:drop-shadow(0 0 16px rgba(255,160,60,.55));transform:scale(1.02)}
    @media (max-width:800px){.hf-logo-fixed{width:140px;height:140px}.hf-logo-fixed img{width:124px;height:124px}}

    /* Forge box + form styling */
    .hf-forge-box{max-width:900px;margin:40px auto;padding:28px;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(0,0,0,.08));border:1px solid rgba(255,255,255,.08);border-radius:16px;box-shadow:0 0 24px rgba(255,77,59,.20), inset 0 0 120px rgba(0,0,0,.25)}
    .hf-h1{margin:0 0 14px}
    .hf-sub{color:#f5f2ec;line-height:1.7}
    .hf-label{display:block;margin:10px 0 6px;font-weight:700;color:#ffd7c2}
    .hf-input, .hf-select, .hf-textarea{width:100%;max-width:620px;background:#000;border:1px solid rgba(255,255,255,.15);color:#f5f2ec;border-radius:8px;padding:10px}
    .hf-textarea{height:160px;resize:vertical}
    .hf-btn{display:inline-block;padding:10px 20px;border-radius:999px;font-weight:700;color:#120909;background:linear-gradient(135deg,#ff4d3b,#ff8a3a);box-shadow:0 6px 24px rgba(255,77,59,.35);transition:transform .25s ease,filter .25s ease}
    .hf-btn:hover{transform:translateY(-2px);filter:brightness(1.05)}
    .hf-note{font-size:13px;opacity:.8;margin-top:6px}
    .hf-hp{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}
  </style>

  <?php if ($atts['show_logo'] === '1'): ?>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="hf-logo-fixed" aria-label="Hephaestus Forge — Home">
      <img src="https://web.hephaestus-forge.cc/wp-content/uploads/2025/10/Logo.png" alt="Forge">
    </a>
  <?php endif; ?>

  <section class="hf-forge-box">
    <h1 class="hf-h1"><?php echo esc_html($atts['title']); ?></h1>
    <p class="hf-sub">Questions, feedback, or support? Send us a message and we’ll get back to you.</p>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" enctype="multipart/form-data">
      <input type="hidden" name="action" value="hf_contact_submit">
      <?php wp_nonce_field('hf_contact_nonce', 'hf_contact_nonce_field'); ?>

      <!-- simple anti-spam honeypot -->
      <div class="hf-hp">
        <label>Do not fill this out <input type="text" name="website"></label>
      </div>

      <label class="hf-label">Your name</label>
      <input class="hf-input" type="text" name="name" required>

      <label class="hf-label">Your email</label>
      <input class="hf-input" type="email" name="email" required>

      <label class="hf-label">Topic</label>
      <select class="hf-select" name="topic" required>
        <option value="General">General</option>
        <option value="Licensing">Licensing</option>
        <option value="Bug Report">Bug Report</option>
        <option value="Other">Other</option>
      </select>

      <label class="hf-label">Message</label>
      <textarea class="hf-textarea" name="message" required></textarea>

      <label class="hf-label">Attachment (optional, PDF/PNG/JPG, max 5 MB)</label>
      <input class="hf-input" type="file" name="attach" accept=".pdf,.png,.jpg,.jpeg">

      <p class="hf-note">By sending this message you consent to us processing your details to reply to you. See our Privacy Policy for details.</p>

      <div style="margin-top:12px">
        <button class="hf-btn" type="submit">Send Message</button>
      </div>
    </form>
  </section>
  <?php
  return ob_get_clean();
});

/*-----------------------------------------------------------------------------
 * 3) Handle submissions (front + logged-in)
 *---------------------------------------------------------------------------*/
add_action('admin_post_nopriv_hf_contact_submit', 'hf_handle_contact_submit');
add_action('admin_post_hf_contact_submit',        'hf_handle_contact_submit');

function hf_handle_contact_submit() {
  // Honeypot
  if (!empty($_POST['website'])) {
    hf_contact_redirect_ok();
  }

  // Nonce check
  if (!isset($_POST['hf_contact_nonce_field']) || !wp_verify_nonce($_POST['hf_contact_nonce_field'], 'hf_contact_nonce')) {
    wp_die('Security check failed.');
  }

  $name    = sanitize_text_field($_POST['name'] ?? '');
  $email   = sanitize_email($_POST['email'] ?? '');
  $topic   = sanitize_text_field($_POST['topic'] ?? 'General');
  $message = sanitize_textarea_field($_POST['message'] ?? '');

  if (empty($name) || empty($email) || empty($message)) {
    wp_die('Missing required fields.');
  }

  // Optional: handle small attachment safely
  $attachment_id = 0;
  if (!empty($_FILES['attach']['name']) && !empty($_FILES['attach']['size'])) {
    if ((int) $_FILES['attach']['size'] > HF_CONTACT_MAX_UPLOAD) {
      wp_die('Attachment too large (max 5 MB).');
    }
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $allowed_mimes = array(
      'pdf'  => 'application/pdf',
      'png'  => 'image/png',
      'jpg'  => 'image/jpeg',
      'jpeg' => 'image/jpeg',
    );
    $uploaded = wp_handle_upload($_FILES['attach'], array('test_form' => false, 'mimes' => $allowed_mimes));
    if (isset($uploaded['error'])) {
      wp_die('Upload error: ' . esc_html($uploaded['error']));
    }
    if (isset($uploaded['file'])) {
      $file = $uploaded['file'];
      $filetype = wp_check_filetype(basename($file), null);
      $attachment = array(
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($_FILES['attach']['name']),
        'post_content'   => '',
        'post_status'    => 'inherit'
      );
      $attachment_id = wp_insert_attachment($attachment, $file);
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      $attach_data = wp_generate_attachment_metadata($attachment_id, $file);
      wp_update_attachment_metadata($attachment_id, $attach_data);
    }
  }

  // Store as private admin-only post
  $post_id = wp_insert_post(array(
    'post_title'   => 'Contact: ' . $topic . ' — ' . $name . ' — ' . current_time('mysql'),
    'post_content' => $message,
    'post_status'  => 'private',
    'post_type'    => 'forge_contact',
  ));
  if ($post_id) {
    add_post_meta($post_id, 'name', $name);
    add_post_meta($post_id, 'email', $email);
    add_post_meta($post_id, 'topic', $topic);
    if ($attachment_id) add_post_meta($post_id, 'attachment_id', $attachment_id);
  }

  // Notify admin
  $admin_email = get_option('admin_email');
  $subject = "[Forge] New contact: $topic — $name";
  $body = "Name: $name\nEmail: $email\nTopic: $topic\n\nMessage:\n$message\n\nDashboard: " . admin_url('edit.php?post_type=forge_contact');
  wp_mail($admin_email, $subject, $body);

  hf_contact_redirect_ok();
}

function hf_contact_redirect_ok(){
  $redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
  $glue = (strpos($redirect, '?') === false) ? '?' : '&';
  wp_safe_redirect($redirect . $glue . 'sent=ok');
  exit;
}

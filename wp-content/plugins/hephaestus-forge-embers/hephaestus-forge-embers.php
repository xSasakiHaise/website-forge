<?php
/**
 * Plugin Name: Hephaestus Forge Background
 * Description: Renders a warm smoke layer and a randomized ember field site-wide (default 500). Uses split nodes so embers rise (outer) and sway/flicker (inner). Includes [forge_bg] shortcode.
 * Version: 1.2.0
 * Author: Hephaestus Forge
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

final class Hephaestus_Forge_Background {
    /** Ensure we only auto-print once per request */
    private static $printed = false;

    public static function boot(): void {
        // Auto-inject near top of <body> (preferred for correct stacking)
        add_action('wp_body_open', [__CLASS__, 'print_once'], 5);

        // Fallback for themes not calling wp_body_open
        add_action('wp_footer', [__CLASS__, 'print_once'], 5);

        // Shortcode for manual placement or page-by-page usage
        add_shortcode('forge_bg', [__CLASS__, 'shortcode']);
    }

    /**
     * Shortcode handler: [forge_bg count="500"]
     * Returns markup instead of echoing, so it can be placed inside content.
     */
    public static function shortcode($atts = []): string {
        $atts = shortcode_atts([
            'count' => 500,
        ], $atts, 'forge_bg');

        $count = (int)$atts['count'];
        return self::render_markup($count);
    }

    /**
     * Auto-print once per request.
     * You can disable auto output via:
     *   add_filter('forge_bg_auto_output', '__return_false');
     */
    public static function print_once(): void {
        if (self::$printed) { return; }
        if (false === apply_filters('forge_bg_auto_output', true)) { return; }

        self::$printed = true;

        // Default ember count can be overridden:
        //   add_filter('forge_bg_ember_count', fn()=>800);
        $count = (int) apply_filters('forge_bg_ember_count', 500);

        echo self::render_markup($count);
    }

    /**
     * Build the smoke + ember HTML markup.
     * CSS/animations are handled by your theme/Additional CSS.
     */
    private static function render_markup(int $count): string {
        // Clamp count (safety guard)
        if ($count < 1)     { $count = 1; }
        if ($count > 2000)  { $count = 2000; }

        // Build ember nodes with randomized per-ember properties.
        // We split into OUTER (rise) and INNER (sway+flicker+size) so transforms don't override.
        $embers = [];
        for ($i = 0; $i < $count; $i++) {
            // Horizontal start 0–100%
            $x = mt_rand(0, 100);

            // Delay 0–20s
            $delay = mt_rand(0, 2000) / 100;

            // Rise speed 8–16s
            $speed = 8 + mt_rand(0, 800) / 100;

            // Sway amplitude 3–18 px
            $dx = 3 + mt_rand(0, 150) / 10;

            // Sway period 2.2–5.0s
            $sway = 2.2 + mt_rand(0, 280) / 100;

            // Flicker period 0.8–2.0s
            $flicker = 0.8 + mt_rand(0, 120) / 100;

            // Size 0.9–2.6 px
            $size = 0.9 + mt_rand(0, 170) / 100;

            // Slight opacity variety 0.7–1.0
            $opacity = (70 + mt_rand(0, 30)) / 100;

            // OUTER vars (rise + placement)
            $outer_style = sprintf(
                'left:%d%%; --ember-x:%d%%; --ember-delay:%.2fs; --ember-speed:%.2fs;',
                $x, $x, $delay, $speed
            );

            // INNER vars (sway/flicker/size/opacity)
            $inner_style = sprintf(
                '--ember-dx:%.1f; --ember-sway-speed:%.2fs; --ember-flicker-speed:%.2fs; --ember-size:%.2fpx; opacity:%.2f;',
                $dx, $sway, $flicker, $size, $opacity
            );

            // Each ember: OUTER rise container + INNER glowing particle
            $embers[] =
                '<div class="forge-ember" style="' . esc_attr($outer_style) . '">' .
                    '<div class="forge-ember-sway" style="' . esc_attr($inner_style) . '"></div>' .
                '</div>';
        }

        // Output smoke + ember field containers (ARIA-hidden since it's decorative)
        return "\n" .
            '<div class="forge-bg-smoke" aria-hidden="true"></div>' . "\n" .
            '<div class="forge-ember-field" aria-hidden="true">' . "\n" .
            implode("\n", $embers) . "\n" .
            '</div>' . "\n";
    }
}

Hephaestus_Forge_Background::boot();

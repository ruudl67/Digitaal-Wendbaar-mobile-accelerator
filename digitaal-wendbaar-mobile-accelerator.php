<?php
/**
 * Plugin Name:       Digitaal Wendbaar Mobile Accelerator
 * Plugin URI:        https://github.com/ruudl67/digitaal-wendbaar-mobile-accelerator
 * Description:       Gerichte mobiele snelheidsoptimalisaties voor Elementor-sites: critical CSS, LCP-preload, jQuery- en scriptoptimalisatie. Werkt naast SiteGround Speed Optimizer.
 * Version:           0.3.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Digitaal Wendbaar
 * Author URI:        https://ruudlicht.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dw-mobile-accelerator
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatisch updaten vanaf GitHub (branch main), net als de andere Digitaal Wendbaar-plugins.
 */
if (file_exists(__DIR__ . '/plugin-update-checker/plugin-update-checker.php')) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
    $dwma_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/ruudl67/Digitaal-Wendbaar-mobile-accelerator',
        __FILE__,
        'digitaal-wendbaar-mobile-accelerator'
    );
    $dwma_update_checker->setBranch('main');
}

final class DW_Mobile_Accelerator {

    const VERSION  = '0.3.1';
    const OPTION   = 'dwma_settings';
    const META     = '_dwma_page';
    const NONCE    = 'dwma_page_nonce';
    const MAX_CSS  = 60000; // bytes critical CSS per pagina

    /** @var array|null */
    private static $opt = null;
    /** @var array */
    private static $page = [];

    /* ------------------------------------------------------------------ *
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post', [__CLASS__, 'save_meta'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'action_links']);

        // Front-end: pas beslissen zodra de query bekend is.
        add_action('wp', [__CLASS__, 'boot_frontend']);
    }

    public static function defaults() {
        return [
            'enabled'          => 0,
            'scope'            => 'pages', // pages | site
            'preconnect'       => '',
            'font_preload'     => '',
            'remove_migrate'   => 0,
            'jquery_footer'    => 0,
            'disable_emoji'    => 0,
            'async_scripts'    => '',
            'async_css'        => '',
            'critical_exclude' => '',
        ];
    }

    public static function page_defaults() {
        return [
            'enabled'      => 0,
            'lcp_mobile'   => '',
            'lcp_desktop'  => '',
            'lcp_id'       => 0,
            'critical_css' => '',
        ];
    }

    public static function options() {
        if (self::$opt === null) {
            $saved     = get_option(self::OPTION, []);
            self::$opt = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
        }
        return self::$opt;
    }

    public static function page_settings($post_id) {
        $saved = get_post_meta((int) $post_id, self::META, true);
        return wp_parse_args(is_array($saved) ? $saved : [], self::page_defaults());
    }

    /* ------------------------------------------------------------------ *
     * Front-end
     * ------------------------------------------------------------------ */

    private static function is_excluded_request() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_preview() || is_customize_preview() || is_embed()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        // Elementor-editor en -preview nooit aanpassen.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['elementor-preview']) || isset($_GET['elementor_library']) || isset($_GET['preview_id'])) {
            return true;
        }
        // Handmatige bypass om voor/na te vergelijken: ?dwma=off
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['dwma']) && 'off' === $_GET['dwma']) {
            return true;
        }
        return false;
    }

    public static function boot_frontend() {
        $o = self::options();
        if (empty($o['enabled']) || self::is_excluded_request()) {
            return;
        }

        $page_on = false;
        if (is_singular()) {
            $ps = self::page_settings(get_queried_object_id());
            if (!empty($ps['enabled'])) {
                $page_on    = true;
                self::$page = $ps;
            }
        }

        $global_on = ('site' === $o['scope']) || $page_on;
        if (!$global_on) {
            return;
        }

        // Hints, preloads en critical CSS vóór de stylesheets (die op prio 8 komen).
        add_action('wp_head', [__CLASS__, 'print_head'], 2);
        add_action('wp_enqueue_scripts', [__CLASS__, 'tweak_scripts'], 9999);
        add_filter('style_loader_tag', [__CLASS__, 'style_tag'], 20, 4);
        add_filter('script_loader_tag', [__CLASS__, 'script_tag'], 20, 3);

        // Hardgecodeerde scripts (bijv. cookiebanner via header-code) ook async kunnen maken.
        if ('' !== trim((string) $o['async_scripts'])) {
            ob_start([__CLASS__, 'buffer_head_scripts']);
        }

        if ($page_on && !empty(self::$page['lcp_id'])) {
            add_filter('wp_get_attachment_image_attributes', [__CLASS__, 'image_attributes'], 20, 2);
        }

        if (!empty($o['disable_emoji'])) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
            add_filter('emoji_svg_url', '__return_false');
        }
    }

    public static function print_head() {
        $o   = self::options();
        $out = "<!-- DW Mobile Accelerator " . esc_html(self::VERSION) . " -->\n";

        // Preconnect naar externe domeinen (bijv. consent.cookiefirst.com).
        foreach (self::lines($o['preconnect']) as $origin) {
            $out .= sprintf("<link rel=\"preconnect\" href=\"%s\">\n", esc_url($origin));
        }

        // Fonts preloaden (alleen eigen domein, woff2).
        foreach (self::lines($o['font_preload']) as $font) {
            $out .= sprintf("<link rel=\"preload\" as=\"font\" type=\"font/woff2\" href=\"%s\" crossorigin>\n", esc_url($font));
        }

        // LCP-preload, apart voor mobiel en desktop.
        if (self::$page) {
            $out .= self::lcp_preload_markup();

            $css = self::$page['critical_css'];
            if ('' !== $css) {
                $out .= "<style id=\"dwma-critical-css\">" . self::clean_css($css) . "</style>\n";
            }
        }

        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- alles hierboven is ge-escaped.
    }

    private static function lcp_preload_markup() {
        $p   = self::$page;
        $bp  = self::mobile_breakpoint();
        $out = '';

        $m = $p['lcp_mobile'];
        $d = $p['lcp_desktop'];

        if ($m) {
            $out .= sprintf(
                "<link rel=\"preload\" as=\"image\" href=\"%s\" media=\"(max-width: %dpx)\" fetchpriority=\"high\">\n",
                esc_url($m),
                $bp
            );
        }
        if ($d) {
            $out .= sprintf(
                "<link rel=\"preload\" as=\"image\" href=\"%s\" media=\"(min-width: %dpx)\" fetchpriority=\"high\">\n",
                esc_url($d),
                $bp + 1
            );
        }

        // Geen URL's, wel een media-ID: preload met srcset zodat de browser dezelfde variant kiest als de <img>.
        if (!$m && !$d && !empty($p['lcp_id'])) {
            $id  = (int) $p['lcp_id'];
            $src = wp_get_attachment_image_url($id, 'full');
            if ($src) {
                $srcset = wp_get_attachment_image_srcset($id, 'full');
                $out   .= sprintf(
                    "<link rel=\"preload\" as=\"image\" href=\"%s\"%s fetchpriority=\"high\">\n",
                    esc_url($src),
                    $srcset ? sprintf(' imagesrcset="%s" imagesizes="100vw"', esc_attr($srcset)) : ''
                );
            }
        }
        return $out;
    }

    public static function tweak_scripts() {
        $o  = self::options();
        $ws = wp_scripts();

        if (!empty($o['remove_migrate']) && isset($ws->registered['jquery'])) {
            $ws->registered['jquery']->deps = array_values(array_diff((array) $ws->registered['jquery']->deps, ['jquery-migrate']));
        }

        if (!empty($o['jquery_footer'])) {
            // WordPress verplaatst jQuery automatisch terug naar de head als een head-script ervan afhangt.
            foreach (['jquery', 'jquery-core', 'jquery-migrate'] as $h) {
                if (isset($ws->registered[$h])) {
                    $ws->add_data($h, 'group', 1);
                }
            }
        }
    }

    public static function style_tag($html, $handle, $href, $media) {
        if (!$href) {
            return $html;
        }
        // Print-stylesheets ongemoeid laten; andere media (bijv. Elementor v4 breakpoint-bestanden) blijven hun media-query houden.
        $media = trim((string) $media);
        if (false !== stripos($media, 'print')) {
            return $html;
        }

        $o            = self::options();
        $has_critical = self::$page && '' !== self::$page['critical_css'];

        if ($has_critical) {
            // Met critical CSS: alles asynchroon, behalve uitgesloten handles.
            $exclude = array_merge(['admin-bar', 'dashicons'], self::handles($o['critical_exclude']));
            if (in_array($handle, $exclude, true)) {
                return $html;
            }
        } elseif (!in_array($handle, self::handles($o['async_css']), true)) {
            return $html;
        }

        $id  = esc_attr($handle . '-css');
        $url = esc_url($href);
        $mq  = ('' === $media || 'all' === $media) ? '' : ' media="' . esc_attr($media) . '"';

        return sprintf(
            "<link rel=\"preload\" as=\"style\" id=\"%1\$s\" href=\"%2\$s\"%3\$s onload=\"this.onload=null;this.rel='stylesheet'\">\n<noscript><link rel=\"stylesheet\" href=\"%2\$s\"%3\$s></noscript>\n",
            $id,
            $url,
            $mq
        );
    }

    public static function script_tag($tag, $handle, $src) {
        if (!$src) {
            return $tag;
        }
        $patterns = self::lines(self::options()['async_scripts']);
        if (!$patterns) {
            return $tag;
        }
        foreach ($patterns as $needle) {
            if ('' !== $needle && false !== stripos($src, $needle)) {
                // Alleen de <script src=...> tag aanpassen, niet de inline before/after scripts.
                return preg_replace_callback(
                    '#<script\b([^>]*\bsrc=[^>]*)>#i',
                    static function ($m) {
                        if (preg_match('#\s(async|defer)\b#i', $m[1])) {
                            return $m[0];
                        }
                        return '<script async' . $m[1] . '>';
                    },
                    $tag,
                    1
                );
            }
        }
        return $tag;
    }

    /**
     * Output-buffer: voegt async toe aan <script src> in de <head> die matchen met "Scripts async laden",
     * ook als ze niet via wp_enqueue_script zijn toegevoegd (header-code, Elementor Custom Code, thema).
     */
    public static function buffer_head_scripts($html) {
        if (!is_string($html) || '' === $html) {
            return $html;
        }
        $end = stripos($html, '</head>');
        if (false === $end || false === stripos(substr($html, 0, 2000), '<html')) {
            return $html;
        }
        $patterns = self::lines(self::options()['async_scripts']);
        if (!$patterns) {
            return $html;
        }

        $head = substr($html, 0, $end);
        $head = preg_replace_callback(
            '#<script\b([^>]*)>#i',
            static function ($m) use ($patterns) {
                $attrs = $m[1];
                if (!preg_match('#\bsrc\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $attrs, $src)) {
                    return $m[0];
                }
                if (preg_match('#\s(async|defer)\b#i', ' ' . $attrs) || preg_match('#\btype\s*=\s*["\']?module#i', $attrs)) {
                    return $m[0];
                }
                foreach ($patterns as $needle) {
                    if ('' !== $needle && false !== stripos($src[2], $needle)) {
                        return '<script async' . $attrs . '>';
                    }
                }
                return $m[0];
            },
            $head
        );

        return $head . substr($html, $end);
    }

    public static function image_attributes($attr, $attachment) {
        if ($attachment && (int) $attachment->ID === (int) self::$page['lcp_id']) {
            $attr['loading']       = 'eager';
            $attr['fetchpriority'] = 'high';
        }
        return $attr;
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private static function mobile_breakpoint() {
        $bp = 767;
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->breakpoints)) {
            try {
                $b = \Elementor\Plugin::$instance->breakpoints->get_breakpoints('mobile');
                if ($b && method_exists($b, 'get_value')) {
                    $bp = (int) $b->get_value();
                }
            } catch (\Throwable $e) {
                $bp = 767;
            }
        }
        return $bp > 0 ? $bp : 767;
    }

    /** Eén item per regel of komma-gescheiden. */
    private static function lines($value) {
        $parts = preg_split('/[\r\n,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('trim', (array) $parts))));
    }

    private static function handles($value) {
        return array_values(array_filter(array_map([__CLASS__, 'sanitize_handle'], self::lines($value))));
    }

    private static function sanitize_handle($h) {
        return preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $h);
    }

    private static function clean_css($css) {
        $css = (string) $css;
        $css = preg_replace('#</?\s*style[^>]*>#i', '', $css);
        $css = wp_strip_all_tags($css);
        return trim($css);
    }

    private static function host($url) {
        $h = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $h);
    }

    private static function same_host_url($url) {
        $url = esc_url_raw(trim((string) $url));
        if (!$url) {
            return '';
        }
        return self::host($url) === self::host(home_url()) ? $url : false;
    }

    /* ------------------------------------------------------------------ *
     * Admin: globale instellingen
     * ------------------------------------------------------------------ */

    public static function register() {
        register_setting('dwma_group', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    public static function sanitize($raw) {
        $raw = is_array($raw) ? $raw : [];

        $origins = [];
        foreach (self::lines($raw['preconnect'] ?? '') as $line) {
            $u = esc_url_raw($line);
            $p = wp_parse_url($u);
            if (!empty($p['scheme']) && 'https' === $p['scheme'] && !empty($p['host'])) {
                $origins[] = 'https://' . $p['host'];
            }
        }

        $fonts = [];
        foreach (self::lines($raw['font_preload'] ?? '') as $line) {
            $u = self::same_host_url($line);
            if ($u === false) {
                add_settings_error(self::OPTION, 'dwma_font', 'Font-preload overgeslagen (ander domein): ' . esc_html($line));
                continue;
            }
            if ($u) {
                $fonts[] = $u;
            }
        }

        $async_scripts = array_map('sanitize_text_field', self::lines($raw['async_scripts'] ?? ''));

        return [
            'enabled'          => empty($raw['enabled']) ? 0 : 1,
            'scope'            => (isset($raw['scope']) && 'site' === $raw['scope']) ? 'site' : 'pages',
            'preconnect'       => implode("\n", array_slice(array_unique($origins), 0, 6)),
            'font_preload'     => implode("\n", array_slice(array_unique($fonts), 0, 4)),
            'remove_migrate'   => empty($raw['remove_migrate']) ? 0 : 1,
            'jquery_footer'    => empty($raw['jquery_footer']) ? 0 : 1,
            'disable_emoji'    => empty($raw['disable_emoji']) ? 0 : 1,
            'async_scripts'    => implode("\n", array_slice($async_scripts, 0, 10)),
            'async_css'        => implode("\n", array_slice(self::handles($raw['async_css'] ?? ''), 0, 30)),
            'critical_exclude' => implode("\n", array_slice(self::handles($raw['critical_exclude'] ?? ''), 0, 30)),
        ];
    }

    public static function menu() {
        add_options_page(
            'DW Mobile Accelerator',
            'DW Mobile Accelerator',
            'manage_options',
            'dw-mobile-accelerator',
            [__CLASS__, 'settings_page']
        );
    }

    public static function action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=dw-mobile-accelerator')) . '">Instellingen</a>');
        return $links;
    }

    private static function field_name($key) {
        return esc_attr(self::OPTION . '[' . $key . ']');
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $o = self::options();

        $pages = get_posts([
            'post_type'      => 'any',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 50,
            'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'fields'         => 'ids',
        ]);
        $active_pages = array_filter($pages, static function ($id) {
            $s = DW_Mobile_Accelerator::page_settings($id);
            return !empty($s['enabled']);
        });

        $sg_combine = (int) get_option('siteground_optimizer_combine_css', 0);
        ?>
        <div class="wrap">
            <h1>Digitaal Wendbaar Mobile Accelerator <small style="font-weight:400">v<?php echo esc_html(self::VERSION); ?></small></h1>

            <?php if ($sg_combine) : ?>
                <div class="notice notice-warning"><p><strong>SiteGround "Combine CSS" staat aan.</strong> Asynchroon laden per handle en critical CSS werken dan niet goed, omdat de losse stylesheets worden samengevoegd. Zet Combine CSS uit (Minify mag aan blijven), of sluit de pagina's met critical CSS uit van combineren in SiteGround Speed Optimizer.</p></div>
            <?php endif; ?>

            <p>Pagina-specifieke instellingen (LCP-afbeelding en critical CSS) vind je in het blok <strong>DW Mobile Accelerator</strong> onder de editor van elke pagina. Test altijd met <code>?dwma=off</code> achter de URL voor een vergelijking zonder plugin.</p>

            <form action="options.php" method="post">
                <?php settings_fields('dwma_group'); ?>
                <h2>Algemeen</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Plugin inschakelen</th>
                        <td><label><input type="checkbox" name="<?php echo self::field_name('enabled'); ?>" value="1" <?php checked($o['enabled'], 1); ?>> Optimalisaties actief op de front-end</label></td></tr>
                    <tr><th scope="row">Waar gelden de algemene opties?</th>
                        <td>
                            <label><input type="radio" name="<?php echo self::field_name('scope'); ?>" value="pages" <?php checked($o['scope'], 'pages'); ?>> Alleen op pagina's waar de plugin is aangezet (aanbevolen om mee te starten)</label><br>
                            <label><input type="radio" name="<?php echo self::field_name('scope'); ?>" value="site" <?php checked($o['scope'], 'site'); ?>> Op de hele site</label>
                            <p class="description">Critical CSS en LCP-preload gelden altijd alleen op de pagina waar ze zijn ingevuld.</p>
                        </td></tr>
                </table>

                <h2>Scripts</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">jQuery Migrate verwijderen</th>
                        <td><label><input type="checkbox" name="<?php echo self::field_name('remove_migrate'); ?>" value="1" <?php checked($o['remove_migrate'], 1); ?>> Niet meer laden (bespaart een render-blokkerend script)</label>
                        <p class="description">Elementor heeft Migrate niet nodig. Oudere plugins soms wel: controleer de console op fouten.</p></td></tr>
                    <tr><th scope="row">jQuery naar de footer</th>
                        <td><label><input type="checkbox" name="<?php echo self::field_name('jquery_footer'); ?>" value="1" <?php checked($o['jquery_footer'], 1); ?>> jQuery pas onderaan de pagina laden</label>
                        <p class="description">Grootste winst bij render-blokkering. Breekt alleen als er losse inline <code>jQuery(...)</code>-code in de head staat (bijv. via een header-codeplugin). Test menu, formulieren en sliders.</p></td></tr>
                    <tr><th scope="row">Scripts async laden</th>
                        <td><textarea class="large-text code" rows="3" name="<?php echo self::field_name('async_scripts'); ?>"><?php echo esc_textarea($o['async_scripts']); ?></textarea>
                        <p class="description">Eén deel van de script-URL per regel, bijv. <code>consent.cookiefirst.com</code>. Werkt ook voor scripts die hard in de header staan (bijv. via Elementor Custom Code). <strong>Let op bij cookiebanners:</strong> async laden kan automatisch blokkeren van cookies verzwakken. Controleer dit in de CookieFirst-instellingen.</p></td></tr>
                    <tr><th scope="row">Emoji-script uitschakelen</th>
                        <td><label><input type="checkbox" name="<?php echo self::field_name('disable_emoji'); ?>" value="1" <?php checked($o['disable_emoji'], 1); ?>> WordPress emoji-detectie niet laden</label></td></tr>
                </table>

                <h2>Verbindingen en fonts</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Preconnect</th>
                        <td><textarea class="large-text code" rows="3" name="<?php echo self::field_name('preconnect'); ?>" placeholder="https://consent.cookiefirst.com"><?php echo esc_textarea($o['preconnect']); ?></textarea>
                        <p class="description">Externe domeinen die vroeg op de pagina nodig zijn. Maximaal 6, alleen https.</p></td></tr>
                    <tr><th scope="row">Fonts preloaden</th>
                        <td><textarea class="large-text code" rows="3" name="<?php echo self::field_name('font_preload'); ?>" placeholder="<?php echo esc_attr(home_url('/wp-content/uploads/elementor/google-fonts/fonts/....woff2')); ?>"><?php echo esc_textarea($o['font_preload']); ?></textarea>
                        <p class="description">Volledige URL van het <code>.woff2</code>-bestand van het font dat boven de vouw staat (maximaal 4, eigen domein). Te vinden in PageSpeed onder "Netwerkafhankelijkheidsstructuur".</p></td></tr>
                </table>

                <h2>CSS</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Uitsluiten bij critical CSS</th>
                        <td><textarea class="large-text code" rows="3" name="<?php echo self::field_name('critical_exclude'); ?>"><?php echo esc_textarea($o['critical_exclude']); ?></textarea>
                        <p class="description">Op pagina's mét critical CSS laadt alle CSS asynchroon. Stylesheet-handles die hier staan blijven normaal laden (bijv. <code>elementor-frontend</code> als er nog verspringing is).</p></td></tr>
                    <tr><th scope="row">Handmatig async (zonder critical CSS)</th>
                        <td><textarea class="large-text code" rows="3" name="<?php echo self::field_name('async_css'); ?>"><?php echo esc_textarea($o['async_css']); ?></textarea>
                        <p class="description">Alleen voor pagina's zonder critical CSS: CSS die zeker niet boven de vouw nodig is, bijv. <code>widget-testimonial-carousel</code>. Eén handle per regel.</p></td></tr>
                </table>

                <?php submit_button('Instellingen opslaan'); ?>
            </form>

            <h2>Pagina's met plugin aan</h2>
            <?php if ($active_pages) : ?>
                <ul style="list-style:disc;padding-left:20px">
                    <?php foreach ($active_pages as $pid) :
                        $s = self::page_settings($pid); ?>
                        <li><a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html(get_the_title($pid) ?: '#' . $pid); ?></a>
                            — <?php echo '' !== $s['critical_css'] ? 'critical CSS ✓' : 'geen critical CSS'; ?>,
                            <?php echo ($s['lcp_mobile'] || $s['lcp_desktop'] || $s['lcp_id']) ? 'LCP-preload ✓' : 'geen LCP-preload'; ?>
                            · <a href="<?php echo esc_url(add_query_arg('dwma', 'off', get_permalink($pid))); ?>" target="_blank" rel="noopener">bekijk zonder plugin</a></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p>Nog geen pagina's. Open een pagina in WordPress en zet de plugin aan in het blok <em>DW Mobile Accelerator</em>.</p>
            <?php endif; ?>

            <p><strong>Na elke wijziging:</strong> SiteGround-cache legen en PageSpeed mobiel 3× meten.</p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     * Admin: per pagina
     * ------------------------------------------------------------------ */

    public static function add_meta_box() {
        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);
        foreach ($types as $type) {
            add_meta_box('dwma_page', 'DW Mobile Accelerator', [__CLASS__, 'render_meta_box'], $type, 'normal', 'low');
        }
    }

    public static function render_meta_box($post) {
        $s = self::page_settings($post->ID);
        wp_nonce_field('dwma_save_' . $post->ID, self::NONCE);
        $bp = self::mobile_breakpoint();
        ?>
        <p><label><input type="checkbox" name="dwma[enabled]" value="1" <?php checked($s['enabled'], 1); ?>> <strong>Optimalisaties aan op deze pagina</strong></label></p>

        <p><label for="dwma_lcp_mobile"><strong>LCP-afbeelding mobiel</strong> (≤ <?php echo (int) $bp; ?>px)</label><br>
            <input class="widefat" type="url" id="dwma_lcp_mobile" name="dwma[lcp_mobile]" value="<?php echo esc_attr($s['lcp_mobile']); ?>" placeholder="<?php echo esc_attr(home_url('/wp-content/uploads/...')); ?>"></p>

        <p><label for="dwma_lcp_desktop"><strong>LCP-afbeelding desktop</strong> (≥ <?php echo (int) $bp + 1; ?>px)</label><br>
            <input class="widefat" type="url" id="dwma_lcp_desktop" name="dwma[lcp_desktop]" value="<?php echo esc_attr($s['lcp_desktop']); ?>"></p>
        <p class="description">Gebruik exact de URL die PageSpeed bij "LCP-verzoekdetectie" toont, ook bij een Elementor-achtergrondafbeelding. Klopt de URL niet, dan downloadt de browser twee afbeeldingen.</p>

        <p><label for="dwma_lcp_id"><strong>LCP media-ID</strong> (alleen bij een Afbeelding-widget)</label><br>
            <input type="number" min="0" id="dwma_lcp_id" name="dwma[lcp_id]" value="<?php echo esc_attr($s['lcp_id']); ?>" style="width:120px">
            <span class="description">Zet loading=eager en fetchpriority=high op die afbeelding.</span></p>

        <p><label for="dwma_critical_css"><strong>Critical CSS</strong></label><br>
            <textarea class="widefat code" rows="8" id="dwma_critical_css" name="dwma[critical_css]" spellcheck="false"><?php echo esc_textarea($s['critical_css']); ?></textarea></p>
        <p class="description">De CSS die nodig is voor het eerste scherm (boven de vouw) op mobiel. Zodra dit is ingevuld, laadt alle andere CSS op deze pagina asynchroon. Werk de critical CSS opnieuw bij als je de bovenkant van de pagina in Elementor aanpast. Max. <?php echo (int) (self::MAX_CSS / 1000); ?> KB.</p>
        <?php
    }

    public static function save_meta($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), 'dwma_save_' . $post_id)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw = isset($_POST['dwma']) && is_array($_POST['dwma']) ? wp_unslash($_POST['dwma']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- per veld hieronder.

        $m = self::same_host_url($raw['lcp_mobile'] ?? '');
        $d = self::same_host_url($raw['lcp_desktop'] ?? '');

        $css = self::clean_css($raw['critical_css'] ?? '');
        if (strlen($css) > self::MAX_CSS) {
            $css = substr($css, 0, self::MAX_CSS);
        }

        $data = [
            'enabled'      => empty($raw['enabled']) ? 0 : 1,
            'lcp_mobile'   => $m ?: '',
            'lcp_desktop'  => $d ?: '',
            'lcp_id'       => absint($raw['lcp_id'] ?? 0),
            'critical_css' => $css,
        ];

        if ($data === self::page_defaults()) {
            delete_post_meta($post_id, self::META);
        } else {
            // wp_slash: update_post_meta haalt backslashes weg, die nodig zijn in CSS (bijv. content:"\f101").
            update_post_meta($post_id, self::META, wp_slash($data));
        }
    }
}

DW_Mobile_Accelerator::init();

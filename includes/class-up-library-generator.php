<?php
namespace UPLG;

if (!defined('ABSPATH')) { exit; }

class UpLibraryGenerator {
    private static $instance;

    private $option_key = 'uplg_settings';
    private $opts = [];
    private $config_map = []; // [cpt => config array]

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function register_generated_cpts(): void {
        $defs = get_posts([
            'post_type' => 'uplg-cpt',
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);
        foreach ($defs as $def) {
            $slug = sanitize_title(get_post_meta($def->ID, '_uplg_cpt_slug', true));
            if (!$slug) { continue; }
            $reg  = 'up_' . $slug;
            $sing = get_post_meta($def->ID, '_uplg_cpt_singular', true) ?: ucfirst($slug);
            $plur = get_post_meta($def->ID, '_uplg_cpt_plural', true) ?: $sing . 's';
            register_post_type($reg, [
                'label' => $plur,
                'labels' => [ 'name' => $plur, 'singular_name' => $sing ],
                'public' => false,
                'publicly_queryable' => false,
                'show_ui' => true,
                'show_in_menu' => 'uplg-main',
                'show_in_rest' => true,
                'rest_base' => $reg,
                'supports' => ['title','editor','custom-fields'],
                'has_archive' => false,
                'rewrite' => false,
            ]);
        }
    }

    public function render_cpt_generator_metabox($post): void {
        wp_nonce_field('uplg_save_cpt', 'uplg_cpt_nonce');
        $slug = get_post_meta($post->ID, '_uplg_cpt_slug', true);
        $sing = get_post_meta($post->ID, '_uplg_cpt_singular', true);
        $plur = get_post_meta($post->ID, '_uplg_cpt_plural', true);
        echo '<p><label><strong>' . esc_html__('Slug (sans préfixe)', 'up-library-generator') . '</strong><br />';
        echo '<input type="text" name="_uplg_cpt_slug" value="' . esc_attr($slug) . '" class="regular-text" placeholder="ex: produit" /></label></p>';
        echo '<p><label><strong>' . esc_html__('Label singulier', 'up-library-generator') . '</strong><br />';
        echo '<input type="text" name="_uplg_cpt_singular" value="' . esc_attr($sing) . '" class="regular-text" placeholder="ex: Produit" /></label></p>';
        echo '<p><label><strong>' . esc_html__('Label pluriel', 'up-library-generator') . '</strong><br />';
        echo '<input type="text" name="_uplg_cpt_plural" value="' . esc_attr($plur) . '" class="regular-text" placeholder="ex: Produits" /></label></p>';
        echo '<p class="description">' . esc_html__('Le CPT sera enregistré sous le slug : up_<slug>. Accès REST activé, pas de pages publiques, visible dans le sous-menu UP Tools.', 'up-library-generator') . '</p>';
    }

    public function register_rest_meta(): void {
        // expose plugin metas to REST for configured CPTs
        foreach ($this->config_map as $cpt => $conf) {
            // schema-defined metas
            if (!empty($conf['meta_schema']) && is_array($conf['meta_schema'])) {
                foreach ($conf['meta_schema'] as $row) {
                    $key = (string)($row['key'] ?? '');
                    $type = (string)($row['type'] ?? 'texte');
                    if (!$key) continue;
                    $meta_key = '_uplg_meta_' . $key;
                    register_post_meta($cpt, $meta_key, [
                        'single' => true,
                        'show_in_rest' => true,
                        'type' => $type === 'checkbox' ? 'boolean' : 'string',
                        'auth_callback' => function() { return current_user_can('edit_posts'); },
                    ]);
                    if ($type === 'code' && !empty($row['with_flag'])) {
                        register_post_meta($cpt, '_uplg_generate_meta_' . $key, [
                            'single' => true,
                            'show_in_rest' => true,
                            'type' => 'boolean',
                            'auth_callback' => function() { return current_user_can('edit_posts'); },
                        ]);
                    }
                    if ($type === 'code' && !empty($row['allow_custom_path'])) {
                        register_post_meta($cpt, '_uplg_meta_' . $key . '_base', [
                            'single' => true,
                            'show_in_rest' => true,
                            'type' => 'string',
                            'auth_callback' => function() { return current_user_can('edit_posts'); },
                        ]);
                        register_post_meta($cpt, '_uplg_meta_' . $key . '_subpath', [
                            'single' => true,
                            'show_in_rest' => true,
                            'type' => 'string',
                            'auth_callback' => function() { return current_user_can('edit_posts'); },
                        ]);
                    }
                }
            }
        }

        // Show notice after importing defaults via admin-post redirect
        if (isset($_GET['import'])) {
            if (sanitize_text_field($_GET['import']) === '1') {
                $notice = '<div class="notice notice-success"><p>' . esc_html__('Import du fichier par défaut effectué.', 'up-library-generator') . '</p></div>';
            } else {
                $notice = '<div class="notice notice-error"><p>' . esc_html__('Échec de l\'import du fichier par défaut.', 'up-library-generator') . '</p></div>';
            }
        }
    }

    private function __construct() {
        $this->opts = $this->get_options();
        register_activation_hook(UPLG_PATH . 'up-library-generator.php', [$this, 'on_activate']);

        // Register CPT and load configurations
        add_action('init', [$this, 'register_cpt_and_load_configs']);
        add_action('rest_api_init', [$this, 'register_rest_meta']);

        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'handle_save_post'], 10, 2);

        // Add a tools panel inside the tablenav for up_* CPTs (we'll move it before bulk actions)
        add_action('restrict_manage_posts', [$this, 'render_up_list_tools_inline'], 5);

        // Register bulk actions for export on all CPT list screens
        add_action('admin_init', [$this, 'register_bulk_export_actions']);

        // Early redirect for per-CPT settings pseudo-pages under edit.php (run very early too)
        add_action('admin_init', [$this, 'maybe_redirect_cpt_settings'], 1);
        add_action('load-edit.php', [$this, 'maybe_redirect_cpt_settings']);

        // Admin-post endpoints for actions triggered from Import/Export UI and inline gear
        add_action('admin_post_uplg_export_all', [$this, 'handle_export_all']);
        add_action('admin_post_uplg_import_defaults', [$this, 'handle_import_defaults']);
        add_action('admin_post_uplg_open_cpt_settings', [$this, 'handle_open_cpt_settings']);
    }

    public function on_activate(): void {
        if (!get_option($this->option_key)) {
            update_option($this->option_key, $this->default_options());
        }
    }

    /**
     * Register the configuration CPT and build the map of configured CPTs.
     */
    public function register_cpt_and_load_configs(): void {
                // CPT generator definitions
        register_post_type('uplg-cpt', [
            'label' => __('CPT manager', 'up-library-generator'),
            'labels' => [
                'name' => __('CPT manager', 'up-library-generator'),
                'singular_name' => __('CPT manager', 'up-library-generator'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'uplg-main',
            'menu_position' => 26,
            'menu_icon' => 'dashicons-hammer',
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);
        // Main config CPT
        register_post_type('library-generator', [
            'label' => __('CPT config', 'up-library-generator'),
            'labels' => [
                'name' => __('CPT configs', 'up-library-generator'),
                'singular_name' => __('CPT config', 'up-library-generator'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'uplg-main',
            'menu_position' => 25,
            'menu_icon' => 'dashicons-admin-generic',
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);



        // Build configurations map
        $this->config_map = [];
        $configs = get_posts([
            'post_type' => 'library-generator',
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);
        foreach ($configs as $conf_post) {
            $conf = $this->get_config_from_post($conf_post->ID);
            $target = $conf['target_cpt'] ?? '';
            if ($target) {
                $this->config_map[$target] = $conf;
            }
        }

        // Register generated CPTs based on 'uplg-cpt' posts
        $this->register_generated_cpts();
    }

    private function get_config_from_post(int $post_id): array {
        $fields = get_post_meta($post_id, '_uplg_conf_fields', true);
        $fields = is_array($fields) ? $fields : [];
        $meta_schema = get_post_meta($post_id, '_uplg_conf_meta_schema', true);
        $meta_schema = is_array($meta_schema) ? $meta_schema : [];
        $defaults = $this->default_options();

        // Retrieve metas with care to preserve string '0' values
        $target_cpt = get_post_meta($post_id, '_uplg_conf_target_cpt', true);
        $base_location = get_post_meta($post_id, '_uplg_conf_base_location', true);
        $relative_subdir = get_post_meta($post_id, '_uplg_conf_relative_subdir', true);
        $wrap_subfolder = get_post_meta($post_id, '_uplg_conf_wrap_subfolder', true);
        $custom_directory = get_post_meta($post_id, '_uplg_conf_custom_directory', true);

        return [
            'target_cpt'       => ($target_cpt !== '') ? $target_cpt : '',
            'fields'           => wp_parse_args($fields, $defaults['fields']),
            'base_location'    => ($base_location !== '') ? $base_location : $defaults['base_location'],
            'relative_subdir'  => ($relative_subdir !== '') ? $relative_subdir : $defaults['relative_subdir'],
            // Important: keep '0' when saved, only fallback if truly absent
            'wrap_subfolder'   => ($wrap_subfolder !== '') ? $wrap_subfolder : $defaults['wrap_subfolder'],
            'custom_directory' => ($custom_directory !== '') ? $custom_directory : $defaults['custom_directory'],
            'meta_schema'      => $meta_schema,
        ];
    }

    private function default_options(): array {
        return [
            'target_cpt'       => '',
            'fields'           => [
                'php'  => ['enabled' => true,  'with_flag' => true],
                'js'   => ['enabled' => true,  'with_flag' => true],
                'scss' => ['enabled' => true,  'with_flag' => true],
                'css'  => ['enabled' => false, 'with_flag' => false],
            ],
            'base_location'    => 'theme', // theme | mu-plugins | plugin | custom
            'relative_subdir'  => 'library',
            'wrap_subfolder'   => '1',
            'custom_directory' => trailingslashit(get_stylesheet_directory()) . 'library/',
        ];
    }

    /**
     * Sanitize a relative subpath to avoid directory traversal and absolute paths.
     */
    private function sanitize_subpath(string $p): string {
        $p = wp_normalize_path($p);
        // Remove any ../ or ./ segments
        $parts = array_filter(explode('/', str_replace(['..', './', '.\\'], '', $p)));
        $p = implode('/', $parts);
        // Trim leading slashes and backslashes
        $p = ltrim($p, "/\\ ");
        return trim($p);
    }

    private function get_options(): array {
        $opts = get_option($this->option_key, []);
        return wp_parse_args($opts, $this->default_options());
    }

    private function update_options(array $opts): void {
        $merged = wp_parse_args($opts, $this->default_options());
        update_option($this->option_key, $merged);
        $this->opts = $this->get_options();
    }

    public function register_admin_pages(): void {
        // Top-level menu for the plugin
        add_menu_page(
            __('Library', 'up-library-generator'),
            __('Library', 'up-library-generator'),
            'manage_options',
            'uplg-main',
            function () {
                echo '<div class="wrap"><h1>Librairie</h1><p>' . esc_html__('Utilisez les sous-menus pour configurer le plugin.', 'up-library-generator') . '</p></div>';
            },
            'dashicons-admin-generic',
            25
        );

        // Per-CPT settings submenu: appears under each CPT menu and opens its library-generator config post
        $all = get_post_types(['show_ui' => true], 'objects');
        foreach ($all as $pt => $obj) {
            if (in_array($pt, ['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','library-generator','uplg-cpt'], true)) { continue; }
            $parent = 'edit.php?post_type=' . $pt;
            $slug = 'uplg-cpt-settings-' . $pt;
            $pto = get_post_type_object($pt);
            $cap_required = $pto && !empty($pto->cap) && !empty($pto->cap->edit_posts) ? $pto->cap->edit_posts : 'edit_posts';
            add_submenu_page(
                $parent,
                sprintf(__('Réglages Library Generator — %s', 'up-library-generator'), $obj->labels->singular_name),
                __('Réglages Library Generator', 'up-library-generator'),
                $cap_required,
                $slug,
                [$this, 'render_empty_settings_placeholder']
            );
        }

        // Add Import/Export submenu for every CPT visible in admin (even non-public), including generated up_*
        $all = get_post_types(['show_ui' => true], 'names');
        $exclude = ['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache'];
        foreach ($all as $cpt) {
            if (in_array($cpt, $exclude, true)) { continue; }
            add_submenu_page(
                'edit.php?post_type=' . $cpt,
                __('Import/Export', 'up-library-generator'),
                __('Import/Export', 'up-library-generator'),
                'manage_options',
                'uplg-import-export',
                [$this, 'render_import_export_page']
            );
        }
    }

    public function admin_assets($hook): void {
        // CodeMirror uniquement sur l'édition du CPT cible
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $enabled_cpts = array_keys($this->config_map);
        if (empty($enabled_cpts) && !empty($this->opts['target_cpt'])) { $enabled_cpts = [$this->opts['target_cpt']]; }
        $is_post_edit = in_array($hook, ['post.php', 'post-new.php'], true);
        $is_list_screen = ($hook === 'edit.php') || ($screen && $screen->base === 'edit');
        $is_target_cpt = $screen && in_array($screen->post_type, $enabled_cpts, true);
        $is_conf_cpt = $screen && $screen->post_type === 'library-generator';

        // Always enqueue base admin CSS on CPT list screens (edit.php) where inline tools are rendered
        if ($screen && $is_list_screen) {
            wp_enqueue_style('uplg-admin', UPLG_URL . 'assets/admin.css', [], UPLG_VERSION);
        }

        if ($screen && $is_post_edit && ($is_target_cpt || $is_conf_cpt)) {
            // Enqueue admin UI on edit screens
            if (!wp_style_is('uplg-admin', 'enqueued')) {
                wp_enqueue_style('uplg-admin', UPLG_URL . 'assets/admin.css', [], UPLG_VERSION);
            }
            // Enqueue code editor only for target CPT edit pages
            $deps = ['jquery'];
            if ($is_target_cpt) {
                $php_settings  = wp_enqueue_code_editor(['type' => 'text/x-php', 'codemirror' => ['theme' => 'monokai']]);
                $scss_settings = wp_enqueue_code_editor(['type' => 'text/scss', 'codemirror' => ['theme' => 'monokai']]);
                $js_settings   = wp_enqueue_code_editor(['type' => 'javascript', 'codemirror' => ['theme' => 'monokai']]);
                $css_settings  = wp_enqueue_code_editor(['type' => 'text/css', 'codemirror' => ['theme' => 'monokai']]);

                wp_enqueue_script('code-editor');
                wp_enqueue_style('code-editor');
                wp_register_script('uplg-admin', UPLG_URL . 'assets/admin.js', array_merge($deps, ['code-editor']), UPLG_VERSION, true);
                wp_localize_script('uplg-admin', 'uplgCodeMirrorSettings', [
                    'php'  => $php_settings,
                    'scss' => $scss_settings,
                    'js'   => $js_settings,
                    'css'  => $css_settings,
                ]);
                wp_enqueue_script('uplg-admin');
            } else {
                // Config screens don't need code editor deps
                wp_enqueue_script('uplg-admin', UPLG_URL . 'assets/admin.js', $deps, UPLG_VERSION, true);
            }
        }
    }

    // Deprecated: global settings page removed in favor of per-CPT configuration.

    public function register_metabox(): void {
        // Metabox on configured CPTs
        $targets = array_keys($this->config_map);
        if (empty($targets) && !empty($this->opts['target_cpt'])) { $targets = [$this->opts['target_cpt']]; }
        foreach ($targets as $cpt) {
            add_meta_box('uplg_code_fields', __('Code et génération', 'up-library-generator'), [$this, 'render_metabox'], $cpt, 'normal', 'high');
        }

        // Metabox for configuration posts themselves
        add_meta_box('uplg_conf_box', __('Configuration', 'up-library-generator'), [$this, 'render_config_metabox'], 'library-generator', 'normal', 'high');

        // Metabox for CPT generator definitions
        add_meta_box('uplg_cpt_box', __('Définition du CPT', 'up-library-generator'), [$this, 'render_cpt_generator_metabox'], 'uplg-cpt', 'normal', 'high');
    }

    public function render_metabox($post): void {
        $conf = $this->get_conf_for_cpt($post->post_type);
        $fields = $conf['fields'];
        wp_nonce_field('uplg_save_meta', 'uplg_meta_nonce');

        $file_name = get_post_meta($post->ID, '_uplg_file_name', true);
        echo '<p><label>' . esc_html__('Nom de fichier (base, sans extension)', 'up-library-generator') . '<br/>';
        echo '<input type="text" name="_uplg_file_name" value="' . esc_attr($file_name) . '" style="width:100%" placeholder="ex: block-hero"></label></p>';

        // Layout: 2-column grid
        echo '<div class="uplg-grid-2cols">';
        // Render schema-defined metas first (if any)
        if (!empty($conf['meta_schema'])) {
            foreach ($conf['meta_schema'] as $row) {
                $label = (string)($row['label'] ?? '');
                $key   = (string)($row['key'] ?? '');
                $type  = (string)($row['type'] ?? 'texte');
                $col   = (string)($row['col'] ?? '1');
                $withf = !empty($row['with_flag']);
                if (!$key) continue;
                $meta_key = '_uplg_meta_' . $key;
                echo '<div class="uplg-grid-col uplg-col-' . esc_attr($col) . '">';
                echo '<p><label><strong>' . esc_html($label) . '</strong></label>';
                if ($type === 'texte') {
                    $val = get_post_meta($post->ID, $meta_key, true);
                    echo '<input type="text" name="' . esc_attr($meta_key) . '" value="' . esc_attr($val) . '" class="regular-text" />';
                } elseif ($type === 'checkbox') {
                    $val = get_post_meta($post->ID, $meta_key, true) === '1' ? '1' : '0';
                    echo '<label><input type="checkbox" name="' . esc_attr($meta_key) . '" ' . checked($val, '1', false) . ' /> ' . esc_html__('Activer', 'up-library-generator') . '</label>';
                } else { // code
                    $val = get_post_meta($post->ID, $meta_key, true);
                    $lang = isset($row['lang']) ? (string)$row['lang'] : 'php';
                    $cm_mode = in_array($lang, ['php','js','scss','css'], true) ? $lang : 'php';
                    echo '<textarea name="' . esc_attr($meta_key) . '" rows="10" style="width:100%" data-codemirror="' . esc_attr($cm_mode) . '">' . esc_textarea($val) . '</textarea>';
                    if ($withf) {
                        $flag_key = '_uplg_generate_meta_' . $key;
                        $flag_val = get_post_meta($post->ID, $flag_key, true) ?: '0';
                        echo '<br><label><input type="checkbox" name="' . esc_attr($flag_key) . '" ' . checked($flag_val, '1', false) . ' /> ' . esc_html__('Générer le fichier', 'up-library-generator') . '</label>';
                    }
                    // Optional custom path override
                    if (!empty($row['allow_custom_path'])) {
                        $base_key = '_uplg_meta_' . $key . '_base';
                        $subp_key = '_uplg_meta_' . $key . '_subpath';
                        $base_val = get_post_meta($post->ID, $base_key, true) ?: '';
                        $subp_val = get_post_meta($post->ID, $subp_key, true) ?: '';
                        echo '<div style="margin-top:8px;">';
                        echo '<label>' . esc_html__('Emplacement', 'up-library-generator') . ' ';
                        echo '<select name="' . esc_attr($base_key) . '">';
                        $bases = ['' => __('— par défaut —', 'up-library-generator'), 'theme' => __('Thème actif', 'up-library-generator'), 'mu-plugins' => __('MU-plugins', 'up-library-generator'), 'plugin' => __('Ce plugin', 'up-library-generator'), 'custom' => __('Chemin personnalisé', 'up-library-generator')];
                        foreach ($bases as $bv => $bl) {
                            echo '<option value="' . esc_attr($bv) . '" ' . selected($base_val, $bv, false) . '>' . esc_html($bl) . '</option>';
                        }
                        echo '</select>';
                        echo '</label> ';
                        echo '<label>' . esc_html__('Sous-chemin/chemin', 'up-library-generator') . ' <input type="text" name="' . esc_attr($subp_key) . '" value="' . esc_attr($subp_val) . '" placeholder="library/my-block" size="30" /></label>';
                        echo '</div>';
                    }
                }
                echo '</p></div>';
            }
        }
        // Legacy fixed code fields (optional): keep after schema-based
        // Legacy fixed code fields removed: use dynamic schema instead.
        echo '</div>'; // grid
    }

    public function render_config_metabox($post): void {
        wp_nonce_field('uplg_save_conf', 'uplg_conf_nonce');
        $post_types = get_post_types(['show_ui' => true], 'objects');
        $conf = $this->get_config_from_post($post->ID);
        $fields = $conf['fields'];
        echo '<p><label>' . esc_html__('CPT cible', 'up-library-generator') . '<br />';
        echo '<select name="_uplg_conf_target_cpt"><option value="">—</option>';
        foreach ($post_types as $pt => $obj) {
            if (in_array($pt, ['library-generator','uplg-cpt','attachment','revision','nav_menu_item'], true)) { continue; }
            echo '<option value="' . esc_attr($pt) . '" ' . selected($conf['target_cpt'], $pt, false) . '>' . esc_html($obj->labels->singular_name . ' (' . $pt . ')') . '</option>';
        }
        echo '</select></label></p>';

        // Static code fields are deprecated; configuration is fully handled via dynamic meta schema.

        echo '<p><label>' . esc_html__('Emplacement', 'up-library-generator') . '<br />';
        $bases = ['theme' => __('Thème actif', 'up-library-generator'), 'mu-plugins' => __('MU-plugins', 'up-library-generator'), 'plugin' => __('Ce plugin', 'up-library-generator'), 'custom' => __('Chemin personnalisé', 'up-library-generator')];
        foreach ($bases as $val => $lab) {
            echo '<label style="margin-right:12px;"><input type="radio" name="_uplg_conf_base_location" value="' . esc_attr($val) . '" ' . checked($conf['base_location'], $val, false) . '> ' . esc_html($lab) . '</label>';
        }
        echo '</label></p>';

        echo '<p><label>' . esc_html__('Sous-dossier relatif', 'up-library-generator') . ' <input type="text" name="_uplg_conf_relative_subdir" value="' . esc_attr($conf['relative_subdir']) . '" placeholder="library"></label><br/>';
        echo '<span class="description">' . esc_html__('Laissez vide ou entrez "/" pour ne pas utiliser de sous-dossier.', 'up-library-generator') . '</span></p>';
        echo '<p><label><input type="checkbox" name="_uplg_conf_wrap_subfolder" ' . checked($conf['wrap_subfolder'], '1', false) . '> ' . esc_html__('Créer un sous-dossier par élément (recommandé)', 'up-library-generator') . '</label></p>';
        echo '<p class="description">' . esc_html__('Emplacements par défaut des fichiers générés :', 'up-library-generator') . '<br/>'
            . esc_html__('• PHP et CSS : à la racine du dossier de l’élément', 'up-library-generator') . '<br/>'
            . esc_html__('• JS : sous assets/js', 'up-library-generator') . '<br/>'
            . esc_html__('• SCSS : sous assets/scss', 'up-library-generator')
            . '</p>';
        echo '<p><label>' . esc_html__('Chemin personnalisé (si sélectionné)', 'up-library-generator') . ' <input type="text" name="_uplg_conf_custom_directory" value="' . esc_attr($conf['custom_directory']) . '" size="70"></label></p>';

        // Meta schema repeater
        $schema = is_array($conf['meta_schema']) ? $conf['meta_schema'] : [];
        echo '<hr />';
        echo '<h3>' . esc_html__('Schéma des metas (par CPT)', 'up-library-generator') . '</h3>';
        echo '<p class="description">' . esc_html__('Ajoutez des metas: label, slug, type (code / texte / case à cocher), colonne (1 ou 2). Pour le type "code", vous pouvez activer une case "Générer le fichier".', 'up-library-generator') . '</p>';
        echo '<table class="widefat fixed uplg-schema-table"><thead><tr>';
        echo '<th>' . esc_html__('Label', 'up-library-generator') . '</th>';
        echo '<th>' . esc_html__('Slug', 'up-library-generator') . '</th>';
        echo '<th>' . esc_html__('Type', 'up-library-generator') . '</th>';
        echo '<th>' . esc_html__('Colonne', 'up-library-generator') . '</th>';
        echo '<th>' . esc_html__('Lang (code)', 'up-library-generator') . '</th>';
        echo '<th>' . esc_html__('Avec bouton Générer (code)', 'up-library-generator') . '</th>';
        echo '<th>' . esc_html__('Autoriser chemin perso (code)', 'up-library-generator') . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody id="uplg-schema-rows">';
        $i = 0;
        foreach ($schema as $row) {
            $label = isset($row['label']) ? (string)$row['label'] : '';
            $key   = isset($row['key']) ? (string)$row['key'] : '';
            $type  = isset($row['type']) ? (string)$row['type'] : 'texte';
            $col   = isset($row['col']) ? (string)$row['col'] : '1';
            $flag  = !empty($row['with_flag']) ? '1' : '0';
            $allow_custom = !empty($row['allow_custom_path']) ? '1' : '0';
            $lang = isset($row['lang']) ? (string)$row['lang'] : 'php';
            echo '<tr class="uplg-schema-row">';
            echo '<td><input type="text" name="_uplg_conf_schema_label[]" value="' . esc_attr($label) . '" /></td>';
            echo '<td><input type="text" name="_uplg_conf_schema_key[]" value="' . esc_attr($key) . '" /></td>';
            echo '<td><select name="_uplg_conf_schema_type[]">';
            foreach ([
                'texte' => __('Texte', 'up-library-generator'),
                'code' => __('Code', 'up-library-generator'),
                'checkbox' => __('Case à cocher', 'up-library-generator'),
            ] as $val => $lab) {
                echo '<option value="' . esc_attr($val) . '" ' . selected($type, $val, false) . '>' . esc_html($lab) . '</option>';
            }
            echo '</select></td>';
            echo '<td><select name="_uplg_conf_schema_col[]">';
            foreach (['1','2'] as $c) {
                echo '<option value="' . esc_attr($c) . '" ' . selected($col, $c, false) . '>' . esc_html($c) . '</option>';
            }
            echo '</select></td>';
            echo '<td><select name="_uplg_conf_schema_lang[]">';
            foreach ([
                'php' => 'PHP',
                'js' => 'JS',
                'scss' => 'SCSS',
                'css' => 'CSS',
            ] as $lv => $ll) {
                echo '<option value="' . esc_attr($lv) . '" ' . selected($lang, $lv, false) . '>' . esc_html($ll) . '</option>';
            }
            echo '</select></td>';
            echo '<td style="text-align:center"><input type="checkbox" name="_uplg_conf_schema_with_flag[' . esc_attr($i) . ']" ' . checked($flag, '1', false) . ' /></td>';
            echo '<td style="text-align:center"><input type="checkbox" name="_uplg_conf_schema_allow_custom[' . esc_attr($i) . ']" ' . checked($allow_custom, '1', false) . ' /></td>';
            echo '<td><button class="button uplg-schema-remove">' . esc_html__('Supprimer', 'up-library-generator') . '</button></td>';
            echo '</tr>';
            $i++;
        }
        // Empty row template
        echo '<tr class="uplg-schema-row uplg-schema-template" style="display:none">';
        echo '<td><input type="text" name="_uplg_conf_schema_label[]" value="" /></td>';
        echo '<td><input type="text" name="_uplg_conf_schema_key[]" value="" /></td>';
        echo '<td><select name="_uplg_conf_schema_type[]">';
        foreach ([
            'texte' => __('Texte', 'up-library-generator'),
            'code' => __('Code', 'up-library-generator'),
            'checkbox' => __('Case à cocher', 'up-library-generator'),
        ] as $val => $lab) {
            echo '<option value="' . esc_attr($val) . '">' . esc_html($lab) . '</option>';
        }
        echo '</select></td>';
        echo '<td><select name="_uplg_conf_schema_col[]"><option value="1">1</option><option value="2">2</option></select></td>';
        echo '<td><select name="_uplg_conf_schema_lang[]"><option value="php">PHP</option><option value="js">JS</option><option value="scss">SCSS</option><option value="css">CSS</option></select></td>';
        echo '<td style="text-align:center"><input type="checkbox" name="_uplg_conf_schema_with_flag[__i__]" /></td>';
        echo '<td style="text-align:center"><input type="checkbox" name="_uplg_conf_schema_allow_custom[__i__]" /></td>';
        echo '<td><button class="button uplg-schema-remove">' . esc_html__('Supprimer', 'up-library-generator') . '</button></td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<p><button type="button" class="button button-secondary" id="uplg-schema-add">' . esc_html__('Ajouter une meta', 'up-library-generator') . '</button></p>';
    }

    public function handle_save_post(int $post_id, $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // If saving a configuration post, handle it first with its own nonce
        if ($post->post_type === 'library-generator') {
            if (!isset($_POST['uplg_conf_nonce']) || !wp_verify_nonce($_POST['uplg_conf_nonce'], 'uplg_save_conf')) return;
            update_post_meta($post_id, '_uplg_conf_target_cpt', sanitize_text_field($_POST['_uplg_conf_target_cpt'] ?? ''));
            update_post_meta($post_id, '_uplg_conf_base_location', sanitize_text_field($_POST['_uplg_conf_base_location'] ?? 'theme'));
            update_post_meta($post_id, '_uplg_conf_relative_subdir', trim(sanitize_text_field($_POST['_uplg_conf_relative_subdir'] ?? 'library')));
            update_post_meta($post_id, '_uplg_conf_wrap_subfolder', isset($_POST['_uplg_conf_wrap_subfolder']) ? '1' : '0');
            update_post_meta($post_id, '_uplg_conf_custom_directory', wp_normalize_path(trim((string)($_POST['_uplg_conf_custom_directory'] ?? ''))));

            // Save meta schema
            $labels = $_POST['_uplg_conf_schema_label'] ?? [];
            $keys   = $_POST['_uplg_conf_schema_key'] ?? [];
            $types  = $_POST['_uplg_conf_schema_type'] ?? [];
            $cols   = $_POST['_uplg_conf_schema_col'] ?? [];
            $withs  = $_POST['_uplg_conf_schema_with_flag'] ?? [];
            $allowc = $_POST['_uplg_conf_schema_allow_custom'] ?? [];
            $langs  = $_POST['_uplg_conf_schema_lang'] ?? [];
            $schema = [];
            $count  = max(count($labels), count($keys), count($types), count($cols));
            for ($i = 0; $i < $count; $i++) {
                $label = sanitize_text_field($labels[$i] ?? '');
                $key   = sanitize_key($keys[$i] ?? '');
                $type  = in_array(($types[$i] ?? 'texte'), ['texte','code','checkbox'], true) ? ($types[$i] ?? 'texte') : 'texte';
                $col   = in_array(($cols[$i] ?? '1'), ['1','2'], true) ? ($cols[$i] ?? '1') : '1';
                if (!$label || !$key) { continue; }
                $schema[] = [
                    'label' => $label,
                    'key' => $key,
                    'type' => $type,
                    'col'  => $col,
                    'with_flag' => isset($withs[$i]) ? true : false,
                    'allow_custom_path' => isset($allowc[$i]) ? true : false,
                    'lang' => in_array(($langs[$i] ?? 'php'), ['php','js','scss','css'], true) ? ($langs[$i] ?? 'php') : 'php',
                ];
            }
            update_post_meta($post_id, '_uplg_conf_meta_schema', $schema);
            // Rebuild config map for this request
            $this->register_cpt_and_load_configs();
            return;
        }

        // Save CPT generator definition
        if ($post->post_type === 'uplg-cpt') {
            if (!isset($_POST['uplg_cpt_nonce']) || !wp_verify_nonce($_POST['uplg_cpt_nonce'], 'uplg_save_cpt')) return;
            $slug = sanitize_title($_POST['_uplg_cpt_slug'] ?? '');
            $sing = sanitize_text_field($_POST['_uplg_cpt_singular'] ?? '');
            $plur = sanitize_text_field($_POST['_uplg_cpt_plural'] ?? '');
            update_post_meta($post_id, '_uplg_cpt_slug', $slug);
            update_post_meta($post_id, '_uplg_cpt_singular', $sing);
            update_post_meta($post_id, '_uplg_cpt_plural', $plur);
            // Re-register generated CPTs for this request
            $this->register_generated_cpts();
            return;
        }

        if (!isset($_POST['uplg_meta_nonce']) || !wp_verify_nonce($_POST['uplg_meta_nonce'], 'uplg_save_meta')) return;

        // Regular post in a configured CPT
        $cpt = $post->post_type;
        $conf = $this->get_conf_for_cpt($cpt);
        if (!$conf) {
            error_log('[UPLG DEBUG] handle_save_post: no conf for CPT ' . $cpt . ' (post ' . $post_id . ')');
            return;
        }
        error_log('[UPLG DEBUG] handle_save_post: saving metas for CPT ' . $cpt . ' (post ' . $post_id . ')');

        $file_name = sanitize_title((string)($_POST['_uplg_file_name'] ?? ''));
        if (!$file_name) { $file_name = sanitize_title($post->post_name ?: ('item-' . $post_id)); }
        update_post_meta($post_id, '_uplg_file_name', $file_name);

        // Save schema-based metas
        if (!empty($conf['meta_schema'])) {
            foreach ($conf['meta_schema'] as $row) {
                $key = (string)($row['key'] ?? '');
                $type = (string)($row['type'] ?? 'texte');
                if (!$key) continue;
                $meta_key = '_uplg_meta_' . $key;
                if ($type === 'checkbox') {
                    update_post_meta($post_id, $meta_key, isset($_POST[$meta_key]) ? '1' : '0');
                } else {
                    $raw_present = array_key_exists($meta_key, $_POST) ? 'yes' : 'no';
                    $val = (string) wp_unslash($_POST[$meta_key] ?? '');
                    if ($type === 'code') {
                        $len = strlen($val);
                        error_log('[UPLG DEBUG] code meta ' . $meta_key . ' present_in_post=' . $raw_present . ' length=' . $len);
                    }
                    update_post_meta($post_id, $meta_key, $val);
                    if ($type === 'code') {
                        $saved = (string) get_post_meta($post_id, $meta_key, true);
                        error_log('[UPLG DEBUG] code meta saved ' . $meta_key . ' length=' . strlen($saved));
                    }
                }
                if ($type === 'code') {
                    if (!empty($row['with_flag'])) {
                        $flag_key = '_uplg_generate_meta_' . $key;
                        update_post_meta($post_id, $flag_key, isset($_POST[$flag_key]) ? '1' : '0');
                    }
                    if (!empty($row['allow_custom_path'])) {
                        $base_key = '_uplg_meta_' . $key . '_base';
                        $subp_key = '_uplg_meta_' . $key . '_subpath';
                        $base_val = sanitize_text_field($_POST[$base_key] ?? '');
                        $subp_val = $this->sanitize_subpath((string)($_POST[$subp_key] ?? ''));
                        update_post_meta($post_id, $base_key, $base_val);
                        update_post_meta($post_id, $subp_key, $subp_val);
                    }
                }
            }
        }

        $this->generate_files($post_id, $conf);
    }

    private function resolve_base_dir(array $opts): string {
        $base = $opts['base_location'];
        $sub  = trim($opts['relative_subdir'], "/\\ ");
        $dir = '';
        if ($base === 'theme') {
            $dir = trailingslashit(get_stylesheet_directory());
        } elseif ($base === 'mu-plugins') {
            $wp_content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? rtrim(ABSPATH, '/\\') . '/wp-content' : '');
            $dir = defined('WPMU_PLUGIN_DIR') ? trailingslashit(WPMU_PLUGIN_DIR) : trailingslashit($wp_content_dir) . 'mu-plugins/';
        } elseif ($base === 'plugin') {
            $dir = trailingslashit(UPLG_PATH);
        } else { // custom
            $custom = $opts['custom_directory'];
            if ($custom) return trailingslashit($custom);
            $dir = trailingslashit(get_stylesheet_directory());
        }
        return $sub ? trailingslashit($dir . $sub) : $dir;
    }

    private function ensure_dir(string $path): void {
        if (!is_dir($path)) {
            wp_mkdir_p($path);
        }
    }

    private function compile_scss(string $scss): string {
        if (class_exists('ScssPhp\\ScssPhp\\Compiler')) {
            try {
                $compiler = new \ScssPhp\ScssPhp\Compiler();
                return (string) $compiler->compileString($scss)->getCss();
            } catch (\Throwable $e) {
                return $scss; // fallback: retour scss brut
            }
        }
        return $scss; // fallback si librairie absente
    }

    private function generate_files(int $post_id, array $opts): void {
        $base_dir = wp_normalize_path($this->resolve_base_dir($opts));
        $file_name = get_post_meta($post_id, '_uplg_file_name', true) ?: ('item-' . $post_id);
        $wrap = $opts['wrap_subfolder'] === '1';

        $root_dir = $wrap ? trailingslashit($base_dir . $file_name) : trailingslashit($base_dir);
        if (!$base_dir) { error_log('[UPLG] Base dir vide pour post ' . $post_id . ' — vérifier les réglages.'); }
        $this->ensure_dir($root_dir);

        // Schema-based code metas → generate individual PHP files
        $conf_post = $this->get_conf_for_cpt(get_post_type($post_id));
        if (!empty($conf_post['meta_schema'])) {
            foreach ($conf_post['meta_schema'] as $row) {
                $type = (string)($row['type'] ?? '');
                if ($type !== 'code') { continue; }
                $key  = (string)($row['key'] ?? '');
                if (!$key) { continue; }
                $flag_required = !empty($row['with_flag']);
                $gen_flag = get_post_meta($post_id, '_uplg_generate_meta_' . $key, true) === '1';
                $should_gen = $flag_required ? $gen_flag : true;
                if (!$should_gen) continue;
                $code_meta = '_uplg_meta_' . $key;
                $code = (string) get_post_meta($post_id, $code_meta, true);
                $lang = isset($row['lang']) ? (string)$row['lang'] : 'php';
                $ext_map = ['php' => '.php', 'js' => '.js', 'scss' => '.scss', 'css' => '.css'];
                $ext = $ext_map[$lang] ?? '.php';
                if ($lang === 'php' && $code && strpos($code, '<?php') !== 0) { $code = "<?php\n" . $code; }
                // Resolve override base + subpath if provided
                $override_base = (string) get_post_meta($post_id, '_uplg_meta_' . $key . '_base', true);
                $override_subp = $this->sanitize_subpath((string) get_post_meta($post_id, '_uplg_meta_' . $key . '_subpath', true));
                $meta_root_dir = $root_dir;
                if ($override_base !== '') {
                    $base_dir_sel = '';
                    if ($override_base === 'theme') {
                        $base_dir_sel = trailingslashit(get_stylesheet_directory());
                    } elseif ($override_base === 'mu-plugins') {
                        $wp_content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? rtrim(ABSPATH, '/\\') . '/wp-content' : '');
                        $base_dir_sel = defined('WPMU_PLUGIN_DIR') ? trailingslashit(WPMU_PLUGIN_DIR) : trailingslashit($wp_content_dir) . 'mu-plugins/';
                    } elseif ($override_base === 'plugin') {
                        $base_dir_sel = trailingslashit(UPLG_PATH);
                    } elseif ($override_base === 'custom') {
                        $wp_content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? rtrim(ABSPATH, '/\\') . '/wp-content' : '');
                        $base_dir_sel = trailingslashit($wp_content_dir);
                    }
                    $subp = $override_subp;
                    $meta_base_dir = $base_dir_sel ? trailingslashit($base_dir_sel . $subp) : ($subp ? trailingslashit($subp) : $root_dir);
                    $meta_root_dir = $opts['wrap_subfolder'] === '1' ? trailingslashit($meta_base_dir . $file_name) : trailingslashit($meta_base_dir);
                    $this->ensure_dir($meta_root_dir);
                }
                // Destination rules:
                // - php, css at root
                // - js -> assets/js
                // - scss -> assets/scss
                $dest_dir = $meta_root_dir;
                if ($lang === 'js') {
                    $dest_dir = trailingslashit($meta_root_dir . 'assets/js');
                    $this->ensure_dir($dest_dir);
                } elseif ($lang === 'scss') {
                    $dest_dir = trailingslashit($meta_root_dir . 'assets/scss');
                    $this->ensure_dir($dest_dir);
                }
                // File naming: do not suffix with meta slug; use only base file name + extension
                $target = $dest_dir . $file_name . $ext;
                $ok = @file_put_contents($target, $code);
                if ($ok === false) { error_log('[UPLG] Echec écriture META PHP: ' . $target); }
            }
        }
    }

    public function render_import_export_page(): void {
        if (!current_user_can('manage_options')) return;
        $notice = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['uplg_ie_action']) && $_POST['uplg_ie_action'] === 'import_upload') {
                check_admin_referer('uplg_ie_action', 'uplg_ie_nonce');
                if (!empty($_FILES['uplg_ie_file']['tmp_name']) && is_uploaded_file($_FILES['uplg_ie_file']['tmp_name'])) {
                    $xml = file_get_contents($_FILES['uplg_ie_file']['tmp_name']);
                    $result = $this->import_from_xml($xml ?: '', sanitize_text_field($_GET['post_type'] ?? ($this->opts['target_cpt'] ?? '')));
                    if (is_wp_error($result)) {
                        $notice = '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                    } else {
                        $notice = '<div class="notice notice-success"><p>' . esc_html__('Import effectué.', 'up-library-generator') . '</p></div>';
                    }
                } else {
                    $notice = '<div class="notice notice-error"><p>' . esc_html__('Aucun fichier sélectionné.', 'up-library-generator') . '</p></div>';
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('UP Library Generator — Import/Export', 'up-library-generator') . '</h1>';
        echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $current_cpt = sanitize_text_field($_GET['post_type'] ?? ($this->opts['target_cpt'] ?? ''));
        $export_url = add_query_arg([
            'action'    => 'uplg_export_all',
            '_wpnonce'  => wp_create_nonce('uplg_export_all'),
            'post_type' => $current_cpt,
        ], admin_url('admin-post.php'));
        echo '<h2>' . esc_html__('Exporter', 'up-library-generator') . '</h2>';
        echo '<p><a class="button button-primary" href="' . esc_url($export_url) . '">' . esc_html__('Exporter (XML)', 'up-library-generator') . '</a></p>';

        echo '<hr />';
        echo '<h2>' . esc_html__('Importer', 'up-library-generator') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('uplg_ie_action', 'uplg_ie_nonce');
        echo '<input type="hidden" name="uplg_ie_action" value="import_upload" />';
        echo '<p><input type="file" name="uplg_ie_file" accept=".xml" /></p>';
        echo '<p><button class="button">' . esc_html__('Importer depuis un fichier XML', 'up-library-generator') . '</button></p>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        echo '<input type="hidden" name="action" value="uplg_import_defaults" />';
        echo '<input type="hidden" name="post_type" value="' . esc_attr($current_cpt) . '" />';
        echo wp_nonce_field('uplg_import_defaults', '_wpnonce', true, false);
        echo '<p><button class="button button-secondary">' . esc_html__('Importer le fichier par défaut (plugin)', 'up-library-generator') . '</button></p>';
        echo '</form>';

        echo '</div>';
    }

    public function handle_export_all(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permissions insuffisantes.', 'up-library-generator'));
        check_admin_referer('uplg_export_all');
        $cpt = sanitize_text_field($_GET['post_type'] ?? ($this->opts['target_cpt'] ?? ''));
        $xml = $this->export_to_xml($cpt);
        $filename = 'uplg-export-' . ($cpt ?: 'items') . '-' . gmdate('Ymd-His') . '.xml';
        $this->send_download($filename, $xml, 'application/xml; charset=utf-8');
    }

    public function handle_import_defaults(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permissions insuffisantes.', 'up-library-generator'));
        check_admin_referer('uplg_import_defaults');
        // Resolve CPT from POST, GET or referer as fallback
        $cpt = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
        if (!$cpt && isset($_GET['post_type'])) { $cpt = sanitize_text_field($_GET['post_type']); }
        if (!$cpt && !empty($_SERVER['HTTP_REFERER'])) {
            $ref = wp_parse_url((string)$_SERVER['HTTP_REFERER']);
            if (!empty($ref['query'])) {
                parse_str($ref['query'], $q);
                if (!empty($q['post_type'])) { $cpt = sanitize_text_field($q['post_type']); }
            }
        }
        if (!$cpt) { $cpt = (string)($this->opts['target_cpt'] ?? ''); }
        $result = $this->import_defaults_from_plugin($cpt);
        $url = add_query_arg([
            'post_type' => $cpt,
            'page'      => 'uplg-import-export',
            'import'    => is_wp_error($result) ? '0' : '1',
        ], admin_url('edit.php'));
        wp_redirect($url);
        exit;
    }

    public function handle_open_cpt_settings(): void {
        $pt = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';
        if (!$pt) { wp_die(esc_html__('Paramètre post_type manquant.', 'up-library-generator')); }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'uplg_open_cpt_settings')) {
            wp_die(esc_html__('Nonce invalide.', 'up-library-generator'));
        }
        $this->open_cpt_settings_page($pt); // will redirect & exit
        exit;
    }

    /**
     * Open or create the per-CPT configuration post (library-generator) and redirect to its edit screen.
     */
    private function open_cpt_settings_page(string $cpt): void {
        // Allow users who have CPT-specific edit capability OR generic editor/admin caps
        $pto = get_post_type_object($cpt);
        $cap_cpt = ($pto && !empty($pto->cap) && !empty($pto->cap->edit_posts)) ? $pto->cap->edit_posts : '';
        $has_cap = false;
        if ($cap_cpt && current_user_can($cap_cpt)) { $has_cap = true; }
        if (current_user_can('manage_options') || current_user_can('edit_posts') || current_user_can('edit_pages')) { $has_cap = true; }
        if (!$has_cap) { wp_die(esc_html__('Permissions insuffisantes.', 'up-library-generator')); }
        $cpt = sanitize_text_field($cpt);
        // Try to find an existing config post targeting this CPT
        $conf = get_posts([
            'post_type' => 'library-generator',
            'post_status' => 'any',
            'meta_key' => '_uplg_conf_target_cpt',
            'meta_value' => $cpt,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        $post_id = $conf ? (int)$conf[0] : 0;
        if (!$post_id) {
            // Create a new one with sane defaults
            $title = sprintf('LG — %s', $cpt);
            $post_id = wp_insert_post([
                'post_type' => 'library-generator',
                'post_status' => 'draft',
                'post_title' => $title,
            ], true);
            if (!is_wp_error($post_id) && $post_id) {
                update_post_meta($post_id, '_uplg_conf_target_cpt', $cpt);
                update_post_meta($post_id, '_uplg_conf_fields', $this->default_options()['fields']);
                update_post_meta($post_id, '_uplg_conf_base_location', $this->default_options()['base_location']);
                update_post_meta($post_id, '_uplg_conf_relative_subdir', $this->default_options()['relative_subdir']);
                update_post_meta($post_id, '_uplg_conf_wrap_subfolder', $this->default_options()['wrap_subfolder']);
                update_post_meta($post_id, '_uplg_conf_custom_directory', $this->default_options()['custom_directory']);
                update_post_meta($post_id, '_uplg_conf_meta_schema', []);
            }
        }
        if (is_wp_error($post_id) || !$post_id) { wp_die(esc_html__('Impossible d’ouvrir les réglages.', 'up-library-generator')); }
        // Redirect to edit screen of that config post (ensure no buffered output)
        if (function_exists('ob_get_level')) { while (ob_get_level()) { @ob_end_clean(); } }
        wp_redirect(add_query_arg(['post' => $post_id, 'action' => 'edit'], admin_url('post.php')));
        exit;
    }

    /**
     * Early-redirect handler for per-CPT settings pseudo-pages before any output happens.
     */
    public function maybe_redirect_cpt_settings(): void {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page && strpos($page, 'uplg-cpt-settings-') === 0) {
            $pt = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';
            if ($pt) {
                $this->open_cpt_settings_page($pt);
            }
        }
    }

    /** No-op renderer for submenu; actual logic handled in maybe_redirect_cpt_settings() */
    public function render_empty_settings_placeholder(): void {
        // Intentionally left blank to avoid output before redirect
    }

    /**
     * Render a small tools inline panel for up_* CPTs and move it before bulk actions.
     */
    public function render_up_list_tools_inline(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') return;
        // If we are on our per-CPT settings pseudo-page under edit.php, avoid output to allow redirects
        if (!empty($_GET['page']) && strpos(sanitize_text_field($_GET['page']), 'uplg-cpt-settings-') === 0) { return; }
        $cpt = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'post';
        // Show tools for all CPT list screens; minimal capability check for visibility
        if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) return;

        $export_url = add_query_arg([
            'action'    => 'uplg_export_all',
            '_wpnonce'  => wp_create_nonce('uplg_export_all'),
            'post_type' => $cpt,
        ], admin_url('admin-post.php'));

        $settings_url = add_query_arg([
            'action'    => 'uplg_open_cpt_settings',
            '_wpnonce'  => wp_create_nonce('uplg_open_cpt_settings'),
            'post_type' => $cpt,
        ], admin_url('admin-post.php'));
        echo '<span id="uplg-tools-inline" class="alignleft actions" style="margin-right:8px;">';
                echo '<a class="uplg-gear-link" href="' . esc_url($settings_url) . '" title="' . esc_attr__('Réglages Library Generator', 'up-library-generator') . '">';
        echo '<span class="dashicons dashicons-admin-generic"></span>';
        echo '</a>';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . $cpt . '&page=uplg-import-export')) . '">' . esc_html__('I/E', 'up-library-generator') . '</a>';
        echo '</span>';
        echo '<script>(function(){
            var tools=document.getElementById("uplg-tools-inline");
            if(!tools) return;
            var tablenav=document.querySelector(".wrap .tablenav.top");
            if(!tablenav) tablenav=document.querySelector(".tablenav.top");
            if(!tablenav) return;
            var bulk=tablenav.querySelector(".bulkactions");
            if(bulk && bulk.parentNode){ bulk.parentNode.insertBefore(tools, bulk); }
        })();</script>';
    }

    /**
     * Register bulk export actions for all admin-visible CPTs.
     */
    public function register_bulk_export_actions(): void {
        $all = get_post_types(['show_ui' => true], 'names');
        $exclude = ['attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache'];
        foreach ($all as $pt) {
            if (in_array($pt, $exclude, true)) { continue; }
            add_filter("bulk_actions-edit-{$pt}", function(array $actions) {
                $actions['uplg_export_xml'] = __('Exporter (XML)', 'up-library-generator');
                $actions['uplg_update_default_xml'] = __('Mettre à jour le fichier par défaut (XML)', 'up-library-generator');
                return $actions;
            });
            add_filter("handle_bulk_actions-edit-{$pt}", function(string $redirect_to, string $doaction, array $post_ids) use ($pt) {
                if ($doaction !== 'uplg_export_xml') { return $redirect_to; }
                if (!current_user_can('edit_posts')) { return $redirect_to; }
                $xml = $this->export_selected_to_xml($pt, $post_ids);
                $filename = 'uplg-export-' . $pt . '-' . gmdate('Ymd-His') . '.xml';
                $this->send_download($filename, $xml, 'application/xml; charset=utf-8');
                exit;
            }, 10, 3);

            // Handle updating default XML from selection
            add_filter("handle_bulk_actions-edit-{$pt}", function(string $redirect_to, string $doaction, array $post_ids) use ($pt) {
                if ($doaction !== 'uplg_update_default_xml') { return $redirect_to; }
                // Require higher capability since we write into the plugin's defaults
                if (!current_user_can('manage_options')) { return add_query_arg('uplg_default_updated', '0', $redirect_to); }
                $xml = $this->export_selected_to_xml($pt, $post_ids);
                // Determine default file path (same convention as import_defaults_from_plugin)
                $default = UPLG_PATH . 'defaults/' . sanitize_title($pt) . '.xml';
                $file = (string) apply_filters('uplg_plugin_default_xml_path', $default, $pt);
                // Ensure directory exists
                $dir = wp_normalize_path(trailingslashit(dirname($file)));
                if (!is_dir($dir)) { wp_mkdir_p($dir); }
                $ok = @file_put_contents($file, $xml);
                $success = ($ok !== false) ? '1' : '0';
                $redirect_to = add_query_arg('uplg_default_updated', $success, $redirect_to);
                return $redirect_to;
            }, 11, 3);
        }
    }

    /**
     * Build an XML containing only the selected posts of a CPT (including plugin metas).
     */
    private function export_selected_to_xml(string $cpt, array $ids): string {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) { return ''; }
        $posts = get_posts(['post_type' => $cpt, 'post__in' => $ids, 'post_status' => 'any', 'posts_per_page' => -1]);
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElement('uplg_items');
        $xml->appendChild($root);
        foreach ($posts as $post) {
            $item = $xml->createElement('item');
            $post_node = $xml->createElement('post');
            $post_node->appendChild($xml->createElement('title', htmlspecialchars($post->post_title)));
            $post_node->appendChild($xml->createElement('slug', htmlspecialchars($post->post_name)));
            $post_node->appendChild($xml->createElement('status', htmlspecialchars($post->post_status)));
            $content_node = $xml->createElement('content');
            $content_node->appendChild($xml->createCDATASection((string)$post->post_content));
            $post_node->appendChild($content_node);
            $item->appendChild($post_node);

            $meta_node = $xml->createElement('meta');
            if ($cpt === 'library-generator') {
                $keys = ['_uplg_conf_target_cpt','_uplg_conf_base_location','_uplg_conf_relative_subdir','_uplg_conf_wrap_subfolder','_uplg_conf_custom_directory','_uplg_conf_meta_schema'];
            } else {
                $keys = ['_uplg_file_name'];
            }
            foreach ($keys as $k) {
                $v = get_post_meta($post->ID, $k, true);
                if (($k === '_uplg_conf_meta_schema') && is_array($v)) { $v = wp_json_encode($v); }
                $v = (string) $v;
                $child = $xml->createElement('meta_key');
                $child->setAttribute('name', $k);
                if (preg_match('/[<>&]/', $v)) { $child->appendChild($xml->createCDATASection($v)); } else { $child->appendChild($xml->createTextNode($v)); }
                $meta_node->appendChild($child);
            }
            if ($cpt !== 'library-generator') {
                $all_meta = get_post_meta($post->ID);
                foreach ($all_meta as $mk => $vals) {
                    if (strpos($mk, '_uplg_meta_') === 0 || strpos($mk, '_uplg_generate_meta_') === 0) {
                        $v = (string) get_post_meta($post->ID, $mk, true);
                        $child = $xml->createElement('meta_key');
                        $child->setAttribute('name', $mk);
                        if (preg_match('/[<>&]/', $v)) { $child->appendChild($xml->createCDATASection($v)); } else { $child->appendChild($xml->createTextNode($v)); }
                        $meta_node->appendChild($child);
                    }
                }
            }
            $item->appendChild($meta_node);
            $root->appendChild($item);
        }
        return $xml->saveXML() ?: '';
    }

    private function send_download(string $filename, string $content, string $content_type): void {
        if (function_exists('ob_get_level')) { while (ob_get_level()) { @ob_end_clean(); } }
        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename=' . sanitize_file_name($filename));
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . strlen($content));
        echo $content; exit;
    }

    private function export_to_xml(string $cpt): string {
        $posts = get_posts(['post_type' => $cpt, 'post_status' => 'any', 'posts_per_page' => -1]);
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElement('uplg_items');
        $xml->appendChild($root);

        foreach ($posts as $post) {
            $item = $xml->createElement('item');
            $post_node = $xml->createElement('post');
            $post_node->appendChild($xml->createElement('title', htmlspecialchars($post->post_title)));
            $post_node->appendChild($xml->createElement('slug', htmlspecialchars($post->post_name)));
            $post_node->appendChild($xml->createElement('status', htmlspecialchars($post->post_status)));
            $content_node = $xml->createElement('content');
            $content_node->appendChild($xml->createCDATASection((string)$post->post_content));
            $post_node->appendChild($content_node);
            $item->appendChild($post_node);

            $meta_node = $xml->createElement('meta');
            if ($cpt === 'library-generator') {
                $keys = ['_uplg_conf_target_cpt','_uplg_conf_fields','_uplg_conf_base_location','_uplg_conf_relative_subdir','_uplg_conf_wrap_subfolder','_uplg_conf_custom_directory','_uplg_conf_meta_schema'];
            } else {
                $keys = ['_uplg_file_name','_uplg_php_code','_uplg_js_code','_uplg_scss_code','_uplg_css_code','_uplg_generate_php_file','_uplg_generate_js_file','_uplg_generate_scss_file','_uplg_generate_css_file'];
            }
            foreach ($keys as $k) {
                $v = get_post_meta($post->ID, $k, true);
                if (($k === '_uplg_conf_meta_schema') && is_array($v)) {
                    $v = wp_json_encode($v);
                }
                $v = (string) $v;
                $child = $xml->createElement('meta_key');
                $child->setAttribute('name', $k);
                if (preg_match('/[<>&]/', $v)) { $child->appendChild($xml->createCDATASection($v)); } else { $child->appendChild($xml->createTextNode($v)); }
                $meta_node->appendChild($child);
            }
            // Include dynamic schema meta keys for target CPTs
            if ($cpt !== 'library-generator') {
                $all_meta = get_post_meta($post->ID);
                foreach ($all_meta as $mk => $vals) {
                    if (strpos($mk, '_uplg_meta_') === 0 || strpos($mk, '_uplg_generate_meta_') === 0) {
                        $v = (string) get_post_meta($post->ID, $mk, true);
                        $child = $xml->createElement('meta_key');
                        $child->setAttribute('name', $mk);
                        if (preg_match('/[<>&]/', $v)) { $child->appendChild($xml->createCDATASection($v)); } else { $child->appendChild($xml->createTextNode($v)); }
                        $meta_node->appendChild($child);
                    }
                }
            }
            $item->appendChild($meta_node);
            $root->appendChild($item);
        }
        return $xml->saveXML() ?: '';
    }

    private function import_from_xml(string $xml_string, string $cpt) {
        if ($xml_string === '') return new \WP_Error('uplg_empty_xml', __('Le fichier XML est vide.', 'up-library-generator'));
        $xml = @simplexml_load_string($xml_string);
        if (!$xml) return new \WP_Error('uplg_invalid_xml', __('XML invalide.', 'up-library-generator'));
        if (!$cpt) { $cpt = $this->opts['target_cpt']; }
        $count = 0;
        foreach ($xml->item as $item) {
            $post_data = $item->post ?? null;
            if (!$post_data) continue;
            $title = (string)($post_data->title ?? '');
            $slug  = sanitize_title((string)($post_data->slug ?? ''));
            $status= (string)($post_data->status ?? 'draft');
            $content = (string)($post_data->content ?? '');

            $existing = $slug ? get_page_by_path($slug, 'OBJECT', $cpt) : null;
            $postarr = [
                'post_title'   => $title,
                'post_name'    => $slug ?: sanitize_title($title ?: uniqid('item-')),
                'post_status'  => $status ?: 'draft',
                'post_type'    => $cpt,
                'post_content' => $content,
            ];
            if ($existing) { $postarr['ID'] = $existing->ID; $post_id = wp_update_post($postarr, true); }
            else { $post_id = wp_insert_post($postarr, true); }
            if (is_wp_error($post_id)) continue;

            if (isset($item->meta)) {
                foreach ($item->meta->meta_key as $meta) {
                    $meta_key = (string)$meta['name'];
                    $meta_value = (string)$meta;
                    if ($cpt === 'library-generator' && in_array($meta_key, ['_uplg_conf_meta_schema'], true)) {
                        $decoded = json_decode($meta_value, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            update_post_meta($post_id, $meta_key, $decoded);
                            continue;
                        }
                    }
                    update_post_meta($post_id, $meta_key, wp_unslash($meta_value));
                }
            }
            // Generate files only for target CPTs (not for configuration CPT)
            if ($cpt !== 'library-generator') {
                $conf = $this->get_conf_for_cpt($cpt) ?: $this->opts;
                $this->generate_files($post_id, $conf);
            }
            $count++;
        }
        return $count;
    }

    private function import_defaults_from_plugin(string $cpt = '') {
        $cpt = $cpt ?: sanitize_text_field($_POST['post_type'] ?? ($_GET['post_type'] ?? ($this->opts['target_cpt'] ?? '')));
        if (!$cpt) return new \WP_Error('uplg_no_cpt', __('Aucun CPT sélectionné.', 'up-library-generator'));
        $default = UPLG_PATH . 'defaults/' . sanitize_title($cpt) . '.xml';
        $file = (string) apply_filters('uplg_plugin_default_xml_path', $default, $cpt);
        if (!file_exists($file)) {
            return new \WP_Error('uplg_defaults_missing', __('Fichier par défaut introuvable pour ce CPT.', 'up-library-generator'));
        }
        $xml = file_get_contents($file);
        if ($xml === false) return new \WP_Error('uplg_defaults_read', __('Impossible de lire le fichier par défaut.', 'up-library-generator'));
        return $this->import_from_xml($xml, $cpt);
    }

    private function get_conf_for_cpt(string $cpt): array {
        if (isset($this->config_map[$cpt])) { return $this->config_map[$cpt]; }
        if (!empty($this->opts['target_cpt']) && $this->opts['target_cpt'] === $cpt) { return $this->opts; }
        return [];
    }
}

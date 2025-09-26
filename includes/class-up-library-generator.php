<?php
namespace UPLG;

if (!defined('ABSPATH')) { exit; }

class UpLibraryGenerator {
    private static $instance;

    private $option_key = 'uplg_settings';
    private $opts = [];

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->opts = $this->get_options();
        register_activation_hook(UPLG_PATH . 'up-library-generator.php', [$this, 'on_activate']);

        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'handle_save_post'], 10, 2);

        add_action('admin_post_uplg_export_all', [$this, 'handle_export_all']);
        add_action('admin_post_uplg_import_defaults', [$this, 'handle_import_defaults']);
    }

    public function on_activate(): void {
        if (!get_option($this->option_key)) {
            update_option($this->option_key, $this->default_options());
        }
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
        add_options_page(
            __('UP Library Generator', 'up-library-generator'),
            __('UP Library Generator', 'up-library-generator'),
            'manage_options',
            'uplg-settings',
            [$this, 'render_settings_page']
        );

        $cpt = $this->opts['target_cpt'];
        if ($cpt) {
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
        if ($screen && $screen->post_type === ($this->opts['target_cpt'] ?? '') && in_array($hook, ['post.php', 'post-new.php'], true)) {
            $php_settings  = wp_enqueue_code_editor(['type' => 'text/x-php', 'codemirror' => ['theme' => 'monokai']]);
            $scss_settings = wp_enqueue_code_editor(['type' => 'text/scss', 'codemirror' => ['theme' => 'monokai']]);
            $js_settings   = wp_enqueue_code_editor(['type' => 'javascript', 'codemirror' => ['theme' => 'monokai']]);
            $css_settings  = wp_enqueue_code_editor(['type' => 'text/css', 'codemirror' => ['theme' => 'monokai']]);

            wp_enqueue_script('code-editor');
            wp_enqueue_style('code-editor');

            wp_enqueue_style('uplg-admin', UPLG_URL . 'assets/admin.css', [], UPLG_VERSION);
            wp_enqueue_script('uplg-admin', UPLG_URL . 'assets/admin.js', ['jquery', 'code-editor'], UPLG_VERSION, true);

            wp_localize_script('uplg-admin', 'uplgCodeMirrorSettings', [
                'php'  => $php_settings,
                'scss' => $scss_settings,
                'js'   => $js_settings,
                'css'  => $css_settings,
            ]);
        }
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) { return; }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['uplg_settings_submit'])) {
            check_admin_referer('uplg_settings');
            $target_cpt      = sanitize_text_field($_POST['target_cpt'] ?? '');
            $base_location   = sanitize_text_field($_POST['base_location'] ?? 'theme');
            $relative_subdir = trim(sanitize_text_field($_POST['relative_subdir'] ?? 'library'));
            $wrap_subfolder  = isset($_POST['wrap_subfolder']) ? '1' : '0';
            $custom_dir      = wp_normalize_path(trim((string)($_POST['custom_directory'] ?? '')));

            $fields = $this->opts['fields'];
            foreach ($fields as $k => $conf) {
                $fields[$k]['enabled']   = isset($_POST['field_enabled_' . $k]) ? true : false;
                $fields[$k]['with_flag'] = isset($_POST['field_flag_' . $k]) ? true : false;
            }

            $this->update_options([
                'target_cpt'       => $target_cpt,
                'base_location'    => $base_location,
                'relative_subdir'  => $relative_subdir,
                'wrap_subfolder'   => $wrap_subfolder,
                'custom_directory' => $custom_dir,
                'fields'           => $fields,
            ]);
            echo '<div class="notice notice-success"><p>' . esc_html__('Réglages enregistrés.', 'up-library-generator') . '</p></div>';
        }

        $post_types = get_post_types(['public' => true], 'objects');
        $opts = $this->opts;
        $fields = $opts['fields'];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('UP Library Generator — Réglages', 'up-library-generator'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('uplg_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('CPT cible', 'up-library-generator'); ?></th>
                        <td>
                            <select name="target_cpt">
                                <option value="">—</option>
                                <?php foreach ($post_types as $pt => $obj): ?>
                                    <option value="<?php echo esc_attr($pt); ?>" <?php selected($opts['target_cpt'], $pt); ?>><?php echo esc_html($obj->labels->singular_name . ' (' . $pt . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Sélectionnez le type de contenu à enrichir (metabox + import/export).', 'up-library-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Champs de code', 'up-library-generator'); ?></th>
                        <td>
                            <?php foreach (['php','js','scss','css'] as $k): ?>
                                <label style="display:block;margin:.25rem 0;">
                                    <input type="checkbox" name="field_enabled_<?php echo esc_attr($k); ?>" <?php checked(!empty($fields[$k]['enabled'])); ?>>
                                    <?php echo esc_html(strtoupper($k)); ?>
                                    &nbsp;&nbsp;
                                    <input type="checkbox" name="field_flag_<?php echo esc_attr($k); ?>" <?php checked(!empty($fields[$k]['with_flag'])); ?>>
                                    <?php esc_html_e('Avec case à cocher de génération', 'up-library-generator'); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Emplacement des fichiers générés', 'up-library-generator'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="base_location" value="theme" <?php checked($opts['base_location'], 'theme'); ?>> <?php esc_html_e('Thème actif', 'up-library-generator'); ?></label><br>
                                <label><input type="radio" name="base_location" value="mu-plugins" <?php checked($opts['base_location'], 'mu-plugins'); ?>> <?php esc_html_e('MU-plugins', 'up-library-generator'); ?></label><br>
                                <label><input type="radio" name="base_location" value="plugin" <?php checked($opts['base_location'], 'plugin'); ?>> <?php esc_html_e('Ce plugin', 'up-library-generator'); ?></label><br>
                                <label><input type="radio" name="base_location" value="custom" <?php checked($opts['base_location'], 'custom'); ?>> <?php esc_html_e('Chemin personnalisé', 'up-library-generator'); ?></label>
                            </fieldset>
                            <p>
                                <label>
                                    <?php esc_html_e('Sous-dossier relatif', 'up-library-generator'); ?>
                                    <input type="text" name="relative_subdir" value="<?php echo esc_attr($opts['relative_subdir']); ?>" placeholder="library">
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="wrap_subfolder" <?php checked($opts['wrap_subfolder'], '1'); ?>>
                                    <?php esc_html_e('Créer un sous-dossier par élément (recommandé)', 'up-library-generator'); ?>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php esc_html_e('Chemin personnalisé (si sélectionné)', 'up-library-generator'); ?>
                                    <input type="text" name="custom_directory" value="<?php echo esc_attr($opts['custom_directory']); ?>" size="70">
                                </label>
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button class="button button-primary" name="uplg_settings_submit" value="1"><?php esc_html_e('Enregistrer', 'up-library-generator'); ?></button></p>
            </form>
        </div>
        <?php
    }

    public function register_metabox(): void {
        $cpt = $this->opts['target_cpt'];
        if (!$cpt) { return; }
        add_meta_box('uplg_code_fields', __('Code et génération', 'up-library-generator'), [$this, 'render_metabox'], $cpt, 'normal', 'high');
    }

    public function render_metabox($post): void {
        $fields = $this->opts['fields'];
        wp_nonce_field('uplg_save_meta', 'uplg_meta_nonce');

        $file_name = get_post_meta($post->ID, '_uplg_file_name', true);
        echo '<p><label>' . esc_html__('Nom de fichier (base, sans extension)', 'up-library-generator') . '<br/>';
        echo '<input type="text" name="_uplg_file_name" value="' . esc_attr($file_name) . '" style="width:100%" placeholder="ex: block-hero"></label></p>';

        foreach (['php','js','scss','css'] as $k) {
            if (empty($fields[$k]['enabled'])) continue;
            $label = strtoupper($k);
            $meta_key = '_uplg_' . $k . '_code';
            $content = get_post_meta($post->ID, $meta_key, true);
            echo '<p><label><strong>' . esc_html($label) . '</strong></label>';
            echo '<textarea name="' . esc_attr($meta_key) . '" rows="10" style="width:100%">' . esc_textarea($content) . '</textarea>';
            if (!empty($fields[$k]['with_flag'])) {
                $flag_key = '_uplg_generate_' . $k . '_file';
                $flag_val = get_post_meta($post->ID, $flag_key, true) ?: '0';
                echo '<br><label><input type="checkbox" name="' . esc_attr($flag_key) . '" ' . checked($flag_val, '1', false) . '> ' . esc_html__('Générer le fichier', 'up-library-generator') . '</label>';
            }
            echo '</p>';
        }
    }

    public function handle_save_post(int $post_id, $post): void {
        if (!isset($_POST['uplg_meta_nonce']) || !wp_verify_nonce($_POST['uplg_meta_nonce'], 'uplg_save_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $cpt = $this->opts['target_cpt'];
        if (!$cpt || $post->post_type !== $cpt) return;

        $file_name = sanitize_title((string)($_POST['_uplg_file_name'] ?? ''));
        if (!$file_name) { $file_name = sanitize_title($post->post_name ?: ('item-' . $post_id)); }
        update_post_meta($post_id, '_uplg_file_name', $file_name);

        foreach (['php','js','scss','css'] as $k) {
            if (empty($this->opts['fields'][$k]['enabled'])) continue;
            $meta_key = '_uplg_' . $k . '_code';
            if (array_key_exists($meta_key, $_POST)) {
                $val = (string) wp_unslash($_POST[$meta_key] ?? '');
                update_post_meta($post_id, $meta_key, $val);
            }
            if (!empty($this->opts['fields'][$k]['with_flag'])) {
                $flag_key = '_uplg_generate_' . $k . '_file';
                update_post_meta($post_id, $flag_key, isset($_POST[$flag_key]) ? '1' : '0');
            }
        }

        $this->generate_files($post_id);
    }

    private function resolve_base_dir(): string {
        $opts = $this->opts;
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

    private function generate_files(int $post_id): void {
        $opts = $this->opts;
        $base_dir = wp_normalize_path($this->resolve_base_dir());
        $file_name = get_post_meta($post_id, '_uplg_file_name', true) ?: ('item-' . $post_id);
        $wrap = $opts['wrap_subfolder'] === '1';

        $root_dir = $wrap ? trailingslashit($base_dir . $file_name) : trailingslashit($base_dir);
        if (!$base_dir) { error_log('[UPLG] Base dir vide pour post ' . $post_id . ' — vérifier les réglages.'); }
        $assets   = [
            'css'  => trailingslashit($root_dir . 'assets/css'),
            'js'   => trailingslashit($root_dir . 'assets/js'),
            'scss' => trailingslashit($root_dir . 'assets/scss'),
        ];

        $this->ensure_dir($root_dir);
        foreach ($assets as $d) { $this->ensure_dir($d); }

        // PHP
        $gen_php = !empty($opts['fields']['php']['enabled']) && (
            !empty($opts['fields']['php']['with_flag'])
                ? (get_post_meta($post_id, '_uplg_generate_php_file', true) === '1')
                : true
        );
        if ($gen_php) {
            $code = (string) get_post_meta($post_id, '_uplg_php_code', true);
            if ($code && strpos($code, '<?php') !== 0) { $code = "<?php\n" . $code; }
            $ok = @file_put_contents($root_dir . $file_name . '.php', $code);
            if ($ok === false) { error_log('[UPLG] Echec écriture PHP: ' . $root_dir . $file_name . '.php'); }
        }
        // JS
        $gen_js = !empty($opts['fields']['js']['enabled']) && (
            !empty($opts['fields']['js']['with_flag'])
                ? (get_post_meta($post_id, '_uplg_generate_js_file', true) === '1')
                : true
        );
        if ($gen_js) {
            $code = (string) get_post_meta($post_id, '_uplg_js_code', true);
            $ok = @file_put_contents($assets['js'] . $file_name . '.js', $code);
            if ($ok === false) { error_log('[UPLG] Echec écriture JS: ' . $assets['js'] . $file_name . '.js'); }
        }
        // SCSS
        $gen_scss = !empty($opts['fields']['scss']['enabled']) && (
            !empty($opts['fields']['scss']['with_flag'])
                ? (get_post_meta($post_id, '_uplg_generate_scss_file', true) === '1')
                : true
        );
        if ($gen_scss) {
            $code = (string) get_post_meta($post_id, '_uplg_scss_code', true);
            $ok = @file_put_contents($assets['scss'] . $file_name . '.scss', $code);
            if ($ok === false) { error_log('[UPLG] Echec écriture SCSS: ' . $assets['scss'] . $file_name . '.scss'); }
        }
        // CSS (depuis SCSS si dispo, sinon direct)
        $gen_css = !empty($opts['fields']['css']['enabled']) && (
            !empty($opts['fields']['css']['with_flag'])
                ? (get_post_meta($post_id, '_uplg_generate_css_file', true) === '1')
                : true
        );
        if ($gen_css) {
            $css = (string) get_post_meta($post_id, '_uplg_css_code', true);
            if (!$css) {
                $scss = (string) get_post_meta($post_id, '_uplg_scss_code', true);
                if ($scss) { $css = $this->compile_scss($scss); }
            }
            $ok = @file_put_contents($assets['css'] . $file_name . '.css', $css);
            if ($ok === false) { error_log('[UPLG] Echec écriture CSS: ' . $assets['css'] . $file_name . '.css'); }
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
                    $result = $this->import_from_xml($xml ?: '');
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

        $export_url = add_query_arg([
            'action'   => 'uplg_export_all',
            '_wpnonce' => wp_create_nonce('uplg_export_all'),
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
        echo wp_nonce_field('uplg_import_defaults', '_wpnonce', true, false);
        echo '<p><button class="button button-secondary">' . esc_html__('Importer le fichier par défaut (plugin)', 'up-library-generator') . '</button></p>';
        echo '</form>';

        echo '</div>';
    }

    public function handle_export_all(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permissions insuffisantes.', 'up-library-generator'));
        check_admin_referer('uplg_export_all');
        $xml = $this->export_to_xml();
        $filename = 'uplg-export-' . ($this->opts['target_cpt'] ?: 'items') . '-' . gmdate('Ymd-His') . '.xml';
        $this->send_download($filename, $xml, 'application/xml; charset=utf-8');
    }

    public function handle_import_defaults(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permissions insuffisantes.', 'up-library-generator'));
        check_admin_referer('uplg_import_defaults');
        $result = $this->import_defaults_from_plugin();
        $url = add_query_arg([
            'post_type' => $this->opts['target_cpt'],
            'page'      => 'uplg-import-export',
            'import'    => is_wp_error($result) ? '0' : '1',
        ], admin_url('edit.php'));
        wp_redirect($url);
        exit;
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

    private function export_to_xml(): string {
        $cpt = $this->opts['target_cpt'];
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
            $keys = ['_uplg_file_name','_uplg_php_code','_uplg_js_code','_uplg_scss_code','_uplg_css_code','_uplg_generate_php_file','_uplg_generate_js_file','_uplg_generate_scss_file','_uplg_generate_css_file'];
            foreach ($keys as $k) {
                $v = (string) get_post_meta($post->ID, $k, true);
                $child = $xml->createElement('meta_key');
                $child->setAttribute('name', $k);
                if (preg_match('/[<>&]/', $v)) { $child->appendChild($xml->createCDATASection($v)); } else { $child->appendChild($xml->createTextNode($v)); }
                $meta_node->appendChild($child);
            }
            $item->appendChild($meta_node);
            $root->appendChild($item);
        }
        return $xml->saveXML() ?: '';
    }

    private function import_from_xml(string $xml_string) {
        if ($xml_string === '') return new \WP_Error('uplg_empty_xml', __('Le fichier XML est vide.', 'up-library-generator'));
        $xml = @simplexml_load_string($xml_string);
        if (!$xml) return new \WP_Error('uplg_invalid_xml', __('XML invalide.', 'up-library-generator'));
        $cpt = $this->opts['target_cpt'];
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
                    update_post_meta($post_id, $meta_key, wp_unslash($meta_value));
                }
            }
            $this->generate_files($post_id);
            $count++;
        }
        return $count;
    }

    private function import_defaults_from_plugin() {
        $cpt = $this->opts['target_cpt'];
        if (!$cpt) return new \WP_Error('uplg_no_cpt', __('Aucun CPT sélectionné.', 'up-library-generator'));
        $default = UPLG_PATH . 'defaults/' . sanitize_title($cpt) . '.xml';
        $file = (string) apply_filters('uplg_plugin_default_xml_path', $default, $cpt);
        if (!file_exists($file)) {
            return new \WP_Error('uplg_defaults_missing', __('Fichier par défaut introuvable pour ce CPT.', 'up-library-generator'));
        }
        $xml = file_get_contents($file);
        if ($xml === false) return new \WP_Error('uplg_defaults_read', __('Impossible de lire le fichier par défaut.', 'up-library-generator'));
        return $this->import_from_xml($xml);
    }
}

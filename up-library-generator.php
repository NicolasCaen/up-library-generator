<?php
/**
 * Plugin Name: UP Library Generator
 * Description: Générateur générique de bibliothèques pour n'importe quel CPT: champs code avec CodeMirror, génération de fichiers, import/export et XML par défaut.
 * Version: 0.2.0
 * Author: Nicolas Gehin
 * Text Domain: up-library-generator
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constantes
define('UPLG_VERSION', '0.2.0');
define('UPLG_PATH', plugin_dir_path(__FILE__));
define('UPLG_URL', plugin_dir_url(__FILE__));

define('UPLG_OPTION_KEY', 'uplg_settings');
require_once UPLG_PATH . 'includes/class-up-library-generator.php';

// Activation: initialiser les réglages par défaut
register_activation_hook(__FILE__, function(){
    \UPLG\UpLibraryGenerator::instance()->on_activate();
});

// Bootstrap
add_action('plugins_loaded', function(){
    UPLG\UpLibraryGenerator::instance();
});

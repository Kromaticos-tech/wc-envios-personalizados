<?php
if (!defined('ABSPATH')) exit;

class WCEP_Activator {

    public static function activate() {
        // El CPT va primero: la taxonomía se registra contra los post types que él declara.
        require_once WCEP_PATH . 'includes/class-sede-cpt.php';
        require_once WCEP_PATH . 'includes/class-taxonomy.php';

        WCEP_Sede_CPT::register();
        WCEP_Taxonomy::register();

        flush_rewrite_rules();

        if (!get_option('wcep_version')) {
            add_option('wcep_version', WCEP_VERSION);
        }
    }
}

<?php
/**
 * Plugin Name: WC Envíos Personalizados
 * Description: Selector de método de envío en tarjetas (Pickup / Delivery / Zoom) para el checkout de WooCommerce, usando el CPT "tiendas" como fuente de sedes.
 * Version: 1.11.1
 * Author: Kromaticos
 * Author URI: https://kromaticos.com
 * Text Domain: wc-envios-personalizados
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('WCEP_PATH', plugin_dir_path(__FILE__));
define('WCEP_URL', plugin_dir_url(__FILE__));
define('WCEP_VERSION', '1.11.1');

require_once WCEP_PATH . 'includes/class-activator.php';
require_once WCEP_PATH . 'includes/class-deactivator.php';

register_activation_hook(__FILE__, ['WCEP_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WCEP_Deactivator', 'deactivate']);

add_action('plugins_loaded', 'wcep_bootstrap');

function wcep_bootstrap() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wcep_notice_falta_woocommerce');
        return;
    }

    require_once WCEP_PATH . 'includes/class-sede-cpt.php';
    require_once WCEP_PATH . 'includes/class-taxonomy.php';
    require_once WCEP_PATH . 'includes/class-sede-meta.php';
    require_once WCEP_PATH . 'includes/class-importador.php';
    require_once WCEP_PATH . 'includes/class-ajax.php';
    require_once WCEP_PATH . 'includes/class-checkout.php';
    require_once WCEP_PATH . 'includes/class-datos-entrega.php';
    require_once WCEP_PATH . 'includes/class-shipping.php';
    require_once WCEP_PATH . 'includes/class-diagnostico.php';

    WCEP_Sede_CPT::init();
    WCEP_Taxonomy::init();
    WCEP_Sede_Meta::init();
    WCEP_Importador::init();
    WCEP_Ajax::init();
    WCEP_Checkout::init();
    WCEP_Datos_Entrega::init();
    WCEP_Shipping::init();
    WCEP_Diagnostico::init();

    // El flush de rewrite rules solo tiene sentido una vez que todos los post types y taxonomías
    // están registrados, es decir después de "init". En "plugins_loaded" sería prematuro.
    add_action('init', 'wcep_check_version', 100);

    // El CPT "tiendas" no lo crea este plugin: lo registra JetEngine (u otro) en el hook "init",
    // que corre DESPUÉS de "plugins_loaded". Por eso el chequeo va acá y no arriba: preguntarlo
    // antes daba siempre "no existe" y cortaba el plugin entero.
    add_action('init', 'wcep_verificar_cpt_tiendas', 99);
}

// Solo avisa si algún método está configurado para sacar sus sedes de "tiendas" y ese CPT no
// existe. El plugin trae su propio CPT de sedes, así que sin "tiendas" sigue siendo funcional.
function wcep_verificar_cpt_tiendas() {
    if (post_type_exists('tiendas')) {
        return;
    }

    $terminos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);
    if (is_wp_error($terminos)) {
        return;
    }

    foreach ($terminos as $termino) {
        if (get_term_meta($termino->term_id, 'post_type_sedes', true) === 'tiendas') {
            add_action('admin_notices', 'wcep_notice_falta_cpt_tiendas');
            return;
        }
    }
}

function wcep_notice_falta_woocommerce() {
    echo '<div class="notice notice-error"><p>' . esc_html__('WC Envíos Personalizados requiere que WooCommerce esté activo.', 'wc-envios-personalizados') . '</p></div>';
}

function wcep_notice_falta_cpt_tiendas() {
    echo '<div class="notice notice-error"><p>' . esc_html__('WC Envíos Personalizados requiere el post type "tiendas".', 'wc-envios-personalizados') . '</p></div>';
}

// Corre migraciones simples (por ahora solo flush de rewrite rules) cuando cambia la versión del plugin.
function wcep_check_version() {
    $version_guardada = get_option('wcep_version');
    if ($version_guardada === WCEP_VERSION) {
        return;
    }

    flush_rewrite_rules();

    // Las tarifas de envío quedan cacheadas en la sesión de cada cliente. Si cambia la lógica de
    // costos entre versiones, sin invalidar ese caché seguirían viéndose las tarifas viejas.
    if (class_exists('WC_Cache_Helper')) {
        WC_Cache_Helper::get_transient_version('shipping', true);
    }

    update_option('wcep_version', WCEP_VERSION);
}

<?php
/**
 * Plugin Name: WC Envíos Personalizados
 * Description: Selector de método de envío en tarjetas (Pickup / Delivery / Zoom) para el checkout de WooCommerce, usando el CPT "tiendas" como fuente de sedes.
 * Version: 1.16.7
 * Author: Kromaticos
 * Author URI: https://kromaticos.com
 * Text Domain: wc-envios-personalizados
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('WCEP_PATH', plugin_dir_path(__FILE__));
define('WCEP_URL', plugin_dir_url(__FILE__));
define('WCEP_VERSION', '1.16.7');

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

    require_once WCEP_PATH . 'includes/class-horarios.php';
    require_once WCEP_PATH . 'includes/class-sede-cpt.php';
    require_once WCEP_PATH . 'includes/class-taxonomy.php';
    require_once WCEP_PATH . 'includes/class-grupo-horario.php';
    require_once WCEP_PATH . 'includes/class-sede-meta.php';
    require_once WCEP_PATH . 'includes/class-importador.php';
    require_once WCEP_PATH . 'includes/class-ajax.php';
    require_once WCEP_PATH . 'includes/class-checkout.php';
    require_once WCEP_PATH . 'includes/class-datos-entrega.php';
    require_once WCEP_PATH . 'includes/class-tarifas-producto.php';
    require_once WCEP_PATH . 'includes/class-shipping.php';
    require_once WCEP_PATH . 'includes/class-pedido-listo.php';
    require_once WCEP_PATH . 'includes/class-diagnostico.php';

    WCEP_Sede_CPT::init();
    WCEP_Taxonomy::init();
    WCEP_Grupo_Horario::init();
    WCEP_Sede_Meta::init();
    WCEP_Importador::init();
    WCEP_Ajax::init();
    WCEP_Checkout::init();
    WCEP_Datos_Entrega::init();
    WCEP_Tarifas_Producto::init();
    WCEP_Shipping::init();
    WCEP_Pedido_Listo::init();
    WCEP_Diagnostico::init();

    // El flush de rewrite rules solo tiene sentido una vez que todos los post types y taxonomías
    // están registrados, es decir después de "init". En "plugins_loaded" sería prematuro.
    add_action('init', 'wcep_check_version', 100);

    // El CPT "tiendas" no lo crea este plugin: lo registra JetEngine (u otro) en el hook "init",
    // que corre DESPUÉS de "plugins_loaded". Por eso el chequeo va acá y no arriba: preguntarlo
    // antes daba siempre "no existe" y cortaba el plugin entero.
    add_action('init', 'wcep_verificar_cpt_tiendas', 99);

    // Va colgada de "init" y no del chequeo de versión: si un día no puede completarse (taxonomía
    // todavía no registrada), así se reintenta en la próxima carga en vez de quedar por hecha.
    add_action('init', 'wcep_limpiar_nota_feriados', 100);
    add_action('init', 'wcep_actualizar_frase_retiro', 100);
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

// La nota de feriados dejó de pedirse. Sacarla de los valores de arranque no alcanza: en los
// sitios donde ya se sembró quedó guardada como meta del término y se seguiría mostrando en el
// checkout. Se borra una sola vez y solo si el texto es exactamente el que sembraba el plugin;
// una nota que el administrador escribió a mano no se toca.
function wcep_limpiar_nota_feriados() {
    if (get_option('wcep_nota_feriados_limpiada')) {
        return;
    }

    $terminos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);

    // Sin marcar la opción: si la taxonomía todavía no está lista, conviene reintentarlo en la
    // próxima carga en vez de dar la limpieza por hecha.
    if (is_wp_error($terminos)) {
        return;
    }

    // El valor se sembraba pasando por __(), así que en un sitio traducido lo guardado puede ser
    // la traducción y no el original. Se comparan las dos formas.
    $textos_sembrados = array_unique([
        'Si la fecha cae feriado, se entrega el próximo día hábil.',
        __('Si la fecha cae feriado, se entrega el próximo día hábil.', 'wc-envios-personalizados'),
    ]);

    foreach ($terminos as $termino) {
        if (in_array(get_term_meta($termino->term_id, 'nota_disponibilidad', true), $textos_sembrados, true)) {
            delete_term_meta($termino->term_id, 'nota_disponibilidad');
        }
    }

    update_option('wcep_nota_feriados_limpiada', '1');
}

// "Retira el Viernes 11 de Septiembre" se leía como una cita: mucha gente entendía que tenía que ir
// ese día y no otro. La frase pasó a "Retira a partir del…", pero cambiar el valor sembrado no
// alcanza, porque en los sitios que ya andan quedó guardada en el grupo de horario.
//
// Se reescribe una sola vez y solo si el texto es exactamente el que sembró el plugin: si alguien lo
// redactó a mano, es suyo y no se toca.
function wcep_actualizar_frase_retiro() {
    if (get_option('wcep_frase_retiro_actualizada')) {
        return;
    }

    $terminos = get_terms(['taxonomy' => WCEP_Grupo_Horario::TAXONOMIA, 'hide_empty' => false]);

    // Sin marcar la opción: si la taxonomía todavía no está registrada conviene reintentarlo en la
    // próxima carga en vez de dar el cambio por hecho.
    if (is_wp_error($terminos)) {
        return;
    }

    // Igual que con la nota de feriados: lo guardado puede ser la traducción y no el original.
    $plantillas_viejas = array_unique([
        'Retira el {fecha}, a partir de las {hora}',
        __('Retira el {fecha}, a partir de las {hora}', 'wc-envios-personalizados'),
    ]);

    $plantilla_nueva = __('Retira a partir del {fecha}, desde las {hora}', 'wc-envios-personalizados');

    foreach ($terminos as $termino) {
        if (in_array(get_term_meta($termino->term_id, 'plantilla', true), $plantillas_viejas, true)) {
            update_term_meta($termino->term_id, 'plantilla', $plantilla_nueva);
        }
    }

    update_option('wcep_frase_retiro_actualizada', '1');
}

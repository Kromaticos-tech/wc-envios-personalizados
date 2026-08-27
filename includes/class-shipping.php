<?php
if (!defined('ABSPATH')) exit;

// Conecta la tarjeta elegida por el cliente con el cálculo real de envío de WooCommerce.
//
// Los métodos marcados como "no cobra envío" reemplazan las tarifas de la zona por una de coste 0
// (Pickup porque el cliente retira, Zoom porque le paga al transportista en destino). El resto
// deja pasar las tarifas normales de la zona tal cual.
class WCEP_Shipping {

    // Clave con la que se guarda el método elegido en la sesión de WooCommerce.
    const CLAVE_SESION_METODO = 'wcep_metodo_envio';

    public static function init() {
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'guardar_metodo_en_sesion']);
        add_filter('woocommerce_cart_shipping_packages', [__CLASS__, 'marcar_paquetes']);
        add_filter('woocommerce_package_rates', [__CLASS__, 'filtrar_tarifas'], 10, 2);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'limpiar_sesion']);
        add_action('woocommerce_cart_emptied', [__CLASS__, 'limpiar_sesion']);
    }

    public static function guardar_metodo_en_sesion($formulario_serializado) {
        parse_str($formulario_serializado, $campos_formulario);

        if (isset($campos_formulario['wcep_metodo_envio']) && WC()->session) {
            WC()->session->set(
                self::CLAVE_SESION_METODO,
                sanitize_text_field($campos_formulario['wcep_metodo_envio'])
            );
        }
    }

    // Método elegido en esta petición: primero el POST del checkout, después la sesión.
    private static function metodo_actual() {
        if (!empty($_POST['wcep_metodo_envio'])) {
            return sanitize_text_field(wp_unslash($_POST['wcep_metodo_envio']));
        }

        return WC()->session ? (string) WC()->session->get(self::CLAVE_SESION_METODO) : '';
    }

    // WooCommerce cachea las tarifas calculadas bajo un hash del paquete (carrito + destino).
    // El método elegido no formaba parte de ese hash: al cambiar de tarjeta el carrito seguía
    // siendo idéntico, así que WooCommerce devolvía las tarifas guardadas y ni siquiera llamaba
    // a filtrar_tarifas(). El resultado era el envío gratis de Pickup pegado en Delivery.
    //
    // Añadir el método al paquete cambia el hash y fuerza el recálculo en cada cambio.
    public static function marcar_paquetes($paquetes) {
        $metodo_slug = self::metodo_actual();

        foreach ($paquetes as $indice => $paquete) {
            $paquetes[$indice]['wcep_metodo_envio'] = $metodo_slug;
        }

        return $paquetes;
    }

    public static function filtrar_tarifas($tarifas_disponibles, $paquete) {
        $metodo_slug = self::metodo_actual();
        if (!$metodo_slug) {
            return $tarifas_disponibles;
        }

        $termino = get_term_by('slug', $metodo_slug, 'metodo_envio');
        if (!$termino) {
            return $tarifas_disponibles;
        }

        if (get_term_meta($termino->term_id, 'envio_gratis', true) !== '1') {
            return $tarifas_disponibles;
        }

        $etiqueta = get_term_meta($termino->term_id, 'etiqueta_envio', true);
        if (!$etiqueta) {
            $etiqueta = $termino->name;
        }

        return [
            'wcep_gratis_' . $metodo_slug => new WC_Shipping_Rate(
                'wcep_gratis_' . $metodo_slug,
                $etiqueta,
                0,
                [],
                'local_pickup'
            ),
        ];
    }

    // Sin esto el método elegido sobrevivía al pedido: si el cliente compró con Pickup,
    // su siguiente compra arrancaba con envío gratis hasta que tocara otra tarjeta.
    public static function limpiar_sesion() {
        if (WC()->session) {
            WC()->session->__unset(self::CLAVE_SESION_METODO);
        }
    }
}

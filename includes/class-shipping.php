<?php
if (!defined('ABSPATH')) exit;

// Conecta la tarjeta elegida por el cliente con el cálculo real de envío de WooCommerce.
//
// Los métodos marcados como "no cobra envío" reemplazan las tarifas de la zona por una de coste 0
// (Pickup, porque el cliente retira). Los que tienen monto fijo las reemplazan por una con ese
// monto (Zoom). Los que aplican tarifas por producto dejan que WCEP_Tarifas_Producto ajuste el
// monto según los SKUs que haya en el carrito, y los que tienen un mínimo de compra dejan el envío
// gratis al alcanzarlo. El resto deja pasar las tarifas normales de la zona tal cual.
class WCEP_Shipping {

    // Clave con la que se guarda el método elegido en la sesión de WooCommerce.
    const CLAVE_SESION_METODO = 'wcep_metodo_envio';

    // Nivel de tarifa con el que se cerró el pedido (estandar / especial / whatsapp / gratis_monto).
    const META_NIVEL_TARIFA = '_wcep_delivery_tarifa';

    // El pedido llegó al mínimo de compra del método y no pagó envío, traiga los productos que traiga.
    const NIVEL_GRATIS_POR_MONTO = 'gratis_monto';

    public static function init() {
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'guardar_metodo_en_sesion']);
        add_filter('woocommerce_cart_shipping_packages', [__CLASS__, 'marcar_paquetes']);
        add_filter('woocommerce_package_rates', [__CLASS__, 'filtrar_tarifas'], 10, 2);
        add_filter('woocommerce_cart_shipping_method_full_label', [__CLASS__, 'etiqueta_linea_envio'], 10, 2);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'guardar_nivel_tarifa'], 20);
        add_action('woocommerce_admin_order_data_after_shipping_address', [__CLASS__, 'mostrar_nivel_en_admin']);
        add_action('woocommerce_email_order_meta', [__CLASS__, 'mostrar_nivel_en_email'], 20, 3);
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

    // Término del método elegido, o null si no hay ninguno o el término ya no existe.
    private static function termino_actual() {
        $metodo_slug = self::metodo_actual();

        if (!$metodo_slug) {
            return null;
        }

        $termino = get_term_by('slug', $metodo_slug, 'metodo_envio');

        return $termino ?: null;
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
        $termino = self::termino_actual();
        if (!$termino) {
            return $tarifas_disponibles;
        }

        if (get_term_meta($termino->term_id, 'envio_gratis', true) === '1') {
            return self::tarifa_propia('wcep_gratis_' . $termino->slug, $termino, 0);
        }

        // Monto fijo: el método cobra lo mismo en todo el país y no depende de la zona. Es el caso
        // de Zoom, que cobra por llevar el paquete a la agencia (el flete de Zoom va aparte, a
        // destino) y se ofrece en ciudades donde WooCommerce no tiene ninguna zona con tarifa.
        $monto_fijo = self::monto_de_meta($termino, 'monto_fijo');
        if ($monto_fijo > 0) {
            $tarifas_disponibles = self::tarifa_propia('wcep_fijo_' . $termino->slug, $termino, $monto_fijo);
        }

        // El envío gratis por monto va ANTES de las reglas por SKU y les gana a las dos: ni la
        // tarifa especial ni el "se acuerda por WhatsApp" aplican a una compra que llegó al mínimo.
        if (self::supera_minimo_gratis($termino, isset($paquete['contents']) ? $paquete['contents'] : [])) {
            foreach ($tarifas_disponibles as $tarifa) {
                WCEP_Tarifas_Producto::fijar_costo($tarifa, 0);
                $tarifa->set_label(self::etiqueta_envio_gratis());
            }

            return $tarifas_disponibles;
        }

        // El método cobra: el monto solo se ajusta si tiene activadas las reglas por SKU y el
        // carrito trae alguno de esos productos.
        if (get_term_meta($termino->term_id, 'aplica_tarifas_producto', true) === '1') {
            return WCEP_Tarifas_Producto::aplicar_a_tarifas($tarifas_disponibles, $paquete);
        }

        return $tarifas_disponibles;
    }

    // Tarifa armada por el plugin, que reemplaza a las de la zona. Se declara "local_pickup" tanto
    // si es gratis como si cobra: las pasarelas restringidas por método la reconocen por ese id.
    private static function tarifa_propia($id, $termino, $costo) {
        $etiqueta = get_term_meta($termino->term_id, 'etiqueta_envio', true);
        if (!$etiqueta) {
            $etiqueta = $termino->name;
        }

        return [$id => new WC_Shipping_Rate($id, $etiqueta, $costo, [], 'local_pickup')];
    }

    private static function etiqueta_envio_gratis() {
        return __('Gratis', 'wc-envios-personalizados');
    }

    // Monto guardado en un meta del método. Vacío o inválido vale 0, que es "no configurado".
    private static function monto_de_meta($termino, $clave) {
        return max(0, (float) get_term_meta($termino->term_id, $clave, true));
    }

    // Lo que suma la compra para el envío gratis: productos después de cupones y descuentos, sin
    // el envío. Se le suman los impuestos solo si la tienda muestra los precios con impuestos, para
    // comparar contra el mismo número que el cliente ve en su subtotal.
    private static function total_de_lineas($lineas) {
        $con_impuestos = WC()->cart && WC()->cart->display_prices_including_tax();
        $total         = 0.0;

        foreach ((array) $lineas as $linea) {
            $total += isset($linea['line_total']) ? (float) $linea['line_total'] : 0;

            if ($con_impuestos) {
                $total += isset($linea['line_tax']) ? (float) $linea['line_tax'] : 0;
            }
        }

        return round($total, wc_get_price_decimals());
    }

    private static function supera_minimo_gratis($termino, $lineas) {
        $minimo = self::monto_de_meta($termino, 'gratis_desde');

        return $minimo > 0 && self::total_de_lineas($lineas) >= $minimo;
    }

    // Las mismas líneas que WooCommerce manda en el paquete de envío: los productos virtuales no
    // cuentan para el mínimo, porque tampoco viajan.
    private static function lineas_con_envio() {
        if (!WC()->cart) {
            return [];
        }

        return array_filter(WC()->cart->get_cart(), function ($linea) {
            return isset($linea['data']) && $linea['data'] instanceof WC_Product && $linea['data']->needs_shipping();
        });
    }

    // La fila "Envío" de "Tu pedido" sale como "Tasa mínima BECO: Ref.5,00". El nombre de la tarifa
    // ahí no aporta nada —la fila ya se llama "Envío"— y el cliente pidió que quedara solo el monto.
    //
    // Se recorta el prefijo en lugar de rearmar el precio: así se conservan el formato de moneda del
    // conversor y la aclaración de impuestos que WooCommerce ya calculó. El nombre de la tarifa se
    // toca solo acá, en el checkout; en el admin, los correos y la factura sigue completo. Vaciarlo
    // desde la zona de WooCommerce no es alternativa: deja el método sin nombre y desaparece.
    public static function etiqueta_linea_envio($etiqueta, $tarifa) {
        // La columna de montos de "Tu pedido" es angosta y partía "Envío gratis" en dos renglones.
        // Se envuelve solo este texto: el mensaje de WhatsApp es largo y sí necesita partirse.
        if ($tarifa->get_label() === self::etiqueta_envio_gratis()) {
            return '<span class="wcep-envio-gratis">' . esc_html($etiqueta) . '</span>';
        }

        $termino = self::termino_actual();

        if (!$termino || get_term_meta($termino->term_id, 'ocultar_etiqueta_envio', true) !== '1') {
            return $etiqueta;
        }

        // Sin costo no hay monto que dejar solo, y borrar la etiqueta dejaría la fila vacía. Es el
        // caso del mensaje de WhatsApp, que justamente viaja como etiqueta de una tarifa en 0.
        if ((float) $tarifa->get_cost() <= 0) {
            return $etiqueta;
        }

        $prefijo = $tarifa->get_label() . ': ';

        return strpos($etiqueta, $prefijo) === 0 ? substr($etiqueta, strlen($prefijo)) : $etiqueta;
    }

    // Con qué regla se cerró el pedido. Importa sobre todo cuando es "whatsapp": ese pedido salió
    // con envío en 0 y alguien tiene que llamar al cliente para cuadrar el flete.
    public static function guardar_nivel_tarifa($pedido) {
        $termino = self::termino_actual();

        if (!$termino || get_term_meta($termino->term_id, 'aplica_tarifas_producto', true) !== '1') {
            return;
        }

        // Con envío gratis por monto no queda nada que acordar: si se guardara "whatsapp", el
        // admin recibiría el aviso de llamar al cliente por un flete que ya no se cobra.
        if (self::supera_minimo_gratis($termino, self::lineas_con_envio())) {
            $pedido->update_meta_data(self::META_NIVEL_TARIFA, self::NIVEL_GRATIS_POR_MONTO);
            return;
        }

        $pedido->update_meta_data(self::META_NIVEL_TARIFA, WCEP_Tarifas_Producto::nivel_del_carrito());
    }

    // Solo se avisa del caso que pide una acción. "Estándar" y "especial" ya se ven en el total.
    private static function aviso_de_nivel($pedido) {
        if ($pedido->get_meta(self::META_NIVEL_TARIFA) !== WCEP_Tarifas_Producto::NIVEL_WHATSAPP) {
            return '';
        }

        return __('Envío no cobrado: el monto se acuerda con el cliente por WhatsApp.', 'wc-envios-personalizados');
    }

    public static function mostrar_nivel_en_admin($pedido) {
        $aviso = self::aviso_de_nivel($pedido);

        if ($aviso) {
            echo '<p><strong>' . esc_html__('Delivery', 'wc-envios-personalizados') . ':</strong> ' . esc_html($aviso) . '</p>';
        }
    }

    // Solo en el correo del admin: el cliente ya ve el mensaje en la fila "Envío" de su pedido,
    // porque el texto viajó como etiqueta de la tarifa y quedó guardado en la línea de envío.
    public static function mostrar_nivel_en_email($pedido, $enviado_al_admin = false, $texto_plano = false) {
        $aviso = $enviado_al_admin ? self::aviso_de_nivel($pedido) : '';

        if (!$aviso) {
            return;
        }

        if ($texto_plano) {
            echo "\n" . esc_html($aviso) . "\n";
            return;
        }

        echo '<p style="margin:0 0 16px">' . esc_html($aviso) . '</p>';
    }

    // Sin esto el método elegido sobrevivía al pedido: si el cliente compró con Pickup,
    // su siguiente compra arrancaba con envío gratis hasta que tocara otra tarjeta.
    public static function limpiar_sesion() {
        if (WC()->session) {
            WC()->session->__unset(self::CLAVE_SESION_METODO);
        }
    }
}

<?php
if (!defined('ABSPATH')) exit;

// Reglas de costo del delivery que dependen de QUÉ hay en el carrito.
//
// El precio base sigue saliendo de la zona de WooCommerce. Acá viven las dos excepciones: los
// productos voluminosos (cavas, toldos, tendederos, ventiladores…) pagan un monto fijo mayor, y los
// que no entran en un carro (muebles, máquinas de ejercicio) no se cobran en la web porque el flete
// se acuerda por WhatsApp.
//
// La clasificación es por SKU y se edita desde una pantalla, no producto por producto: el catálogo
// son más de diez mil productos sincronizados desde un sistema externo, y un campo guardado en el
// producto se puede pisar en la próxima sincronización sin que nadie lo note hasta que se cobre mal.
class WCEP_Tarifas_Producto {

    const SLUG_PAGINA = 'wcep-tarifas-delivery';
    const NONCE       = 'wcep_guardar_tarifas';

    const OPCION_SKUS_ESPECIAL    = 'wcep_delivery_skus_especial';
    const OPCION_MONTO_ESPECIAL   = 'wcep_delivery_monto_especial';
    const OPCION_SKUS_WHATSAPP    = 'wcep_delivery_skus_whatsapp';
    const OPCION_MENSAJE_WHATSAPP = 'wcep_delivery_mensaje_whatsapp';

    // Niveles posibles de un carrito. El orden importa: whatsapp gana sobre especial, y especial
    // sobre estandar.
    const NIVEL_ESTANDAR = 'estandar';
    const NIVEL_ESPECIAL = 'especial';
    const NIVEL_WHATSAPP = 'whatsapp';

    const MONTO_ESPECIAL_PREDETERMINADO = 10;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'agregar_pagina']);
    }

    public static function agregar_pagina() {
        add_submenu_page(
            'edit.php?post_type=' . WCEP_Sede_CPT::POST_TYPE,
            __('Tarifas de delivery', 'wc-envios-personalizados'),
            __('Tarifas de delivery', 'wc-envios-personalizados'),
            'manage_woocommerce',
            self::SLUG_PAGINA,
            [__CLASS__, 'render']
        );
    }

    // --- Lectura de la configuración -----------------------------------------------------------

    public static function mensaje_whatsapp_predeterminado() {
        return __('El monto del delivery será acordado vía WhatsApp.', 'wc-envios-personalizados');
    }

    public static function mensaje_whatsapp() {
        $guardado = trim((string) get_option(self::OPCION_MENSAJE_WHATSAPP, ''));

        return $guardado !== '' ? $guardado : self::mensaje_whatsapp_predeterminado();
    }

    public static function monto_especial() {
        $guardado = get_option(self::OPCION_MONTO_ESPECIAL, '');

        return $guardado === '' ? (float) self::MONTO_ESPECIAL_PREDETERMINADO : (float) $guardado;
    }

    // Las listas se guardan como el texto crudo del textarea y se parsean al leer. Guardar el texto
    // y no un array deja que el administrador pegue la lista tal como se la mandaron —con comas,
    // espacios o líneas en blanco— y la vuelva a ver igual la próxima vez que abra la pantalla.
    public static function texto_lista($opcion) {
        return (string) get_option($opcion, '');
    }

    public static function skus($opcion) {
        return self::parsear_lista(self::texto_lista($opcion));
    }

    // Acepta un SKU por línea, y también separados por coma o punto y coma, que es como suelen
    // llegar pegados desde un correo o una hoja de cálculo.
    private static function parsear_lista($texto) {
        $partes = preg_split('/[\r\n,;]+/', (string) $texto);
        $skus   = [];

        foreach ($partes as $parte) {
            $sku = self::normalizar_sku($parte);

            if ($sku !== '' && !in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }

        return $skus;
    }

    // Comparar en mayúsculas y sin espacios evita el fallo más caro de todos: un SKU pegado con un
    // espacio al final que no coincide con nada y hace que el producto se cobre como estándar.
    private static function normalizar_sku($sku) {
        return strtoupper(trim((string) $sku));
    }

    // --- Resolución del nivel ------------------------------------------------------------------

    // El SKU de una variación puede estar vacío: en ese caso el que existe es el del producto
    // padre, que es el que el cliente tendría cargado en su lista. Se miran los dos.
    private static function skus_de_linea($linea) {
        $producto = isset($linea['data']) ? $linea['data'] : null;

        if (!$producto instanceof WC_Product) {
            return [];
        }

        $skus = [self::normalizar_sku($producto->get_sku())];

        $padre_id = $producto->get_parent_id();
        if ($padre_id) {
            $padre = wc_get_product($padre_id);
            if ($padre) {
                $skus[] = self::normalizar_sku($padre->get_sku());
            }
        }

        return array_filter($skus);
    }

    // Nivel que le toca a un conjunto de líneas de carrito.
    //
    // La cantidad no se mira a propósito: la tarifa es por pedido y no por unidad, así que tres
    // cavas pagan lo mismo que una. Es como ya se venía trabajando el delivery.
    public static function nivel_de_lineas($lineas) {
        $skus_whatsapp = self::skus(self::OPCION_SKUS_WHATSAPP);
        $skus_especial = self::skus(self::OPCION_SKUS_ESPECIAL);

        if (!$skus_whatsapp && !$skus_especial) {
            return self::NIVEL_ESTANDAR;
        }

        $nivel = self::NIVEL_ESTANDAR;

        foreach ((array) $lineas as $linea) {
            foreach (self::skus_de_linea($linea) as $sku) {
                // Un solo producto "a convenir" define el pedido completo: no tiene sentido cobrarle
                // el flete de la almohada a quien además se lleva un juego de comedor.
                if (in_array($sku, $skus_whatsapp, true)) {
                    return self::NIVEL_WHATSAPP;
                }

                if (in_array($sku, $skus_especial, true)) {
                    $nivel = self::NIVEL_ESPECIAL;
                }
            }
        }

        return $nivel;
    }

    public static function nivel_del_carrito() {
        return WC()->cart ? self::nivel_de_lineas(WC()->cart->get_cart()) : self::NIVEL_ESTANDAR;
    }

    // --- Aplicación a las tarifas --------------------------------------------------------------

    public static function aplicar_a_tarifas($tarifas_disponibles, $paquete) {
        $nivel = self::nivel_de_lineas(isset($paquete['contents']) ? $paquete['contents'] : []);

        if ($nivel === self::NIVEL_ESTANDAR) {
            return $tarifas_disponibles;
        }

        if ($nivel === self::NIVEL_WHATSAPP) {
            foreach ($tarifas_disponibles as $tarifa) {
                // Costo 0 y el mensaje como etiqueta: en "Tu pedido" la fila Envío pasa a decir la
                // frase en lugar del monto, que es exactamente lo que se pidió. WooCommerce no le
                // agrega precio a una tarifa sin costo, así que no queda un "0,00" colgando.
                self::fijar_costo($tarifa, 0);
                $tarifa->set_label(self::mensaje_whatsapp());
            }

            return $tarifas_disponibles;
        }

        $monto_especial = self::monto_especial();

        foreach ($tarifas_disponibles as $tarifa) {
            // Se cobra el mayor y no el monto especial a secas: si algún día la zona cobrara más que
            // el recargo, bajarle el envío a un producto voluminoso sería justo al revés.
            self::fijar_costo($tarifa, max((float) $tarifa->get_cost(), $monto_especial));
        }

        return $tarifas_disponibles;
    }

    // Cambiar el costo sin tocar los impuestos dejaría calculado el IVA del monto viejo. Se
    // reescalan en la misma proporción, que respeta la configuración de impuestos de la zona sea
    // cual sea.
    public static function fijar_costo($tarifa, $costo_nuevo) {
        $costo_actual = (float) $tarifa->get_cost();
        $impuestos    = $tarifa->get_taxes();

        if ($costo_nuevo <= 0) {
            $tarifa->set_taxes([]);
        } elseif ($costo_actual > 0 && $impuestos) {
            $factor = $costo_nuevo / $costo_actual;

            $tarifa->set_taxes(array_map(function ($impuesto) use ($factor) {
                return (float) $impuesto * $factor;
            }, $impuestos));
        }

        $tarifa->set_cost($costo_nuevo);
    }

    // --- Pantalla de ajustes -------------------------------------------------------------------

    private static function guardar() {
        update_option(self::OPCION_SKUS_ESPECIAL, sanitize_textarea_field(wp_unslash($_POST['skus_especial'] ?? '')));
        update_option(self::OPCION_SKUS_WHATSAPP, sanitize_textarea_field(wp_unslash($_POST['skus_whatsapp'] ?? '')));
        update_option(self::OPCION_MENSAJE_WHATSAPP, sanitize_text_field(wp_unslash($_POST['mensaje_whatsapp'] ?? '')));

        $monto = wc_format_decimal(wp_unslash($_POST['monto_especial'] ?? ''));
        update_option(self::OPCION_MONTO_ESPECIAL, $monto === '' ? '' : max(0, (float) $monto));

        // Las tarifas quedan cacheadas por cliente bajo un hash que incluye el carrito pero no estas
        // opciones. Sin invalidarlo, cargar la lista de SKUs no cambiaría nada en el checkout hasta
        // que cada cliente tocara su carrito.
        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::get_transient_version('shipping', true);
        }
    }

    // Métodos donde estas reglas están activas. Si no hay ninguno, la pantalla no hace nada, y
    // conviene decirlo antes de que alguien cargue 40 SKUs y no vea ningún cambio en el checkout.
    private static function metodos_con_tarifas() {
        $terminos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);

        if (is_wp_error($terminos)) {
            return [];
        }

        $nombres = [];
        foreach ($terminos as $termino) {
            if (get_term_meta($termino->term_id, 'aplica_tarifas_producto', true) === '1') {
                $nombres[] = $termino->name;
            }
        }

        return $nombres;
    }

    public static function render() {
        $guardado = false;

        if (!empty($_POST['wcep_guardar_tarifas']) && check_admin_referer(self::NONCE)) {
            self::guardar();
            $guardado = true;
        }

        $metodos_activos = self::metodos_con_tarifas();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tarifas de delivery por producto', 'wc-envios-personalizados'); ?></h1>

            <p class="description" style="max-width:700px">
                <?php esc_html_e('El precio normal del delivery se configura en WooCommerce → Ajustes → Envío. Acá se cargan solo las excepciones: los productos que pagan un monto fijo mayor y los que no se cobran en la web porque el flete se acuerda por WhatsApp.', 'wc-envios-personalizados'); ?>
            </p>

            <?php if ($guardado) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Tarifas guardadas.', 'wc-envios-personalizados'); ?></p></div>
            <?php endif; ?>

            <?php if (!$metodos_activos) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('Ningún método de envío tiene activadas las tarifas por producto, así que estas listas todavía no se aplican. Marca "Aplica tarifas por producto" en Método de envío → Delivery.', 'wc-envios-personalizados'); ?></p>
                </div>
            <?php else : ?>
                <p>
                    <strong><?php esc_html_e('Se aplican en:', 'wc-envios-personalizados'); ?></strong>
                    <?php echo esc_html(implode(', ', $metodos_activos)); ?>
                </p>
            <?php endif; ?>

            <form method="post" style="max-width:700px">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="wcep_guardar_tarifas" value="1">

                <h2><?php esc_html_e('Productos con tarifa especial', 'wc-envios-personalizados'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wcep-skus-especial"><?php esc_html_e('SKUs', 'wc-envios-personalizados'); ?></label></th>
                        <td>
                            <textarea name="skus_especial" id="wcep-skus-especial" rows="8" class="large-text code"><?php echo esc_textarea(self::texto_lista(self::OPCION_SKUS_ESPECIAL)); ?></textarea>
                            <p class="description"><?php esc_html_e('Un SKU por línea (también valen separados por coma). Ej: cavas, maletas, toldos, sillas y mesas plegables, mesas de planchar, tendederos, cestas y ventiladores.', 'wc-envios-personalizados'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcep-monto-especial"><?php esc_html_e('Monto del envío', 'wc-envios-personalizados'); ?></label></th>
                        <td>
                            <input type="text" name="monto_especial" id="wcep-monto-especial" value="<?php echo esc_attr(get_option(self::OPCION_MONTO_ESPECIAL, self::MONTO_ESPECIAL_PREDETERMINADO)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Se cobra este monto en lugar del de la zona, por pedido y no por unidad. Si la zona cobrara más, se respeta el mayor de los dos.', 'wc-envios-personalizados'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Productos que se acuerdan por WhatsApp', 'wc-envios-personalizados'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wcep-skus-whatsapp"><?php esc_html_e('SKUs', 'wc-envios-personalizados'); ?></label></th>
                        <td>
                            <textarea name="skus_whatsapp" id="wcep-skus-whatsapp" rows="8" class="large-text code"><?php echo esc_textarea(self::texto_lista(self::OPCION_SKUS_WHATSAPP)); ?></textarea>
                            <p class="description"><?php esc_html_e('Ej: muebles y máquinas de hacer ejercicio. Basta con que uno de estos productos esté en el carrito para que el pedido completo no cobre envío y muestre el mensaje.', 'wc-envios-personalizados'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcep-mensaje-whatsapp"><?php esc_html_e('Mensaje', 'wc-envios-personalizados'); ?></label></th>
                        <td>
                            <input type="text" name="mensaje_whatsapp" id="wcep-mensaje-whatsapp" value="<?php echo esc_attr(self::texto_lista(self::OPCION_MENSAJE_WHATSAPP)); ?>" class="large-text" placeholder="<?php echo esc_attr(self::mensaje_whatsapp_predeterminado()); ?>">
                            <p class="description"><?php esc_html_e('Reemplaza al monto en la línea "Envío" del resumen del pedido. Vacío, se usa el texto de ejemplo.', 'wc-envios-personalizados'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

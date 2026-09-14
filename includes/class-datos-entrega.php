<?php
if (!defined('ABSPATH')) exit;

// Bloque "Datos de entrega": los 4 campos que aparecen bajo las tarjetas cuando el método
// pide dirección (Delivery). No son campos inventados: se apoyan en los shipping_* nativos de
// WooCommerce para que el pedido tenga una dirección de envío de verdad — así sale en el admin,
// en los emails, en las facturas PDF y en cualquier plugin de etiquetas.
//
// El bloque de facturación NO se toca: ahí sigue viviendo la dirección fiscal del titular.
class WCEP_Datos_Entrega {

    // Clase de los campos que el JS mueve dentro de la tarjeta de envío.
    const CLASE_BLOQUE = 'wcep-campo-entrega';

    // Clase de los campos que WooCommerce necesita pero que nunca se le piden al cliente.
    const CLASE_OCULTO = 'wcep-campo-oculto';

    // Campos visibles, en el orden del diseño.
    const CAMPOS_VISIBLES = [
        'shipping_address_1',
        'shipping_address_2',
        'shipping_first_name',
        'shipping_phone',
    ];

    // Completan la dirección para zonas de envío, impuestos y factura. Se copian de facturación
    // porque Delivery es una sola ciudad: pedirlos otra vez sería redundante.
    const CAMPOS_HEREDADOS = [
        'shipping_country',
        'shipping_state',
        'shipping_city',
        'shipping_postcode',
    ];

    // Se pone en true si la plantilla llegó a pintar el bloque de envío nativo, para no duplicarlo.
    private static $campos_ya_renderizados = false;

    public static function init() {
        // Sin esto WooCommerce ignora los shipping_* y copia la dirección de facturación.
        add_filter('woocommerce_ship_to_different_address_checked', '__return_true');
        add_filter('woocommerce_checkout_posted_data', [__CLASS__, 'forzar_envio_separado']);

        add_filter('woocommerce_checkout_fields', [__CLASS__, 'configurar_campos'], 20);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'completar_direccion'], 5);
        add_action('wp_footer', [__CLASS__, 'render_campos'], 5);
        add_action('woocommerce_checkout_shipping', [__CLASS__, 'marcar_campos_renderizados'], 1);
        add_filter('woocommerce_order_needs_shipping_address', [__CLASS__, 'mostrar_direccion_de_sede'], 10, 3);
    }

    // WooCommerce oculta la dirección de envío en los pedidos de tipo "local_pickup", asumiendo
    // que si el cliente retira no hay a dónde enviar. Nuestras tarifas de Pickup y Zoom se declaran
    // así (hace falta para que las pasarelas restringidas por método las reconozcan), de modo que
    // la sede desaparecía del email y de la factura justo en los pedidos donde más importa saberla.
    //
    // Se fuerza solo en los pedidos que tienen sede: el resto conserva el comportamiento de WooCommerce.
    public static function mostrar_direccion_de_sede($necesita_direccion, $metodos_ocultos, $pedido) {
        if ($pedido && $pedido->get_meta('_wcep_sede_id')) {
            return true;
        }

        return $necesita_direccion;
    }

    // Las plantillas de JetWooBuilder/Elementor arman el checkout con widgets sueltos y muchas
    // no incluyen el de envío, así que los shipping_* nunca se pintan. Aquí se renderizan en un
    // contenedor oculto y el JS los traslada dentro del bloque "Datos de entrega", que vive
    // dentro de form.checkout. Sin esto el bloque quedaría siempre vacío.
    public static function render_campos() {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        // Si la plantilla ya los pintó, no se duplican.
        if (self::$campos_ya_renderizados) {
            return;
        }

        $checkout = WC()->checkout();
        $campos   = $checkout->get_checkout_fields('shipping');

        if (empty($campos)) {
            return;
        }

        echo '<div id="wcep-campos-origen" hidden>';
        foreach ($campos as $clave_campo => $campo) {
            woocommerce_form_field($clave_campo, $campo, $checkout->get_value($clave_campo));
        }
        echo '</div>';
    }

    public static function marcar_campos_renderizados() {
        self::$campos_ya_renderizados = true;
    }

    // Red de seguridad: si el tema quitó la casilla "Enviar a una dirección diferente",
    // el POST no la trae y WooCommerce descartaría todo lo que escribió el cliente.
    public static function forzar_envio_separado($datos_enviados) {
        $datos_enviados['ship_to_different_address'] = true;
        return $datos_enviados;
    }

    // El método elegido en esta petición. Se prefiere el POST (más fresco durante la validación)
    // y se cae a la sesión, que es lo único disponible al renderizar la página.
    private static function metodo_actual() {
        if (!empty($_POST['wcep_metodo_envio'])) {
            return sanitize_text_field(wp_unslash($_POST['wcep_metodo_envio']));
        }

        return WC()->session ? (string) WC()->session->get(WCEP_Shipping::CLAVE_SESION_METODO) : '';
    }

    // ¿El método pide dirección? Ante la duda se responde que sí, para no relajar
    // validaciones por accidente cuando todavía no hay nada elegido.
    public static function metodo_pide_direccion($metodo_slug) {
        if (!$metodo_slug) {
            return true;
        }

        $termino = get_term_by('slug', $metodo_slug, 'metodo_envio');
        if (!$termino) {
            return true;
        }

        return get_term_meta($termino->term_id, 'muestra_datos_entrega', true) === '1';
    }

    // Etiquetas y placeholders del diseño, más si el campo es obligatorio.
    private static function definicion_visible() {
        return [
            'shipping_address_1' => [
                'label'       => __('Dirección', 'wc-envios-personalizados'),
                'placeholder' => __('Municipio, avenida, calle, edificio', 'wc-envios-personalizados'),
                'required'    => true,
            ],
            'shipping_address_2' => [
                'label'       => '', // el diseño no muestra etiqueta en este campo
                'placeholder' => __('Punto de referencia', 'wc-envios-personalizados'),
                'required'    => false,
            ],
            'shipping_first_name' => [
                'label'       => __('Nombre del que recibe', 'wc-envios-personalizados'),
                'placeholder' => '',
                'required'    => true,
            ],
            'shipping_phone' => [
                'label'       => __('Teléfono de la persona que recibe', 'wc-envios-personalizados'),
                'placeholder' => '',
                'required'    => true,
            ],
        ];
    }

    public static function configurar_campos($campos) {
        if (!isset($campos['shipping'])) {
            return $campos;
        }

        // Al pintar la página los campos se marcan SIEMPRE como obligatorios: se renderizan una sola
        // vez y el JS solo muestra u oculta el bloque, así que las etiquetas no se regeneran al
        // cambiar de tarjeta. Si se pintaran según el método inicial, con Pickup activo saldrían como
        // "(opcional)" y seguirían diciéndolo tras elegir Delivery.
        // La condición real se aplica al validar, que es cuando el POST ya trae el método elegido.
        $es_envio_del_formulario = isset($_POST['wcep_metodo_envio']);
        $pide_direccion          = $es_envio_del_formulario
            ? self::metodo_pide_direccion(self::metodo_actual())
            : true;

        // shipping_phone existe desde WooCommerce 8.0. En versiones anteriores se define aquí.
        if (!isset($campos['shipping']['shipping_phone'])) {
            $campos['shipping']['shipping_phone'] = [
                'type'         => 'tel',
                'validate'     => ['phone'],
                'autocomplete' => 'tel',
            ];
        }

        $campos_finales = [];
        $prioridad      = 10;

        foreach (self::definicion_visible() as $clave_campo => $definicion) {
            $campo = $campos['shipping'][$clave_campo] ?? [];

            $campo['label']       = $definicion['label'];
            $campo['placeholder'] = $definicion['placeholder'];
            $campo['priority']    = $prioridad;
            $campo['class']       = [self::CLASE_BLOQUE];

            // Solo son obligatorios cuando el bloque está a la vista. Si no, WooCommerce
            // rechazaría el pedido por un campo que el cliente nunca vio.
            $campo['required'] = $definicion['required'] && $pide_direccion;

            $campos_finales[$clave_campo] = $campo;
            $prioridad += 10;
        }

        foreach (self::CAMPOS_HEREDADOS as $clave_campo) {
            if (!isset($campos['shipping'][$clave_campo])) {
                continue;
            }

            $campo             = $campos['shipping'][$clave_campo];
            $campo['required'] = false;
            $campo['class']    = [self::CLASE_OCULTO];
            $campo['priority'] = $prioridad;

            $campos_finales[$clave_campo] = $campo;
            $prioridad += 10;
        }

        // Reemplazar el grupo completo descarta shipping_last_name y shipping_company,
        // que el diseño no pide. El nombre completo va entero en "Nombre del que recibe".
        $campos['shipping'] = $campos_finales;

        return $campos;
    }

    // Rellena lo que no se le pidió al cliente, para que la dirección de envío quede completa.
    public static function completar_direccion($pedido) {
        $metodo_slug = self::metodo_actual();

        self::completar_ciudad_facturacion($pedido);

        if (self::metodo_pide_direccion($metodo_slug)) {
            self::heredar_partes_faltantes($pedido, true);
            return;
        }

        $sede_id = isset($_POST['wcep_sede_id']) ? absint($_POST['wcep_sede_id']) : 0;

        if ($sede_id && WCEP_Checkout::sede_valida($sede_id, $metodo_slug)) {
            self::escribir_direccion_de_sede($pedido, $sede_id);
            // Solo el país se hereda: estado, ciudad y CP de la sede no tienen nada que ver
            // con los del cliente y ya se escribieron (o se dejaron vacíos) arriba.
            self::heredar_partes_faltantes($pedido, false);
            return;
        }

        // Método sin sede y sin dirección propia: la de envío replica la de facturación.
        self::copiar_datos_de_facturacion($pedido);
        self::heredar_partes_faltantes($pedido, true);
    }

    // Hay plantillas que desactivan el billing_city estándar y ponen un campo propio en su lugar
    // (aquí, billing_city2). WooCommerce no lo reconoce, así que el pedido se guarda sin ciudad:
    // la dirección sale incompleta en el admin, en el email, en la factura PDF y en las etiquetas.
    // Si el estándar viene vacío, se rellena con el primero de los campos alternativos que traiga
    // datos. No toca la configuración de campos del sitio, solo evita perder el dato.
    private static function completar_ciudad_facturacion($pedido) {
        if ($pedido->get_billing_city()) {
            return;
        }

        $campos_alternativos = apply_filters('wcep_campos_ciudad_alternativos', ['billing_city2']);

        foreach ($campos_alternativos as $clave_campo) {
            if (!empty($_POST[$clave_campo])) {
                $pedido->set_billing_city(sanitize_text_field(wp_unslash($_POST[$clave_campo])));
                return;
            }
        }
    }

    // En Pickup y Zoom el paquete NO va a casa del cliente: va a la sede. Escribirla como dirección
    // de envío del pedido es lo que hace que aparezca sola en el admin, en el email de confirmación,
    // en la factura PDF y en cualquier plugin de etiquetas. Sin esto la factura mostraría la
    // dirección particular del cliente y el depósito podría despachar al sitio equivocado.
    private static function escribir_direccion_de_sede($pedido, $sede_id) {
        // Quien retira sigue siendo el cliente; lo que cambia es el destino.
        $pedido->set_shipping_first_name($pedido->get_billing_first_name());
        $pedido->set_shipping_last_name($pedido->get_billing_last_name());
        self::asignar_telefono_envio($pedido, $pedido->get_billing_phone());

        $pedido->set_shipping_company(get_the_title($sede_id));
        $pedido->set_shipping_address_1((string) get_post_meta($sede_id, 'direccion_completa', true));
        $pedido->set_shipping_address_2('');
        $pedido->set_shipping_city((string) get_post_meta($sede_id, 'ciudad', true));

        // Estado y código postal se limpian a propósito. Vienen prellenados con los del cliente
        // (WooCommerce los arrastra de su sesión) y de la sede no los tenemos: el listado de Zoom
        // no los trae. Dejarlos produciría direcciones falsas pero creíbles —"Barinas, Distrito
        // Capital, 1060"— que es peor que un dato faltante: nadie las cuestiona y enrutan mal.
        $pedido->set_shipping_state('');
        $pedido->set_shipping_postcode('');
    }

    private static function copiar_datos_de_facturacion($pedido) {
        $pedido->set_shipping_first_name($pedido->get_billing_first_name());
        $pedido->set_shipping_last_name($pedido->get_billing_last_name());
        $pedido->set_shipping_address_1($pedido->get_billing_address_1());
        $pedido->set_shipping_address_2($pedido->get_billing_address_2());
        self::asignar_telefono_envio($pedido, $pedido->get_billing_phone());
    }

    // set_shipping_phone() existe desde WooCommerce 8.0.
    private static function asignar_telefono_envio($pedido, $telefono) {
        if (method_exists($pedido, 'set_shipping_phone')) {
            $pedido->set_shipping_phone($telefono);
        }
    }

    // País, estado, ciudad y código postal nunca se piden: se completan desde facturación,
    // pero sin pisar lo que ya se haya escrito. Con una dirección de sede solo se hereda el país:
    // el resto pertenece a la sede, no al cliente.
    private static function heredar_partes_faltantes($pedido, $incluir_ubicacion_del_cliente) {
        $heredables = ['country' => 'get_billing_country'];

        if ($incluir_ubicacion_del_cliente) {
            $heredables['state']    = 'get_billing_state';
            $heredables['city']     = 'get_billing_city';
            $heredables['postcode'] = 'get_billing_postcode';
        }

        foreach ($heredables as $parte => $metodo_billing) {
            $getter = 'get_shipping_' . $parte;
            $setter = 'set_shipping_' . $parte;

            if (!$pedido->$getter()) {
                $pedido->$setter($pedido->$metodo_billing());
            }
        }
    }
}

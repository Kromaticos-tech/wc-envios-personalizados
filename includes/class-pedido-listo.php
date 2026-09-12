<?php
if (!defined('ABSPATH')) exit;

// Estado de pedido "Pedido listo" y el correo que avisa al cliente que ya puede pasar a buscarlo.
//
// El flujo real de la tienda tiene un paso que WooCommerce no trae. Entre "Procesando" (se cobró y
// se está preparando) y "Completado" (el cliente ya se lo llevó) está el rato —a veces días— en que
// el paquete espera en la sucursal. Sin un estado propio, la única forma de disparar el aviso era
// marcar "Completado", que cierra el pedido mientras la caja todavía lo tiene en el estante: nadie
// sabe después qué se entregó y qué sigue esperando.
class WCEP_Pedido_Listo {

    // Slug del estado. WooCommerce lo maneja sin prefijo en los hooks y con "wc-" en la base de
    // datos, así que se guardan las dos formas para no andar concatenando en cada uso.
    const ESTADO          = 'pedido-listo';
    const ESTADO_CON_WC   = 'wc-pedido-listo';

    // Id del correo dentro de WooCommerce → Ajustes → Correos electrónicos. De él sale el nombre de
    // la opción donde se guardan asunto y encabezado (woocommerce_wcep_pedido_listo_settings).
    const ID_EMAIL = 'wcep_pedido_listo';

    // Term meta del método de envío que habilita el aviso. Es una casilla y no una comparación
    // contra el slug "pickup" por lo mismo que en las tarifas por producto: hoy el aviso es solo de
    // Pickup, pero el día que Zoom avise que el paquete llegó a la agencia se resuelve con un clic.
    const META_AVISO = 'avisa_pedido_listo';

    public static function init() {
        add_action('init', [__CLASS__, 'registrar_estado']);
        add_filter('wc_order_statuses', [__CLASS__, 'agregar_al_desplegable']);
        add_filter('woocommerce_order_is_paid_statuses', [__CLASS__, 'contar_como_pagado']);

        // La lista de pedidos vive en una pantalla u otra según el sitio tenga activado el
        // almacenamiento de pedidos en tablas propias (HPOS) o siga con los posts. Se cuelga de las
        // dos: la que no exista simplemente no dispara.
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'agregar_accion_masiva']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'agregar_accion_masiva']);

        add_filter('woocommerce_email_classes', [__CLASS__, 'registrar_email']);
        add_filter('woocommerce_email_actions', [__CLASS__, 'registrar_disparador']);

        // "El correo no me llegó" es una llamada de todos los días. Con esto el aviso aparece en
        // "Acciones del pedido" y se reenvía sin tener que sacar y volver a poner el estado.
        add_filter('woocommerce_resend_order_emails_available', [__CLASS__, 'permitir_reenvio']);
    }

    public static function etiqueta() {
        return _x('Pedido listo', 'Estado del pedido', 'wc-envios-personalizados');
    }

    // Registrar el post status es lo que hace que el estado aparezca como pestaña con contador
    // arriba de la lista de pedidos y que WordPress no lo trate como un valor inválido.
    public static function registrar_estado() {
        register_post_status(self::ESTADO_CON_WC, [
            'label'                     => self::etiqueta(),
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: cantidad de pedidos en el estado. */
            'label_count'               => _n_noop(
                'Pedido listo <span class="count">(%s)</span>',
                'Pedidos listos <span class="count">(%s)</span>',
                'wc-envios-personalizados'
            ),
        ]);
    }

    // El desplegable "Estado" de la ficha del pedido se arma con este filtro. Se inserta justo
    // después de "Procesando" en vez de al final porque ese es el orden en que ocurren las cosas, y
    // así queda pegado al estado desde el que se marca.
    public static function agregar_al_desplegable($estados) {
        $ordenados = [];

        foreach ($estados as $clave => $etiqueta) {
            $ordenados[$clave] = $etiqueta;

            if ($clave === 'wc-processing') {
                $ordenados[self::ESTADO_CON_WC] = self::etiqueta();
            }
        }

        // Si otro plugin quitó "Procesando" de la lista, el estado tiene que aparecer igual: sin
        // esto desaparecería del desplegable y no habría forma de marcar un pedido como listo.
        if (!isset($ordenados[self::ESTADO_CON_WC])) {
            $ordenados[self::ESTADO_CON_WC] = self::etiqueta();
        }

        return $ordenados;
    }

    // Un pedido llega a "listo" después de haberse cobrado. Sin esto WooCommerce deja de
    // considerarlo pagado mientras espera en la sucursal: sale de los informes de ventas y
    // cualquier código que pregunte $pedido->is_paid() responde que no.
    public static function contar_como_pagado($estados) {
        $estados[] = self::ESTADO;

        return $estados;
    }

    // Marcar de a uno desde la ficha sirve para un pedido; el mostrador cierra el día con varios.
    // WooCommerce resuelve solo cualquier acción "mark_<estado>" que exista en wc_get_order_statuses(),
    // así que alcanza con ofrecerla: el cambio de estado y el correo salen por el mismo camino.
    public static function agregar_accion_masiva($acciones) {
        $acciones['mark_' . self::ESTADO] = __('Marcar como pedido listo', 'wc-envios-personalizados');

        return $acciones;
    }

    // WooCommerce solo convierte en correo los hooks que estén en esta lista: recorre cada uno y le
    // engancha su despachador, que a su vez dispara "<hook>_notification", que es donde escucha la
    // clase del correo. Sin registrarlo acá el estado cambia y no se envía nada.
    public static function registrar_disparador($acciones) {
        $acciones[] = 'woocommerce_order_status_' . self::ESTADO;

        return $acciones;
    }

    // Métodos que hoy tienen el aviso activo, por nombre. Lo usa la pantalla de diagnóstico: cuando
    // alguien reporta "marqué el pedido como listo y el correo no llegó", la causa casi siempre es
    // esta casilla sin marcar o el correo desactivado en los ajustes de WooCommerce.
    public static function metodos_que_avisan() {
        $terminos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);

        if (is_wp_error($terminos)) {
            return [];
        }

        $nombres = [];
        foreach ($terminos as $termino) {
            if (get_term_meta($termino->term_id, self::META_AVISO, true) === '1') {
                $nombres[] = $termino->name;
            }
        }

        return $nombres;
    }

    // Mientras nadie guarde la pantalla de ajustes del correo no hay opción guardada, y ahí manda el
    // valor por defecto de WooCommerce, que es "activado".
    public static function correo_activo() {
        $ajustes = get_option('woocommerce_' . self::ID_EMAIL . '_settings', []);

        return !isset($ajustes['enabled']) || $ajustes['enabled'] === 'yes';
    }

    public static function permitir_reenvio($ids_correos) {
        $ids_correos[] = self::ID_EMAIL;

        return $ids_correos;
    }

    // La clase del correo se carga acá y no en el bootstrap a propósito: extiende WC_Email, que
    // WooCommerce recién define al inicializar sus correos. Requerirla antes es un error fatal.
    public static function registrar_email($correos) {
        require_once WCEP_PATH . 'includes/class-email-pedido-listo.php';

        $correos[self::ID_EMAIL] = new WCEP_Email_Pedido_Listo();

        return $correos;
    }

    // ¿El método con el que se compró avisa cuando el pedido está listo? Hoy solo Pickup: el
    // cliente pasa por el mostrador, así que el aviso es la única forma de saber que ya puede ir.
    // En Delivery el pedido sale hacia la casa y en Zoom lo mueve el transportista, que tiene su
    // propio rastreo.
    public static function metodo_avisa($pedido) {
        $metodo_slug = $pedido->get_meta('_wcep_metodo_envio');

        if (!$metodo_slug) {
            return false;
        }

        $termino = get_term_by('slug', $metodo_slug, 'metodo_envio');

        return $termino && get_term_meta($termino->term_id, self::META_AVISO, true) === '1';
    }

    // Sede a nombrar en el aviso, o null si a este pedido no le toca aviso. Las dos condiciones van
    // juntas porque el correo no se sostiene sin ninguna de las dos: el método tiene que avisar y
    // tiene que haber una sede a la que mandar al cliente.
    public static function sede_del_aviso($pedido) {
        return self::metodo_avisa($pedido) ? self::datos_sede($pedido) : null;
    }

    // Datos de la sede que el cliente eligió en el checkout, o null si el pedido no tiene ninguna
    // (Delivery) o si la sede se borró después de la compra.
    //
    // Se leen del CPT y no de una copia congelada en el pedido a propósito: entre la compra y el
    // aviso pueden pasar días, y lo que el cliente necesita es la dirección y el teléfono de hoy,
    // no los de la semana pasada.
    public static function datos_sede($pedido) {
        $sede_id = (int) $pedido->get_meta('_wcep_sede_id');

        if (!$sede_id || !get_post($sede_id)) {
            return null;
        }

        return [
            'nombre'    => get_the_title($sede_id),
            'direccion' => (string) get_post_meta($sede_id, 'direccion_completa', true),
            'ciudad'    => (string) get_post_meta($sede_id, 'ciudad', true),
            'telefono'  => (string) get_post_meta($sede_id, 'telefono', true),
            'horarios'  => (string) get_post_meta($sede_id, 'horarios', true),
        ];
    }
}

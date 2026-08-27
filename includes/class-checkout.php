<?php
if (!defined('ABSPATH')) exit;

// Encola los assets del checkout, expone los métodos de envío al JS, valida la selección
// antes de crear el pedido y la guarda/muestra en el admin.
class WCEP_Checkout {

    // Extensiones aceptadas para las imágenes empaquetadas, en orden de preferencia.
    const ICONO_EXTENSIONES = ['svg', 'png', 'webp', 'jpg', 'jpeg'];

    // Carpeta (relativa al plugin) donde viven las imágenes empaquetadas.
    const CARPETA_ICONOS = 'assets/icons/';

    // Si ningún término está marcado como predeterminado, se intenta este slug antes de caer al primero.
    const SLUG_PREFERIDO_POR_DEFECTO = 'pickup';

    // Shortcode para colocar el selector donde el usuario quiera dentro de la plantilla del checkout.
    const SHORTCODE = 'wcep_envio';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode_selector']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('woocommerce_checkout_process', [__CLASS__, 'validar_seleccion']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'guardar_seleccion']);
        add_action('woocommerce_admin_order_data_after_shipping_address', [__CLASS__, 'mostrar_en_admin']);
    }

    // Devuelve el nombre del archivo que exista para un nombre base (ej. "pickup-activo" -> "pickup-activo.svg"),
    // o '' si no hay ninguno. Se prueba extensión por extensión para no obligar a que sea SVG.
    public static function archivo_icono($nombre_base) {
        foreach (self::ICONO_EXTENSIONES as $extension) {
            $nombre_archivo = $nombre_base . '.' . $extension;
            if (file_exists(WCEP_PATH . self::CARPETA_ICONOS . $nombre_archivo)) {
                return $nombre_archivo;
            }
        }
        return '';
    }

    // URL de una imagen empaquetada, o '' si no existe. Se usa solo cuando el término no trae URL propia.
    private static function url_empaquetada($nombre_base) {
        $nombre_archivo = self::archivo_icono($nombre_base);
        return $nombre_archivo ? WCEP_URL . self::CARPETA_ICONOS . $nombre_archivo : '';
    }

    // Métodos ordenados por el campo "orden" del término; a igual orden, alfabéticamente.
    // get_terms() por sí solo ordena por nombre, que no es el orden del diseño.
    public static function metodos_ordenados() {
        $terminos_metodo = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);

        if (is_wp_error($terminos_metodo)) {
            return [];
        }

        usort($terminos_metodo, function ($termino_a, $termino_b) {
            $orden_a = (int) get_term_meta($termino_a->term_id, 'orden', true);
            $orden_b = (int) get_term_meta($termino_b->term_id, 'orden', true);

            return $orden_a === $orden_b
                ? strcasecmp($termino_a->name, $termino_b->name)
                : $orden_a - $orden_b;
        });

        return $terminos_metodo;
    }

    // Slug de la tarjeta que arranca marcada: el término marcado como predeterminado,
    // si no hay ninguno el slug preferido (pickup), y si tampoco existe el primero de la lista.
    public static function slug_predeterminado(array $terminos_metodo) {
        foreach ($terminos_metodo as $termino) {
            if (get_term_meta($termino->term_id, 'predeterminado', true) === '1') {
                return $termino->slug;
            }
        }

        foreach ($terminos_metodo as $termino) {
            if ($termino->slug === self::SLUG_PREFERIDO_POR_DEFECTO) {
                return $termino->slug;
            }
        }

        return $terminos_metodo ? $terminos_metodo[0]->slug : '';
    }

    // Arma el paquete de datos que consume checkout.js para pintar una tarjeta y su detalle.
    private static function datos_metodo($termino) {
        $valores = WCEP_Taxonomy::leer_metas($termino->term_id);
        $slug    = $termino->slug;

        return [
            'slug'                   => $slug,
            'nombre'                 => $termino->name,
            'descripcion'            => $valores['descripcion'],
            'icono'                  => $valores['icono'] ?: self::url_empaquetada($slug),
            'iconoActivo'            => $valores['icono_activo'] ?: self::url_empaquetada($slug . '-activo'),
            'ilustracion'            => $valores['ilustracion'] ?: self::url_empaquetada('ilustracion-' . $slug),
            'requiere_ciudad'        => $valores['requiere_ciudad'] === '1',
            'requiere_sede'          => $valores['requiere_sede'] === '1',
            'muestra_datos_entrega'  => $valores['muestra_datos_entrega'] === '1',
            'placeholder_sede'       => $valores['placeholder_sede'],
            'estilo_aviso'           => $valores['estilo_aviso'],
            'aviso_titulo'           => $valores['aviso_titulo'],
            'aviso_direccion'        => $valores['aviso_direccion'],
            'mostrar_resumen'        => $valores['mostrar_resumen'] === '1',
        ];
    }

    // Punto de montaje vacío: el JS construye el selector dentro. Con constructores de página
    // (Elementor/JetWooBuilder) es la única forma fiable de colocarlo, porque la estructura de
    // filas y columnas varía de una plantilla a otra y adivinarla desde JS rompe el layout.
    //
    // Por defecto el plugin pinta la tarjeta (fondo, borde y sombra). Con [wcep_envio tarjeta="no"]
    // no la pinta, para cuando el contenedor del constructor ya aporta ese estilo.
    public static function shortcode_selector($atributos = []) {
        $atributos = shortcode_atts(['tarjeta' => 'si'], $atributos, self::SHORTCODE);

        $clases = 'wcep-punto-montaje';
        if ($atributos['tarjeta'] === 'no') {
            $clases .= ' wcep-sin-tarjeta';
        }

        return '<div class="' . esc_attr($clases) . '"></div>';
    }

    public static function assets() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        wp_enqueue_style('wcep-checkout', WCEP_URL . 'assets/css/checkout.css', [], WCEP_VERSION);

        wp_enqueue_script('wcep-checkout', WCEP_URL . 'assets/js/checkout.js', ['jquery'], WCEP_VERSION, true);

        $terminos_metodo = self::metodos_ordenados();
        $metodos_para_js = array_map([__CLASS__, 'datos_metodo'], $terminos_metodo);

        wp_localize_script('wcep-checkout', 'WCEP', [
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('wcep_nonce'),
            'metodos'            => $metodos_para_js,
            'slugPredeterminado' => self::slug_predeterminado($terminos_metodo),
            'iconoSedeAvatar'    => self::url_empaquetada('sede-avatar'),
            'claseCampoEntrega'  => WCEP_Datos_Entrega::CLASE_BLOQUE,
            'textos'             => [
                'seleccionaCiudad' => __('Selecciona tu ciudad', 'wc-envios-personalizados'),
                'seleccionaSede'   => __('Selecciona sede', 'wc-envios-personalizados'),
                'sinSedes'         => __('No hay sedes disponibles', 'wc-envios-personalizados'),
                'sinResultados'    => __('Sin resultados', 'wc-envios-personalizados'),
                'costoAprox'       => __('Costo aproximado de envío:', 'wc-envios-personalizados'),
                'tituloSelector'   => __('Selecciona el tipo de envío', 'wc-envios-personalizados'),
                'tituloTienda'     => __('Selecciona tienda', 'wc-envios-personalizados'),
                'tituloEntrega'    => __('Datos de entrega', 'wc-envios-personalizados'),
                'campoObligatorio' => __('(Campo obligatorio)', 'wc-envios-personalizados'),
            ],
        ]);
    }

    // Una sede solo es aceptable si existe, es del CPT correcto, está activa y pertenece al método elegido.
    // Sin esto se podía forzar cualquier ID por POST.
    public static function sede_valida($sede_id, $metodo_slug) {
        $post_type = WCEP_Sede_CPT::post_type_de_metodo($metodo_slug);

        if (!$sede_id || get_post_type($sede_id) !== $post_type) {
            return false;
        }
        if (get_post_meta($sede_id, 'activo', true) !== '1') {
            return false;
        }

        // Igual que en la consulta: la etiqueta solo se exige si el post type es compartido.
        if (WCEP_Sede_CPT::post_type_compartido($post_type)) {
            return has_term($metodo_slug, 'metodo_envio', $sede_id);
        }

        return true;
    }

    // Corre antes de crear el pedido: si algo falta o es inválido, WooCommerce muestra el error
    // y no deja completar la compra.
    public static function validar_seleccion() {
        $metodo_slug = isset($_POST['wcep_metodo_envio']) ? sanitize_text_field(wp_unslash($_POST['wcep_metodo_envio'])) : '';

        if (!$metodo_slug) {
            wc_add_notice(__('Selecciona un tipo de envío para continuar.', 'wc-envios-personalizados'), 'error');
            return;
        }

        $termino = get_term_by('slug', $metodo_slug, 'metodo_envio');
        if (!$termino) {
            wc_add_notice(__('El tipo de envío seleccionado ya no está disponible.', 'wc-envios-personalizados'), 'error');
            return;
        }

        if (get_term_meta($termino->term_id, 'requiere_sede', true) !== '1') {
            return; // Delivery y similares no eligen sede.
        }

        $sede_id = isset($_POST['wcep_sede_id']) ? absint($_POST['wcep_sede_id']) : 0;

        if (!$sede_id) {
            wc_add_notice(
                // translators: %s es el nombre del método de envío, ej. "Pickup".
                sprintf(__('Selecciona una sede para %s.', 'wc-envios-personalizados'), $termino->name),
                'error'
            );
            return;
        }

        if (!self::sede_valida($sede_id, $metodo_slug)) {
            wc_add_notice(__('La sede seleccionada ya no está disponible. Elige otra.', 'wc-envios-personalizados'), 'error');
        }
    }

    public static function guardar_seleccion($pedido) {
        $metodo_slug = isset($_POST['wcep_metodo_envio']) ? sanitize_text_field(wp_unslash($_POST['wcep_metodo_envio'])) : '';

        if (!$metodo_slug) {
            return;
        }

        $pedido->update_meta_data('_wcep_metodo_envio', $metodo_slug);

        $sede_id = isset($_POST['wcep_sede_id']) ? absint($_POST['wcep_sede_id']) : 0;

        // Se revalida en lugar de confiar en el POST: validar_seleccion() ya cortó los casos malos,
        // pero esto evita persistir basura si otro plugin salta la validación.
        if (self::sede_valida($sede_id, $metodo_slug)) {
            $pedido->update_meta_data('_wcep_sede_id', $sede_id);
        }
    }

    public static function mostrar_en_admin($pedido) {
        $metodo_slug = $pedido->get_meta('_wcep_metodo_envio');
        $sede_id     = $pedido->get_meta('_wcep_sede_id');

        if (!$metodo_slug) {
            return;
        }

        $termino       = get_term_by('slug', $metodo_slug, 'metodo_envio');
        $nombre_metodo = $termino ? $termino->name : $metodo_slug;

        echo '<p><strong>' . esc_html__('Método de envío elegido', 'wc-envios-personalizados') . ':</strong> ' . esc_html($nombre_metodo) . '</p>';

        if ($sede_id) {
            echo '<p><strong>' . esc_html__('Sede', 'wc-envios-personalizados') . ':</strong> ' . esc_html(get_the_title($sede_id)) . '</p>';
            $direccion = get_post_meta($sede_id, 'direccion_completa', true);
            if ($direccion) {
                echo '<p>' . esc_html($direccion) . '</p>';
            }
        }
    }
}

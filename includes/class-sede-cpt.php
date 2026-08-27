<?php
if (!defined('ABSPATH')) exit;

// CPT propio para las sedes de terceros (Zoom hoy, otros transportistas mañana).
//
// Existe para no mezclarlas con el CPT "tiendas" del cliente: ese lo usan otras partes del sitio
// y tiene ~20 registros propios. Volcarle 377 oficinas ajenas rompería los listados existentes y
// dejaría el admin inservible para el equipo que administra las tiendas de verdad.
//
// Es un solo CPT para todos los transportistas, no uno por cada uno: se diferencian con la
// taxonomía "metodo_envio", que ya existe.
class WCEP_Sede_CPT {

    const POST_TYPE = 'wcep_sede';

    // Clave del filtro de ciudad en la lista del admin.
    const PARAM_CIUDAD = 'wcep_ciudad';

    public static function init() {
        // Prioridad 15: después de que JetEngine registre "tiendas" (init normal) y antes de que
        // la taxonomía se enganche a ambos post types en prioridad 20.
        add_action('init', [__CLASS__, 'register'], 15);

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [__CLASS__, 'columnas']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [__CLASS__, 'contenido_columna'], 10, 2);
        add_action('restrict_manage_posts', [__CLASS__, 'filtro_ciudad']);
        add_filter('parse_query', [__CLASS__, 'aplicar_filtro_ciudad']);
        add_filter('bulk_actions-edit-' . self::POST_TYPE, [__CLASS__, 'acciones_lote']);
        add_filter('handle_bulk_actions-edit-' . self::POST_TYPE, [__CLASS__, 'ejecutar_accion_lote'], 10, 3);
        add_action('admin_notices', [__CLASS__, 'aviso_accion_lote']);
    }

    public static function register() {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Sedes de envío', 'wc-envios-personalizados'),
                'singular_name'      => __('Sede de envío', 'wc-envios-personalizados'),
                'add_new_item'       => __('Añadir sede de envío', 'wc-envios-personalizados'),
                'edit_item'          => __('Editar sede de envío', 'wc-envios-personalizados'),
                'search_items'       => __('Buscar sedes', 'wc-envios-personalizados'),
                'not_found'          => __('No hay sedes todavía.', 'wc-envios-personalizados'),
                'menu_name'          => __('Sedes de envío', 'wc-envios-personalizados'),
            ],
            // No son páginas públicas: solo alimentan el selector del checkout.
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'has_archive'         => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 56,
            'menu_icon'           => 'dashicons-location',
            'supports'            => ['title'],
            // false a propósito: fuerza el editor clásico, donde la caja de taxonomía y las cajas
            // meta se comportan de forma predecible. Con el editor de bloques y solo soporte de
            // título la pantalla queda incómoda para capturar datos.
            'show_in_rest'        => false,
        ]);
    }

    // Post types que pueden alojar sedes. Se ofrecen en el desplegable del término.
    public static function post_types_disponibles() {
        $disponibles = [
            self::POST_TYPE => __('Sedes de envío (las gestiona este plugin)', 'wc-envios-personalizados'),
        ];

        if (post_type_exists('tiendas')) {
            $objeto = get_post_type_object('tiendas');
            $disponibles['tiendas'] = $objeto->labels->name;
        }

        return apply_filters('wcep_post_types_sedes', $disponibles);
    }

    // Post type por defecto: "tiendas" si existe, para no cambiar el comportamiento de lo ya configurado.
    public static function post_type_por_defecto() {
        return post_type_exists('tiendas') ? 'tiendas' : self::POST_TYPE;
    }

    // Post type donde viven las sedes de un método.
    public static function post_type_de_metodo($metodo_slug) {
        $termino = get_term_by('slug', $metodo_slug, 'metodo_envio');
        if (!$termino) {
            return self::post_type_por_defecto();
        }

        return self::post_type_de_termino($termino->term_id);
    }

    public static function post_type_de_termino($termino_id) {
        $guardado = get_term_meta($termino_id, 'post_type_sedes', true);

        return ($guardado && post_type_exists($guardado))
            ? $guardado
            : self::post_type_por_defecto();
    }

    // ¿Hay más de un método usando este post type? Si solo hay uno, no hace falta etiquetar cada
    // sede con la taxonomía: todo lo que está en ese post type pertenece a ese método.
    public static function post_type_compartido($post_type) {
        $terminos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);
        if (is_wp_error($terminos)) {
            return true;
        }

        $cuantos = 0;
        foreach ($terminos as $termino) {
            // Delivery y similares no usan sedes: no cuentan para decidir esto.
            if (get_term_meta($termino->term_id, 'requiere_sede', true) !== '1') {
                continue;
            }
            if (self::post_type_de_termino($termino->term_id) === $post_type) {
                $cuantos++;
            }
        }

        return $cuantos > 1;
    }

    // --- Lista del admin ---------------------------------------------------------------------
    // Con cientos de registros, la lista por defecto (solo título y fecha) es inservible.

    public static function columnas($columnas) {
        $nuevas = [];

        foreach ($columnas as $clave => $etiqueta) {
            $nuevas[$clave] = $etiqueta;

            if ($clave === 'title') {
                $nuevas['wcep_ciudad']    = __('Ciudad', 'wc-envios-personalizados');
                $nuevas['wcep_direccion'] = __('Dirección', 'wc-envios-personalizados');
                $nuevas['wcep_activo']    = __('Activa', 'wc-envios-personalizados');
            }
        }

        return $nuevas;
    }

    public static function contenido_columna($columna, $sede_id) {
        if ($columna === 'wcep_ciudad') {
            $ciudad = get_post_meta($sede_id, 'ciudad', true);
            echo $ciudad ? esc_html($ciudad) : '—';
            return;
        }

        if ($columna === 'wcep_direccion') {
            $direccion = get_post_meta($sede_id, 'direccion_completa', true);
            echo $direccion ? esc_html(wp_trim_words($direccion, 12)) : '—';
            return;
        }

        if ($columna === 'wcep_activo') {
            echo get_post_meta($sede_id, 'activo', true) === '1'
                ? '<span style="color:#1a7f37;font-weight:600">✔</span>'
                : '<span style="color:#b32d2e">✘</span>';
        }
    }

    public static function filtro_ciudad($post_type) {
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        $ciudades       = self::ciudades_existentes();
        $ciudad_elegida = isset($_GET[self::PARAM_CIUDAD]) ? sanitize_text_field(wp_unslash($_GET[self::PARAM_CIUDAD])) : '';

        if (empty($ciudades)) {
            return;
        }

        echo '<select name="' . esc_attr(self::PARAM_CIUDAD) . '">';
        echo '<option value="">' . esc_html__('Todas las ciudades', 'wc-envios-personalizados') . '</option>';
        foreach ($ciudades as $ciudad) {
            echo '<option value="' . esc_attr($ciudad) . '" ' . selected($ciudad_elegida, $ciudad, false) . '>' . esc_html($ciudad) . '</option>';
        }
        echo '</select>';
    }

    public static function aplicar_filtro_ciudad($consulta) {
        if (!is_admin() || !$consulta->is_main_query()) {
            return $consulta;
        }
        if (($consulta->query_vars['post_type'] ?? '') !== self::POST_TYPE) {
            return $consulta;
        }
        if (empty($_GET[self::PARAM_CIUDAD])) {
            return $consulta;
        }

        $consulta->query_vars['meta_query'][] = [
            'key'   => 'ciudad',
            'value' => sanitize_text_field(wp_unslash($_GET[self::PARAM_CIUDAD])),
        ];

        return $consulta;
    }

    public static function ciudades_existentes($post_type = self::POST_TYPE) {
        global $wpdb;

        $ciudades = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta.meta_value
             FROM {$wpdb->postmeta} meta
             INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id
             WHERE meta.meta_key = 'ciudad'
               AND meta.meta_value != ''
               AND posts.post_type = %s
               AND posts.post_status != 'trash'
             ORDER BY meta.meta_value ASC",
            $post_type
        ));

        return $ciudades ?: [];
    }

    // --- Acciones en lote --------------------------------------------------------------------
    // Activar o desactivar de a cientos sin entrar sede por sede.

    public static function acciones_lote($acciones) {
        $acciones['wcep_activar']    = __('Marcar como activas', 'wc-envios-personalizados');
        $acciones['wcep_desactivar'] = __('Marcar como inactivas', 'wc-envios-personalizados');
        return $acciones;
    }

    public static function ejecutar_accion_lote($url_destino, $accion, $ids_sedes) {
        if ($accion !== 'wcep_activar' && $accion !== 'wcep_desactivar') {
            return $url_destino;
        }

        $valor = $accion === 'wcep_activar' ? '1' : '0';

        foreach ($ids_sedes as $sede_id) {
            update_post_meta($sede_id, 'activo', $valor);
        }

        return add_query_arg([
            'wcep_lote_accion' => $accion,
            'wcep_lote_total'  => count($ids_sedes),
        ], $url_destino);
    }

    public static function aviso_accion_lote() {
        if (empty($_GET['wcep_lote_accion']) || empty($_GET['wcep_lote_total'])) {
            return;
        }

        $total   = (int) $_GET['wcep_lote_total'];
        $activar = $_GET['wcep_lote_accion'] === 'wcep_activar';

        $mensaje = $activar
            // translators: %d es la cantidad de sedes afectadas.
            ? sprintf(_n('%d sede marcada como activa.', '%d sedes marcadas como activas.', $total, 'wc-envios-personalizados'), $total)
            // translators: %d es la cantidad de sedes afectadas.
            : sprintf(_n('%d sede marcada como inactiva.', '%d sedes marcadas como inactivas.', $total, 'wc-envios-personalizados'), $total);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($mensaje) . '</p></div>';
    }
}

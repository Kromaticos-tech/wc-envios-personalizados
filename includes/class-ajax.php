<?php
if (!defined('ABSPATH')) exit;

// Devuelve las sedes activas del CPT "tiendas" filtradas por método de envío (y ciudad, si aplica),
// y la lista de ciudades disponibles para poblar el primer select cuando el método lo requiere.
class WCEP_Ajax {

    public static function init() {
        add_action('wp_ajax_wcep_get_sedes', [__CLASS__, 'get_sedes']);
        add_action('wp_ajax_nopriv_wcep_get_sedes', [__CLASS__, 'get_sedes']);
        add_action('wp_ajax_wcep_get_ciudades', [__CLASS__, 'get_ciudades']);
        add_action('wp_ajax_nopriv_wcep_get_ciudades', [__CLASS__, 'get_ciudades']);
    }

    // Lee y valida el slug del método que viene en la petición; corta con error si no llega.
    private static function metodo_solicitado() {
        $metodo_slug = isset($_POST['metodo']) ? sanitize_text_field(wp_unslash($_POST['metodo'])) : '';

        if (!$metodo_slug) {
            wp_send_json_error(__('Método de envío no especificado.', 'wc-envios-personalizados'));
        }

        return $metodo_slug;
    }

    // Consulta base de sedes activas de un método, opcionalmente acotada a una ciudad.
    private static function consultar_sedes($metodo_slug, $ciudad_filtro = '', $solo_ids = false) {
        $post_type = WCEP_Sede_CPT::post_type_de_metodo($metodo_slug);

        $filtros_meta = [['key' => 'activo', 'value' => '1']];
        if ($ciudad_filtro) {
            $filtros_meta[] = ['key' => 'ciudad', 'value' => $ciudad_filtro];
        }

        $argumentos = [
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'meta_query'     => $filtros_meta,
        ];

        // La taxonomía solo hace falta cuando dos métodos comparten el mismo post type. Si el
        // método tiene el suyo propio, todo lo que hay dentro le pertenece y no hace falta
        // etiquetar sede por sede.
        if (WCEP_Sede_CPT::post_type_compartido($post_type)) {
            $argumentos['tax_query'] = [[
                'taxonomy' => 'metodo_envio',
                'field'    => 'slug',
                'terms'    => $metodo_slug,
            ]];
        }

        if ($solo_ids) {
            $argumentos['fields'] = 'ids';
        }

        $consulta = new WP_Query($argumentos);
        return $consulta->posts;
    }

    public static function get_sedes() {
        check_ajax_referer('wcep_nonce', 'nonce');

        $metodo_slug   = self::metodo_solicitado();
        $ciudad_filtro = isset($_POST['ciudad']) ? sanitize_text_field(wp_unslash($_POST['ciudad'])) : '';

        $sedes_encontradas = array_map(function ($sede_post) {
            $sede_id = $sede_post->ID;
            return [
                'id'                     => $sede_id,
                'nombre'                 => $sede_post->post_title,
                'direccion'              => get_post_meta($sede_id, 'direccion_completa', true),
                'horarios'               => get_post_meta($sede_id, 'horarios', true),
                'ciudad'                 => get_post_meta($sede_id, 'ciudad', true),
                'mapa'                => get_post_meta($sede_id, 'ubicacion_mapa', true),
                'costo_envio_texto'   => get_post_meta($sede_id, 'costo_envio_texto', true),
                'costo_envio_mostrar' => get_post_meta($sede_id, 'costo_envio_mostrar', true) === '1',
            ];
        }, self::consultar_sedes($metodo_slug, $ciudad_filtro));

        wp_send_json_success($sedes_encontradas);
    }

    // Ciudades distintas que tienen al menos una sede activa en este método.
    // Sin esto el select de ciudad se quedaba vacío y las sedes salían sin filtrar.
    public static function get_ciudades() {
        check_ajax_referer('wcep_nonce', 'nonce');

        $metodo_slug = self::metodo_solicitado();
        $ciudades    = [];

        foreach (self::consultar_sedes($metodo_slug, '', true) as $sede_id) {
            $ciudad = trim((string) get_post_meta($sede_id, 'ciudad', true));
            if ($ciudad !== '') {
                $ciudades[] = $ciudad;
            }
        }

        $ciudades = array_values(array_unique($ciudades));
        sort($ciudades, SORT_NATURAL | SORT_FLAG_CASE);

        wp_send_json_success($ciudades);
    }
}

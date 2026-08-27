<?php
if (!defined('ABSPATH')) exit;

// Metabox propio del plugin en el CPT "tiendas": no depende de configurar campos en otro plugin (JetEngine, etc.),
// así el plugin queda autocontenido y funciona igual en cualquier ambiente (test o producción) con solo activarlo.
class WCEP_Sede_Meta {

    const NONCE_ACCION = 'wcep_sede_meta';
    const NONCE_CAMPO  = 'wcep_sede_meta_nonce';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'registrar_metabox']);

        // "save_post" genérico en vez de "save_post_{$post_type}": init() corre en plugins_loaded,
        // cuando el CPT "tiendas" de JetEngine todavía no está registrado y la lista de post types
        // saldría incompleta. El filtrado se hace dentro de guardar().
        add_action('save_post', [__CLASS__, 'guardar']);
    }

    public static function registrar_metabox() {
        // Contexto "normal": la caja sale en el panel inferior, junto a los campos de JetEngine
        // (Dirección Completa, Horarios, Ubicación Mapa). En el lateral quedaba lejos del resto
        // de datos de la tienda y se pierde si se cierra el panel de ajustes.
        foreach (array_keys(WCEP_Sede_CPT::post_types_disponibles()) as $post_type) {
            add_meta_box(
                'wcep_sede_meta',
                __('Envíos personalizados', 'wc-envios-personalizados'),
                [__CLASS__, 'render'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    // En el CPT propio del plugin no hay JetEngine que aporte la dirección: la pide esta caja.
    // En "tiendas" no se duplica, porque ahí ya existe el campo direccion_completa del cliente.
    private static function aporta_direccion($post_type) {
        return $post_type === WCEP_Sede_CPT::POST_TYPE;
    }

    public static function render($sede) {
        wp_nonce_field(self::NONCE_ACCION, self::NONCE_CAMPO);

        $sede_activa         = get_post_meta($sede->ID, 'activo', true);
        $ciudad              = get_post_meta($sede->ID, 'ciudad', true);
        $costo_envio_texto   = get_post_meta($sede->ID, 'costo_envio_texto', true);
        $costo_envio_visible = get_post_meta($sede->ID, 'costo_envio_mostrar', true);
        ?>
        <p>
            <label><input type="checkbox" name="activo" value="1" <?php checked($sede_activa, '1'); ?>>
                <?php esc_html_e('Sede activa (visible en el checkout)', 'wc-envios-personalizados'); ?></label>
        </p>
        <p>
            <label for="wcep_ciudad"><?php esc_html_e('Ciudad', 'wc-envios-personalizados'); ?></label><br>
            <input type="text" id="wcep_ciudad" name="ciudad" value="<?php echo esc_attr($ciudad); ?>" class="widefat" style="max-width:420px">
        </p>

        <?php if (self::aporta_direccion(get_post_type($sede))) : ?>
            <p>
                <label for="wcep_direccion"><?php esc_html_e('Dirección completa', 'wc-envios-personalizados'); ?></label><br>
                <textarea id="wcep_direccion" name="direccion_completa" class="widefat" rows="2"><?php
                    echo esc_textarea(get_post_meta($sede->ID, 'direccion_completa', true));
                ?></textarea>
            </p>
            <p>
                <label for="wcep_telefono"><?php esc_html_e('Teléfono', 'wc-envios-personalizados'); ?></label><br>
                <input type="text" id="wcep_telefono" name="telefono"
                       value="<?php echo esc_attr(get_post_meta($sede->ID, 'telefono', true)); ?>"
                       class="widefat" style="max-width:280px">
            </p>
        <?php endif; ?>

        <hr>
        <p>
            <label><input type="checkbox" name="costo_envio_mostrar" value="1" <?php checked($costo_envio_visible, '1'); ?>>
                <?php esc_html_e('Mostrar costo aproximado de envío', 'wc-envios-personalizados'); ?></label>
        </p>
        <p>
            <label for="wcep_costo_envio_texto"><?php esc_html_e('Texto del costo (informativo, no se cobra)', 'wc-envios-personalizados'); ?></label><br>
            <input type="text" id="wcep_costo_envio_texto" name="costo_envio_texto"
                   value="<?php echo esc_attr($costo_envio_texto); ?>" class="widefat" style="max-width:420px"
                   placeholder="<?php esc_attr_e('Ej: USD 5 - 8 aprox.', 'wc-envios-personalizados'); ?>">
        </p>
        <?php
    }

    public static function guardar($sede_id) {
        if (!array_key_exists(get_post_type($sede_id), WCEP_Sede_CPT::post_types_disponibles())) {
            return;
        }

        $nonce_enviado = $_POST[self::NONCE_CAMPO] ?? '';
        if (!$nonce_enviado || !wp_verify_nonce($nonce_enviado, self::NONCE_ACCION)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        update_post_meta($sede_id, 'activo', isset($_POST['activo']) ? '1' : '0');
        update_post_meta($sede_id, 'ciudad', sanitize_text_field(wp_unslash($_POST['ciudad'] ?? '')));

        // Solo se guardan si la caja los pintó: en "tiendas" la dirección la maneja JetEngine y
        // escribirla aquí la borraría en cada guardado.
        if (self::aporta_direccion(get_post_type($sede_id))) {
            update_post_meta($sede_id, 'direccion_completa', sanitize_textarea_field(wp_unslash($_POST['direccion_completa'] ?? '')));
            update_post_meta($sede_id, 'telefono', sanitize_text_field(wp_unslash($_POST['telefono'] ?? '')));
        }

        update_post_meta($sede_id, 'costo_envio_mostrar', isset($_POST['costo_envio_mostrar']) ? '1' : '0');
        update_post_meta($sede_id, 'costo_envio_texto', sanitize_text_field(wp_unslash($_POST['costo_envio_texto'] ?? '')));
    }
}

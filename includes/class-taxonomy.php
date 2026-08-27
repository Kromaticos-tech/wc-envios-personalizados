<?php
if (!defined('ABSPATH')) exit;

// Registra "metodo_envio" como taxonomía del CPT "tiendas" y todos los campos que hacen
// que cada método se vea distinto en el checkout sin tocar código.
class WCEP_Taxonomy {

    // Centinela que solo existe en los formularios de este plugin. Sirve para distinguir
    // un guardado real desde el admin de una llamada programática a wp_insert_term()/wp_update_term().
    const CAMPO_CENTINELA = 'wcep_campos_metodo';

    // Estilos disponibles para la caja de aviso (Pickup va gris, Delivery morado en el diseño).
    const ESTILOS_AVISO = ['morado', 'gris'];

    public static function init() {
        // Prioridad 20: JetEngine registra el CPT "tiendas" en "init" con prioridad normal,
        // así que la taxonomía se engancha después, cuando el post type ya existe.
        add_action('init', [__CLASS__, 'register'], 20);
        add_action('metodo_envio_add_form_fields', [__CLASS__, 'campos_nuevo']);
        add_action('metodo_envio_edit_form_fields', [__CLASS__, 'campos_editar']);
        add_action('created_metodo_envio', [__CLASS__, 'guardar_meta']);
        add_action('edited_metodo_envio', [__CLASS__, 'guardar_meta']);
    }

    public static function register() {
        // Se engancha a los dos post types que pueden alojar sedes: el del cliente y el propio.
        $post_types = array_keys(WCEP_Sede_CPT::post_types_disponibles());

        register_taxonomy('metodo_envio', $post_types, [
            'label'             => __('Método de envío', 'wc-envios-personalizados'),
            // Los métodos no se anidan, pero "hierarchical" cambia la interfaz del editor: con true
            // sale una lista de casillas (como las categorías) en vez de un campo de texto libre.
            // Con texto libre, escribir "pickup" o un typo CREA un término nuevo en lugar de asignar
            // el existente, y el checkout deja de encontrar la sede sin dar ninguna pista.
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            // Imprescindible: el editor de bloques lee las taxonomías por la API REST. Con esto en
            // false, la caja "Método de envío" no aparece al editar una tienda y no hay forma de
            // asignar el método desde el editor.
            'show_in_rest'      => true,
        ]);
    }

    private static function centinela() {
        echo '<input type="hidden" name="' . esc_attr(self::CAMPO_CENTINELA) . '" value="1">';
    }

    public static function campos_nuevo() {
        self::centinela();
        ?>
        <div class="form-field">
            <label for="descripcion"><?php esc_html_e('Descripción en la tarjeta', 'wc-envios-personalizados'); ?></label>
            <input type="text" name="descripcion" id="descripcion" placeholder="Ej: Retira en nuestra tienda">
        </div>
        <div class="form-field">
            <label for="orden"><?php esc_html_e('Orden en el checkout', 'wc-envios-personalizados'); ?></label>
            <input type="number" name="orden" id="orden" value="0" step="1">
            <p><?php esc_html_e('Menor número = más a la izquierda. Ej: Pickup 1, Delivery 2, Zoom 3.', 'wc-envios-personalizados'); ?></p>
        </div>
        <div class="form-field">
            <label><input type="checkbox" name="requiere_ciudad" value="1"> <?php esc_html_e('Requiere selección de ciudad', 'wc-envios-personalizados'); ?></label>
        </div>
        <div class="form-field">
            <label><input type="checkbox" name="requiere_sede" value="1"> <?php esc_html_e('Requiere selección de sede', 'wc-envios-personalizados'); ?></label>
        </div>
        <div class="form-field">
            <label><input type="checkbox" name="muestra_datos_entrega" value="1"> <?php esc_html_e('Pide dirección de entrega (bloque "Datos de entrega")', 'wc-envios-personalizados'); ?></label>
        </div>
        <div class="form-field">
            <label><input type="checkbox" name="predeterminado" value="1"> <?php esc_html_e('Marcar esta tarjeta por defecto al abrir el checkout', 'wc-envios-personalizados'); ?></label>
        </div>
        <div class="form-field">
            <label for="placeholder_sede"><?php esc_html_e('Texto del select de sede', 'wc-envios-personalizados'); ?></label>
            <input type="text" name="placeholder_sede" id="placeholder_sede" placeholder="Ej: Escoge sede Zoom">
        </div>
        <div class="form-field">
            <label><input type="checkbox" name="mostrar_resumen" value="1"> <?php esc_html_e('Mostrar caja de resumen', 'wc-envios-personalizados'); ?></label>
        </div>
        <div class="form-field">
            <label><input type="checkbox" name="envio_gratis" value="1"> <?php esc_html_e('No cobrar envío con este método', 'wc-envios-personalizados'); ?></label>
        </div>
        <?php
    }

    public static function campos_editar($termino) {
        $valores = self::leer_metas($termino->term_id);

        self::centinela();
        ?>
        <tr class="form-field">
            <th><label for="descripcion"><?php esc_html_e('Descripción en la tarjeta', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="descripcion" id="descripcion" value="<?php echo esc_attr($valores['descripcion']); ?>" class="regular-text" placeholder="Ej: Retira en nuestra tienda">
                <p class="description"><?php esc_html_e('Texto gris bajo el nombre del método, dentro de la tarjeta.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="orden"><?php esc_html_e('Orden en el checkout', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="number" name="orden" id="orden" value="<?php echo esc_attr($valores['orden']); ?>" step="1">
                <p class="description"><?php esc_html_e('Menor número = más a la izquierda. Ej: Pickup 1, Delivery 2, Zoom 3.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Requiere ciudad', 'wc-envios-personalizados'); ?></th>
            <td><label><input type="checkbox" name="requiere_ciudad" value="1" <?php checked($valores['requiere_ciudad'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label></td>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Requiere sede', 'wc-envios-personalizados'); ?></th>
            <td><label><input type="checkbox" name="requiere_sede" value="1" <?php checked($valores['requiere_sede'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label></td>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Pide dirección de entrega', 'wc-envios-personalizados'); ?></th>
            <td>
                <label><input type="checkbox" name="muestra_datos_entrega" value="1" <?php checked($valores['muestra_datos_entrega'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label>
                <p class="description"><?php esc_html_e('Marca esto solo en Delivery. Con los demás métodos los campos de dirección se ocultan y dejan de ser obligatorios.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Tarjeta por defecto', 'wc-envios-personalizados'); ?></th>
            <td>
                <label><input type="checkbox" name="predeterminado" value="1" <?php checked($valores['predeterminado'], '1'); ?>> <?php esc_html_e('Marcar esta tarjeta al abrir el checkout', 'wc-envios-personalizados'); ?></label>
                <p class="description"><?php esc_html_e('Solo un método puede ser el predeterminado; al marcar este se desmarcan los demás.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="post_type_sedes"><?php esc_html_e('Dónde viven las sedes', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <select name="post_type_sedes" id="post_type_sedes">
                    <?php foreach (WCEP_Sede_CPT::post_types_disponibles() as $post_type => $etiqueta) : ?>
                        <option value="<?php echo esc_attr($post_type); ?>" <?php selected($valores['post_type_sedes'], $post_type); ?>>
                            <?php echo esc_html($etiqueta); ?> (<?php echo esc_html($post_type); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Separa catálogos: las tiendas propias en un sitio y las oficinas de un transportista en otro, para no mezclar cientos de registros ajenos con los del cliente.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="placeholder_sede"><?php esc_html_e('Texto del select de sede', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="placeholder_sede" id="placeholder_sede" value="<?php echo esc_attr($valores['placeholder_sede']); ?>" class="regular-text" placeholder="Ej: Escoge sede Zoom">
                <p class="description"><?php esc_html_e('Si lo dejas vacío se usa "Selecciona sede".', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>

        <tr>
            <th colspan="2"><h2><?php esc_html_e('Costo del envío', 'wc-envios-personalizados'); ?></h2></th>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('No cobrar envío', 'wc-envios-personalizados'); ?></th>
            <td>
                <label><input type="checkbox" name="envio_gratis" value="1" <?php checked($valores['envio_gratis'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label>
                <p class="description"><?php esc_html_e('Reemplaza las tarifas de la zona por un envío de costo 0. Márcalo en Pickup (el cliente retira) y en Zoom (le paga al transportista en destino). Déjalo sin marcar en Delivery, que sí cobra la tarifa de la zona.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="etiqueta_envio"><?php esc_html_e('Texto en el resumen del pedido', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="etiqueta_envio" id="etiqueta_envio" value="<?php echo esc_attr($valores['etiqueta_envio']); ?>" class="regular-text" placeholder="Ej: Retiro en tienda">
                <p class="description"><?php esc_html_e('Lo que aparece en la línea "Envío" de "Tu pedido". Si lo dejas vacío se usa el nombre del método.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>

        <tr>
            <th colspan="2"><h2><?php esc_html_e('Caja de resumen', 'wc-envios-personalizados'); ?></h2></th>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Mostrar caja', 'wc-envios-personalizados'); ?></th>
            <td>
                <label><input type="checkbox" name="mostrar_resumen" value="1" <?php checked($valores['mostrar_resumen'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label>
                <p class="description"><?php esc_html_e('Caja bajo las tarjetas con el nombre, la dirección y la ilustración. En métodos con sede los datos salen de la sede elegida.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="estilo_aviso"><?php esc_html_e('Color de la caja', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <select name="estilo_aviso" id="estilo_aviso">
                    <option value="morado" <?php selected($valores['estilo_aviso'], 'morado'); ?>><?php esc_html_e('Morado', 'wc-envios-personalizados'); ?></option>
                    <option value="gris" <?php selected($valores['estilo_aviso'], 'gris'); ?>><?php esc_html_e('Gris', 'wc-envios-personalizados'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="aviso_titulo"><?php esc_html_e('Título de la caja', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="aviso_titulo" id="aviso_titulo" value="<?php echo esc_attr($valores['aviso_titulo']); ?>" class="regular-text" placeholder="Ej: Delivery">
                <p class="description"><?php esc_html_e('Solo para métodos SIN sede (Delivery), que no tienen de dónde sacar el nombre.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="aviso_direccion"><?php esc_html_e('Dirección de la caja', 'wc-envios-personalizados'); ?></label></th>
            <td><input type="text" name="aviso_direccion" id="aviso_direccion" value="<?php echo esc_attr($valores['aviso_direccion']); ?>" class="large-text"></td>
        </tr>

        <tr>
            <th colspan="2"><h2><?php esc_html_e('Imágenes', 'wc-envios-personalizados'); ?></h2></th>
        </tr>
        <tr class="form-field">
            <th><label for="icono"><?php esc_html_e('Ícono (sin seleccionar)', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="icono" id="icono" value="<?php echo esc_attr($valores['icono']); ?>" class="large-text">
                <p class="description"><?php echo esc_html(self::pista_archivo($termino->slug)); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="icono_activo"><?php esc_html_e('Ícono (seleccionado)', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="icono_activo" id="icono_activo" value="<?php echo esc_attr($valores['icono_activo']); ?>" class="large-text">
                <p class="description"><?php echo esc_html(self::pista_archivo($termino->slug . '-activo')); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="ilustracion"><?php esc_html_e('Ilustración de la caja de aviso', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="ilustracion" id="ilustracion" value="<?php echo esc_attr($valores['ilustracion']); ?>" class="large-text">
                <p class="description"><?php echo esc_html(self::pista_archivo('ilustracion-' . $termino->slug)); ?></p>
            </td>
        </tr>
        <?php
    }

    // Explica al admin qué archivo empaquetado se usa si deja la URL vacía.
    private static function pista_archivo($nombre_base) {
        $archivo = WCEP_Checkout::archivo_icono($nombre_base);

        return $archivo
            // translators: %s es el nombre del archivo empaquetado, ej. "pickup.svg".
            ? sprintf(__('Vacío = se usa el archivo del plugin: assets/icons/%s', 'wc-envios-personalizados'), $archivo)
            // translators: %s es el nombre base buscado, ej. "pickup-activo".
            : sprintf(__('No hay archivo empaquetado para "%s". Si dejas esto vacío no se muestra imagen.', 'wc-envios-personalizados'), $nombre_base);
    }

    // Un solo lugar donde vive la lista de metas y sus valores por defecto.
    public static function leer_metas($termino_id) {
        return [
            'descripcion'            => get_term_meta($termino_id, 'descripcion', true),
            'orden'                  => (int) get_term_meta($termino_id, 'orden', true),
            'requiere_ciudad'        => get_term_meta($termino_id, 'requiere_ciudad', true),
            'requiere_sede'          => get_term_meta($termino_id, 'requiere_sede', true),
            'muestra_datos_entrega'  => get_term_meta($termino_id, 'muestra_datos_entrega', true),
            'predeterminado'         => get_term_meta($termino_id, 'predeterminado', true),
            'placeholder_sede'       => get_term_meta($termino_id, 'placeholder_sede', true),
            'post_type_sedes'        => WCEP_Sede_CPT::post_type_de_termino($termino_id),
            'mostrar_resumen'        => get_term_meta($termino_id, 'mostrar_resumen', true),
            'envio_gratis'           => get_term_meta($termino_id, 'envio_gratis', true),
            'etiqueta_envio'         => get_term_meta($termino_id, 'etiqueta_envio', true),
            'estilo_aviso'           => get_term_meta($termino_id, 'estilo_aviso', true) ?: 'morado',
            'aviso_titulo'           => get_term_meta($termino_id, 'aviso_titulo', true),
            'aviso_direccion'        => get_term_meta($termino_id, 'aviso_direccion', true),
            'icono'                  => get_term_meta($termino_id, 'icono', true),
            'icono_activo'           => get_term_meta($termino_id, 'icono_activo', true),
            'ilustracion'            => get_term_meta($termino_id, 'ilustracion', true),
        ];
    }

    public static function guardar_meta($termino_id) {
        // created_/edited_metodo_envio también disparan desde wp_insert_term()/wp_update_term(), que se pueden
        // llamar desde un importador, WP-CLI u otro plugin. En esos casos $_POST no trae nuestros campos y los
        // checkboxes se guardarían como '0', borrando la configuración. El centinela corta ese caso.
        if (!isset($_POST[self::CAMPO_CENTINELA]) || !current_user_can('manage_categories')) {
            return;
        }

        $textos = ['descripcion', 'placeholder_sede', 'aviso_titulo', 'aviso_direccion', 'etiqueta_envio'];
        foreach ($textos as $campo) {
            update_term_meta($termino_id, $campo, sanitize_text_field(wp_unslash($_POST[$campo] ?? '')));
        }

        $urls = ['icono', 'icono_activo', 'ilustracion'];
        foreach ($urls as $campo) {
            update_term_meta($termino_id, $campo, esc_url_raw(wp_unslash($_POST[$campo] ?? '')));
        }

        $casillas = ['requiere_ciudad', 'requiere_sede', 'muestra_datos_entrega', 'mostrar_resumen', 'envio_gratis'];
        foreach ($casillas as $campo) {
            update_term_meta($termino_id, $campo, isset($_POST[$campo]) ? '1' : '0');
        }

        update_term_meta($termino_id, 'orden', absint($_POST['orden'] ?? 0));

        $post_type_sedes = sanitize_key($_POST['post_type_sedes'] ?? '');
        if (array_key_exists($post_type_sedes, WCEP_Sede_CPT::post_types_disponibles())) {
            update_term_meta($termino_id, 'post_type_sedes', $post_type_sedes);
        }

        $estilo_aviso = sanitize_key($_POST['estilo_aviso'] ?? 'morado');
        if (!in_array($estilo_aviso, self::ESTILOS_AVISO, true)) {
            $estilo_aviso = 'morado';
        }
        update_term_meta($termino_id, 'estilo_aviso', $estilo_aviso);

        self::guardar_predeterminado($termino_id, isset($_POST['predeterminado']));
    }

    // El predeterminado es excluyente: al marcar uno se desmarcan todos los demás.
    private static function guardar_predeterminado($termino_id, $es_predeterminado) {
        update_term_meta($termino_id, 'predeterminado', $es_predeterminado ? '1' : '0');

        if (!$es_predeterminado) {
            return;
        }

        $otros_terminos = get_terms([
            'taxonomy'   => 'metodo_envio',
            'hide_empty' => false,
            'exclude'    => [$termino_id],
            'fields'     => 'ids',
        ]);

        if (is_wp_error($otros_terminos)) {
            return;
        }

        foreach ($otros_terminos as $otro_termino_id) {
            update_term_meta($otro_termino_id, 'predeterminado', '0');
        }
    }
}

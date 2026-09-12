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

    // Filas vacías que se agregan al final de la tabla de franjas para poder cargar nuevas.
    const FILAS_FRANJA_EXTRA = 2;

    // Marca de hasta qué versión se rellenaron los valores de arranque. Guarda la versión y no un
    // simple "1" porque cada tanto se suma un valor nuevo (el mensaje de Zoom, sin ir más lejos), y
    // con un booleano los sitios ya instalados nunca lo recibirían. Al subir esta constante el
    // sembrado se repite una vez, y como solo rellena lo que está vacío, no pisa nada configurado.
    const OPCION_SEMBRADO  = 'wcep_metodos_sembrados';
    const VERSION_SEMBRADO = '1.16.0';

    // Marca de que los métodos de arranque ya se intentaron crear. Igual que con los grupos de
    // horario: si el administrador los borra a propósito, no vuelven a aparecer.
    const OPCION_METODOS_CREADOS = 'wcep_metodos_creados';

    public static function init() {
        // 25: después de register(), cuando los términos ya se pueden consultar.
        add_action('init', [__CLASS__, 'sembrar_predeterminados'], 25);

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

    // --- Valores de arranque -------------------------------------------------------------------

    // Valores listos para usar, por slug de método. Ahorran cargar a mano una tabla de cuatro filas
    // que además es fácil de equivocar en los bordes (11:59/12:00), y dejan a Zoom explicando su
    // cobro a destino desde el primer día.
    private static function valores_predeterminados() {
        return [
            'pickup' => [
                // El aviso de "pedido listo" es de Pickup: es el único método donde el cliente tiene
                // que venir a buscarlo y no se entera de que ya puede hacerlo si nadie le escribe.
                'avisa_pedido_listo' => '1',
            ],
            'zoom' => [
                'mensaje_adicional' => implode("\n", [
                    __('Cobro a destino: Pagas al retirar tu paquete.', 'wc-envios-personalizados'),
                    __('1 a 3 kg: $3', 'wc-envios-personalizados'),
                    __('Kilo adicional: $1 por kg extra', 'wc-envios-personalizados'),
                ]),
            ],
            'delivery' => [
                'titulo_franjas'          => __('¿Cuándo quieres recibirlo?', 'wc-envios-personalizados'),
                // Delivery es el único que cobra tarifa de zona, así que es donde tienen sentido las
                // dos casillas nuevas: mostrar el monto sin el nombre de la tarifa y aplicar las
                // reglas por SKU.
                'ocultar_etiqueta_envio'  => '1',
                'aplica_tarifas_producto' => '1',
                'franjas'                 => [
                    // Pedido por la mañana: alcanza para la tarde del mismo día.
                    ['desde' => '00:00', 'hasta' => '11:59', 'offset' => 0, 'inicio' => '15:00', 'fin' => '18:00'],
                    ['desde' => '00:00', 'hasta' => '11:59', 'offset' => 1, 'inicio' => '10:00', 'fin' => '12:00'],
                    // Pedido de mediodía en adelante: todo pasa al día siguiente.
                    ['desde' => '12:00', 'hasta' => '23:59', 'offset' => 1, 'inicio' => '12:00', 'fin' => '15:00'],
                    ['desde' => '12:00', 'hasta' => '23:59', 'offset' => 1, 'inicio' => '15:00', 'fin' => '18:00'],
                ],
            ],
        ];
    }

    // Los tres métodos con los que arranca una instalación nueva, con la configuración que hoy tiene
    // el sitio del cliente. Antes había que crearlos a mano y marcar una por una las casillas de
    // cada uno; los grupos de horario y las franjas ya venían listos y los métodos no.
    //
    // Acá va solo lo que valores_predeterminados() no cubre: al crear el término se aplican las dos
    // listas juntas, así las franjas, el mensaje de Zoom y el aviso de Pickup viven en un solo lugar.
    private static function metodos_de_arranque() {
        return [
            'pickup' => [
                'nombre' => 'Pick-up',
                'metas'  => [
                    'descripcion'            => __('Retira en nuestra tienda', 'wc-envios-personalizados'),
                    'orden'                  => 1,
                    'requiere_sede'          => '1',
                    'envio_gratis'           => '1',
                    'mostrar_resumen'        => '1',
                    'estilo_aviso'           => 'gris',
                    // Sin prefijo: la frase del grupo ya se lee completa ("Retira a partir de mañana
                    // a las 3:00 pm"), y así está en el sitio del cliente.
                    // Sin post_type_sedes: se usa "tiendas" si existe y, si no, el CPT propio.
                ],
            ],
            'delivery' => [
                'nombre' => 'Delivery',
                'metas'  => [
                    'descripcion'           => __('Entregas en Caracas', 'wc-envios-personalizados'),
                    'orden'                 => 2,
                    'muestra_datos_entrega' => '1',
                    // Sin caja de resumen. La caja morada con "Delivery" y una dirección fija se
                    // retiró a pedido del cliente (10/09/2026): al elegir Delivery se pasa directo
                    // a "¿Cuándo quieres recibirlo?".
                    'mostrar_resumen'       => '0',
                ],
            ],
            'zoom' => [
                'nombre' => 'Zoom',
                'metas'  => [
                    'descripcion'      => __('Envíos a todo el país', 'wc-envios-personalizados'),
                    'orden'            => 3,
                    'requiere_ciudad'  => '1',
                    'requiere_sede'    => '1',
                    'envio_gratis'     => '1',
                    // Las oficinas de Zoom son cientos y no son tiendas del cliente: van en el CPT
                    // propio para no mezclarlas con "tiendas".
                    'post_type_sedes'  => WCEP_Sede_CPT::POST_TYPE,
                    'placeholder_sede' => __('Escoge sede Zoom', 'wc-envios-personalizados'),
                    // Sin caja de resumen al elegir la oficina: lo que el cliente necesita saber de
                    // Zoom es el cobro a destino, y eso ya lo dice el mensaje de arriba.
                    'mostrar_resumen'  => '0',
                ],
            ],
        ];
    }

    // Solo en una instalación nueva, con la taxonomía vacía. Si ya hay métodos cargados no se crea
    // nada: un sitio que llamó "retiro" a su Pickup terminaría con dos tarjetas de lo mismo en el
    // checkout.
    private static function crear_metodos_de_arranque() {
        if (get_option(self::OPCION_METODOS_CREADOS)) {
            return;
        }

        $existentes = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false, 'fields' => 'ids']);

        // Sin marcar la opción: si la taxonomía todavía no está registrada conviene reintentarlo en
        // la próxima carga en vez de dar la creación por hecha.
        if (is_wp_error($existentes)) {
            return;
        }

        // La marca va ANTES de crear nada, como en los grupos: si algo falla a mitad de camino, el
        // intento no se repite en cada carga llenando la lista de métodos duplicados.
        update_option(self::OPCION_METODOS_CREADOS, '1');

        if ($existentes) {
            return;
        }

        $sembrados = self::valores_predeterminados();

        foreach (self::metodos_de_arranque() as $metodo_slug => $metodo) {
            $creado = wp_insert_term($metodo['nombre'], 'metodo_envio', ['slug' => $metodo_slug]);

            if (is_wp_error($creado)) {
                continue;
            }

            $metas = array_merge($sembrados[$metodo_slug] ?? [], $metodo['metas']);

            foreach ($metas as $clave => $valor) {
                update_term_meta($creado['term_id'], $clave, $valor);
            }
        }
    }

    public static function sembrar_predeterminados() {
        self::crear_metodos_de_arranque();

        if (get_option(self::OPCION_SEMBRADO) === self::VERSION_SEMBRADO) {
            return;
        }

        update_option(self::OPCION_SEMBRADO, self::VERSION_SEMBRADO);

        foreach (self::valores_predeterminados() as $metodo_slug => $metas) {
            $termino = get_term_by('slug', $metodo_slug, 'metodo_envio');

            if (!$termino) {
                continue;
            }

            foreach ($metas as $clave => $valor) {
                // Solo se rellena lo que está vacío. A diferencia de los grupos, que son objetos
                // nuevos, acá se toca un término que el administrador ya configuró: pisarle una
                // tabla de franjas que armó a mano sería inaceptable.
                $actual = get_term_meta($termino->term_id, $clave, true);

                if ($actual === '' || $actual === [] || $actual === false) {
                    update_term_meta($termino->term_id, $clave, $valor);
                }
            }
        }
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
        <tr class="form-field">
            <th><?php esc_html_e('Mostrar solo el monto', 'wc-envios-personalizados'); ?></th>
            <td>
                <label><input type="checkbox" name="ocultar_etiqueta_envio" value="1" <?php checked($valores['ocultar_etiqueta_envio'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label>
                <p class="description"><?php esc_html_e('En la línea "Envío" se muestra el monto sin el nombre de la tarifa ("Ref.5,00" en vez de "Tasa mínima BECO: Ref.5,00"). Solo cambia el checkout: el nombre sigue completo en el admin, los correos y la factura.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Aplica tarifas por producto', 'wc-envios-personalizados'); ?></th>
            <td>
                <label><input type="checkbox" name="aplica_tarifas_producto" value="1" <?php checked($valores['aplica_tarifas_producto'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label>
                <p class="description">
                    <?php esc_html_e('Habilita las reglas por SKU: los productos voluminosos pagan un monto fijo y los que se acuerdan por WhatsApp no cobran envío. Las listas se cargan en Sedes de envío → Tarifas de delivery.', 'wc-envios-personalizados'); ?>
                </p>
            </td>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Avisar cuando el pedido esté listo', 'wc-envios-personalizados'); ?></th>
            <td>
                <label><input type="checkbox" name="avisa_pedido_listo" value="1" <?php checked($valores['avisa_pedido_listo'], '1'); ?>> <?php esc_html_e('Sí', 'wc-envios-personalizados'); ?></label>
                <p class="description">
                    <?php esc_html_e('Al pasar el pedido al estado "Pedido listo" se le envía un correo al cliente diciéndole que ya puede retirarlo, con los datos de la sede que eligió. Márcalo en Pickup: es el único método donde el cliente tiene que venir a buscarlo. El texto del correo se edita en WooCommerce → Ajustes → Correos electrónicos.', 'wc-envios-personalizados'); ?>
                </p>
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
            <th colspan="2"><h2><?php esc_html_e('Mensaje del método', 'wc-envios-personalizados'); ?></h2></th>
        </tr>
        <tr class="form-field">
            <th><label for="mensaje_adicional"><?php esc_html_e('Texto', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <textarea name="mensaje_adicional" id="mensaje_adicional" rows="5" class="large-text"><?php echo esc_textarea($valores['mensaje_adicional']); ?></textarea>
                <p class="description">
                    <?php esc_html_e('Aviso que se muestra al elegir la tarjeta, para explicar cómo funciona el envío de ese método (por ejemplo el cobro a destino de Zoom y sus montos por kilo). Se respetan los saltos de línea. Vacío, no se muestra nada.', 'wc-envios-personalizados'); ?>
                </p>
                <p class="description">
                    <?php esc_html_e('A diferencia del título y la dirección de arriba, este funciona en todos los métodos, elijan sede o no.', 'wc-envios-personalizados'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th colspan="2"><h2><?php esc_html_e('Disponibilidad', 'wc-envios-personalizados'); ?></h2></th>
        </tr>
        <tr class="form-field">
            <th><label for="prefijo_disponibilidad"><?php esc_html_e('Prefijo de la línea', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="prefijo_disponibilidad" id="prefijo_disponibilidad" value="<?php echo esc_attr($valores['prefijo_disponibilidad']); ?>" class="regular-text" placeholder="<?php esc_attr_e('Ej: Puedes retirar:', 'wc-envios-personalizados'); ?>">
                <p class="description"><?php esc_html_e('Texto en negro que antecede a la fecha dentro de la caja de resumen. En Pickup "Puedes retirar:", en Delivery "Recibes:".', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th><label for="nota_disponibilidad"><?php esc_html_e('Nota al pie', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="nota_disponibilidad" id="nota_disponibilidad" value="<?php echo esc_attr($valores['nota_disponibilidad']); ?>" class="large-text">
                <p class="description"><?php esc_html_e('Aclaración opcional en gris bajo la fecha. Vacío, no se muestra nada.', 'wc-envios-personalizados'); ?></p>
            </td>
        </tr>

        <tr>
            <th colspan="2"><h2><?php esc_html_e('Franjas de entrega', 'wc-envios-personalizados'); ?></h2></th>
        </tr>
        <tr class="form-field">
            <th><label for="titulo_franjas"><?php esc_html_e('Título del bloque', 'wc-envios-personalizados'); ?></label></th>
            <td>
                <input type="text" name="titulo_franjas" id="titulo_franjas" value="<?php echo esc_attr($valores['titulo_franjas']); ?>" class="regular-text" placeholder="<?php esc_attr_e('Ej: ¿Cuándo quieres recibirlo?', 'wc-envios-personalizados'); ?>">
            </td>
        </tr>
        <tr class="form-field">
            <th><?php esc_html_e('Franjas', 'wc-envios-personalizados'); ?></th>
            <td>
                <?php self::tabla_franjas($valores['franjas']); ?>
                <p class="description"><?php esc_html_e('Cada fila dice: si el pedido entra dentro de esa ventana horaria, se ofrece esta franja de entrega. Se le muestran al cliente todas las filas cuya ventana contenga la hora actual de Venezuela, y tiene que elegir una. Deja una fila sin horas para borrarla.', 'wc-envios-personalizados'); ?></p>
                <p class="description"><?php esc_html_e('Si no cargas ninguna fila, el método no pide franja.', 'wc-envios-personalizados'); ?></p>
            </td>
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

    // Tabla de franjas: las filas guardadas más dos vacías para agregar. Sin JS a propósito —
    // un repetidor dinámico es mucha maquinaria para una tabla que se toca dos veces al año.
    private static function tabla_franjas(array $franjas) {
        $filas = array_merge($franjas, array_fill(0, self::FILAS_FRANJA_EXTRA, [
            'desde'  => '',
            'hasta'  => '',
            'offset' => 0,
            'inicio' => '',
            'fin'    => '',
        ]));

        echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
        echo '<th>' . esc_html__('Pedido desde', 'wc-envios-personalizados') . '</th>';
        echo '<th>' . esc_html__('Pedido hasta', 'wc-envios-personalizados') . '</th>';
        echo '<th>' . esc_html__('Se entrega', 'wc-envios-personalizados') . '</th>';
        echo '<th>' . esc_html__('Entre', 'wc-envios-personalizados') . '</th>';
        echo '<th>' . esc_html__('Y', 'wc-envios-personalizados') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($filas as $indice => $fila) {
            $nombre = 'franjas[' . $indice . ']';

            echo '<tr>';
            echo '<td><input type="time" name="' . esc_attr($nombre) . '[desde]" value="' . esc_attr($fila['desde']) . '"></td>';
            echo '<td><input type="time" name="' . esc_attr($nombre) . '[hasta]" value="' . esc_attr($fila['hasta']) . '"></td>';
            echo '<td>' . self::control_offset($nombre, (int) $fila['offset']) . '</td>';
            echo '<td><input type="time" name="' . esc_attr($nombre) . '[inicio]" value="' . esc_attr($fila['inicio']) . '"></td>';
            echo '<td><input type="time" name="' . esc_attr($nombre) . '[fin]" value="' . esc_attr($fila['fin']) . '"></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function control_offset($nombre, $offset_actual) {
        $opciones = [
            0 => __('El mismo día', 'wc-envios-personalizados'),
            1 => __('Al día siguiente', 'wc-envios-personalizados'),
            2 => __('En 2 días', 'wc-envios-personalizados'),
            3 => __('En 3 días', 'wc-envios-personalizados'),
        ];

        $html = '<select name="' . esc_attr($nombre) . '[offset]">';
        foreach ($opciones as $valor => $etiqueta) {
            $html .= '<option value="' . esc_attr($valor) . '" ' . selected($offset_actual, $valor, false) . '>' . esc_html($etiqueta) . '</option>';
        }

        return $html . '</select>';
    }

    // Las franjas se guardan como un solo array en un term meta, no como filas sueltas: así se
    // leen y se reemplazan de una, sin quedar restos de una configuración anterior más larga.
    public static function leer_franjas($termino_id) {
        $guardadas = get_term_meta($termino_id, 'franjas', true);

        return is_array($guardadas) ? $guardadas : [];
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
            'descripcion'             => get_term_meta($termino_id, 'descripcion', true),
            'orden'                   => (int) get_term_meta($termino_id, 'orden', true),
            'requiere_ciudad'         => get_term_meta($termino_id, 'requiere_ciudad', true),
            'requiere_sede'           => get_term_meta($termino_id, 'requiere_sede', true),
            'muestra_datos_entrega'   => get_term_meta($termino_id, 'muestra_datos_entrega', true),
            'predeterminado'          => get_term_meta($termino_id, 'predeterminado', true),
            'placeholder_sede'        => get_term_meta($termino_id, 'placeholder_sede', true),
            'post_type_sedes'         => WCEP_Sede_CPT::post_type_de_termino($termino_id),
            'mostrar_resumen'         => get_term_meta($termino_id, 'mostrar_resumen', true),
            'envio_gratis'            => get_term_meta($termino_id, 'envio_gratis', true),
            'etiqueta_envio'          => get_term_meta($termino_id, 'etiqueta_envio', true),
            'ocultar_etiqueta_envio'  => get_term_meta($termino_id, 'ocultar_etiqueta_envio', true),
            'aplica_tarifas_producto' => get_term_meta($termino_id, 'aplica_tarifas_producto', true),
            'avisa_pedido_listo'      => get_term_meta($termino_id, 'avisa_pedido_listo', true),
            'estilo_aviso'            => get_term_meta($termino_id, 'estilo_aviso', true) ?: 'morado',
            'aviso_titulo'            => get_term_meta($termino_id, 'aviso_titulo', true),
            'aviso_direccion'         => get_term_meta($termino_id, 'aviso_direccion', true),
            'mensaje_adicional'       => get_term_meta($termino_id, 'mensaje_adicional', true),
            'icono'                   => get_term_meta($termino_id, 'icono', true),
            'icono_activo'            => get_term_meta($termino_id, 'icono_activo', true),
            'ilustracion'             => get_term_meta($termino_id, 'ilustracion', true),
            'prefijo_disponibilidad'  => get_term_meta($termino_id, 'prefijo_disponibilidad', true),
            'nota_disponibilidad'     => get_term_meta($termino_id, 'nota_disponibilidad', true),
            'titulo_franjas'          => get_term_meta($termino_id, 'titulo_franjas', true),
            'franjas'                 => self::leer_franjas($termino_id),
        ];
    }

    public static function guardar_meta($termino_id) {
        // created_/edited_metodo_envio también disparan desde wp_insert_term()/wp_update_term(), que se pueden
        // llamar desde un importador, WP-CLI u otro plugin. En esos casos $_POST no trae nuestros campos y los
        // checkboxes se guardarían como '0', borrando la configuración. El centinela corta ese caso.
        if (!isset($_POST[self::CAMPO_CENTINELA]) || !current_user_can('manage_categories')) {
            return;
        }

        $textos = [
            'descripcion', 'placeholder_sede', 'aviso_titulo', 'aviso_direccion', 'etiqueta_envio',
            'prefijo_disponibilidad', 'nota_disponibilidad', 'titulo_franjas',
        ];
        foreach ($textos as $campo) {
            update_term_meta($termino_id, $campo, sanitize_text_field(wp_unslash($_POST[$campo] ?? '')));
        }

        // Aparte de los textos de una línea: sanitize_text_field() se come los saltos de línea, y el
        // mensaje del método son varias líneas ("1 a 3 kg: $3" debajo de "Cobro a destino:").
        update_term_meta($termino_id, 'mensaje_adicional', sanitize_textarea_field(wp_unslash($_POST['mensaje_adicional'] ?? '')));

        $urls = ['icono', 'icono_activo', 'ilustracion'];
        foreach ($urls as $campo) {
            update_term_meta($termino_id, $campo, esc_url_raw(wp_unslash($_POST[$campo] ?? '')));
        }

        $casillas = [
            'requiere_ciudad', 'requiere_sede', 'muestra_datos_entrega', 'mostrar_resumen',
            'envio_gratis', 'ocultar_etiqueta_envio', 'aplica_tarifas_producto', 'avisa_pedido_listo',
        ];
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
        update_term_meta($termino_id, 'franjas', self::sanear_franjas($_POST['franjas'] ?? []));

        self::guardar_predeterminado($termino_id, isset($_POST['predeterminado']));
    }

    // Una fila sin horario de entrega no describe nada, así que se descarta. Es también la forma
    // de borrar una franja: se le vacían las horas y se guarda.
    private static function sanear_franjas($filas_enviadas) {
        $franjas = [];

        foreach ((array) $filas_enviadas as $fila) {
            if (empty($fila['inicio']) || empty($fila['fin'])) {
                continue;
            }

            $franjas[] = [
                // ?? no alcanza: un <input type="time"> vacío llega como '' y no como null, así que
                // "Pedido hasta" en blanco se guardaba como 00:00 y la franja solo aplicaba a
                // medianoche exacta. Una fila sin ventana horaria vale todo el día.
                'desde'  => WCEP_Grupo_Horario::sanear_hora(empty($fila['desde']) ? '00:00' : $fila['desde']),
                'hasta'  => WCEP_Grupo_Horario::sanear_hora(empty($fila['hasta']) ? '23:59' : $fila['hasta']),
                'offset' => absint($fila['offset'] ?? 0),
                'inicio' => WCEP_Grupo_Horario::sanear_hora($fila['inicio']),
                'fin'    => WCEP_Grupo_Horario::sanear_hora($fila['fin']),
            ];
        }

        return $franjas;
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

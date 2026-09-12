<?php
if (!defined('ABSPATH')) exit;

// Taxonomía "grupo_horario": la regla de cuándo se puede retirar, compartida por varias sedes.
//
// La regla no vive en cada tienda a propósito. Con los campos en la sede, configurar Valencia y
// Barquisimeto son dos formularios idénticos, y cambiar la hora de retiro del interior obliga a
// editar sede por sede. Con grupos se edita una vez y aplica a todas: sumar Maracay con la misma
// lógica es elegir su grupo en un desplegable.
//
// Arranque previsto: un grupo "Caracas" (Chacaíto, CCCT, La Trinidad) y otro "Interior"
// (Valencia, Barquisimeto). Una sede sin grupo no muestra ninguna línea de disponibilidad.
class WCEP_Grupo_Horario {

    const TAXONOMIA = 'grupo_horario';

    // Mismo truco que en WCEP_Taxonomy: distingue un guardado real desde el admin de una llamada
    // programática a wp_insert_term(), donde $_POST no trae nuestros campos.
    const CAMPO_CENTINELA = 'wcep_campos_grupo';

    // Marca de que los grupos de ejemplo ya se crearon alguna vez. Es lo que impide que vuelvan a
    // aparecer si el administrador los borra a propósito.
    const OPCION_SEMBRADO = 'wcep_grupos_sembrados';

    public static function init() {
        // Prioridad 20, igual que "metodo_envio": para entonces el CPT "tiendas" de JetEngine
        // ya está registrado y la taxonomía se puede enganchar a los dos post types.
        add_action('init', [__CLASS__, 'register'], 20);
        // 25: después de register(), porque wp_insert_term() falla si la taxonomía no existe.
        add_action('init', [__CLASS__, 'sembrar_predeterminados'], 25);
        add_action(self::TAXONOMIA . '_add_form_fields', [__CLASS__, 'campos_nuevo']);
        add_action(self::TAXONOMIA . '_edit_form_fields', [__CLASS__, 'campos_editar']);
        add_action('created_' . self::TAXONOMIA, [__CLASS__, 'guardar_meta']);
        add_action('edited_' . self::TAXONOMIA, [__CLASS__, 'guardar_meta']);
    }

    public static function register() {
        register_taxonomy(self::TAXONOMIA, array_keys(WCEP_Sede_CPT::post_types_disponibles()), [
            'labels' => [
                'name'          => __('Grupos de horario', 'wc-envios-personalizados'),
                'singular_name' => __('Grupo de horario', 'wc-envios-personalizados'),
                'add_new_item'  => __('Añadir grupo de horario', 'wc-envios-personalizados'),
                'edit_item'     => __('Editar grupo de horario', 'wc-envios-personalizados'),
                'not_found'     => __('No hay grupos de horario todavía.', 'wc-envios-personalizados'),
                'menu_name'     => __('Grupos de horario', 'wc-envios-personalizados'),
            ],
            'hierarchical'      => true,
            'show_ui'           => true,
            // El plugin ya pinta su propia columna "Grupo de horario" en el listado de sedes.
            // Con esto en true WordPress agregaba una segunda casi idéntica ("Grupos de horario"),
            // y en pantalla quedaban dos columnas que dicen lo mismo.
            'show_admin_column' => false,
            'show_in_rest'      => false,
            // Sin caja propia en el editor: una sede pertenece a UN grupo, y la lista de casillas
            // de WordPress invita a marcar varias. El grupo se elige en el desplegable del metabox
            // "Envíos personalizados", que garantiza uno solo.
            'meta_box_cb'       => false,
        ]);
    }

    // --- Grupos de arranque --------------------------------------------------------------------

    // Los dos grupos con los que arranca una instalación nueva. No son obligatorios ni sagrados:
    // se renombran, se ajustan o se borran desde el admin como cualquier otro. Están para que el
    // plugin se instale y ya funcione, en vez de obligar a cargar siete campos a mano dos veces.
    public static function predeterminados() {
        return [
            [
                // Retiro al día siguiente a una hora fija. El caso de las tiendas de Caracas.
                'nombre' => 'Caracas',
                'regla'  => [
                    'tipo'          => WCEP_Horarios::TIPO_DIAS,
                    'dias'          => 1,
                    'hora'          => '15:00',
                    'dia_objetivo'  => 5,
                    'dias_alcanzan' => [],
                    'plantilla'     => __('Retira a partir de {fecha} a las {hora}', 'wc-envios-personalizados'),
                    'relativo'      => '1',
                ],
            ],
            [
                // Retiro los viernes, con corte los martes. El caso de Valencia y Barquisimeto.
                'nombre' => 'Interior',
                'regla'  => [
                    'tipo'          => WCEP_Horarios::TIPO_DIA_SEMANA,
                    'dias'          => 0,
                    'hora'          => '12:00',
                    'dia_objetivo'  => 5,
                    'dias_alcanzan' => [1, 2],
                    // "Retira el viernes" se leía como una cita a la que hay que ir ese día. La
                    // tienda guarda el pedido, así que la frase tiene que dejar claro que el viernes
                    // es cuándo empieza a estar disponible y no el único día para buscarlo.
                    'plantilla'     => __('Retira a partir del {fecha}, desde las {hora}', 'wc-envios-personalizados'),
                    'relativo'      => '0',
                ],
            ],
        ];
    }

    public static function sembrar_predeterminados() {
        if (get_option(self::OPCION_SEMBRADO)) {
            return;
        }

        // La marca se pone ANTES de crear nada. Si algo falla a mitad de camino, el intento no se
        // repite en cada carga de página llenando la lista de grupos duplicados.
        update_option(self::OPCION_SEMBRADO, '1');

        foreach (self::predeterminados() as $grupo) {
            if (term_exists($grupo['nombre'], self::TAXONOMIA)) {
                continue;
            }

            $creado = wp_insert_term($grupo['nombre'], self::TAXONOMIA);

            if (is_wp_error($creado)) {
                continue;
            }

            foreach ($grupo['regla'] as $clave => $valor) {
                update_term_meta($creado['term_id'], $clave, $valor);
            }
        }
    }

    // --- Lectura -------------------------------------------------------------------------------

    // Valores por defecto pensados para el caso más común (retiro al día siguiente). Un grupo
    // recién creado ya muestra algo coherente sin tocar nada.
    public static function leer_regla($termino_id) {
        $dias_alcanzan = get_term_meta($termino_id, 'dias_alcanzan', true);

        return [
            'tipo'          => (string) get_term_meta($termino_id, 'tipo', true),
            'dias'          => (int) get_term_meta($termino_id, 'dias', true),
            'hora'          => get_term_meta($termino_id, 'hora', true) ?: '15:00',
            'dia_objetivo'  => (int) get_term_meta($termino_id, 'dia_objetivo', true) ?: 5,
            'dias_alcanzan' => is_array($dias_alcanzan) ? $dias_alcanzan : [],
            'plantilla'     => (string) get_term_meta($termino_id, 'plantilla', true),
            'relativo'      => get_term_meta($termino_id, 'relativo', true) === '1' ? '1' : '0',
        ];
    }

    // Regla que le toca a una sede según el grupo que tenga asignado, o null si no tiene ninguno.
    // Ese null es justamente el caso "Puente Yanes": aparece en el selector, pero sin fecha.
    public static function regla_de_sede($sede_id) {
        $terminos = wp_get_object_terms($sede_id, self::TAXONOMIA, ['fields' => 'ids']);

        if (is_wp_error($terminos) || empty($terminos)) {
            return null;
        }

        return self::leer_regla((int) $terminos[0]);
    }

    public static function grupo_de_sede($sede_id) {
        $terminos = wp_get_object_terms($sede_id, self::TAXONOMIA, ['fields' => 'ids']);

        return (is_wp_error($terminos) || empty($terminos)) ? 0 : (int) $terminos[0];
    }

    // Texto ya armado para una sede. Un solo lugar donde se junta "qué grupo tiene" con "qué dice".
    public static function texto_de_sede($sede_id, ?DateTimeImmutable $ahora = null) {
        $regla = self::regla_de_sede($sede_id);

        return $regla ? WCEP_Horarios::texto_de_regla($regla, $ahora) : '';
    }

    public static function grupos() {
        $terminos = get_terms([
            'taxonomy'   => self::TAXONOMIA,
            'hide_empty' => false,
        ]);

        return is_wp_error($terminos) ? [] : $terminos;
    }

    // --- Formularios del término ---------------------------------------------------------------

    public static function campos_nuevo() {
        self::pintar_campos('nuevo', self::leer_regla(0));
    }

    public static function campos_editar($termino) {
        self::pintar_campos('editar', self::leer_regla($termino->term_id));
    }

    // Un solo renderizador para los dos formularios: el de "añadir" usa <div>, el de "editar" usa
    // filas de tabla. Duplicar siete campos para cambiar dos etiquetas HTML se desincroniza solo.
    private static function pintar_campos($contexto, array $regla) {
        echo '<input type="hidden" name="' . esc_attr(self::CAMPO_CENTINELA) . '" value="1">';

        self::campo(
            $contexto,
            __('Tipo de regla', 'wc-envios-personalizados'),
            self::control_tipo($regla['tipo']),
            __('Define cómo se calcula la fecha. "Sin aviso" deja la sede sin línea de disponibilidad en el checkout.', 'wc-envios-personalizados')
        );

        self::campo(
            $contexto,
            __('Días a sumar', 'wc-envios-personalizados'),
            '<input type="number" name="dias" min="0" step="1" value="' . esc_attr($regla['dias']) . '">',
            __('Solo para "A partir de N días". 1 = mañana, 2 = pasado mañana.', 'wc-envios-personalizados')
        );

        self::campo(
            $contexto,
            __('Día de la semana', 'wc-envios-personalizados'),
            self::control_dia_objetivo($regla['dia_objetivo']),
            __('Solo para "Próximo día de la semana". Ej: Viernes.', 'wc-envios-personalizados')
        );

        self::campo(
            $contexto,
            __('Días de compra que alcanzan esa semana', 'wc-envios-personalizados'),
            self::control_dias_alcanzan($regla['dias_alcanzan']),
            __('Comprando en uno de estos días, el retiro es el día objetivo de esta misma semana. Comprando en cualquier otro, pasa al de la semana siguiente.', 'wc-envios-personalizados')
        );

        self::campo(
            $contexto,
            __('Hora', 'wc-envios-personalizados'),
            '<input type="time" name="hora" value="' . esc_attr($regla['hora']) . '">',
            __('A partir de qué hora se puede retirar ese día.', 'wc-envios-personalizados')
        );

        self::campo(
            $contexto,
            __('Texto', 'wc-envios-personalizados'),
            '<input type="text" name="plantilla" class="large-text" value="' . esc_attr($regla['plantilla']) . '" placeholder="' . esc_attr__('Retira a partir de {fecha} a las {hora}', 'wc-envios-personalizados') . '">',
            __('{fecha} y {hora} se reemplazan por lo calculado. Si lo dejas vacío no se muestra nada.', 'wc-envios-personalizados')
        );

        self::campo(
            $contexto,
            __('Decir "hoy" y "mañana"', 'wc-envios-personalizados'),
            '<label><input type="checkbox" name="relativo" value="1" ' . checked($regla['relativo'], '1', false) . '> ' . esc_html__('Sí', 'wc-envios-personalizados') . '</label>',
            __('Marcado, {fecha} dice "mañana" cuando corresponde. Sin marcar dice siempre la fecha escrita ("Viernes 4 de Septiembre").', 'wc-envios-personalizados')
        );

        // Vista previa: la forma más rápida de confirmar que la regla quedó bien es ver qué diría
        // el checkout ahora mismo, sin abrir el checkout.
        if ($contexto === 'editar') {
            $vista_previa = WCEP_Horarios::texto_de_regla($regla);

            self::campo(
                $contexto,
                __('Se vería así hoy', 'wc-envios-personalizados'),
                '<strong>' . esc_html($vista_previa ?: __('(no se muestra nada)', 'wc-envios-personalizados')) . '</strong>',
                __('Calculado con la hora de Venezuela de este momento. Guarda para actualizarlo.', 'wc-envios-personalizados')
            );
        }
    }

    private static function campo($contexto, $etiqueta, $control, $descripcion = '') {
        if ($contexto === 'nuevo') {
            echo '<div class="form-field">';
            echo '<label>' . esc_html($etiqueta) . '</label>';
            echo $control;
            if ($descripcion) {
                echo '<p>' . esc_html($descripcion) . '</p>';
            }
            echo '</div>';
            return;
        }

        echo '<tr class="form-field">';
        echo '<th>' . esc_html($etiqueta) . '</th>';
        echo '<td>' . $control;
        if ($descripcion) {
            echo '<p class="description">' . esc_html($descripcion) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function control_tipo($tipo_actual) {
        $opciones = [
            WCEP_Horarios::TIPO_NINGUNO    => __('Sin aviso de disponibilidad', 'wc-envios-personalizados'),
            WCEP_Horarios::TIPO_DIAS       => __('A partir de N días', 'wc-envios-personalizados'),
            WCEP_Horarios::TIPO_DIA_SEMANA => __('Próximo día de la semana', 'wc-envios-personalizados'),
        ];

        $html = '<select name="tipo">';
        foreach ($opciones as $valor => $etiqueta) {
            $html .= '<option value="' . esc_attr($valor) . '" ' . selected($tipo_actual, $valor, false) . '>' . esc_html($etiqueta) . '</option>';
        }

        return $html . '</select>';
    }

    private static function control_dia_objetivo($dia_actual) {
        $html = '<select name="dia_objetivo">';
        foreach (WCEP_Horarios::dias_semana() as $numero => $nombre) {
            $html .= '<option value="' . esc_attr($numero) . '" ' . selected($dia_actual, $numero, false) . '>' . esc_html($nombre) . '</option>';
        }

        return $html . '</select>';
    }

    private static function control_dias_alcanzan(array $dias_marcados) {
        $html = '';
        foreach (WCEP_Horarios::dias_semana() as $numero => $nombre) {
            $marcado = in_array($numero, array_map('intval', $dias_marcados), true);

            $html .= '<label style="margin-right:1rem;white-space:nowrap">'
                . '<input type="checkbox" name="dias_alcanzan[]" value="' . esc_attr($numero) . '" ' . checked($marcado, true, false) . '> '
                . esc_html($nombre)
                . '</label>';
        }

        return $html;
    }

    // --- Guardado ------------------------------------------------------------------------------

    public static function guardar_meta($termino_id) {
        if (!isset($_POST[self::CAMPO_CENTINELA]) || !current_user_can('manage_categories')) {
            return;
        }

        $tipo = sanitize_key($_POST['tipo'] ?? '');
        if (!in_array($tipo, [WCEP_Horarios::TIPO_NINGUNO, WCEP_Horarios::TIPO_DIAS, WCEP_Horarios::TIPO_DIA_SEMANA], true)) {
            $tipo = WCEP_Horarios::TIPO_NINGUNO;
        }
        update_term_meta($termino_id, 'tipo', $tipo);

        update_term_meta($termino_id, 'dias', absint($_POST['dias'] ?? 0));
        update_term_meta($termino_id, 'hora', self::sanear_hora($_POST['hora'] ?? ''));
        update_term_meta($termino_id, 'plantilla', sanitize_text_field(wp_unslash($_POST['plantilla'] ?? '')));
        update_term_meta($termino_id, 'relativo', isset($_POST['relativo']) ? '1' : '0');

        $dia_objetivo = (int) ($_POST['dia_objetivo'] ?? 0);
        update_term_meta($termino_id, 'dia_objetivo', ($dia_objetivo >= 1 && $dia_objetivo <= 7) ? $dia_objetivo : 5);

        $dias_alcanzan = array_values(array_filter(
            array_map('intval', (array) ($_POST['dias_alcanzan'] ?? [])),
            function ($dia) {
                return $dia >= 1 && $dia <= 7;
            }
        ));
        update_term_meta($termino_id, 'dias_alcanzan', $dias_alcanzan);
    }

    // Los <input type="time"> mandan "HH:MM", pero el campo también se puede rellenar a mano en
    // navegadores que lo degradan a texto. Se normaliza para que el cálculo no dependa de eso.
    public static function sanear_hora($valor) {
        $minutos = WCEP_Horarios::a_minutos($valor);

        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}

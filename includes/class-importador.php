<?php
if (!defined('ABSPATH')) exit;

// Importador de sedes desde CSV. Cargar 377 oficinas a mano no es viable, y el listado de un
// transportista cambia con el tiempo: abren y cierran oficinas.
//
// Reimportar NO duplica: cada sede guarda una clave estable (nombre + ciudad) y el importador
// actualiza la que ya existe. Las que dejan de estar en el archivo se reportan, y solo se
// desactivan si el usuario lo pide expresamente. Nunca se borra nada de forma automática.
class WCEP_Importador {

    const SLUG_PAGINA = 'wcep-importar-sedes';
    const NONCE       = 'wcep_importar_sedes';
    const META_CLAVE  = '_wcep_clave_import';

    // Columnas que se leen del CSV. Solo "nombre" es obligatoria.
    const COLUMNAS = ['nombre', 'direccion_completa', 'ciudad', 'telefono'];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'agregar_pagina']);
    }

    public static function agregar_pagina() {
        add_submenu_page(
            'edit.php?post_type=' . WCEP_Sede_CPT::POST_TYPE,
            __('Importar sedes', 'wc-envios-personalizados'),
            __('Importar sedes', 'wc-envios-personalizados'),
            'manage_woocommerce',
            self::SLUG_PAGINA,
            [__CLASS__, 'render']
        );
    }

    // Identifica una sede entre importaciones. Nombre + ciudad es lo único estable que trae el
    // listado de Zoom: no hay código de oficina.
    private static function clave_de($nombre, $ciudad) {
        $normalizado = strtolower(trim($nombre)) . '|' . strtolower(trim($ciudad));
        $normalizado = preg_replace('/\s+/', ' ', $normalizado);

        return md5($normalizado);
    }

    // Lee el CSV subido y devuelve las filas válidas, o un WP_Error.
    private static function leer_csv($archivo_subido) {
        if (empty($archivo_subido['tmp_name']) || !is_uploaded_file($archivo_subido['tmp_name'])) {
            return new WP_Error('sin_archivo', __('No se recibió ningún archivo.', 'wc-envios-personalizados'));
        }

        $manejador = fopen($archivo_subido['tmp_name'], 'r');
        if (!$manejador) {
            return new WP_Error('sin_lectura', __('No se pudo abrir el archivo.', 'wc-envios-personalizados'));
        }

        $encabezados = fgetcsv($manejador);
        if (!$encabezados) {
            fclose($manejador);
            return new WP_Error('vacio', __('El archivo está vacío.', 'wc-envios-personalizados'));
        }

        // Excel guarda los CSV con BOM; sin quitarlo la primera columna no coincide con "nombre".
        $encabezados[0] = preg_replace('/^\x{FEFF}/u', '', $encabezados[0]);
        $encabezados    = array_map(function ($encabezado) {
            return strtolower(trim($encabezado));
        }, $encabezados);

        if (!in_array('nombre', $encabezados, true)) {
            fclose($manejador);
            return new WP_Error(
                'sin_columna',
                __('El CSV debe tener una columna "nombre". Encontradas: ', 'wc-envios-personalizados') . implode(', ', $encabezados)
            );
        }

        $filas = [];
        while (($celdas = fgetcsv($manejador)) !== false) {
            $fila = [];
            foreach (self::COLUMNAS as $columna) {
                $posicion       = array_search($columna, $encabezados, true);
                $fila[$columna] = ($posicion !== false && isset($celdas[$posicion]))
                    ? sanitize_text_field(trim($celdas[$posicion]))
                    : '';
            }

            if ($fila['nombre'] !== '') {
                $filas[] = $fila;
            }
        }

        fclose($manejador);

        return $filas;
    }

    // Sedes que ya existen en ese post type, indexadas por su clave de importación.
    private static function sedes_existentes($post_type) {
        $sedes = get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ]);

        $por_clave = [];
        foreach ($sedes as $sede) {
            $clave = get_post_meta($sede->ID, self::META_CLAVE, true);

            // Las sedes creadas a mano no tienen clave: se calcula para poder reconocerlas.
            if (!$clave) {
                $clave = self::clave_de($sede->post_title, get_post_meta($sede->ID, 'ciudad', true));
            }

            $por_clave[$clave] = $sede->ID;
        }

        return $por_clave;
    }

    private static function importar($filas, $metodo_slug, $desactivar_ausentes, $simular) {
        $post_type = WCEP_Sede_CPT::post_type_de_metodo($metodo_slug);
        $existentes = self::sedes_existentes($post_type);

        $resumen = [
            'post_type'    => $post_type,
            'creadas'      => 0,
            'actualizadas' => 0,
            'ausentes'     => [],
            'desactivadas' => 0,
        ];

        $claves_del_archivo = [];

        foreach ($filas as $fila) {
            $clave = self::clave_de($fila['nombre'], $fila['ciudad']);
            $claves_del_archivo[$clave] = true;

            $sede_id = $existentes[$clave] ?? 0;

            if ($sede_id) {
                $resumen['actualizadas']++;
            } else {
                $resumen['creadas']++;
            }

            if ($simular) {
                continue;
            }

            if (!$sede_id) {
                $sede_id = wp_insert_post([
                    'post_type'   => $post_type,
                    'post_title'  => $fila['nombre'],
                    'post_status' => 'publish',
                ]);

                if (is_wp_error($sede_id) || !$sede_id) {
                    continue;
                }
            } else {
                wp_update_post(['ID' => $sede_id, 'post_title' => $fila['nombre']]);
            }

            update_post_meta($sede_id, self::META_CLAVE, $clave);
            update_post_meta($sede_id, 'direccion_completa', $fila['direccion_completa']);
            update_post_meta($sede_id, 'ciudad', $fila['ciudad']);
            update_post_meta($sede_id, 'telefono', $fila['telefono']);
            update_post_meta($sede_id, 'activo', '1');

            wp_set_object_terms($sede_id, $metodo_slug, 'metodo_envio', false);
        }

        // Las que están en el sitio pero no en el archivo: se reportan siempre, se desactivan solo si se pidió.
        foreach ($existentes as $clave => $sede_id) {
            if (isset($claves_del_archivo[$clave])) {
                continue;
            }
            if (get_post_meta($sede_id, 'activo', true) !== '1') {
                continue; // ya estaba inactiva
            }

            $resumen['ausentes'][] = get_the_title($sede_id);

            if ($desactivar_ausentes) {
                $resumen['desactivadas']++;
                if (!$simular) {
                    update_post_meta($sede_id, 'activo', '0');
                }
            }
        }

        return $resumen;
    }

    public static function render() {
        $resumen = null;
        $error   = null;
        $simular = true;

        if (!empty($_POST['wcep_importar']) && check_admin_referer(self::NONCE)) {
            $metodo_slug         = sanitize_key($_POST['metodo'] ?? '');
            $desactivar_ausentes = !empty($_POST['desactivar_ausentes']);
            $simular             = !empty($_POST['simular']);

            $filas = self::leer_csv($_FILES['archivo'] ?? []);

            if (is_wp_error($filas)) {
                $error = $filas->get_error_message();
            } elseif (!$metodo_slug || !get_term_by('slug', $metodo_slug, 'metodo_envio')) {
                $error = __('Elige un método de envío válido.', 'wc-envios-personalizados');
            } elseif (empty($filas)) {
                $error = __('El archivo no tiene ninguna fila con nombre.', 'wc-envios-personalizados');
            } else {
                $resumen = self::importar($filas, $metodo_slug, $desactivar_ausentes, $simular);
                $resumen['leidas'] = count($filas);
            }
        }

        $metodos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Importar sedes desde CSV', 'wc-envios-personalizados'); ?></h1>

            <?php if ($error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if ($resumen) : self::render_resumen($resumen, $simular); endif; ?>

            <form method="post" enctype="multipart/form-data" style="max-width:700px">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="wcep_importar" value="1">

                <table class="form-table">
                    <tr>
                        <th><label for="wcep-archivo"><?php esc_html_e('Archivo CSV', 'wc-envios-personalizados'); ?></label></th>
                        <td>
                            <input type="file" name="archivo" id="wcep-archivo" accept=".csv,text/csv" required>
                            <p class="description">
                                <?php esc_html_e('Columnas: nombre, direccion_completa, ciudad, telefono. Solo "nombre" es obligatoria.', 'wc-envios-personalizados'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcep-metodo"><?php esc_html_e('Método de envío', 'wc-envios-personalizados'); ?></label></th>
                        <td>
                            <select name="metodo" id="wcep-metodo" required>
                                <option value=""><?php esc_html_e('Elige un método…', 'wc-envios-personalizados'); ?></option>
                                <?php if (!is_wp_error($metodos)) : foreach ($metodos as $termino) : ?>
                                    <option value="<?php echo esc_attr($termino->slug); ?>">
                                        <?php echo esc_html($termino->name); ?>
                                        (<?php echo esc_html(WCEP_Sede_CPT::post_type_de_termino($termino->term_id)); ?>)
                                    </option>
                                <?php endforeach; endif; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Las sedes se crean en el post type configurado para ese método.', 'wc-envios-personalizados'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Sedes ausentes', 'wc-envios-personalizados'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="desactivar_ausentes" value="1">
                                <?php esc_html_e('Desactivar las sedes que ya no estén en el archivo', 'wc-envios-personalizados'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Nunca se borran: solo dejan de mostrarse en el checkout, para no romper los pedidos que las referencian.', 'wc-envios-personalizados'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Modo', 'wc-envios-personalizados'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="simular" value="1" checked>
                                <strong><?php esc_html_e('Simular (no guarda nada)', 'wc-envios-personalizados'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Déjalo marcado la primera vez para ver qué haría. Desmárcalo y vuelve a subir el archivo para aplicar.', 'wc-envios-personalizados'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Procesar archivo', 'wc-envios-personalizados')); ?>
            </form>
        </div>
        <?php
    }

    private static function render_resumen($resumen, $simular) {
        $clase = $simular ? 'notice-info' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr($clase); ?>">
            <p>
                <strong>
                    <?php echo $simular
                        ? esc_html__('Simulación — no se guardó nada', 'wc-envios-personalizados')
                        : esc_html__('Importación aplicada', 'wc-envios-personalizados'); ?>
                </strong>
            </p>
            <table class="widefat" style="max-width:520px;margin-bottom:12px">
                <tbody>
                    <tr><td><?php esc_html_e('Filas leídas del archivo', 'wc-envios-personalizados'); ?></td><td><?php echo (int) $resumen['leidas']; ?></td></tr>
                    <tr><td><?php esc_html_e('Post type destino', 'wc-envios-personalizados'); ?></td><td><code><?php echo esc_html($resumen['post_type']); ?></code></td></tr>
                    <tr><td><?php echo $simular ? esc_html__('Se crearían', 'wc-envios-personalizados') : esc_html__('Creadas', 'wc-envios-personalizados'); ?></td><td><?php echo (int) $resumen['creadas']; ?></td></tr>
                    <tr><td><?php echo $simular ? esc_html__('Se actualizarían', 'wc-envios-personalizados') : esc_html__('Actualizadas', 'wc-envios-personalizados'); ?></td><td><?php echo (int) $resumen['actualizadas']; ?></td></tr>
                    <tr><td><?php esc_html_e('Activas en el sitio que NO están en el archivo', 'wc-envios-personalizados'); ?></td><td><?php echo count($resumen['ausentes']); ?></td></tr>
                    <tr><td><?php echo $simular ? esc_html__('Se desactivarían', 'wc-envios-personalizados') : esc_html__('Desactivadas', 'wc-envios-personalizados'); ?></td><td><?php echo (int) $resumen['desactivadas']; ?></td></tr>
                </tbody>
            </table>

            <?php if (!empty($resumen['ausentes'])) : ?>
                <p><?php esc_html_e('Sedes activas que no aparecen en el archivo:', 'wc-envios-personalizados'); ?></p>
                <p><em><?php echo esc_html(implode(' · ', array_slice($resumen['ausentes'], 0, 40))); ?><?php echo count($resumen['ausentes']) > 40 ? '…' : ''; ?></em></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

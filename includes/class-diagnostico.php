<?php
if (!defined('ABSPATH')) exit;

// Pantalla de diagnóstico: muestra en crudo los valores que el plugin está leyendo
// (métodos de envío, sedes y su configuración) para detectar rápido qué falta llenar
// o por qué algo no aparece en el checkout, sin tener que entrar post por post.
class WCEP_Diagnostico {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'agregar_pagina']);
    }

    public static function agregar_pagina() {
        // Cuelga del menú propio del plugin, que siempre existe. Antes dependía de "tiendas",
        // un CPT de otro plugin que podría no estar registrado.
        add_submenu_page(
            'edit.php?post_type=' . WCEP_Sede_CPT::POST_TYPE,
            __('Diagnóstico envíos', 'wc-envios-personalizados'),
            __('Diagnóstico envíos', 'wc-envios-personalizados'),
            'manage_woocommerce',
            'wcep-diagnostico',
            [__CLASS__, 'render']
        );
    }

    public static function render() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WC Envíos Personalizados — Diagnóstico', 'wc-envios-personalizados'); ?></h1>

            <h2><?php esc_html_e('Estado general', 'wc-envios-personalizados'); ?></h2>
            <table class="widefat" style="max-width:600px">
                <tbody>
                    <tr><td><?php esc_html_e('WooCommerce activo', 'wc-envios-personalizados'); ?></td><td><?php self::estado(class_exists('WooCommerce')); ?></td></tr>
                    <tr><td><?php esc_html_e('CPT "tiendas" registrado', 'wc-envios-personalizados'); ?></td><td><?php self::estado(post_type_exists('tiendas')); ?></td></tr>
                    <tr><td><?php esc_html_e('Taxonomía "metodo_envio" registrada', 'wc-envios-personalizados'); ?></td><td><?php self::estado(taxonomy_exists('metodo_envio')); ?></td></tr>
                    <tr>
                        <td><?php esc_html_e('Checkout clásico (shortcode)', 'wc-envios-personalizados'); ?></td>
                        <td>
                            <?php self::estado(!self::checkout_usa_bloques()); ?>
                            <?php if (self::checkout_usa_bloques()) : ?>
                                <br><small><?php esc_html_e('La página de pago usa el bloque de WooCommerce. El selector de envío no se muestra: cambia la página a [woocommerce_checkout].', 'wc-envios-personalizados'); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Aviso de "pedido listo"', 'wc-envios-personalizados'); ?></td>
                        <td>
                            <?php
                            $metodos_que_avisan = WCEP_Pedido_Listo::metodos_que_avisan();
                            self::estado(WCEP_Pedido_Listo::correo_activo() && $metodos_que_avisan);
                            ?>
                            <?php if (!WCEP_Pedido_Listo::correo_activo()) : ?>
                                <br><small><?php esc_html_e('El correo está desactivado en WooCommerce → Ajustes → Correos electrónicos → "Pedido listo para retirar".', 'wc-envios-personalizados'); ?></small>
                            <?php elseif (!$metodos_que_avisan) : ?>
                                <br><small><?php esc_html_e('Ningún método tiene marcado "Avisar cuando el pedido esté listo", así que marcar el estado no envía nada. Se marca en Pickup.', 'wc-envios-personalizados'); ?></small>
                            <?php else : ?>
                                <br><small><?php
                                    /* translators: %s: lista de métodos separados por coma. */
                                    printf(esc_html__('Avisan: %s', 'wc-envios-personalizados'), esc_html(implode(', ', $metodos_que_avisan)));
                                ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><td><?php esc_html_e('Versión del plugin', 'wc-envios-personalizados'); ?></td><td><?php echo esc_html(WCEP_VERSION); ?> (<?php echo esc_html__('guardada:', 'wc-envios-personalizados'); ?> <?php echo esc_html(get_option('wcep_version', '—')); ?>)</td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e('Métodos de envío (taxonomía)', 'wc-envios-personalizados'); ?></h2>
            <?php self::tabla_metodos(); ?>

            <h2><?php esc_html_e('Sedes (CPT tiendas)', 'wc-envios-personalizados'); ?></h2>
            <?php self::tabla_sedes(); ?>

            <h2><?php esc_html_e('Probar la consulta que usa el checkout (AJAX)', 'wc-envios-personalizados'); ?></h2>
            <p>
                <label><?php esc_html_e('Método (slug):', 'wc-envios-personalizados'); ?>
                    <input type="text" id="wcep-test-metodo" placeholder="pickup / delivery / zoom">
                </label>
                <label style="margin-left:10px"><?php esc_html_e('Ciudad:', 'wc-envios-personalizados'); ?>
                    <input type="text" id="wcep-test-ciudad" placeholder="(opcional)">
                </label>
                <button type="button" class="button button-primary" id="wcep-test-btn"><?php esc_html_e('Consultar', 'wc-envios-personalizados'); ?></button>
            </p>
            <pre id="wcep-test-resultado" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:800px;white-space:pre-wrap;"></pre>

            <script>
            document.getElementById('wcep-test-btn').addEventListener('click', function () {
                const metodoSlug   = document.getElementById('wcep-test-metodo').value;
                const ciudadFiltro = document.getElementById('wcep-test-ciudad').value;
                const salida       = document.getElementById('wcep-test-resultado');
                salida.textContent = '<?php echo esc_js(__('Consultando...', 'wc-envios-personalizados')); ?>';

                const parametros = new URLSearchParams();
                parametros.append('action', 'wcep_get_sedes');
                parametros.append('nonce', '<?php echo esc_js(wp_create_nonce('wcep_nonce')); ?>');
                parametros.append('metodo', metodoSlug);
                parametros.append('ciudad', ciudadFiltro);

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: parametros })
                    .then(function (respuesta) { return respuesta.json(); })
                    .then(function (json) { salida.textContent = JSON.stringify(json, null, 2); })
                    .catch(function (error) { salida.textContent = 'Error: ' + error; });
            });
            </script>
        </div>
        <?php
    }

    // El selector se inyecta en form.checkout, que solo existe con el shortcode clásico.
    private static function checkout_usa_bloques() {
        $checkout_id = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : 0;

        if ($checkout_id <= 0) {
            return false;
        }

        return has_block('woocommerce/checkout', get_post_field('post_content', $checkout_id));
    }

    // Verde/rojo: para cosas que de verdad están bien o mal (WooCommerce activo, CPT registrado…).
    private static function estado($condicion_cumplida) {
        echo $condicion_cumplida
            ? '<span style="color:#1a7f37;font-weight:600">✔ OK</span>'
            : '<span style="color:#b32d2e;font-weight:600">✘ Falta</span>';
    }

    // Neutro: para ajustes que son sí/no según el diseño, donde un "no" no es ningún error.
    // Que Delivery no requiera sede o que Zoom no muestre caja es lo correcto, no una falla.
    private static function si_no($activo) {
        echo $activo
            ? esc_html__('Sí', 'wc-envios-personalizados')
            : '<span style="color:#8c8f94">' . esc_html__('No', 'wc-envios-personalizados') . '</span>';
    }

    // Resume en una celda de dónde sale el costo de ese método. Cuando hay reglas por SKU se dice
    // cuántos hay cargados: una lista vacía es la explicación más probable de "configuré la tarifa
    // especial y sigue cobrando lo mismo".
    private static function estado_envio($termino, $envio_gratis, $etiqueta_envio) {
        if ($envio_gratis) {
            return __('Gratis', 'wc-envios-personalizados') . ' — ' . $etiqueta_envio;
        }

        $monto_fijo   = (float) get_term_meta($termino->term_id, 'monto_fijo', true);
        $gratis_desde = (float) get_term_meta($termino->term_id, 'gratis_desde', true);

        $partes = [
            $monto_fijo > 0
                // translators: %s es el monto fijo del envío.
                ? sprintf(__('Monto fijo %s', 'wc-envios-personalizados'), wc_format_localized_price($monto_fijo))
                : __('Tarifa de la zona', 'wc-envios-personalizados'),
        ];

        if ($gratis_desde > 0) {
            // translators: %s es el monto de compra desde el que el envío es gratis.
            $partes[] = sprintf(__('gratis desde %s', 'wc-envios-personalizados'), wc_format_localized_price($gratis_desde));
        }

        if (get_term_meta($termino->term_id, 'aplica_tarifas_producto', true) === '1') {
            $partes[] = sprintf(
                // translators: 1: monto especial, 2: SKUs con tarifa especial, 3: SKUs a convenir por WhatsApp.
                __('tarifas por SKU (especial %1$s: %2$d SKU / WhatsApp: %3$d SKU)', 'wc-envios-personalizados'),
                // Número pelado y no wc_price(): esta celda se imprime escapada, y el HTML del
                // formateador de moneda se vería literal.
                wc_format_localized_price(WCEP_Tarifas_Producto::monto_especial()),
                count(WCEP_Tarifas_Producto::skus(WCEP_Tarifas_Producto::OPCION_SKUS_ESPECIAL)),
                count(WCEP_Tarifas_Producto::skus(WCEP_Tarifas_Producto::OPCION_SKUS_WHATSAPP))
            );
        }

        if (get_term_meta($termino->term_id, 'ocultar_etiqueta_envio', true) === '1') {
            $partes[] = __('en el checkout se muestra solo el monto', 'wc-envios-personalizados');
        }

        return implode(' · ', $partes);
    }

    private static function tabla_metodos() {
        if (!taxonomy_exists('metodo_envio')) {
            echo '<p>' . esc_html__('La taxonomía aún no está registrada (revisa que WooCommerce y el CPT tiendas estén activos).', 'wc-envios-personalizados') . '</p>';
            return;
        }

        // Se usa el mismo orden que el checkout, para que la tabla refleje lo que ve el cliente.
        $terminos_metodo = WCEP_Checkout::metodos_ordenados();
        if (empty($terminos_metodo)) {
            echo '<p>' . esc_html__('No hay ningún término creado todavía en Tiendas → Método de envío.', 'wc-envios-personalizados') . '</p>';
            return;
        }

        $slug_predeterminado = WCEP_Checkout::slug_predeterminado($terminos_metodo);
        ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th><?php esc_html_e('Orden', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Nombre', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Slug', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Por defecto', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Sedes en', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Requiere ciudad', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Requiere sede', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Ícono', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Envío', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Caja de resumen', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Sedes activas asignadas', 'wc-envios-personalizados'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($terminos_metodo as $termino) :
                    $icono_personalizado = get_term_meta($termino->term_id, 'icono', true);
                    $archivo_icono       = WCEP_Checkout::archivo_icono($termino->slug);

                    if ($icono_personalizado) {
                        $icono_en_uso = $icono_personalizado;
                    } elseif ($archivo_icono) {
                        // translators: %s es el nombre del archivo de ícono encontrado, ej. "pickup.png".
                        $icono_en_uso = sprintf(__('por defecto (assets/icons/%s)', 'wc-envios-personalizados'), $archivo_icono);
                    } else {
                        $icono_en_uso = __('sin ícono', 'wc-envios-personalizados');
                    }

                    // Mismo criterio que el checkout: post type del método y, solo si está
                    // compartido con otro método, además el filtro por taxonomía.
                    $post_type_sedes = WCEP_Sede_CPT::post_type_de_termino($termino->term_id);

                    $argumentos_conteo = [
                        'post_type'      => $post_type_sedes,
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'meta_query'     => [['key' => 'activo', 'value' => '1']],
                    ];

                    if (WCEP_Sede_CPT::post_type_compartido($post_type_sedes)) {
                        $argumentos_conteo['tax_query'] = [['taxonomy' => 'metodo_envio', 'field' => 'slug', 'terms' => $termino->slug]];
                    }

                    $consulta_sedes_activas = new WP_Query($argumentos_conteo);

                    $requiere_ciudad = get_term_meta($termino->term_id, 'requiere_ciudad', true) === '1';
                    $requiere_sede   = get_term_meta($termino->term_id, 'requiere_sede', true) === '1';
                    $resumen_visible = get_term_meta($termino->term_id, 'mostrar_resumen', true) === '1';
                    $envio_gratis    = get_term_meta($termino->term_id, 'envio_gratis', true) === '1';
                    $etiqueta_envio  = get_term_meta($termino->term_id, 'etiqueta_envio', true) ?: $termino->name;
                    $titulo_caja     = get_term_meta($termino->term_id, 'aviso_titulo', true);
                    $estado_envio    = self::estado_envio($termino, $envio_gratis, $etiqueta_envio);

                    // La caja se alimenta de la sede elegida o, si el método no usa sede, de los
                    // campos del propio término. Ese segundo caso es el que se olvida llenar.
                    if (!$resumen_visible) {
                        $estado_resumen = '<span style="color:#8c8f94">' . esc_html__('No', 'wc-envios-personalizados') . '</span>';
                    } elseif ($requiere_sede) {
                        $estado_resumen = esc_html__('Sí, con datos de la sede', 'wc-envios-personalizados');
                    } elseif ($titulo_caja) {
                        $estado_resumen = esc_html__('Sí', 'wc-envios-personalizados') . ' — ' . esc_html($titulo_caja);
                    } else {
                        $estado_resumen = '<span style="color:#b32d2e">' . esc_html__('Activada, pero falta "Título de la caja"', 'wc-envios-personalizados') . '</span>';
                    }
                    ?>
                    <tr>
                        <td><?php echo (int) get_term_meta($termino->term_id, 'orden', true); ?></td>
                        <td><?php echo esc_html($termino->name); ?></td>
                        <td><code><?php echo esc_html($termino->slug); ?></code></td>
                        <td><?php echo $termino->slug === $slug_predeterminado ? '★' : '—'; ?></td>
                        <td><code><?php echo esc_html($post_type_sedes); ?></code></td>
                        <td><?php self::si_no($requiere_ciudad); ?></td>
                        <td><?php self::si_no($requiere_sede); ?></td>
                        <td><?php echo esc_html($icono_en_uso); ?></td>
                        <td><?php echo esc_html($estado_envio); ?></td>
                        <td><?php echo $estado_resumen; ?></td>
                        <td><?php echo (int) $consulta_sedes_activas->found_posts; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function tabla_sedes() {
        // Se listan los dos catálogos juntos, pero acotado: con cientos de sedes esta tabla
        // no es el sitio para revisarlas una a una, para eso está la lista del CPT.
        $sedes = get_posts([
            'post_type'      => array_keys(WCEP_Sede_CPT::post_types_disponibles()),
            'posts_per_page' => 50,
            'post_status'    => 'any',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        if (empty($sedes)) {
            echo '<p>' . esc_html__('Todavía no hay ninguna sede/tienda creada.', 'wc-envios-personalizados') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th><?php esc_html_e('Sede', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Método asignado', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Activo', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Ciudad', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Dirección', 'wc-envios-personalizados'); ?></th>
                    <th><?php esc_html_e('Costo aprox.', 'wc-envios-personalizados'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sedes as $sede) :
                    $metodos_de_la_sede = get_the_terms($sede->ID, 'metodo_envio');
                    $slugs_metodo       = (!$metodos_de_la_sede || is_wp_error($metodos_de_la_sede))
                        ? ''
                        : implode(', ', wp_list_pluck($metodos_de_la_sede, 'slug'));

                    $sede_activa         = get_post_meta($sede->ID, 'activo', true) === '1';
                    $ciudad              = get_post_meta($sede->ID, 'ciudad', true);
                    $direccion           = get_post_meta($sede->ID, 'direccion_completa', true);
                    $costo_envio_visible = get_post_meta($sede->ID, 'costo_envio_mostrar', true) === '1';
                    $costo_envio_texto   = get_post_meta($sede->ID, 'costo_envio_texto', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($sede->post_title); ?></td>
                        <td><?php echo $slugs_metodo === '' ? '<span style="color:#b32d2e">' . esc_html__('sin asignar', 'wc-envios-personalizados') . '</span>' : '<code>' . esc_html($slugs_metodo) . '</code>'; ?></td>
                        <td><?php self::si_no($sede_activa); ?></td>
                        <td><?php echo $ciudad ? esc_html($ciudad) : '—'; ?></td>
                        <td><?php echo $direccion ? esc_html($direccion) : '<span style="color:#b32d2e">' . esc_html__('falta direccion_completa', 'wc-envios-personalizados') . '</span>'; ?></td>
                        <td><?php echo $costo_envio_visible ? '✔ "' . esc_html($costo_envio_texto) . '"' : '—'; ?></td>
                        <td><a href="<?php echo esc_url(get_edit_post_link($sede->ID)); ?>"><?php esc_html_e('Editar', 'wc-envios-personalizados'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

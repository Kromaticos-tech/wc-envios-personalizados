<?php
// Se ejecuta cuando el plugin se borra desde el admin de WordPress (no en una simple desactivación).
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('wcep_version');
delete_option('wcep_nota_feriados_limpiada');
delete_option('wcep_frase_retiro_actualizada');
delete_option('wcep_migracion_cobro_envios');

// Tarifas de delivery por producto.
delete_option('wcep_delivery_skus_especial');
delete_option('wcep_delivery_monto_especial');
delete_option('wcep_delivery_skus_whatsapp');
delete_option('wcep_delivery_mensaje_whatsapp');

// Ajustes del correo de "pedido listo" (asunto, encabezado y texto extra editables desde
// WooCommerce → Ajustes → Correos electrónicos).
delete_option('woocommerce_wcep_pedido_listo_settings');

// Las sedes (CPT "tiendas") pertenecen a otro plugin y NO se eliminan aquí.
// Si además quieres borrar los términos de la taxonomía "metodo_envio" al desinstalar, descomenta:
// $terminos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);
// if (!is_wp_error($terminos)) {
//     foreach ($terminos as $termino) {
//         wp_delete_term($termino->term_id, 'metodo_envio');
//     }
// }

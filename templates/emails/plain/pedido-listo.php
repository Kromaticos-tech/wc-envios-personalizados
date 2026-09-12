<?php
/**
 * Versión en texto plano del aviso de pedido listo.
 *
 * WooCommerce la usa cuando los correos están configurados como texto plano, y también como parte
 * alternativa de los multipart. Cualquier etiqueta HTML acá se vería literal.
 *
 * @var WC_Order $order
 * @var array    $sede
 */

if (!defined('ABSPATH')) exit;

echo "= " . esc_html(wp_strip_all_tags($email_heading)) . " =\n\n";

/* translators: %s: nombre del cliente. */
echo esc_html(sprintf(__('Hola %s,', 'wc-envios-personalizados'), $order->get_billing_first_name())) . "\n\n";

echo esc_html(sprintf(
    /* translators: 1: número de pedido, 2: nombre de la sede. */
    __('Tu pedido #%1$s ya está listo y te espera en %2$s.', 'wc-envios-personalizados'),
    $order->get_order_number(),
    $sede['nombre']
)) . "\n\n";

echo esc_html($sede['nombre']) . "\n";

if ($sede['direccion']) {
    echo esc_html($sede['direccion']) . "\n";
}

if ($sede['ciudad']) {
    echo esc_html($sede['ciudad']) . "\n";
}

if ($sede['horarios']) {
    echo esc_html__('Horario', 'wc-envios-personalizados') . ': ' . esc_html($sede['horarios']) . "\n";
}

if ($sede['telefono']) {
    echo esc_html__('Teléfono', 'wc-envios-personalizados') . ': ' . esc_html($sede['telefono']) . "\n";
}

echo "\n----------------------------------------\n\n";

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n----------------------------------------\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content))) . "\n\n";
}

echo esc_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));

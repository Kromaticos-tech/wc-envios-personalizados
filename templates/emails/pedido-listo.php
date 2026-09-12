<?php
/**
 * Aviso al cliente de que su pedido ya está listo en la sede que eligió.
 *
 * Para cambiar el diseño, copiar este archivo a tu-tema/woocommerce/emails/pedido-listo.php
 * en lugar de editarlo acá: así los cambios sobreviven a las actualizaciones del plugin.
 *
 * @var WC_Order $order
 * @var array    $sede
 */

if (!defined('ABSPATH')) exit;

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p style="margin:0 0 16px">
    <?php
    /* translators: %s: nombre del cliente. */
    printf(esc_html__('Hola %s,', 'wc-envios-personalizados'), esc_html($order->get_billing_first_name()));
    ?>
</p>

<p style="margin:0 0 16px">
    <?php
    echo wp_kses_post(sprintf(
        /* translators: 1: número de pedido, 2: nombre de la sede. */
        __('Tu pedido <strong>#%1$s</strong> ya está listo y te espera en <strong>%2$s</strong>.', 'wc-envios-personalizados'),
        esc_html($order->get_order_number()),
        esc_html($sede['nombre'])
    ));
    ?>
</p>

<?php
/*
 * Los datos de la sede van en un bloque aparte: es lo que el cliente va a buscar cuando abra el
 * correo parado en la calle, no algo que deba rastrear dentro de un párrafo. La tabla y los estilos
 * en línea son por los clientes de correo, que ignoran las hojas de estilo y a menudo los divs.
 */
?>
<table cellspacing="0" cellpadding="12" border="0" style="width:100%;margin:0 0 24px;border:1px solid #e5e7eb;border-radius:8px">
    <tr>
        <td style="font-family:inherit;font-size:14px;line-height:1.5;color:#374151">
            <strong style="display:block;margin:0 0 4px;font-size:15px;color:#1f2937"><?php echo esc_html($sede['nombre']); ?></strong>

            <?php if ($sede['direccion']) : ?>
                <span style="display:block"><?php echo esc_html($sede['direccion']); ?></span>
            <?php endif; ?>

            <?php if ($sede['ciudad']) : ?>
                <span style="display:block"><?php echo esc_html($sede['ciudad']); ?></span>
            <?php endif; ?>

            <?php if ($sede['horarios']) : ?>
                <span style="display:block;margin-top:8px">
                    <strong><?php esc_html_e('Horario', 'wc-envios-personalizados'); ?>:</strong>
                    <?php echo esc_html($sede['horarios']); ?>
                </span>
            <?php endif; ?>

            <?php if ($sede['telefono']) : ?>
                <span style="display:block">
                    <strong><?php esc_html_e('Teléfono', 'wc-envios-personalizados'); ?>:</strong>
                    <?php echo esc_html($sede['telefono']); ?>
                </span>
            <?php endif; ?>
        </td>
    </tr>
</table>

<?php
/**
 * El detalle del pedido sirve para que el cliente sepa qué va a recibir y para que en el mostrador
 * puedan cotejarlo desde el teléfono.
 *
 * No se dispara "woocommerce_email_order_meta" a propósito: ahí se imprime la fecha prometida en el
 * checkout ("Retira a partir del Viernes 11…"), que en este correo ya es vieja y contradice al
 * asunto. Todo lo demás que ese hook agrega es información para el admin, no para el cliente.
 */
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);

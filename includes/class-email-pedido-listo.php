<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WC_Email')) return;

// Correo "tu pedido ya está en la sucursal". Se dispara al pasar el pedido al estado "Pedido listo".
//
// Es una clase WC_Email y no un wp_mail() suelto para que quede en WooCommerce → Ajustes → Correos
// electrónicos: así el asunto, el encabezado y el texto extra los edita el cliente sin tocar código,
// y el correo sale con la plantilla, el logo y los colores del resto de la tienda.
class WCEP_Email_Pedido_Listo extends WC_Email {

    // Sede del pedido que se está enviando. La resuelve trigger() y la leen las plantillas.
    public $sede = null;

    public function __construct() {
        $this->id             = WCEP_Pedido_Listo::ID_EMAIL;
        $this->customer_email = true;
        $this->title          = __('Pedido listo para retirar', 'wc-envios-personalizados');
        $this->description    = __('Se envía al cliente cuando el pedido pasa al estado "Pedido listo". Le avisa que ya puede pasar por la sede que eligió en el checkout.', 'wc-envios-personalizados');

        $this->template_html  = 'emails/pedido-listo.php';
        $this->template_plain = 'emails/plain/pedido-listo.php';
        $this->template_base  = WCEP_PATH . 'templates/';

        $this->placeholders = [
            '{order_number}' => '',
            '{order_date}'   => '',
            '{sede}'         => '',
        ];

        // El despachador de correos de WooCommerce convierte el cambio de estado en este hook.
        add_action('woocommerce_order_status_' . WCEP_Pedido_Listo::ESTADO . '_notification', [$this, 'trigger'], 10, 2);

        parent::__construct();
    }

    public function get_default_subject() {
        return __('Tu pedido #{order_number} ya está listo en {sede}', 'wc-envios-personalizados');
    }

    public function get_default_heading() {
        return __('Tu pedido ya está listo para retirar', 'wc-envios-personalizados');
    }

    public function trigger($pedido_id, $pedido = false) {
        $this->setup_locale();

        if ($pedido_id && !is_a($pedido, 'WC_Order')) {
            $pedido = wc_get_order($pedido_id);
        }

        if (is_a($pedido, 'WC_Order')) {
            $this->object = $pedido;
            $this->sede   = WCEP_Pedido_Listo::sede_del_aviso($pedido);

            $this->placeholders['{order_number}'] = $pedido->get_order_number();
            $this->placeholders['{order_date}']   = wc_format_datetime($pedido->get_date_created());
            $this->placeholders['{sede}']         = $this->sede ? $this->sede['nombre'] : '';

            $this->recipient = $pedido->get_billing_email();
        }

        // Sin sede no hay nada que anunciar: el aviso es de los métodos donde el cliente pasa a
        // buscar el pedido (hoy Pickup), y un pedido a domicilio marcado como listo no tiene dónde
        // retirarse: el correo diría una mentira. El estado se puede usar igual en esos pedidos
        // para saber que la preparación terminó; lo único que no ocurre es el aviso.
        if ($this->sede && $this->is_enabled() && $this->get_recipient()) {
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }

        $this->restore_locale();
    }

    private function argumentos_plantilla($texto_plano) {
        return [
            'order'              => $this->object,
            'sede'               => $this->sede,
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $texto_plano,
            'email'              => $this,
        ];
    }

    public function get_content_html() {
        return wc_get_template_html($this->template_html, $this->argumentos_plantilla(false), '', $this->template_base);
    }

    public function get_content_plain() {
        return wc_get_template_html($this->template_plain, $this->argumentos_plantilla(true), '', $this->template_base);
    }

    // Texto que el cliente puede editar desde los ajustes, debajo del pedido. Se deja algo útil
    // cargado para que el correo sirva desde el primer envío sin pasar por la pantalla de ajustes.
    public function get_default_additional_content() {
        return __('Si tienes cualquier duda, respóndenos este correo o escríbenos por WhatsApp.', 'wc-envios-personalizados');
    }
}

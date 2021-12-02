<?php

class WC_Gateway_GoCuotas extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'gocuotas';
        $this->icon = get_option('go_cuotas_icon', plugin_dir_url(__FILE__) . 'logo.png');
        $this->has_fields = false;
        $this->method_title = 'GoCuotas';
        $this->method_description = 'Descripcion';

        $this->supports = array(
            'products'
        );

        $this->init_form_fields();

        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        add_action('woocommerce_api_gocuotas', array($this, 'webhook'));

        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

        add_action('woocommerce_api_gocuotas_webhook', array($this, 'webhook'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => [
                'title'       => 'Activar/Desactivar',
                'label'       => 'Activar GOcuotas',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ],
            'title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'Titulo a mostrar al finalizar compra.',
                'default'     => 'Pagar CUOTAS con DEBITO sin interés',
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'Descripcion a mostrar al finalizar compra.',
                'default'     => 'Ahora podes pagar en CUOTAS sin interés con tu tarjeta de DEBITO!',
            ],
            'email_go' => [
                'title'       => 'Email API Comercio',
                'type'        => 'text'
            ],
            'password_go' => [
                'title'       => 'Password API Comercio',
                'type'        => 'password'
            ],
            'show_cuotas' => [
                'title'       => 'Mostrar cuotas en los productos',
                'label'       => 'Activar',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ],
            'iconfile' => [
                'title'       => 'Icono',
                'label'       => 'Icono a mostrar',
                'type'        => 'file',
                'description' => 'Icono que se mostrara al finalizar la compra.',
                'default'     => get_option('go_cuotas_icon'),
            ],
            'logg' => [
                'title'       => 'Activar Log',
                'label'       => 'Activar',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ]
        );
    }

    public function admin_options()
    {
        echo '<h3>' . $this->method_title . '</h3>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
    }

    public function payment_scripts()
    {

        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        if (empty($this->comercio_key) || empty($this->publishable_key)) {
            return;
        }

        wp_localize_script('woocommerce_gocuotas', 'gocuotas_params', array(
            'publishableKey' => $this->publishable_key
        ));

        wp_enqueue_script('woocommerce_gocuotas');
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        $order = wc_get_order($order_id);
        $godata = get_option('woocommerce_gocuotas_settings', true);


        $authentication = wp_remote_post('https://www.gocuotas.com/api_redirect/v1/authentication', [
            'method' => 'POST',
            'body' => [
                'email' => $godata['email_go'],
                'password' => $godata['password_go']
            ]
        ]);

        if ($godata['logg'] == 'yes') {
            GoCuotas_Helper::go_log('auth-info.txt', json_encode($authentication) . PHP_EOL);
        }

        $auth_response = $authentication['response'];

        if ($auth_response['code'] != 200) {
            wc_add_notice($auth_response['message'] == 'Unauthorized' ? 'Error de autenticación de comercio.' : $auth_response['message'], 'error');
            return;
        }

        $token = $authentication['body'];
        $token = json_decode($token)->token;

        $total = $order->get_total();
        $total = str_replace(".", "", $total);
        $total = str_replace(",", "", $total);

        $order_received_url = wc_get_endpoint_url('order-received', $order->get_id(), wc_get_checkout_url());
        $order_received_url_ok = $order_received_url . "done/";

        $order_received_url_fail =  $order->get_checkout_payment_url($on_checkout = false);

        $order_received_url_okk = add_query_arg('key', $order->get_order_key(), $order_received_url_ok);


        $payment_init = wp_remote_post('https://www.gocuotas.com/api_redirect/v1/checkouts', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => $token
            ],
            'body' => [
                'amount_in_cents' => $total,
                'order_reference_id' => $order_id,
                'url_success' => $order_received_url_okk,
                'url_failure' => $order_received_url_fail,
                'webhook_url' => home_url().'/wc-api/gocuotas_webhook'
            ]
        ]);

        if (is_wp_error($payment_init)) {
            wc_add_notice('Error de pago: Ocurrio un error al conectar con la API.', 'error');
            return;
        };

        $response = $payment_init['response'];

        if ($response['code'] != 201) {
            wc_add_notice('Error de pago: Ocurrio un error al pagar. "' . $response['message'] . '"', 'error');
            return;
        }

        if ($godata['logg'] == 'yes') {
            GoCuotas_Helper::go_log('payment_info.txt', json_encode($payment_init['body']) . PHP_EOL);
        }

        $url_init = $payment_init['body'];
        $url_init = json_decode($url_init)->url_init;

        update_post_meta($order_id, 'gocuotas_response', $payment_init['body']);
        $order->reduce_order_stock();
        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            'redirect' => $url_init
        );
    }
    public function thankyou_page($order_id)
    {
        if ($this->instructions) {
            echo wpautop(wptexturize($this->instructions));
        }

        $r = $_SERVER['REQUEST_URI'];
        $r = explode('/', $r);

        if (in_array("done", $r)) {
            $order = wc_get_order($order_id);
            $order->payment_complete();
            $order->add_order_note(
                'GO Cuotas: ' .
                    __('Payment APPROVED. IPN', 'gocuotas')
            );
        }
    }

    public function webhook()
    {
        $data = json_decode(file_get_contents('php://input'));
        GoCuotas_Helper::go_log('webhook.txt', $data . PHP_EOL);

        update_option('webhook_debug', $_GET);
    }
}
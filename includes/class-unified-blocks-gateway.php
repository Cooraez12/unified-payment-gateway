<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class UNIFIED_Blocks_Gateway extends AbstractPaymentMethodType {
    protected $name = 'unified';
	protected $id = 'unified';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
    }

	public function is_active() {
        return (
            isset( $this->settings['enabled'] ) &&
            $this->settings['enabled'] === 'yes'
        );
    }

    // In class
	public function get_payment_method_script_handles() {
	   	wp_register_script(
			'unified-blocks-js',
			plugin_dir_url( UNIFIED_PAYMENT_GATEWAY_FILE ) . 'assets/js/unified-blocks.js',
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
			'1.0.0',
			true
		);
	    	return [ 'unified-blocks-js' ]; // match your registered handle
	}

  	public function get_payment_method_data() {
        return [
            'id'          => $this->name, // e.g. 'unified'
            'title'       => $this->settings['title'] ?? 'Unified',
            'description' => $this->settings['description'] ?? '',
            'supports'    => ['products'],
            'isActive'    => $this->is_active(), // ✅ camelCase, boolean

            // Optional extra data
            'sandbox'     => $this->settings['sandbox'] ?? '',
            'order_status'=> $this->settings['order_status'] ?? '',
            'instructions'=> $this->settings['instructions'] ?? '',
            'accounts'    => $this->settings['accounts'] ?? '',
        ];
    }
}
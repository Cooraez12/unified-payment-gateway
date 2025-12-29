<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class UNIFIED_Blocks_Gateway extends AbstractPaymentMethodType {
    protected $name = 'unified';
	protected $id = 'unified';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
    }

	public function is_active() {
		if (has_block( 'woocommerce/cart' )) {
				return [];
		}
	    return ( isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] );
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
			'name'          => $this->name, // e.g. 'unified'
	        'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : '',
	        'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
	        'supports'    => [ 'products' ],
	        'is_active'   => ( isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ),
	        // extra settings you want to pass
	        'sandbox'     => isset( $this->settings['sandbox'] ) ? $this->settings['sandbox'] : '',
	        'order_status'=> isset( $this->settings['order_status'] ) ? $this->settings['order_status'] : '',
	        'instructions'=> isset( $this->settings['instructions'] ) ? $this->settings['instructions'] : '',
	        'accounts'    => isset( $this->settings['accounts'] ) ? $this->settings['accounts'] : '',
	    ];
	}

}

<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class UNIFIED_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = 'unified';
	protected $id   = 'unified';

	public function initialize() {
		$this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
	}

	public function is_active() {
		return (
			isset($this->settings['enabled']) &&
			$this->settings['enabled'] === 'yes'
		);
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'unified-blocks-js',
			plugin_dir_url(UNIFIED_PAYMENT_GATEWAY_FILE) . 'assets/js/unified-blocks.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n'
			],
			'1.0.0',
			false
		);
		return ['unified-blocks-js'];
	}

	public function get_payment_method_data() {
        $title       = $this->settings['title'] ?? 'Unified';
        $description = $this->settings['description'] ?? '';

		if (WC()->cart) {
			$amount   = (float) WC()->cart->get_total('edit');
			if ($amount < 0.01) {
				$totals = WC()->cart->get_totals();
				$amount = (float) ($totals['total'] ?? 0);
			}
			$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
			$gateway  = $gateways['unified'] ?? null;
			if ($gateway && method_exists($gateway, 'get_checkout_info_for_amount')) {
				$info = $gateway->get_checkout_info_for_amount($amount);
				if (!empty($info['title']))    $title       = $info['title'];
				if (!empty($info['subtitle'])) $description = $info['subtitle'];
				// Debug log for block checkout account info
				if (function_exists('wc_get_logger')) {
					$logger = wc_get_logger();
					$logger->info('Block checkout: get_payment_method_data - amount: ' . $amount . ' | title: ' . $title . ' | description: ' . wp_strip_all_tags($description), [ 'source' => 'unified-payment-gateway' ]);
				}
			}
		}
		return [
			'id'          => $this->name,
            'title'       => $title,
            'description' => $description,
			'supports'    => ['products'],
			'isActive'    => $this->is_active(),
			'can_pay'	  => $this->is_active(),
			'sandbox'     => $this->settings['sandbox'] ?? '',
			'order_status'=> $this->settings['order_status'] ?? '',
			'instructions'=> $this->settings['instructions'] ?? '',
			'accounts'    => $this->settings['accounts'] ?? '',
		];

		error_log('Unified Blocks Data: ' . print_r($data, true));
	}
}


/**
 * ─────────────────────────────────────────────────────────────────────────────
 * AJAX handler for Block Checkout payment processing.
 *
 * KEY FIX: Instead of `new UNIFIED_PAYMENT_GATEWAY()` (which creates a fresh,
 * partially-initialised instance), we pull the already-booted gateway instance
 * from WooCommerce's payment gateway registry.  That instance has had
 * init_settings() called by WooCommerce during the normal boot cycle, so
 * $this->sandbox, $this->enabled, and all get_option() values are correctly
 * populated when process_payment() runs.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function unified_register_block_ajax_handlers() {
	add_action('wp_ajax_unified_block_gateway_process',        'handle_unified_gateway_ajax');
	add_action('wp_ajax_nopriv_unified_block_gateway_process', 'handle_unified_gateway_ajax');
}
add_action('init', 'unified_register_block_ajax_handlers');

function handle_unified_gateway_ajax() {
	// ── Nonce verification ────────────────────────────────────────────────────
	$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
	if (empty($nonce) || !wp_verify_nonce($nonce, 'unified_payment')) {
		wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
		die;
	}

	// ── Get the already-initialised gateway instance from WooCommerce ─────────
	// This is the critical fix. WC()->payment_gateways()->payment_gateways()
	// returns instances that have already been through init_settings(), so
	// sandbox mode, enabled state, and all options are correctly loaded.
	$gateways       = WC()->payment_gateways()->payment_gateways();
	$unifiedPayment = $gateways['unified'] ?? null;

	if (!$unifiedPayment) {
		// Fallback: if for any reason the registry doesn't have it yet,
		// instantiate manually and force-reload settings from the DB.
		$unifiedPayment = new UNIFIED_PAYMENT_GATEWAY();
		$unifiedPayment->init_settings();
		$unifiedPayment->load_gateway_settings();

		wc_get_logger()->warning(
			'Unified: gateway not found in WC registry during AJAX — fell back to manual instantiation.',
			['source' => 'unified-payment-gateway']
		);
	}

	// ── Resolve the draft order ID from the block checkout session ────────────
	$orderID = WC()->session ? WC()->session->get('store_api_draft_order') : null;

	$status = [];

	if ($orderID) {
		$status = $unifiedPayment->process_payment($orderID);
	} else {
		wc_add_notice(__('Invalid order.', 'unified-payment-gateway'), 'error');
		$status = ['result' => 'fail', 'error' => 'Invalid order.'];
	}

	wp_send_json($status);
	die;
}

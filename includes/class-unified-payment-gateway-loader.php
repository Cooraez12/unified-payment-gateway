<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Class UNIFIED_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the Unified Payment Gateway plugin.
 */
class UNIFIED_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

	private $base_url;

	/**
	 * Get the singleton instance of this class.
	 * @return UNIFIED_PAYMENT_GATEWAY_Loader
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Constructor. Sets up actions and hooks.
	 */
	private function __construct()
	{

		$this->base_url = UNIFIED_BASE_URL;
		
		$this->admin_notices = new UNIFIED_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'unified_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'unified_init'], 10);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_unified_check_payment_status', array($this, 'unified_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_unified_check_payment_status', array($this, 'unified_handle_check_payment_status_request'));

		add_action('wp_ajax_unified_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_unified_popup_closed_event', array($this, 'handle_popup_close'));

		add_action('wp_ajax_unified_manual_sync', [$this, 'unified_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'unified_add_cron_interval']);
		add_action('unified_cron_event', [$this, 'handle_cron_event']);
		add_action('wp_ajax_unified_block_gateway_process', [$this,'handle_unified_gateway_ajax']);
		add_action('wp_ajax_nopriv_unified_block_gateway_process', [$this,'handle_unified_gateway_ajax']); 
		add_action('wp', function () {
		    // Allow notices ONLY on checkout page
		    if ( ! is_checkout() ) {
			remove_action(
			    'woocommerce_before_checkout_form',
			    'woocommerce_output_all_notices',
			    10
			);
			// Clear queued notices (errors, success, info)
			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}
		    }

		});

		add_action('woocommerce_checkout_create_order', function($order){
			$order->delete_meta_data('_wc_order_attribution_session_entry');
		}, 10);
		add_action('init', function() {
			if (function_exists('WC') && WC()->session == null) {
				WC()->initialize_session();
			}
		});

		add_action('woocommerce_before_checkout_form', [$this, 'unified_show_checkout_error']);
	}

	/**
	 * ── FIXED ──────────────────────────────────────────────────────────────────
	 * Handle the block checkout AJAX payment request.
	 *
	 * Root cause of "No available payment accounts":
	 * `new UNIFIED_PAYMENT_GATEWAY()` creates a cold instance. In an AJAX
	 * context WooCommerce has not called init_settings() on it, so
	 * $this->sandbox defaults to false and get_option() returns empty values.
	 * get_next_available_account() then finds no matching keys → returns false.
	 *
	 * Fix: pull the already-booted instance from WC()->payment_gateways().
	 * That instance was fully initialised during the normal WC boot cycle so
	 * sandbox mode and account keys are correct.
	 * ───────────────────────────────────────────────────────────────────────────
	 */
	function handle_unified_gateway_ajax(){

		// Nonce verification
		$nonce = isset($_POST['nonce'])
			? sanitize_text_field(wp_unslash($_POST['nonce']))
			: '';

		if (empty($nonce) || !wp_verify_nonce($nonce, 'unified_payment')) {
			wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
			die;
		}

		// Pull the already-initialised gateway from the WC registry.
		// Never use `new UNIFIED_PAYMENT_GATEWAY()` here — see note above.
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$unifiedPayment = $gateways['unified'] ?? null;

		if (!$unifiedPayment) {
			// Fallback: manually instantiate and force-load settings from DB.
			// Should never happen in normal operation.
			$unifiedPayment = new UNIFIED_PAYMENT_GATEWAY();
			$unifiedPayment->init_settings();
			$unifiedPayment->load_gateway_settings();

			wc_get_logger()->warning(
				'Unified: gateway not found in WC registry during AJAX — fell back to manual instantiation.',
				['source' => 'unified-payment-gateway']
			);
		}

		$orderID = WC()->session ? WC()->session->get('store_api_draft_order') : null;

		$status = [];
		if($orderID){
			$status = $unifiedPayment->process_payment($orderID);
		}else{
			wc_add_notice(__('Invalid order.', 'unified-payment-gateway'), 'error');
			$status = ['result' => 'fail','error' => 'Invalid order.'];
		}
		
		wp_send_json($status);
		die;
	}

	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function unified_init()
	{
		// Check if the environment is compatible
		$environment_warning = unified_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Initialize gateways
		$this->unified_init_gateways();

		// Register blocks gateway
		$this->unified_init_blocks();
		
		add_action( 'enqueue_block_assets', [ $this, 'register_blocks_assets' ] );

		// Initialize REST API
		$rest_api = UNIFIED_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->unified_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(UNIFIED_PAYMENT_GATEWAY_FILE), [$this, 'unified_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'unified_plugin_row_meta'], 10, 2);
	}

	public function unified_show_checkout_error()
	{
		if (!function_exists('WC')) return;

		$error = WC()->session->get('unified_error');
		if (!$error) return;

		$messages = [
			'failed'    => 'Payment failed. Please try again.',
			'cancelled' => 'Payment was cancelled.',
			'expired'   => 'Payment session expired. Please try again.'
		];

		// Clear error immediately
		WC()->session->__unset('unified_error');

		if (isset($messages[$error])) {
			wc_add_notice($messages[$error], 'error');
		}
	}

	/**
	 * Initialize gateways.
	 */
	private function unified_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once UNIFIED_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-unified-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'UNIFIED_PAYMENT_GATEWAY';			
			return $methods;
		});
	}

	private function unified_init_blocks() {
		
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {

				require_once UNIFIED_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-unified-blocks-gateway.php';

				add_action( 'woocommerce_blocks_payment_method_type_registration', function( $registry ) {
					$registry->register( new UNIFIED_Blocks_Gateway() );
				});
			}
	
	}
	
	public function register_blocks_assets() {
		
		if (is_checkout()) {
			$image_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/loader.gif';
			wp_register_script(
				'unified-blocks-js',
				plugin_dir_url( UNIFIED_PAYMENT_GATEWAY_FILE ) . 'assets/js/unified-blocks.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
				'1.0.0',
				true
			);

			$settings = get_option( 'woocommerce_unified_settings', [] );

			wp_localize_script(
				'unified-blocks-js',
				'unified_params',
				[ 'settings' => $settings,
				 'ajax_url' => admin_url('admin-ajax.php'),
				 'unified_loader' => $image_url,
				 'unified_nonce' => wp_create_nonce('unified_payment'), 
				 'checkout_url' => wc_get_checkout_url(),
				 'payment_method' => 'unified' 
				]
			);
	
		}
	}


	private function get_api_url($endpoint)
	{
		return $this->base_url . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function unified_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=unified')) . '">' . esc_html__('Settings', 'unified-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}

	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function unified_plugin_row_meta($links, $file)
	{
		if (plugin_basename(UNIFIED_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('unified_docs_url', 'https://pay.unified.xyz/api/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'unified-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('unified_support_url', 'https://pay.unified.xyz/reach-out')) . '" target="_blank">' . esc_html__('Support', 'unified-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function unified_handle_environment_check()
	{
		$environment_warning = unified_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->unified_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 * @param $request
	 */
	public function unified_handle_check_payment_status_request($request)
	{
		check_ajax_referer('unified_payment', 'security');

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id) {
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'unified-payment-gateway')));
		}

		// Call the function to check payment status with the validated order ID
		return $this->unified_check_payment_status($order_id);
	}

	/**
	 * Check the payment status for an order.
	 * @param int $order_id
	 * @return WP_REST_Response
	 */
	public function unified_check_payment_status($order_id)
	{
		// Get the order details
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response(['error' => esc_html__('Order not found', 'unified-payment-gateway')], 404);
		}

		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'unified_payment')) {
			wp_send_json_error(['message' => 'Nonce verification failed.']);
			wp_die();
		}

		$payment_token = $order->get_meta('_unified_pay_id');
		$transactionStatusApiUrl = $this->get_api_url('/api/update-txn-status');
		$response = wp_remote_post($transactionStatusApiUrl, [
			'method'    => 'POST',
			'body'      => wp_json_encode(['order_id' => $order_id, 'payment_token' => $payment_token]),
			'headers'   => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $security,
			],
			'timeout'   => 15,
		]);

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);
			
		$payment_return_url = $order->get_checkout_order_received_url();

		$gateway_id = 'unified'; // Replace with your gateway ID
		$payment_gateways = WC()->payment_gateways->payment_gateways();
		if (isset($payment_gateways[$gateway_id])) {
			$gateway = $payment_gateways[$gateway_id];
			$configured_order_status = sanitize_text_field($gateway->get_option('order_status'));
		} else {
			wp_send_json_error(['message' => 'Payment gateway not found.']);
			wp_die();
		}
		wc_clear_notices();
		// Determine order status
		if ($order->is_paid() || (isset($response_data['transaction_status']) && ($response_data['transaction_status'] == "success" || $response_data['transaction_status'] == "paid" || $response_data['transaction_status'] == "processing"))) {
			$order->update_status($configured_order_status, 'Order marked as ' . $configured_order_status . ' by Unified.');
			wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
			exit;
		}
		
		if ($order->has_status('failed') || (isset($response_data['transaction_status']) && $response_data['transaction_status'] == "failed")) {
			wc_add_notice( 'Payment Failed: Transaction declined, please try another card.', 'error' );
			$order->update_status('failed', 'Order marked as failed by Unified.');
			wp_send_json_success(['status' => 'failed', 'redirect_url' => $payment_return_url]);
			exit;
		}
		
		if ($order->has_status('cancelled') || (isset($response_data['transaction_status']) && $response_data['transaction_status'] == "canceled")) {
			if (WC()->cart) {
				WC()->cart->empty_cart();
				WC()->session->cleanup_sessions();
				WC()->session->destroy_session();
				WC()->session->set_customer_session_cookie( false );
			}
			wc_add_notice( 'Payment Canceled: The Payment method canceled your transaction.', 'error' );
			$order->update_status('cancelled', 'Order marked as canceled by Unified.');
			wp_send_json_success(['status' => 'cancelled', 'redirect_url' => $order->get_cancel_order_url()]);
			exit;
		}

		if ($order->has_status(['on-hold', 'pending'])) {
			wp_send_json_success(['status' => 'pending', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status('refunded')) {
			wp_send_json_success(['status' => 'refunded', 'redirect_url' => $payment_return_url]);
			exit;
		}

		// Default response (unknown status)
		wp_send_json_success(['status' => 'unknown', 'redirect_url' => $payment_return_url]);
		exit;
	}

	public function handle_popup_close()
	{
		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'unified_payment')) {
			wp_send_json_error(['message' => 'Nonce verification failed.']);
			wp_die();
		}

		// Get the order ID from the request
		$order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : null;

		// Validate order ID
		if (!$order_id) {
			wp_send_json_error(['message' => 'Order ID is missing.']);
			wp_die();
		}

		// Fetch the WooCommerce order
		$order = wc_get_order($order_id);

		// Check if the order exists
		if (!$order) {
			wp_send_json_error(['message' => 'Order not found in WordPress.']);
			wp_die();
		}

		//Get uuid from WP
		$payment_token = $order->get_meta('_unified_pay_id');
		
		// Proceed only if the order status is 'pending'
		if ($order->get_status() === 'pending' || $order->get_status() === 'processing' || $order->get_status() === 'failed' || $order->get_status() === 'cancelled' || $order->get_status() === 'completed') {
			// Call the Unified API to update status
			$transactionStatusApiUrl = $this->get_api_url('/api/update-txn-status');
			$response = wp_remote_post($transactionStatusApiUrl, [
				'method'    => 'POST',
				'body'      => wp_json_encode(['order_id' => $order_id, 'payment_token' => $payment_token]),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $security,
				],
				'timeout'   => 15,
			]);

			// Check for errors in the API request
			if (is_wp_error($response)) {
				wp_send_json_error(['message' => 'Failed to connect to the Unified API.']);
				wp_die();
			}

			// Parse the API response
			$response_body = wp_remote_retrieve_body($response);
			$response_data = json_decode($response_body, true);

			$log_message = 'Popup closed. Transaction status received from Unified API.';

			wc_get_logger()->info($log_message, [
				'source'  => 'unified-payment-gateway',
				'context' => [
					'order_id'           => $order_id,
					'transaction_status' => $response_data['transaction_status'] ?? 'unknown',
					'payment_status' => $response_data['payment_status'] ?? 'unknown'
				],
			]);

			// Ensure the response contains the expected data
			if (!isset($response_data['payment_status'])) {
				wp_send_json_error(['message' => 'Invalid response from Unified API.']);
				wp_die();
			}

			// Get the configured order status from the payment gateway settings
			$gateway_id = 'unified';
			$payment_gateways = WC()->payment_gateways->payment_gateways();
			if (isset($payment_gateways[$gateway_id])) {
				$gateway = $payment_gateways[$gateway_id];
				$configured_order_status = sanitize_text_field($gateway->get_option('order_status'));
			} else {
				wp_send_json_error(['message' => 'Payment gateway not found.']);
				wp_die();
			}

			// Validate the configured order status
			$allowed_statuses = wc_get_order_statuses();
			if (!array_key_exists('wc-' . $configured_order_status, $allowed_statuses)) {
				wp_send_json_error(['message' => 'Invalid order status configured: ' . esc_html($configured_order_status)]);
				wp_die();
			}

			$payment_return_url = $order->get_checkout_order_received_url();
			wc_clear_notices();
			if (isset($response_data['payment_status'])) {
				// Handle transaction status from API
				switch ($response_data['payment_status']) {
					case 'success':
					case 'paid':
					case 'processing':
						try {
							$order->update_status($configured_order_status, 'Order marked as ' . $configured_order_status . ' by Unified.');
							wp_send_json_success(['message' => 'Order status updated successfully.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;

					case 'failed':
						try {
							wc_add_notice( 'Payment Failed: Transaction declined, please try another card.', 'error' );
							$order->update_status('failed', 'Order marked as failed by Unified.');
							wp_send_json_success(['message' => 'Order status updated to failed.', 'order_id' => $order_id, 'notices' => 'Payment Failed: We couldn\'t process your payment. Please try again or use another payment method.']);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;

					case 'canceled':
						try {
							if (WC()->cart) {
								WC()->cart->empty_cart();
								WC()->session->cleanup_sessions();
								WC()->session->destroy_session();
								WC()->session->set_customer_session_cookie( false );
							}
							wc_add_notice( 'Payment Canceled: The Payment method canceled your transaction.', 'error' );
							$order->update_status('cancelled', 'Order marked as canceled by Unified.');
							wp_send_json_success(['message' => 'Order status updated to canceled.', 'order_id' => $order_id, 'redirect_url' => $order->get_cancel_order_url(),'notices' => 'Payment Canceled: The payment was canceled. Please try again if you wish to complete your purchase.']);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;

					default:
						wp_send_json_error(['message' => 'Unknown Payment Status received.']);
				}
			}
		} else {
			// Skip API call if the order status is not 'pending'
			wp_send_json_success(['message' => 'No update required as the order status is not pending.', 'order_id' => $order_id]);
		}

		wp_die();
	}

	/**
     * Add custom cron schedules.
     */
	public function unified_add_cron_interval($schedules)
	{
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'unified-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		wc_get_logger()->info('Automatic payment status checks have been enabled.', ['source' => 'unified-payment-gateway']);

		// Clear existing scheduled event if it exists
		$timestamp = wp_next_scheduled('unified_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'unified_cron_event');
		}

		// Schedule with new interval
		wp_schedule_event(time(), 'every_two_hours', 'unified_cron_event');
	}

	function deactivate_cron_job()
	{
		wc_get_logger()->info('Automatic payment status checks have been disabled.', ['source' => 'unified-payment-gateway']);
		wp_clear_scheduled_hook('unified_cron_event');
	}

	public function unified_manual_sync_callback() {
		$logger_context = ['source' => 'unified-payment-gateway'];

		// Verify nonce
		if (!check_ajax_referer('unified_sync_nonce', 'nonce', false)) {
			wc_get_logger()->error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'unified-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
			wc_get_logger()->error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'unified-payment-gateway')
			], 403);
			wp_die();
		}

		wc_get_logger()->info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();

			// Run the cron handler to get updated statuses
			$statusSummary = $this->handle_cron_event($_POST['accounts']);

			$output = ob_get_clean();
			if (!empty($output)) {
				wc_get_logger()->warning('Unexpected output generated during sync: ' . $output, $logger_context);
			}

			if (empty($statusSummary)) {
				// Treat as failure if nothing updated or connectivity issue
				wc_get_logger()->error('Payment accounts sync failed: No valid accounts updated.', $logger_context);
				wp_send_json_error([
					'message' => __('Sync failed: Unable to connect to the sync service or no valid accounts found.', 'unified-payment-gateway')
				], 500);
				wp_die();
			}

			wc_get_logger()->info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'   => __('Payment accounts synchronized successfully.', 'unified-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses'  => $statusSummary
			]);

		} catch (Exception $e) {
			wc_get_logger()->error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'unified-payment-gateway') . $e->getMessage(),
				'code'    => $e->getCode()
			], 500);
		}

		wp_die();
	}

	public function handle_cron_event($accounts = []) {
		$logger_context = ['source' => 'unified-payment-gateway'];

		if (empty($accounts)) {
			$accounts = get_option('woocommerce_unified_payment_gateway_accounts');
		}

		if (is_string($accounts)) {
			$accounts = maybe_unserialize($accounts);
		}

		if (!is_array($accounts) || empty($accounts)) {
			wc_get_logger()->warning('No payment accounts found. Sync aborted.', $logger_context);
			return [];
		}

		// ✅ Get global sandbox setting
		$global_settings = get_option('woocommerce_unified_settings', []);
		$global_settings = maybe_unserialize($global_settings);

		$isGlobalSandbox = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		wc_get_logger()->info('Global Mode: ' . ($isGlobalSandbox ? 'SANDBOX' : 'LIVE'), $logger_context);

		$accountsData = [];

		// ✅ Build payload based on global mode
		foreach ($accounts as $account) {

			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			if ($isGlobalSandbox) {
				// 🔹 SANDBOX MODE → send ONLY sandbox keys
				if ($isSandboxEnabled && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
					$accountsData[] = [
						'account_name' => $account['title'] ?? 'N/A',
						'public_key'   => $account['sandbox_public_key'],
						'secret_key'   => $account['sandbox_secret_key'],
						'mode'         => 'sandbox',
					];
				}
			} else {
				// 🔹 LIVE MODE → send ONLY live keys
				if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
					$accountsData[] = [
						'account_name' => $account['title'] ?? 'N/A',
						'public_key'   => $account['live_public_key'],
						'secret_key'   => $account['live_secret_key'],
						'mode'         => 'live',
					];
				}
			}
		}

		if (empty($accountsData)) {
			wc_get_logger()->warning('No valid credentials found for current mode. Sync skipped.', $logger_context);
			return [];
		}

		$url = esc_url($this->base_url . '/api/sync-account-status');

		wc_get_logger()->info('Sync API URL: ' . $url, $logger_context);
		wc_get_logger()->info('Sync Payload: ' . json_encode($accountsData), $logger_context);

		$response = wp_remote_post($url, [
			'headers' => ['Content-Type' => 'application/json'],
			'body'    => json_encode(['accounts' => $accountsData]),
			'timeout' => 15,
		]);

		// ❌ Connection error
		if (is_wp_error($response)) {
			wc_get_logger()->error('Connection failed: ' . $response->get_error_message(), $logger_context);
			return $this->build_status_summary_from_db($accounts);
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		wc_get_logger()->info('HTTP Code: ' . $http_code, $logger_context);
		wc_get_logger()->info('Raw Response: ' . $response_body, $logger_context);

		$response_data = json_decode($response_body, true);

		// ❌ Invalid JSON
		if (json_last_error() !== JSON_ERROR_NONE) {
			wc_get_logger()->error('Invalid JSON response: ' . json_last_error_msg(), $logger_context);
			return $this->build_status_summary_from_db($accounts);
		}

		// ✅ Support both API formats
		$apiStatuses = $response_data['data'] ?? $response_data['statuses'] ?? [];

		wc_get_logger()->info('Parsed API statuses: ' . json_encode($apiStatuses), $logger_context);

		$updated = false;
		$statusSummary = [];
		$processedKeys = [];

		// ✅ All account will be active by default.
		foreach ($accounts as &$account) {

			if ($isGlobalSandbox) {
				// Only sandbox relevant
				if (!empty($account['has_sandbox']) && $account['has_sandbox'] === 'on') {
					if (!in_array($account['sandbox_public_key'], $processedKeys)) {
						$account['sandbox_status'] = 'active';

						$statusSummary[] = [
							'title'  => $account['title'],
							'mode'   => 'sandbox',
							'status' => 'active',
							'usable' => true,
							'reason' => 'No response from sync service',
						];

						$updated = true;
					}
				}
			} else {
				// Only live relevant
				if (!in_array($account['live_public_key'], $processedKeys)) {
					$account['live_status'] = 'active';

					$statusSummary[] = [
						'title'  => $account['title'],
						'mode'   => 'live',
						'status' => 'active',
						'usable' => true,
						'reason' => 'No response from sync service',
					];

					$updated = true;
				}
			}
		}

		// ✅ Update Account Statuses based on API response
		foreach ($apiStatuses as $statusData) {

			if (empty($statusData['mode']) || empty($statusData['public_key']) || empty($statusData['status'])) {
				continue;
			}

			$mode = $statusData['mode'];
			$publicKey = $statusData['public_key'];
			$status = strtolower($statusData['status']);

			$processedKeys[] = $publicKey;

			foreach ($accounts as &$account) {

				$matched = false;

				if ($mode === 'live' && $account['live_public_key'] === $publicKey) {
					$account['live_status'] = $status;
					$matched = true;
				}

				if ($mode === 'sandbox' && $account['sandbox_public_key'] === $publicKey) {
					$account['sandbox_status'] = $status;
					$matched = true;
				}

				if ($matched) {
					$usable = ($status === 'active');
					$reason = $statusData['message'] ?? '';

					if (!$usable && empty($reason)) {
						$reason = 'Account is not active';
					}

					$statusSummary[] = [
						'title'  => $account['title'] ?? 'N/A',
						'mode'   => $mode,
						'status' => $status,
						'usable' => $usable,
						'reason' => $reason,
					];

					$updated = true;
				}
			}
		}


		// ✅ Save updates
		if ($updated) {
			update_option('woocommerce_unified_payment_gateway_accounts', $accounts);

			wc_get_logger()->info('Account statuses updated.', [
				'source'  => 'unified-payment-gateway',
				'summary' => $statusSummary
			]);
		} else {
			wc_get_logger()->info('No changes required.', $logger_context);
		}

		return $statusSummary;
	}

	/**
	 * Build fallback summary from DB without calling remote service
	 */
	private function build_status_summary_from_db($accounts) {
		$summary = [];
		foreach ($accounts as $account) {
			if (!empty($account['live_status'])) {
				$summary[] = [
					'title'  => $account['title'] ?? 'N/A',
					'mode'   => 'live',
					'status' => $account['live_status'],
					'usable' => $account['live_status'] === 'active',
					'reason' => $account['live_status'] === 'active' ? '' : 'Account is not active',
				];
			}
			if (!empty($account['sandbox_status'])) {
				$summary[] = [
					'title'  => $account['title'] ?? 'N/A',
					'mode'   => 'sandbox',
					'status' => $account['sandbox_status'],
					'usable' => $account['sandbox_status'] === 'active',
					'reason' => $account['sandbox_status'] === 'active' ? '' : 'Account is not active',
				];
			}
		}
		return $summary;
	}
}
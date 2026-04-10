<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class UNIFIED_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		// Initialize the logger
		$this->logger = wc_get_logger();
		

		add_action('rest_api_init', function () {
			// Remove WordPress's default CORS headers
			remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

			// Add custom CORS headers
			add_filter('rest_pre_serve_request', function ($value) {

			    header('Access-Control-Allow-Origin: '.UNIFIED_BASE_URL);
			    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
			    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, User-Agent, Accept');
			    header('Access-Control-Allow-Credentials: true');

			   // Safely get the request method
					$request_method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
					$request_method = $request_method ? strtoupper($request_method) : '';

					// Handle preflight request
					if ($request_method === 'OPTIONS') {
						status_header(200);
						exit;
					}

			    return $value;
			}, 15);
		    });
	}

	public function unified_register_routes()
	{
		// Log incoming request with sanitized parameters
		add_action('rest_api_init', function () {
			register_rest_route('unified/v1', '/data', array(
				'methods' => ['GET', 'POST'],
				'callback' => array($this, 'unified_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}

	private function unified_verify_api_key($api_key)
	{
	    $api_key = sanitize_text_field($api_key);

	    // Retrieve plugin options
	    $accounts_data = get_option('woocommerce_unified_payment_gateway_accounts');
	    $general_settings = get_option('woocommerce_unified_settings');

	    if (empty($accounts_data)) {
	        $this->logger->warning('No account data found', ['source' => 'unified-payment-gateway']);
	        return false;
	    }

	    // If it's a single account array, wrap it inside an array for consistency
	    if (isset($accounts_data['live_public_key']) || isset($accounts_data['sandbox_public_key'])) {
	        $accounts_data = [ $accounts_data ];
	    }

	    $sandbox = isset($general_settings['sandbox']) && $general_settings['sandbox'] === 'yes';

	    foreach ($accounts_data as $account_id => $account) {
	        // Ensure valid array
	        if (!is_array($account)) {
	            $this->logger->warning('Skipping invalid account entry', [
	                'source' => 'unified-payment-gateway',
	                'account_id' => $account_id,
	                'account_value' => $account
	            ]);
	            continue;
	        }

	        $public_key = $sandbox
	            ? sanitize_text_field($account['sandbox_public_key'] ?? '')
	            : sanitize_text_field($account['live_public_key'] ?? '');

	        $this->logger->info('Checking public key :: ' . $public_key, [
	            'source' => 'unified-payment-gateway',
	            'sandbox' => $sandbox,
	        ]);

	        if (!empty($public_key) && hash_equals($public_key, $api_key)) {
	            $this->logger->info('Keys matched successfully', [
	                'source' => 'unified-payment-gateway',
	                'account_id' => $account_id,
	            ]);
	            return true;
	        }
	    }

	    return false;
	}

	/**
	 * Central REST API Handler for Payment Notifications.
	 * * This function manages the synchronization between the payment provider and WooCommerce.
	 * It is specifically engineered to handle high-concurrency environments where a 
	 * server-to-server notification (POST) and a user-browser redirect (GET) might 
	 * trigger simultaneously.
	 *
	 * @author  Harry/UnifiedTeam
	 * @package UnifiedPaymentGateway
	 * @version 1.0.1
	 * @since   2026-04-10
	 * @ticket  [RA-87]
	 *
	 * @param  WP_REST_Request $request Incoming API request.
	 * @return WP_REST_Response|void Standard REST response or redirect.
	 */
	public function unified_handle_api_request(WP_REST_Request $request)
    {
        $method      = $request->get_method();
        $params      = $request->get_params();
        $log_context = array('source' => 'unified-payment-gateway');
        
        // Normalize payload: Check for nested 'api_data' (typical in Celery JSON posts)
        $data = isset($params['api_data']) ? $params['api_data'] : $params;

        $order_id         = intval($data['order_id'] ?? 0);
        $api_order_status = sanitize_text_field($data['order_status'] ?? '');
        $pay_id           = sanitize_text_field($data['pay_id'] ?? '');
        $api_key_raw      = $data['nonce'] ?? '';

        $this->logger->info(sprintf("Unified Payment: Received %s for Order #%s. Status: %s", $method, $order_id, $api_order_status), $log_context);

        if ($order_id <= 0) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid Order ID'], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->logger->error(sprintf("Unified Payment Error: Order #%s not found.", $order_id), $log_context);
            return new WP_REST_Response(['success' => false, 'message' => 'Order not found'], 404);
        }

        /**
         * 1. GUARD CLAUSE
         * Skip if the order is already successful to prevent double-processing.
         */
        $current_status = $order->get_status();
        if (in_array($current_status, ['processing', 'completed', 'shipping'])) {
            $this->logger->info(sprintf("Order #%s already handled (%s). Skipping update.", $order_id, $current_status), $log_context);
            if ($method === 'POST') {
                return new WP_REST_Response(['success' => true, 'message' => 'Order already handled.'], 200);
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        /**
         * 2. SECURITY CHECK (Encoded Approach)
         * Decodes the Base64 nonce sent by the Python worker before verification.
         */
        if ($method === 'POST') {
            $decoded_nonce = base64_decode($api_key_raw);
            if (empty($api_key_raw) || !$this->unified_verify_api_key($decoded_nonce)) {
                $this->logger->error(sprintf("Security Alert: INVALID_API_KEY for Order #%s", $order_id), $log_context);
                return new WP_REST_Response(['success' => false, 'error_code' => 'INVALID_API_KEY'], 401);
            }
        }

        /**
         * 3. STATUS MAPPING
         */
        $settings       = get_option('woocommerce_unified_settings', []);
        $success_status = $settings['order_status'] ?? 'processing';
        $status_map     = [
            'completed' => $success_status,
            'failed'    => 'failed',
            'expired'   => 'cancelled',
            'cancelled' => 'cancelled'
        ];
        $target_status  = $status_map[$api_order_status] ?? null;

        /**
         * 4. EXECUTION WITH ATOMIC LOCK & TRANSACTION
         */
        if ($target_status && $order->get_status() !== $target_status) {
            $lock_key = 'unified_lock_order_' . $order_id;

            // Transient-based concurrency lock
            if (get_transient($lock_key)) {
                $this->logger->info(sprintf("Order #%s currently locked. Concurrent request ignored.", $order_id), $log_context);
                if ($method === 'POST') return new WP_REST_Response(['success' => true], 200);
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            set_transient($lock_key, 'locked', 15);

            global $wpdb;
            $wpdb->query('START TRANSACTION');

            try {
                $source_desc = ($method === 'POST') ? 'Background Sync' : 'Customer Redirect';
                
                // Premium formatted note for merchant dashboard
                $note = "✅ **UNIFIED PAYMENT GATEWAY UPDATE**\n\n";
                $note .= "**Result:** Payment Successfully Verified\n";
                $note .= "**Source:** " . $source_desc . "\n";
                $note .= "**Transaction ID:** " . $pay_id . "\n\n";
                $note .= "*Automated synchronization complete.*";
                
                $order->update_status($target_status, $note);
                if ($pay_id) {
                    $order->update_meta_data('_unified_pay_id', $pay_id);
                }
                $order->save();
                
                $wpdb->query('COMMIT');
                $this->logger->info(sprintf("Success: Order #%s updated via %s.", $order_id, $source_desc), $log_context);

            } catch (\Exception $e) {
                $wpdb->query('ROLLBACK');
                $this->logger->error(sprintf("Critical Error on Order #%s: %s", $order_id, $e->getMessage()), $log_context);
            } finally {
                delete_transient($lock_key);
            }
        }

        /**
         * 5. FINAL RESPONSE
         */
        if ($method === 'POST') {
            return new WP_REST_Response(['success' => true], 200);
        }

        if (in_array($target_status, ['failed', 'cancelled'])) {
            wc_add_notice('Your payment was unsuccessful. Please try again.', 'error');
            wp_safe_redirect(wc_get_checkout_url());
        } else {
            wp_safe_redirect($order->get_checkout_order_received_url());
        }
        exit;
    }
}

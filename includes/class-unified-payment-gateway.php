<?php
if (!defined('ABSPATH')) {
	exit(); // Exit if accessed directly.
}

/**
 * Main WooCommerce Unified Payment Gateway class.
 */
class UNIFIED_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	protected $sandbox;
	private $base_url;
	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;
	private $version;

	private $admin_notices;
	private $accounts = [];
	private $current_account_index = 0;
	private $used_accounts = [];

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		 global $unified_config;

		// Check if WooCommerce is active
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', [$this, 'woocommerce_not_active_notice']);
			return;
		}

		// Instantiate the notices class
		$this->admin_notices = new UNIFIED_PAYMENT_GATEWAY_Admin_Notices();

		$this->base_url = UNIFIED_BASE_URL;
		
		// Define user set variables
		$this->id = UNIFIED_PLUGIN_ID;
		$this->icon = !empty($unified_config['icon']) ? $unified_config['icon'] : ''; // Define an icon URL if needed.
		$this->method_title       = !empty($unified_config['title']) ? $unified_config['title'] : '';
		$this->method_description = !empty($unified_config['description']) ? $unified_config['description'] : '';
		$this->version = !empty($unified_config['version']) ? $unified_config['version'] : '';

		// Load the settings
		$this->unified_init_form_fields();
		$this->init_settings();

		// Define properties
		$this->title = sanitize_text_field($this->get_option('title'));
		$this->description = !empty($this->get_option('description')) ? sanitize_textarea_field($this->get_option('description')) : ($this->get_option('show_consent_checkbox') === 'yes' ? 1 : 0);
		$this->enabled = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox = 'yes' === sanitize_text_field($this->get_option('sandbox')); // Use boolean
		$this->public_key = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('public_key')) : sanitize_text_field($this->get_option('sandbox_public_key'));
		$this->secret_key = $this->sandbox === 'no' ? sanitize_text_field($this->get_option('secret_key')) : sanitize_text_field($this->get_option('sandbox_secret_key'));
		$this->current_account_index = 0;

		// Define hooks and actions.
		add_action('woocommerce_update_options_payment_gateways_unified', [$this, 'unified_process_admin_options']);

		// Enqueue styles and scripts
		add_action('wp_enqueue_scripts', [$this, 'unified_enqueue_styles_and_scripts']);

		add_action('admin_enqueue_scripts', [$this, 'unified_admin_scripts']);

		add_action('wp_footer', [$this, 'render_unified_payment_popup']);

		// Add action to display test order tag in order details
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'unified_display_test_order_tag']);

		// Hook into WooCommerce to add a custom label to order rows
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'unified_add_custom_label_to_order_row'], 10, 2);

		add_filter('woocommerce_available_payment_gateways', [$this, 'hide_custom_payment_gateway_conditionally']);
	}

	private function get_api_url($endpoint)
	{
		return $this->base_url . $endpoint;
	}

	public function unified_process_admin_options()
	{
		parent::process_admin_options();

		$errors = [];
		$valid_accounts = [];

		if (!isset($_POST['unified_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['unified_accounts_nonce'])), 'unified_accounts_nonce_action')) {
			wp_die(esc_html__('Security check failed!', 'unified-payment-gateway'));
		}

		//  CHECK IF ACCOUNTS EXIST
		if (!isset($_POST['accounts']) || !is_array($_POST['accounts']) || empty($_POST['accounts'])) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'unified-payment-gateway');
		} else {
			$normalized_index = 0;
			$unique_live_keys = [];
			$unique_sandbox_keys = [];

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input is sanitized below
			$raw_accounts = isset($_POST['accounts']) ? wp_unslash($_POST['accounts']) : [];

			if (!is_array($raw_accounts)) {
				$raw_accounts = [];
			}

			$accounts = array_map(function ($account) {
				if (is_array($account)) {
					return array_map('sanitize_text_field', $account);
				}
				return sanitize_text_field($account);
			}, $raw_accounts);

			foreach ($accounts as $index => $account) {
				// Sanitize input
				$account_title = sanitize_text_field($account['title'] ?? '');
				$priority = isset($account['priority']) ? intval($account['priority']) : 1;
				$live_public_key = sanitize_text_field($account['live_public_key'] ?? '');
				$live_secret_key = sanitize_text_field($account['live_secret_key'] ?? '');
				$sandbox_public_key = sanitize_text_field($account['sandbox_public_key'] ?? '');
				$sandbox_secret_key = sanitize_text_field($account['sandbox_secret_key'] ?? '');
				$has_sandbox = isset($account['has_sandbox']); // Checkbox handling

				//  Ignore empty accounts
				if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
					continue;
				}

				//  Validate required fields
				if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
					// Translators: %s is the account title.
					$errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'unified-payment-gateway'), $account_title);
					continue;
				}

				//  Ensure live keys are unique
				$live_combined = $live_public_key . '|' . $live_secret_key;
				if (in_array($live_combined, $unique_live_keys)) {
					// Translators: %s is the account title.
					$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'unified-payment-gateway'), $account_title);
					continue;
				}
				$unique_live_keys[] = $live_combined;

				//  Ensure live keys are different
				if ($live_public_key === $live_secret_key) {
					// Translators: %s is the account title.
					$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'unified-payment-gateway'), $account_title);
				}

				//  Sandbox Validation
				if ($has_sandbox) {
					if (!empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
						// Sandbox keys must be unique
						$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
						if (in_array($sandbox_combined, $unique_sandbox_keys)) {
							// Translators: %s is the account title.
							$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'unified-payment-gateway'), $account_title);
							continue;
						}
						$unique_sandbox_keys[] = $sandbox_combined;

						// Sandbox keys must be different
						if ($sandbox_public_key === $sandbox_secret_key) {
							// Translators: %s is the account title.
							$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'unified-payment-gateway'), $account_title);
						}
					}
				}
				// Add the 'status' field, defaulting to 'active' for new accounts
				$sandbox_status = isset($account['sandbox_status']) ? sanitize_text_field($account['sandbox_status']) : 'Active';
				$live_status = isset($account['live_status']) ? sanitize_text_field($account['live_status']) : 'Active';
				// Store valid account
				$valid_accounts[$normalized_index] = [
					'title' => $account_title,
					'priority' => $priority,
					'live_public_key' => $live_public_key,
					'live_secret_key' => $live_secret_key,
					'sandbox_public_key' => $sandbox_public_key,
					'sandbox_secret_key' => $sandbox_secret_key,
					'has_sandbox' => $has_sandbox ? 'on' : 'off',
					'sandbox_status' => $has_sandbox ? $sandbox_status : '',
					'live_status' => $live_status,
				];
				$normalized_index++;
			}
		}

		//  Ensure at least one valid account exists
		if (empty($valid_accounts) && empty($errors)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'unified-payment-gateway');
		}

		//  Stop saving if there are any errors
		if (empty($errors)) {
			update_option('woocommerce_unified_payment_gateway_accounts', $valid_accounts);
			$this->admin_notices->unified_add_notice('settings_success', 'notice notice-success', __('Settings saved successfully.', 'unified-payment-gateway'));
			if (class_exists('UNIFIED_PAYMENT_GATEWAY_Loader')) {
				$loader = UNIFIED_PAYMENT_GATEWAY_Loader::get_instance(); // Use the static method
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event(); // Perform sync immediately
				}
			}
		} else {
			foreach ($errors as $error) {
				$this->admin_notices->unified_add_notice('settings_error', 'notice notice-error', $error);
			}
		}

		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
	}

	/**
	 * Initialize gateway settings form fields.
	 */
	public function unified_init_form_fields()
	{
		$this->form_fields = $this->unified_get_form_fields();
	}

	/**
	 * Get form fields.
	 */
	public function unified_get_form_fields()
	{
		$form_fields = [
			'enabled' => [
				'title' => __('Enable/Disable', 'unified-payment-gateway'),
				'label' => __('Enable Unified Payment Gateway', 'unified-payment-gateway'),
				'type' => 'checkbox',
				'description' => '',
				'default' => 'yes',
			],
			'title' => [
				'title' => __('Title', 'unified-payment-gateway'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'unified-payment-gateway'),
				'default' => __('Credit/Debit Card', 'unified-payment-gateway'),
				'desc_tip' => __('Enter the title of the payment gateway as it will appear to customers during checkout.', 'unified-payment-gateway'),
			],
			'description' => [
				'title' => __('Description', 'unified-payment-gateway'),
				'type' => 'text',
				'description' => __('Provide a brief description of the Unified Payment Gateway option.', 'unified-payment-gateway'),
				'default' => 'Description of the Unified Payment Gateway Option.',
				'desc_tip' => __('Enter a brief description that explains the Unified Payment Gateway option.', 'unified-payment-gateway'),
			],
			'instructions' => [
				'title' => __('Instructions', 'unified-payment-gateway'),
				'type' => 'title',
				// Translators comment added here
				/* translators: 1: Link to developer account */
				'description' => sprintf(
					/* translators: %1$s is a link to the developer account. %2$s is used for any additional formatting if necessary. */
					__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'unified-payment-gateway'),
					'<strong><a class="unified-instructions-url" href="' .
						esc_url($this->base_url . '/developers') .
						'" target="_blank">' .
						__('click here to access your developer account', 'unified-payment-gateway') .
						'</a></strong><br>',
					''
				),
				'desc_tip' => true,
			],
			'sandbox' => [
				'title' => __('Sandbox', 'unified-payment-gateway'),
				'label' => __('Enable Sandbox Mode', 'unified-payment-gateway'),
				'type' => 'checkbox',
				'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'unified-payment-gateway'),
				'default' => 'no',
			],
			'accounts' => [
				'title' => __('Payment Accounts', 'unified-payment-gateway'),
				'type' => 'accounts_repeater', // Custom field type for dynamic accounts
				'description' => __('Add multiple payment accounts dynamically.', 'unified-payment-gateway'),
			],
			'order_status' => [
				'title' => __('Order Status', 'unified-payment-gateway'),
				'type' => 'select',
				'description' => __('Select the order status to be set after successful payment.', 'unified-payment-gateway'),
				'default' => '', // Default is empty, which is our placeholder
				'desc_tip' => true,
				'id' => 'order_status_select', // Add an ID for targeting
				'options' => [
					// '' => __('Select order status', 'unified-payment-gateway'), // Placeholder option
					'processing' => __('Processing', 'unified-payment-gateway'),
					'completed' => __('Completed', 'unified-payment-gateway'),
				],
			],
			'show_consent_checkbox' => [
				'title' => __('Show Consent Checkbox', 'unified-payment-gateway'),
				'label' => __('Enable consent checkbox on checkout page', 'unified-payment-gateway'),
				'type' => 'checkbox',
				'description' => __('Check this box to show the consent checkbox on the checkout page. Uncheck to hide it.', 'unified-payment-gateway'),
				'default' => 'no',
			],
		];

		return apply_filters(
			'unified_woocommerce_gateway_settings_fields_' . $this->id,
			$form_fields,
			$this
		);
	}

	public function generate_accounts_repeater_html($key, $data)
	{

		$option_value = get_option('woocommerce_unified_payment_gateway_accounts', []);
		$option_value = maybe_unserialize($option_value);
		$active_account = get_option('unified_active_account', 0); // Store active account ID
		$global_settings = get_option('woocommerce_unified_settings', []);
		$global_settings = maybe_unserialize($global_settings);
		$sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		ob_start();
?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div id="global-error" class="error-message" style="color: red; margin-bottom: 10px;"></div>
				<div class="unified-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="unified-sync-account">
							<span id="unified-sync-status"></span>
							<button class="button" class="unified-sync-accounts" id="unified-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Sync Accounts', 'unified-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>


					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'unified-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							?>
							<div class="unified-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]"
									value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]"
									value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">

									<h4>
										<span class="account-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'unified-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down <?php echo esc_attr($this->id); ?>-toggle-btn" aria-hidden="true"></i>
									</h4>

									<div class="action-button">
										<div class="account-status-block" style="float: right;">
											<span class="account-status-label 
									    <?php echo esc_attr($sandbox_enabled ? 'sandbox-status' : 'live-status'); ?> 
									    <?php echo esc_attr(strtolower($sandbox_enabled ? ($sandbox_status ?? '') : ($live_status ?? ''))); ?>">
												<?php
												if ($sandbox_enabled) {
													echo esc_html__('Sandbox Account Status: ', 'unified-payment-gateway') . esc_html(ucfirst($sandbox_status));
												} else {
													echo esc_html__('Live Account Status: ', 'unified-payment-gateway') . esc_html(ucfirst($live_status));
												} ?>
											</span>
										</div>
										<button type="button" class="delete-account-btn">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>
								
								<div class="<?php echo esc_attr($this->id); ?>-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account Name', 'unified-payment-gateway'); ?></label>
											<input type="text" class="account-title"
												name="accounts[<?php echo esc_attr($index); ?>][title]"
												placeholder="<?php esc_attr_e('Account Title', 'unified-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'unified-payment-gateway'); ?></label>
											<input type="number" class="account-priority"
												name="accounts[<?php echo esc_attr($index); ?>][priority]"
												placeholder="<?php esc_attr_e('Priority', 'unified-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>
									</div>

									<div class="add-blog">

										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'unified-payment-gateway'); ?></label>
											<input type="text" class="live-public-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_public_key]"
												placeholder="<?php esc_attr_e('Public Key', 'unified-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]"
												placeholder="<?php esc_attr_e('Secret Key', 'unified-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>

									<div class="account-checkbox">
										<?php
											$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
											$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox"
											class="<?php echo esc_attr( $checkbox_class ); ?>"
											id="<?php echo esc_attr( $checkbox_id ); ?>"
											name="accounts[<?php echo esc_attr( $index ); ?>][has_sandbox]"
											<?php checked( ! empty( $account['sandbox_public_key'] ) ); ?>>
										<label for="<?php echo esc_attr( $checkbox_id ); ?>">
											<?php esc_html_e( 'Do you have the sandbox keys?', 'unified-payment-gateway' ); ?>
										</label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = empty($account['sandbox_public_key']) ? 'display: none;' : '';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>"
									     class="<?php echo esc_attr($sandbox_container_class); ?>"
									     style="<?php echo esc_attr($sandbox_display_style); ?>">

									    <div class="add-blog">
									        <div class="account-input">
									            <label><?php esc_html_e('Sandbox Keys', 'unified-payment-gateway'); ?></label>
									            <input type="text" class="sandbox-public-key"
									                   name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]"
									                   placeholder="<?php esc_attr_e('Public Key', 'unified-payment-gateway'); ?>"
									                   value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
									        </div>
									        <div class="account-input">
									            <input type="text" class="sandbox-secret-key"
									                   name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]"
									                   placeholder="<?php esc_attr_e('Secret Key', 'unified-payment-gateway'); ?>"
									                   value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
									        </div>
									    </div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('unified_accounts_nonce_action', 'unified_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button unified-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'unified-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
<?php return ob_get_clean();
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment($order_id, $used_accounts = [])
	{
		global $wpdb;
		$logger_context = ['source' => 'unified-payment-gateway'];

		// Retrieve client IP
		$ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			$ip_address = 'invalid';
		}

		// **Rate Limiting**
		$window_size = 30; // 30 seconds
		$max_requests = 100;
		$timestamp_key = "rate_limit_{$ip_address}_timestamps";
		$request_timestamps = get_transient($timestamp_key) ?: [];

		// Remove old timestamps
		$timestamp = time();
		$request_timestamps = array_filter($request_timestamps, fn($ts) => $timestamp - $ts <= $window_size);

		if (count($request_timestamps) >= $max_requests) {
			wc_get_logger()->warning("Rate limit exceeded for IP: {$ip_address}", $logger_context);
			wc_add_notice(__('Too many requests. Please try again later.', 'unified-payment-gateway'), 'error');
			return ['result' => 'fail'];
		}

		// Add the current timestamp
		$request_timestamps[] = $timestamp;
		set_transient($timestamp_key, $request_timestamps, $window_size);

		// **Retrieve Order**
		$order = wc_get_order($order_id);
		
		if (!$order) {
			wc_get_logger()->error("Invalid order ID: {$order_id}", $logger_context);
			wc_add_notice(__('Invalid order.', 'unified-payment-gateway'), 'error');
			return ['result' => 'fail'];
		}
		//BeaverTech Code Change start
		$current_order_status = $order->get_status();
		if ($current_order_status === 'completed') {
			if (WC()->cart) {
				WC()->cart->empty_cart();
				WC()->session->cleanup_sessions();
				WC()->session->destroy_session();
				WC()->session->set_customer_session_cookie( false );

			}

			// Return a successful response to Unified API.
			$payment_return_url = esc_url($order->get_checkout_order_received_url());
			return [
				'result'       => 'success',
				'order_id'     => $order->get_id(),
				'payment_status'     => 'success',
				'redirect_url' => esc_url($order->get_checkout_order_received_url()),
			];
		}elseif($current_order_status === 'cancelled'){
			if (WC()->cart) {
				WC()->cart->empty_cart();
				WC()->session->cleanup_sessions();
				WC()->session->destroy_session();
				WC()->session->set_customer_session_cookie( false );
			}
			
			return [
				'result'       => 'success',
				'order_id'     => $order->get_id(),
				'payment_status'     => 'success',
				'redirect_url' => esc_url($order->get_cancel_order_url()),
			];
			
		}
		//BeaverTech Code Change end

		// **Sandbox Mode Handling**
		if ($this->sandbox) {
			$test_note = __('This is a test order processed in sandbox mode.', 'unified-payment-gateway');
			$existing_notes = get_comments(['post_id' => $order->get_id(), 'type' => 'order_note', 'approve' => 'approve']);

			if (!array_filter($existing_notes, fn($note) => trim($note->comment_content) === trim($test_note))) {
				$order->update_meta_data('_is_test_order', true);
				$order->add_order_note($test_note);
			}
			wc_get_logger()->info("Sandbox mode: test order flag set for Order ID: {$order_id}", $logger_context);
		}
		$last_failed_account = null; // Track the last account that reached the limit
		$previous_account = null;
		// **Start Payment Process**
		while (true) {
			$account = $this->get_next_available_account($used_accounts);

			if (empty($account) || !is_array($account)) {
				// **Ensure email is sent to the last failed account**
				if ($last_failed_account) {
					wc_get_logger()->info("Sending notification to account '{$last_failed_account['title']}' due to no available alternatives.", $logger_context);
					// Only send switch email if more than one valid account exists
					if ($this->has_multiple_accounts()) {
						$this->send_account_switch_email($last_failed_account, $account);
					} else {
						wc_get_logger()->info('Skipping account switch email because only one valid account is configured.', $logger_context);
					}
				}
				wc_add_notice(__('No available payment accounts.', 'unified-payment-gateway'), 'error');
				return ['result' => 'fail'];
			}

			$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

			$accStatusApiUrl = $this->get_api_url('/api/check-merchant-status');
			$merchant_status_data = [
			    'is_sandbox'     => $this->sandbox,
			    'amount'         => $order->get_total(),
			    'api_public_key' => $public_key,
			];

			// Use cache for status check
			$unified_cache_key = 'merchant_status_' . md5($public_key);
			$merchant_status_response = $this->get_cached_api_response($accStatusApiUrl, $merchant_status_data, $unified_cache_key);

			if (
			    !is_array($merchant_status_response) ||
			    !isset($merchant_status_response['status']) ||
			    $merchant_status_response['status'] !== 'success'
			) {
			    wc_get_logger()->warning("Account '{$account['title']}' failed merchant status check.", [
			        'source'  => 'unified-payment-gateway',
			        'context' => [
			            'order_id'      => $order_id,
			            'account_title' => $account['title'] ?? 'unknown',
			            'response'      => $merchant_status_response,
			        ],
			    ]);

			    if (!empty($lock_key)) {
			        $this->release_lock($lock_key);
			    }

			    // 👇 THIS LINE PREVENTS INFINITE LOOP
				$used_accounts[] = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

			    continue; // Try next account
			}


			/* ========================== END ========================== */

			$lock_key = $account['lock_key'] ?? null;

			// Add order note mentioning account name
			$order->add_order_note(__('Processing Payment Via: ', 'unified-payment-gateway') . $account['title']);

			// **Prepare API Data**
			$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			$data = $this->unified_prepare_payment_data($order, $public_key, $secret_key);

			// **Check Transaction Limit**
			$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
			$transaction_limit_response = wp_remote_post($transactionLimitApiUrl, [
				'method' => 'POST',
				'timeout' => 30,
				'body' => $data,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
				],
				'sslverify' => true,
			]);

			$transaction_limit_data = json_decode(wp_remote_retrieve_body($transaction_limit_response), true);

			// **Handle Account Limit Error**
			if (isset($transaction_limit_data['status']) && $transaction_limit_data['status'] === 'error') {
				$error_message = sanitize_text_field($transaction_limit_data['message']);
				wc_get_logger()->warning("['{$account['title']}'] exceeded daily transaction limit: $error_message", $logger_context);

				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}

				$last_failed_account = $account;
				// Switch to next available account
				$used_accounts[] = $account['title'];
				$new_account = $this->get_next_available_account($used_accounts);

				// **Send Email Notification **
				if ($new_account) {
					wc_get_logger()->info("Switched to fallback account '{$new_account['title']}' after '{$account['title']}' limit reached.", $logger_context);

					// Send email only to the previously failed account
					if ($previous_account) {
						//$this->send_account_switch_email($previous_account, $account);
					}

					$previous_account = $account;
					continue; // Retry with the new account
				} else {
					// **No available accounts left, send email to the last failed account**
					if ($last_failed_account) {
						// Only send switch email if more than one valid account exists
						if ($this->has_multiple_accounts()) {
							$this->send_account_switch_email($last_failed_account, $account);
						} else {
							wc_get_logger()->info('Skipping account switch email because only one valid account is configured.', $logger_context);
						}
					}
					wc_add_notice(__('All accounts have reached their transaction limit.', 'unified-payment-gateway'), 'error');
					return ['result' => 'fail'];
				}
			}

			// **Proceed with Payment**
			wc_get_logger()->info("Sending payment request using account '{$account['title']}'", $logger_context);
			$apiPath = '/api/request-payment';
			$url = esc_url($this->base_url . $apiPath);

			$order->update_meta_data('_order_origin', 'unified_payment_gateway');
			$order->save();
			
			$response = wp_remote_post($url, [
				'method' => 'POST',
				'timeout' => 30,
				'body' => $data,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
				],
				'sslverify' => true,
			]);

			// **Handle Response**
			if (is_wp_error($response)) {
				wc_get_logger()->error("HTTP error during payment request: {$response->get_error_message()}", $logger_context);
				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}
				wc_add_notice(__('Payment error: Unable to process.', 'unified-payment-gateway'), 'error');
				return ['result' => 'fail'];
			}

			$response_data = json_decode(wp_remote_retrieve_body($response), true);

			// Ensure sensitive data is sanitized or omitted if necessary.
			$response_data_str = is_array($response_data) ? json_encode($response_data) : (string)$response_data;

			// Log the response data.
			wc_get_logger()->info('Payment raw response: ' . $response_data_str, $logger_context);

			//BeaverTech Code Change start
			if (!empty($response_data['status']) && $response_data['status'] === 'success' && !empty($response_data['data']['payment_link'])) {

				if($response_data['data']['payment_status'] == 'success'){
					$current_order_status = $order->get_status();
					$target_order_status = $response_data['data']['payment_status'];

					// **Update Order Status**
					$order->update_status('processing', 'Order marked as processing by Unified.');

					if (WC()->cart) {
						WC()->cart->empty_cart();
					}
				
					return [
						'result'       => 'success',
						'order_id'     => $order->get_id(),
						'payment_status'     => $response_data['data']['payment_status'],
						'redirect_url' => esc_url($order->get_checkout_order_received_url()),
					];
				}
				//BeaverTech Code Change end
				if ($last_failed_account) {
					wc_get_logger()->info("Sending email before returning success to: '{$last_failed_account['title']}'", ['source' => 'unified-payment-gateway']);
					// Only send switch email if more than one valid account exists
					if ($this->has_multiple_accounts()) {
						$this->send_account_switch_email($last_failed_account, $account);
					} else {
						wc_get_logger()->info('Skipping account switch email because only one valid account is configured.', ['source' => 'unified-payment-gateway']);
					}
				}
				//$last_successful_account = $account;
				// Save pay_id to order meta
				$pay_id = $response_data['data']['pay_id'] ?? '';
				if (!empty($pay_id)) {
					$order->update_meta_data('_unified_pay_id', $pay_id);
					$order->update_meta_data('_unified_public_key', $public_key);
					$order->update_meta_data('_unified_secret_key', $secret_key);
					$order->save();
				}

				$table_name = $wpdb->prefix . 'order_payment_link';

				// Add simple cache to avoid hitting DB on every request
				$unified_cache_key    = 'unified_table_exists_' . md5($table_name);
				$cache_group  = 'unified_payment_gateway';

				$table_exists = wp_cache_get($unified_cache_key, $cache_group);

				if (false === $table_exists) {
				    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				    $table_exists = $wpdb->get_var(
				        $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
				    );

				    // Cache result for 1 hour
				    wp_cache_set($unified_cache_key, $table_exists, $cache_group, HOUR_IN_SECONDS);
				}

				if ($table_exists !== $table_name) {
				    // Create the table if not exists
				    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

				    $charset_collate = $wpdb->get_charset_collate();

				    $create_sql = "CREATE TABLE $table_name (
				        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				        order_id BIGINT UNSIGNED NOT NULL,
				        uuid VARCHAR(100) NOT NULL,
				        payment_link TEXT NOT NULL,
				        customer_email VARCHAR(191),
				        amount DECIMAL(18,2),
				        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
				    ) $charset_collate;";

				    dbDelta($create_sql);

				    wc_get_logger()->info("Created missing `$table_name` table.", [
				        'source' => 'unified-payment-gateway',
				        'context' => ['table' => $table_name],
				    ]);
				}

				// Prepare amount
				$formatted_amount = number_format((float) ($response_data['data']['amount'] ?? 0), 2, '.', '');

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert is safely prepared with format specifiers
				$wpdb->insert(
				    $table_name,
				    [
				        'order_id'       => $order_id,
				        'uuid'           => sanitize_text_field($pay_id),
				        'payment_link'   => esc_url_raw($response_data['data']['payment_link'] ?? ''),
				        'customer_email' => sanitize_email($response_data['data']['customer_email'] ?? ''),
				        'amount'         => $formatted_amount,
				        'created_at'     => current_time('mysql', 1),
				    ],
				    ['%d', '%s', '%s', '%s', '%s', '%s']
				);

				wc_get_logger()->info('Stored order payment link to DB.', [
				    'source'  => 'unified-payment-gateway',
				    'context' => [
				        'order_id' => $order_id,
				        'uuid'     => $pay_id,
				        'amount'   => $formatted_amount,
				    ],
				]);

				// **Update Order Status**
				$order->update_status('pending', __('Payment pending.', 'unified-payment-gateway'));

				// **Add Order Note (If Not Exists)**
				// translators: %s represents the account title.
				$new_note = sprintf(
					/* translators: %s represents the account title. */
					esc_html__('Payment initiated via Unified. Awaiting your completion ( %s )', 'unified-payment-gateway'),
					esc_html($account['title'])
				);
				$existing_notes = $order->get_customer_order_notes();

				if (!array_filter($existing_notes, fn($note) => trim(wp_strip_all_tags($note->comment_content)) === trim($new_note))) {
					$order->add_order_note($new_note, false, true);
				}				

				$order_id   = $order->get_id();
				$uuid = sanitize_text_field($response_data['data']['pay_id']);

				$json_data = json_encode($response_data);
				wc_get_logger()->info(
				    'Received successful payment API response. Saving order payment link data.',
				    [
				        'source'  => 'unified-payment-gateway',
				        'context' => [
				            'order_id'       => $order_id,
				            'uuid'           => $uuid,
				            'payment_link'   => $response_data['data']['payment_link'] ?? '',
				            'customer_email' => $response_data['data']['customer_email'] ?? '',
				            'amount'         => $response_data['data']['amount'] ?? '',
				        ],
				    ]
				);

				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}
				return [
		            'result'       => 'success',
		            'order_id'     => $order->get_id(),
		            'payment_link' => esc_url($response_data['data']['payment_link']),
		        ];
			}

			// **Handle Payment Failure**
			if (!empty($response_data['message'])) {
				$error_message = sanitize_text_field($response_data['message']);
			} elseif (!empty($response_data['error'])) {
				$error_message = sanitize_text_field($response_data['error']);
			} elseif (!empty($response_data['data']['error'])) {
				$error_message = sanitize_text_field($response_data['data']['error']);
			} else {
				$error_message = __('Unknown error occurred.', 'unified-payment-gateway');
			}

			wc_get_logger()->error("Final payment failure using '{$account['title']}': $error_message", $logger_context);
			// **Add Order Note for Failed Payment**
			$order->add_order_note(
				sprintf(
					/* translators: 1: Account title, 2: Error message. */
					esc_html__('Payment failed using account: %1$s. Error: %2$s', 'unified-payment-gateway'),
					esc_html($account['title']),
					esc_html($error_message)
				)
			);

			// Add WooCommerce error notice
			wc_add_notice(__('Payment error: ', 'unified-payment-gateway') . $error_message, 'error');
			if (!empty($lock_key)) {
				$this->release_lock($lock_key);
			}
			// return ['result' => 'fail'];			 
	        return [
	            'result'         => 'fail',
	            'payment_result' => [
	                'status'  => 'failure',
	                'message' => 'fail message',
	            ],
	        ];
		}
	}

	// public function process_payment( $order_id ) {
	//     $order = wc_get_order( $order_id );

	//     // Example: redirect to a payment popup / external page
	//     return [
	//         'result'   => 'success',
	//         'redirect' => $this->get_return_url( $order ), // or your custom payment URL
	//     ];
	// }


	// Display the "Test Order" tag in admin order details
	public function unified_display_test_order_tag($order)
	{
		if (get_post_meta($order->get_id(), '_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'unified-payment-gateway') . '</strong></p>';
		}
	}

	private function unified_get_return_url_base()
	{
		return rest_url('/unified/v1/data');
	}

	private function unified_prepare_payment_data($order, $api_public_key, $api_secret)
	{
		$order_id = $order->get_id(); // Validate order ID
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';

		// Sanitize and get the billing email or phone
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		// Get order details and sanitize
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$amount = number_format($order->get_total(), 2, '.', '');

		$email = sanitize_text_field($order->get_billing_email());
		$phone = sanitize_text_field($order->get_billing_phone());

		// Get billing country (ISO code like "US", "IN", etc.)
		$country = $order->get_billing_country();

		// Convert to country calling code
		$country_code = WC()->countries->get_country_calling_code($country);

		// Get billing address details
		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city = sanitize_text_field($order->get_billing_city());
		$billing_postcode = sanitize_text_field($order->get_billing_postcode());
		$billing_country = sanitize_text_field($order->get_billing_country());
		$billing_state = sanitize_text_field($order->get_billing_state());
		$billing_phone = sanitize_text_field($order->get_billing_phone());

		$redirect_url = esc_url_raw(
			add_query_arg(
				[
					'order_id' => $order_id, // Include order ID or any other identifier
					'key' => $order->get_order_key(),
					'nonce' => wp_create_nonce('unified_payment_nonce'), // Create a nonce for verification
					'mode' => 'wp',
				],
				$this->unified_get_return_url_base() // Use the updated base URL method
			)
		);

		$ip_address = sanitize_text_field($this->unified_get_client_ip());

		if (empty($order_id)) {
			wc_get_logger()->error('Order ID is missing or invalid.', ['source' => 'unified-payment-gateway']);
			return ['result' => 'fail'];
		}

		// Create the meta data array
		$meta_data_array = [
			'order_id' => $order_id,
			'amount' => $amount,
			'source' => 'woocommerce',
		];

		// Log errors but continue processing
		foreach ($meta_data_array as $key => $value) {
			$meta_data_array[$key] = sanitize_text_field($value); // Sanitize each field
			if (is_object($value) || is_resource($value)) {
				wc_get_logger()->error('Invalid value for key ' . $key . ': ' . wp_json_encode($value), ['source' => 'unified-payment-gateway']);
			}
		}

		return [
			'api_secret' => $api_secret, // Use sandbox or live secret key
			'api_public_key' => $api_public_key, // Add the public key for API calls
			'first_name' => $first_name,
			'last_name' => $last_name,
			'request_for' => $request_for,
			'amount' => $amount,
			'redirect_url' => $redirect_url,
			'redirect_time' => 3,
			'ip_address' => $ip_address,
			'source' => 'wordpress',
			'meta_data' => $meta_data_array,
			'email' => $email,
			'phone_number' => $phone,
			'country_code' => $country_code,
			'remarks' => 'Order ' . $order->get_order_number(),
			// Add billing address details to the request
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city' => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country' => $billing_country,
			'billing_state' => $billing_state,
			'billing_phone' => $billing_phone,
			'is_sandbox' => $is_sandbox,
			'plugin_version' => $this->version,
		];
	}

	// Helper function to get client IP address
	private function unified_get_client_ip()
	{
		$ip = '';

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Sanitize the client's IP directly on $_SERVER access
			$ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Sanitize and handle multiple proxies
			$ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
			$ip = trim($ip_list[0]); // Take the first IP in the list and trim any whitespace
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			// Sanitize the remote address directly
			$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}

		// Validate the IP after retrieving it
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	/**
	 * Add a custom label next to the order status in the order list.
	 *
	 * @param array $line_items The order line items array.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return array Modified line items array.
	 */
	public function unified_add_custom_label_to_order_row($line_items, $order)
	{
		// Get the custom meta field value (e.g. '_order_origin')
		$order_origin = $order->get_meta('_order_origin');

		// Check if the meta exists and has value
		if (!empty($order_origin)) {
			// Add the label text to the first item in the order preview
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}

		return $line_items;
	}

	/**
	 * WooCommerce not active notice.
	 */
	public function unified_woocommerce_not_active_notice()
	{
		echo '<div class="error">
        <p>' .
			esc_html__('Unified Payment Gateway requires WooCommerce to be installed and active.', 'unified-payment-gateway') .
			'</p>
    </div>';
	}

	/**
	 * Payment form on checkout page.
	 */
	public function payment_fields()
	{
		$description = $this->get_option('description');

		if ($description) {
			// Apply formatting
			$formatted_description = wpautop(wptexturize(trim($description)));
			// Output directly with escaping
			echo wp_kses_post($formatted_description);
		}

		// Check if the consent checkbox should be displayed
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			// Add user consent checkbox with escaping
			echo '<p class="form-row form-row-wide">
                <label for="unified_consent">
                    <input type="checkbox" id="unified_consent" name="unified_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'unified-payment-gateway') .
				'
                </label>
            </p>';

			// Add nonce field for security

			wp_nonce_field('unified_payment', 'unified_nonce');
		}
	}

	/**
	 * Validate the payment form.
	 */
	public function validate_fields()
	{
		// Check for SQL injection attempts
		if (!$this->check_for_sql_injection()) {
			return false;
		}
		// Check if the consent checkbox setting is enabled
		if ($this->get_option('show_consent_checkbox') === 'yes') {
			// Sanitize and validate the nonce field
			$nonce = isset($_POST['unified_nonce']) ? sanitize_text_field(wp_unslash($_POST['unified_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'unified_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'unified-payment-gateway'), 'error');
				return false;
			}

			// Sanitize the consent checkbox input
			$consent = isset($_POST['unified_consent']) ? sanitize_text_field(wp_unslash($_POST['unified_consent'])) : '';

			// Validate the consent checkbox was checked
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'unified-payment-gateway'), 'error');
				return false;
			}
		}

		return true;
	}

	/**
	 * Enqueue stylesheets for the plugin.
	 */
	public function unified_enqueue_styles_and_scripts()
	{
		if (is_checkout()) {
			// Enqueue stylesheets
			wp_enqueue_style(
				'unified-frontend-styles',
				plugins_url('../assets/css/frontend.css', __FILE__),
				[], // Dependencies (if any)
				'1.0', // Version number
				'all' // Media
			);
			wp_enqueue_style(
				'unified-payment-loader-styles',
				plugins_url('../assets/css/loader.css', __FILE__),
				[], // Dependencies (if any)
				'1.0', // Version number
				'all' // Media
			);

			// Enqueue unified.js script
			wp_enqueue_script(
				'unified-js',
				plugins_url('../assets/js/unified.js', __FILE__),
				['jquery'], // Dependencies
				'1.0', // Version number
				true // Load in footer
			);

			// Localize script with parameters that need to be passed to unified.js
			wp_localize_script('unified-js', 'unified_params', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'checkout_url' => wc_get_checkout_url(),
				'unified_loader' => plugins_url('../assets/images/loader.gif', __FILE__),
				'unified_nonce' => wp_create_nonce('unified_payment'), // Create a nonce for verification
				'payment_method' => $this->id,
			]);
		}
	}

	function unified_admin_scripts($hook)
	{

		if (
		    'woocommerce_page_wc-settings' !== $hook ||
		    (sanitize_text_field(wp_unslash($_GET['section'] ?? '')) !== $this->id) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
		    return;
		}

		// Enqueue Admin CSS
		wp_enqueue_style('unified-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');

		// Enqueue Admin CSS
		wp_enqueue_style('unified-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');

		// Register and enqueue your script
		wp_enqueue_script('unified-admin-script', plugins_url('../assets/js/unified-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/unified-admin.js'), true);

		wp_localize_script('unified-admin-script', 'unified_admin_data', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('unified_sync_nonce'),
			'gateway_id' => $this->id,
		]);
	}

	protected function log_info($message, $context = [])
	{
	    $logger = wc_get_logger();
	    $data = ['source' => 'unified-payment-gateway'];

	    if (!empty($context)) {
	        $data['context'] = $context;
	    }

	    $logger->info($message, $data);
	}

	/**
	 * Get the next available account for payment processing.
	 *
	 * @param array $used_accounts List of already used accounts.
	 * @return array|null
	 */
	public function get_updated_account() {
	    $accounts = get_option('woocommerce_unified_payment_gateway_accounts', []);
	    $valid_accounts = [];

	    foreach ($accounts as $index => $account) {
	        $useSandbox = $this->sandbox;
	        $secretKey = $useSandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
	        $publicKey = $useSandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

	        $this->log_info("Checking merchant status for account '{$account['title']}'", [
	            'useSandbox' => $useSandbox,
	            'publicKey' => $publicKey,
	        ]);

	        $checkStatusUrl = $this->get_api_url('/api/check-merchant-status', $useSandbox);
	        $response = wp_remote_post($checkStatusUrl, [
	            'headers' => [
	                'Authorization' => 'Bearer ' . $publicKey,
	                'Content-Type'  => 'application/json',
	            ],
	            'timeout' => 10,
	            'body' => wp_json_encode([
	                'api_secret_key' => $secretKey,
	                'is_sandbox'     => $useSandbox,
	            ]),
	        ]);

	        $body = json_decode(wp_remote_retrieve_body($response), true);
	        $isError = is_array($body) && strtolower($body['status'] ?? '') === 'error';

	        $valid_accounts[] = [
	            'title'              => $account['title'],
	            'priority'           => $account['priority'],
	            'live_public_key'    => $account['live_public_key'],
	            'live_secret_key'    => $account['live_secret_key'],
	            'sandbox_public_key' => $account['sandbox_public_key'],
	            'sandbox_secret_key' => $account['sandbox_secret_key'],
	            'has_sandbox'        => $account['has_sandbox'],
	            'sandbox_status'     => $isError ? 'Inactive' : 'Active',
	            'live_status'        => $isError ? 'Inactive' : 'Active',
	        ];

	        if ($isError) {
	            $this->log_info("Account '{$account['title']}' is inactive", ['response' => $body]);
	        } else {
	            $this->log_info("Account '{$account['title']}' is active");
	        }
	    }

	    if (!empty($valid_accounts)) {
	        update_option('woocommerce_unified_payment_gateway_accounts', $valid_accounts);
	        return true;
	    }

	    $this->log_info('No active account. Removing unified gateway.');
	    return false;
	}


	public function hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = $this->id;

	    if (!isset($available_gateways[$gateway_id])) {
	        return $available_gateways;
	    }

		 // Unique cache/log key per cart state
	    $cart_hash = WC()->cart ? WC()->cart->get_cart_hash() : 'no_cart';

		// Skip logging if cart_hash is empty or 'no_cart' (cart not initialized)
	    if (empty($cart_hash) || $cart_hash === 'no_cart') {
	        return $available_gateways;
	    }

	    // Unique cache/log key per gateway and cart state
		$unified_cache_key = 'unified_gateway_visibility_' . $gateway_id . '_' . $cart_hash;


	    // ✅ Avoid running multiple times for the same cart_hash in the same request
	    static $processed_hashes = [];
	    if (in_array($cart_hash, $processed_hashes, true)) {
	        return $available_gateways;
	    }
	    $processed_hashes[] = $cart_hash;

		$this->log_info_once_per_session(
	        'gateway_check_start_' . $cart_hash,
	        'Payment Option Check Started',
	        ['cart_hash' => $cart_hash]
	    );

		// ✅ Handle both page load & AJAX differently
	    $is_ajax_order_review = (
	        defined('DOING_AJAX') &&
	        DOING_AJAX &&
	        isset($_REQUEST['wc-ajax']) &&
	        $_REQUEST['wc-ajax'] === 'update_order_review'
	    );

	   $this->log_info_once_per_session(
		    'request_context_' . $cart_hash,
		    'Checking payment option visibility',
		    [
		        'cart_hash'       => $cart_hash,
		        'Request Type'    => $is_ajax_order_review ? 'Cart update (AJAX)' : 'Checkout page load',
		        'On Checkout Page'=> is_checkout() ? 'Yes' : 'No'
		    ]
		);


	    $amount = 0.00;

	    if (isset($GLOBALS[$unified_cache_key])) {
	        return $GLOBALS[$unified_cache_key];
	    }

	    if (!is_checkout() && !$is_ajax_order_review) {
		    return $available_gateways;
		}

	    if (is_admin()) {
			$this->log_info_once_per_session(
			    'in_admin_' . $cart_hash,
			    'Payment option check skipped (admin area)',
			    ['cart_hash' => $cart_hash]
			);

			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        $this->get_updated_account();
	        return $available_gateways;
	    }
	   
	    if (WC()->cart) {
	        if ($is_ajax_order_review) {
	            // During AJAX, cart totals are often not recalculated yet
	            // Get from totals array instead of get_total('raw')
	            $totals = WC()->cart->get_totals();
	            $amount = isset($totals['total']) ? (float) $totals['total'] : 0.00;

	            // If still zero but cart has items, skip hiding for now
	            if ($amount < 0.01 && WC()->cart->get_cart_contents_count() > 0) {
	               $this->log_info_once_per_session(
					    'ajax_skip_' . $cart_hash,
					    'Skipping hide during AJAX recalculation (cart has items, amount 0)',
					    ['cart_hash' => $cart_hash]
					);

					$this->log_info_once_per_session(
					    'gateway_check_end_' . $cart_hash,
					    'Payment Option Check Finished',
					    ['cart_hash' => $cart_hash]
					);
	                return $available_gateways;
	            }
	        } else {
	            // Normal page load
	            $amount = (float) WC()->cart->get_total('raw');
	            if ($amount < 0.01) {
	                // Try fallback
	                $totals = WC()->cart->get_totals();
	                if (!empty($totals['total'])) {
	                    $amount = (float) $totals['total'];
	                }
	            }
	        }
	    }

	    $this->log_info_once_per_session(
		    'cart_amount_' . $cart_hash,
		    'Cart total detected',
		    [
		        'Amount'    => $amount,
		        'cart_hash' => $cart_hash
		    ]
		);

	    // Hide if truly below minimum
	    if ($amount < 0.01) {
			$this->log_info_once_per_session(
			    'hide_reason_low_amount_' . $cart_hash,
			    'Payment option hidden: order total below minimum',
			    [
			        'cart_hash' => $cart_hash,
			        'Amount'    => $amount
			    ]
			);
			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    $amount = number_format($amount, 2, '.', '');

	    // Get accounts
	    if (!method_exists($this, 'get_all_accounts')) {
	       $this->log_info_once_per_session(
			    'missing_get_all_accounts_' . $cart_hash,
			    'Gateway misconfigured: missing account retrieval method',
			    ['cart_hash' => $cart_hash]
			);
			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    $accounts = $this->get_all_accounts();

		$this->log_info_once_per_session(
		    'account_check_' . $cart_hash,
		    'Checking payment provider accounts',
		    [
				'accounts' => $accounts,
		        'Number of Accounts Found' => count($accounts),
		        'cart_hash' => $cart_hash
		    ]
		);


	    if (empty($accounts)) {
	        $this->log_info_once_per_session(
			    'no_accounts_' . $cart_hash,
			    'Payment option hidden: no merchant accounts configured',
			    ['cart_hash' => $cart_hash]
			);
			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    usort($accounts, fn($a, $b) => $a['priority'] <=> $b['priority']);

	    $transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
	    $accStatusApiUrl = $this->get_api_url('/api/check-merchant-status');

	    $user_account_active = false;
	    $all_accounts_limited = true;

	   $this->log_info_once_per_session(
		    'account_check_' . $cart_hash,
		    'Evaluating accounts for availability',
		    [
		        'cart_hash' => $cart_hash,
		        'Amount'    => $amount,
		        'Accounts'  => $accounts
		    ]
		);


	   $force_refresh = (
		    isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
		    $_GET['refresh_accounts'] === '1' &&
		    wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);

	    foreach ($accounts as $account) {
	        $public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
	        $secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

	        $data = [
	            'is_sandbox'     => $this->sandbox,
	            'amount'         => $amount,
	            'api_public_key' => $public_key,
	            'api_secret_key' => $secret_key,
	        ];

	        $cache_base = 'unified_daily_limit_' . md5($public_key . $amount);

	        $status_data = $this->get_cached_api_response($accStatusApiUrl, $data, $cache_base . '_status', 30, $force_refresh);
	        if (!empty($status_data['status']) && $status_data['status'] === 'success') {
	            $user_account_active = true;
	        }

	        $limit_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_base . '_limit');
	       $this->log_info_once_per_session(
			    'limit_response_' . $public_key . '_' . $cart_hash,
			    'Transaction limit response',
			    [
			        'cart_hash' => $cart_hash,
			        'Sandbox'   => $this->sandbox,
			        'Data'      => $limit_data
			    ]
			);


	        if (!empty($limit_data['status']) && $limit_data['status'] === 'success') {
	            $all_accounts_limited = false;
	        }

	        if ($user_account_active && !$all_accounts_limited) {
	            break;
	        }
	    }

	    if (!$user_account_active) {
	       $this->log_info_once_per_session(
			    'no_active_accounts_' . $cart_hash,
			    'Payment option hidden: no active accounts',
			    ['cart_hash' => $cart_hash]
			);

			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    if ($all_accounts_limited) {
	       $this->log_info_once_per_session(
			    'accounts_limited_' . $cart_hash,
			    'Payment option hidden: all accounts reached transaction limits',
			    ['cart_hash' => $cart_hash]
			);

			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

		$this->log_info_once_per_session(
		    'gateway_active_' . $cart_hash,
		    'Payment option available: account active and within limits',
		    ['cart_hash' => $cart_hash]
		);

		// End log
		$this->log_info_once_per_session(
		    'gateway_check_end_' . $cart_hash,
		    'Payment Option Check Finished',
		    ['cart_hash' => $cart_hash]
		);


	    $GLOBALS[$unified_cache_key] = $available_gateways;
	    return $available_gateways;
	}

	private function hide_gateway($available_gateways, $gateway_id)
	{
	    unset($available_gateways[$gateway_id]);
	    $GLOBALS['unified_gateway_visibility_' . $this->id] = $available_gateways;
	    return $available_gateways;
	}

	private function log_info_once_per_session($key, $message, $context = [])
	{
	    if (!WC()->session) {
	        return; // Session not started yet
	    }

	    // Extract cart_hash from context if provided, else fallback
	    $cart_hash = isset($context['cart_hash']) ? $context['cart_hash'] : 'no_cart';

	    // Make the log key unique for both the event key and current cart state
	    $log_key = 'unified_log_once_' . md5($key . $this->id . $cart_hash);

	    // Check if we've already logged for this cart state
	    if (WC()->session->get($log_key)) {
	        return;
	    }

	    // Mark as logged for this cart state
	    WC()->session->set($log_key, true);

	    // Perform the actual logging
	    if (!empty($context)) {
	        $this->log_info($message, $context);
	    } else {
	        $this->log_info($message);
	    }
	}

	/**
	 * Validate an individual account.
	 *
	 * @param array $account The account data to validate.
	 * @param int $index The index of the account (for error messages).
	 * @return bool|string True if valid, error message if invalid.
	 */
	protected function validate_account($account, $index)
	{
		$is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);
		$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

		if (!$is_empty && !$is_filled) {
			/* Translators: %d is the keys are valid or leave empty.*/
			return sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'unified-payment-gateway'), $index + 1);
		}

		return true;
	}

	/**
	 * Validate all accounts.
	 *
	 * @param array $accounts The list of accounts to validate.
	 * @return bool|string True if valid, error message if invalid.
	 */
	protected function validate_accounts($accounts)
	{
		$valid_accounts = [];
		$errors = [];

		foreach ($accounts as $index => $account) {
			// Check if the account is completely empty
			$is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);

			// Check if the account is completely filled
			$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

			// If the account is neither empty nor fully filled, it's invalid
			if (!$is_empty && !$is_filled) {
				/* Translators: %d is the keys are valid or leave empty.*/
				$errors[] = sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'unified-payment-gateway'), $index + 1);
			} elseif ($is_filled) {
				// If the account is fully filled, add it to the valid accounts array
				$valid_accounts[] = $account;
			}
		}

		// If there are validation errors, return them
		if (!empty($errors)) {
			return ['errors' => $errors, 'valid_accounts' => $valid_accounts];
		}

		// If no errors, return the valid accounts
		return ['valid_accounts' => $valid_accounts];
	}

	private function get_cached_api_response($url, $data, $unified_cache_key, $ttl = 120, $force_refresh = false)
	{
	    // Allow ?refresh_accounts=1&_wpnonce=... in URL to force-refresh cache (useful for testing)
		if (
		    !$force_refresh &&
		    isset($_GET['refresh_accounts']) &&
		    $_GET['refresh_accounts'] === '1' &&
		    isset($_GET['_wpnonce']) &&
		    wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		) {
		    $force_refresh = true;
		}

	    // If not forcing refresh, return cached version if it exists
	    if (!$force_refresh) {
	        $cached_response = get_transient($unified_cache_key);
	        if ($cached_response !== false) {
	            return $cached_response;
	        }
	    } else {
	        delete_transient($unified_cache_key); // Clear previous cached version
	    }

	    // Make the API call
	    $response = wp_remote_post($url, [
	        'method'  => 'POST',
	        'timeout' => 30,
	        'body'    => $data,
	        'headers' => [
	            'Content-Type'  => 'application/x-www-form-urlencoded',
	            'Authorization' => 'Bearer ' . $data['api_public_key'],
	        ],
	        'sslverify' => true,
	    ]);

	    if (is_wp_error($response)) {
	        return ['status' => 'error', 'message' => $response->get_error_message()];
	    }

	    $response_body = wp_remote_retrieve_body($response);
	    $response_data = json_decode($response_body, true);

	    // Cache the response
	    set_transient($unified_cache_key, $response_data, $ttl);

	    return $response_data;
	}


	private function get_all_accounts()
	{
	    $accounts = get_option('woocommerce_unified_payment_gateway_accounts', []);

	    if (is_string($accounts)) {
	        $unserialized = maybe_unserialize($accounts);
	        $accounts = is_array($unserialized) ? $unserialized : [];
	        wc_get_logger()->debug(
			    'Unserialized accounts.',
			    [
			        'source'  => 'unified-payment-gateway',
			        'context' => [
			            'accounts' => $accounts,
			        ],
			    ]
			);

	    }

	    $valid_accounts = [];

	    foreach ($accounts as $i => $account) {

	        if ($this->sandbox) {
	            $status = strtolower($account['sandbox_status'] ?? '');
	            $has_keys = !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']);
	    
	            if ($status === 'active' && $has_keys) {
	                $valid_accounts[] = $account;
	            }
	        } else {
	            $status = strtolower($account['live_status'] ?? '');
	            $has_keys = !empty($account['live_public_key']) && !empty($account['live_secret_key']);
	            if ($status === 'active' && $has_keys) {
	                $valid_accounts[] = $account;
	            }
	        }
	    }

	    $this->accounts = $valid_accounts;
	    return $valid_accounts;
	}

	/**
	 * Return true if more than one valid account is configured (for current mode).
	 *
	 * @return bool
	 */
	private function has_multiple_accounts()
	{
		$accounts = $this->get_all_accounts();
		return is_array($accounts) && count($accounts) > 1;
	}


	function unified_enqueue_admin_styles($hook)
	{
		// Load only on WooCommerce settings pages
		if (strpos($hook, 'woocommerce') === false) {
			return;
		}

		wp_enqueue_style('unified-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
	}

	/**
	 * Send an email notification via Unified API
	 */
	private function send_account_switch_email($oldAccount, $newAccount)
	{
		// Defensive check: only send switch emails when more than one valid account is configured
		if (!$this->has_multiple_accounts()) {
			wc_get_logger()->info('Skipping account switch email because only one valid account is configured.', ['source' => 'unified-payment-gateway']);
			return false;
		}
		$unifiedApiUrl = $this->get_api_url('/api/switch-account-email'); // unified API Endpoint

		// Use the credentials of the old (current) account to authenticate
		$api_key = $this->sandbox ? $oldAccount['sandbox_public_key'] : $oldAccount['live_public_key'];
		$api_secret = $this->sandbox ? $oldAccount['sandbox_secret_key'] : $oldAccount['live_secret_key'];

		// Prepare data for API request
		$emailData = [
			'old_account' => [
				'title' => $oldAccount['title'],
				'secret_key' => $api_secret,
			],
			'new_account' => [
				'title' => $newAccount['title'],
			],
			'message' => "Payment processing account has been switched. Please review the details.",
		];
		$emailData['is_sandbox'] = $this->sandbox;

		// API request headers using old account credentials
		$headers = [
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . sanitize_text_field($api_key),
		];

		// Log API request details
		//wc_get_logger()->info('Request Data: ' . json_encode($emailData), ['source' => 'unified-payment-gateway']);

		// Send data to Unified API
		$response = wp_remote_post($unifiedApiUrl, [
			'method' => 'POST',
			'timeout' => 30,
			'body' => json_encode($emailData),
			'headers' => $headers,
			'sslverify' => true,
		]);

		// Handle API response
		if (is_wp_error($response)) {
			wc_get_logger()->error('Failed to send switch email: ' . $response->get_error_message(), ['source' => 'unified-payment-gateway']);
			return false;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		// Check if authentication failed
		if ($response_code == 401 || $response_code == 403 || (!empty($response_data['error']) && strpos($response_data['error'], 'invalid credentials') !== false)) {
			wc_get_logger()->error('Email Sending Failed : Authentication failed: Invalid API key or secret for old account', ['source' => 'unified-payment-gateway']);
			return false; // Stop further execution
		}

		// Check if the API response has errors
		if (!empty($response_data['error'])) {
			wc_get_logger()->error('Unified API Error: ' . json_encode($response_data), ['source' => 'unified-payment-gateway']);
			return false;
		}

		wc_get_logger()->info("Switch email successfully sent to: '{$oldAccount['title']}'", ['source' => 'unified-payment-gateway']);
		return true;
	}

	/**
	 * Get the next available payment account, handling concurrency.
	 */
	private function get_next_available_account($used_accounts = [])
	{
		global $wpdb;

		// Fetch all accounts ordered by priority
		$settings = get_option('woocommerce_unified_payment_gateway_accounts', []);

		if (is_string($settings)) {
			$settings = maybe_unserialize($settings);
		}

		if (!is_array($settings)) {
			return false;
		}

		$mode = $this->sandbox ? 'sandbox' : 'live';
		$status_key = $mode . '_status';
		$public_key = $mode . '_public_key';
		$secret_key = $mode . '_secret_key';

		// Filter out used accounts and check correct mode status & keys
		$available_accounts = array_filter($settings, function ($account) use ($used_accounts, $status_key, $public_key, $secret_key) {
			return !in_array($account[$public_key], $used_accounts, true)
				&& isset($account[$status_key]) && $account[$status_key] === 'Active'
				&& !empty($account[$public_key]) && !empty($account[$secret_key]);
		});


		if (empty($available_accounts)) {
			return false;
		}

		// Sort by priority (lower number = higher priority)
		usort($available_accounts, function ($a, $b) {
			return $a['priority'] <=> $b['priority'];
		});

		// Concurrency Handling: Lock the selected account
		foreach ($available_accounts as $account) {
			$lock_key = "unified_lock_{$account['title']}";

			// Try to acquire lock
			if ($this->acquire_lock($lock_key)) {
				$account['lock_key'] = $lock_key;
				return $account;
			}
		}

		return false;
	}

	/**
	 * Acquire a lock to prevent concurrent access to the same account.
	 */
	private function acquire_lock($lock_key)
	{
		$lock_timeout = 10; // Lock expires after 10 seconds

		// Set lock expiry time
		$lock_value = time() + $lock_timeout;

		// Try to add or update the lock in the options table
		$result = update_option($lock_key, $lock_value, false); // 'false' ensures no autoload

		if (!$result) {
			// Log the error if update_option fails
			wc_get_logger()->error(
				"DB Error: Unable to acquire lock for '{$lock_key}'",
				['source' => 'unified-payment-gateway']
			);
			return false; // Lock acquisition failed
		}

		// Log successful lock acquisition
		wc_get_logger()->info("Lock acquired for '{$lock_key}'", ['source' => 'unified-payment-gateway']);

		return true;
	}


	/**
	 * Release a lock after payment processing is complete.
	 */
	private function release_lock($lock_key)
	{
		// Delete the lock entry using WordPress options API
		delete_option($lock_key);

		// Log the release of the lock
		wc_get_logger()->info("Released lock for '{$lock_key}'", ['source' => 'unified-payment-gateway']);
	}


	function check_for_sql_injection()
	{

		$sql_injection_patterns = ['/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER)\b(?![^{}]*})/i', '/(\-\-|\#|\/\*|\*\/)/i', '/(\b(AND|OR)\b\s*\d+\s*[=<>])/i'];

		$errors = []; // Store multiple errors

                                  		// Get checkout fields dynamically
		$checkout_fields = WC()
			->checkout()
			->get_checkout_fields();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce checkout nonce
		foreach ($_POST as $key => $value) {
			if (is_string($value)) {
				foreach ($sql_injection_patterns as $pattern) {
					if (preg_match($pattern, $value)) {
						// Get the field label dynamically
						$field_label = isset($checkout_fields['billing'][$key]['label'])
							? $checkout_fields['billing'][$key]['label']
							: (isset($checkout_fields['shipping'][$key]['label'])
								? $checkout_fields['shipping'][$key]['label']
								: (isset($checkout_fields['account'][$key]['label'])
									? $checkout_fields['account'][$key]['label']
									: (isset($checkout_fields['order'][$key]['label'])
										? $checkout_fields['order'][$key]['label']
	 									: ucfirst(str_replace('_', ' ', $key)))));

						// Log error for debugging
						$ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
						wc_get_logger()->info(
							"Potential SQL Injection Attempt - Field: $field_label, Value: $value, IP: {$ip_address}",
							['source' => 'unified-payment-gateway']
						);
						// This comment must be directly above the i18n function call with no blank line
						/* translators: %s is the field label, like "Email Address" or "Username". */
						$errors[] = sprintf(esc_html__('Please enter a valid "%s".', 'unified-payment-gateway'), $field_label);
						break; // Stop checking other patterns for this field
					}
				}
			}
		}

		// Display all collected errors at once
		if (!empty($errors)) {
			foreach ($errors as $error) {
				wc_add_notice($error, 'error');
			}
			return false;
		}

		return true;
	}

	public function render_unified_payment_popup()
	{
		if (!is_checkout()) {
			return;
		}

		$customer = WC()->customer;
		$cart     = WC()->cart;

		$first_name = $customer->get_billing_first_name();
		$last_name  = $customer->get_billing_last_name();
		$email      = $customer->get_billing_email();
		$phone      = $customer->get_billing_phone();

		$address_1  = $customer->get_billing_address_1();
		$address_2  = $customer->get_billing_address_2();
		$city       = $customer->get_billing_city();
		$state      = $customer->get_billing_state();
		$postcode   = $customer->get_billing_postcode();
		$country    = $customer->get_billing_country();

		$subtotal = $cart->get_subtotal();
		$fees     = $cart->get_fee_total();
		$total    = $cart->get_total('edit');
		?>
		<div class="overlay" id="unified-payment-popup" style="display: none;">
			<div class="modal">

				<!-- Header -->
				<div class="modal-header">
					<button class="back-btn"><i class="fa fa-arrow-left" aria-hidden="true"></i></button>
					<div class="logo"><img src="<?php echo esc_url(plugins_url('../assets/images/logo.png', __FILE__)); ?>" width="71.5" height="26" /></div>
					<button class="close-btn">&times;</button>
				</div>

				<!-- Content -->
				<div class="modal-body">

					<div class="processing-overlay">
						<div class="processing-card">

							<div class="logo-wrap">
								<div class="logo"><img src="<?php echo esc_url(plugins_url('../assets/images/logo.png', __FILE__)); ?>" width="100" height="39" /></div>
							</div>
							<div class="loading-messages">
								<p class="loading-text" id="loadingText">
									Finding the best payment route for you...
								</p>
							</div>

							<div class="progress-bar">
								<span class="progress-fill"></span>
							</div>

							<div class="methods">
								<p>Supported payment methods:</p>
								<div class="icons">
									<span><img src="<?php echo esc_url(plugins_url('../assets/images/pay1.png', __FILE__)); ?>" width="22" height="22" /></span>
									<span><img src="<?php echo esc_url(plugins_url('../assets/images/pay2.png', __FILE__)); ?>" width="22" height="22" /></span>
									<span><img src="<?php echo esc_url(plugins_url('../assets/images/pay3.png', __FILE__)); ?>" width="22" height="22" /></span>
									<span><img src="<?php echo esc_url(plugins_url('../assets/images/pay4.png', __FILE__)); ?>" width="22" height="22" /></span>
								</div>
							</div>

						</div>
					</div>

					<div class="stepper">
						<div class="line"></div>

						<div class="step active">
							<span class="dot"></span>
							<span class="text">Details</span>
						</div>


						<div class="step">
							<span class="dot"></span>
							<span class="text">Payment Method</span>
						</div>


						<div class="step">
							<span class="dot"></span>
							<span class="text">Pay</span>
						</div>

						<div class="line"></div>

					</div>

					<div class="main-content" style="display:none">

						<!-- Personal Details -->
						<div class="summary">
							<div class="card-header toggle-summary pl-10">
								<div class="card-title">
									<img src="<?php echo esc_url(plugins_url('../assets/images/User_icon.png', __FILE__)); ?>" width="15" height="15" /> <span>Personal
										Details</span>
								</div>
								<!-- <span class="arrow"><i class="fa fa-angle-down" aria-hidden="true"></i></span> -->
								<span class="edit-icon personal-info"><i class="fa fa-pencil-square-o" aria-hidden="true">Edit</i></span>
							</div>
							<div class="card">
								<div class="summary-body grid">
									<div>
										<label>First Name</label>
										<p><?php echo esc_html($first_name); ?></p>
									</div>
									<div>
										<label>Last Name</label>
										<p><?php echo esc_html($last_name); ?></p>
									</div>
									<div>
										<label>Email</label>
										<p><?php echo esc_html($email); ?></p>
									</div>
									<div>
										<label>Phone Number</label>
										<p><?php echo esc_html($phone); ?></p>
									</div>
								</div>

								<div class="middle-content edit-personal-info">
									<div class="form-grid">
										<div class="form-group">
											<label>First Name</label>
											<input type="text" value="<?php echo esc_html($first_name); ?>" placeholder="Enter First Name">
										</div>

										<div class="form-group">
											<label>Last Name</label>
											<input type="text" value="<?php echo esc_html($last_name); ?>" placeholder="Enter Last Name">
										</div>
									</div>

									<div class="form-group">
										<label>Email</label>
										<input type="email" value="<?php echo esc_html($email); ?>" placeholder="Enter Email">
									</div>

									<div class="form-group">
										<label>Phone Number</label>
										<div class="phone-input">
											<div class="country-code">
												🇺🇸
												<i class="fa fa-angle-down"></i>
											</div>
											<input type="text" value="<?php echo esc_html($phone); ?>" placeholder="Enter Phone Number">
										</div>
									</div>

									<button class="save-btn">Save</button>
								</div>
							</div>
						</div>

						<!-- Date of Birth -->
						<div class="card summary">
							<div class="card-header toggle-summary">
								<div class="card-title">
									<img src="<?php echo esc_url(plugins_url('../assets/images/calendar_icon.png', __FILE__)); ?>" width="15" height="15" /> <span>Date of Birth
										<span>(Required)</span></span>
								</div>
							</div>
							<div class="summary-body">
								<p class="date-text">Used for age verification & compliance</p>
								<div class="form-group">
									<input type="date" placeholder="MM/DD/YYYY">
								</div>
							</div>

						</div>

						<!-- Billing Address -->
						<div class="summary">
							<div class="card-header toggle-summary pl-10">
								<div class="card-title">
									<img src="<?php echo esc_url(plugins_url('../assets/images/bill_Icon.png', __FILE__)); ?>" width="15" height="15" /> <span>Billing
										Address</span>
								</div>
								<!-- <span class="arrow"><i class="fa fa-angle-down" aria-hidden="true"></i></span> -->
								<span class="edit-icon billing-address"><i class="fa fa-pencil-square-o"
										aria-hidden="true"></i></span>
							</div>

							<div class="card">

								<div class="summary-body address">
									<p class="address"> 4517 Washington Ave. Manchester, Kentucky 39495. </p>
								</div>

								<div class="middle-content edit-billing-address">
									<div class="form-group">
										<label>Address</label>
										<input type="text" value="jennyw@example.com" placeholder="Enter Address">
									</div>
									<div class="form-group">
										<label>Address</label>
										<input type="text" value="jennyw@example.com" placeholder="Enter Address">
									</div>
									<div class="form-grid">
										<div class="form-group">
											<label>Country</label>
											<select>
												<option>Country</option>
											</select>
										</div>

										<div class="form-group">
											<label>City</label>
											<input type="text" value="Williamson" placeholder="Enter City">
										</div>
									</div>
									<div class="form-grid">
										<div class="form-group">
											<label>State</label>
											<input type="text" value="Jenny" placeholder="Enter State">
										</div>

										<div class="form-group">
											<label>Pin code</label>
											<input type="text" value="Williamson" placeholder="Enter Pin code">
										</div>
									</div>
									<button class="save-btn">Save</button>

								</div>
							</div>
						</div>

						<!-- Payment Summary -->
						<div class="card-header">
							<div class="card-title summary-title pl-10">
								<img src="<?php echo esc_url(plugins_url('../assets/images/payment_Icon.png', __FILE__)); ?>" width="15" height="15" /> <span>Payment
									Summary</span>
							</div>

							<!-- Arrow (default) -->
							<!-- <span class="arrows"><i class="fa fa-angle-down" aria-hidden="true"></i></span> -->


						</div>

						<div class="card">

							<div class="summary-body">
								<div class="summary-row">
									<span>Amount</span>
									<span>$100.00</span>
								</div>

								<div class="summary-row">
									<span>Fees</span>
									<span>$3.46</span>
								</div>

								<div class="summary-divider"></div>

								<div class="summary-row total">
									<span>Total Payment</span>
									<span><b>$103.46</b></span>
								</div>
							</div>
						</div>
					</div>

					<div class="payment-method-content">
						<div class="payment-wrapper payment-method">
							<div class="title">
								<div class="icon"><img src="<?php echo esc_url(plugins_url('../assets/images/secure-payment.png', __FILE__)); ?>" width="45" height="45" />
								</div>
								<h2>Select a payment method</h2>
								<p>Securely continue with your preferred option.</p>
							</div>

							<div class="payment-list">

								<label class="payment-option" id="credit-card">
									<input type="radio" name="payment" checked />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/card_Icon.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>Credit Card</h4>
											<span>Visa, Mastercard supported</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

								<label class="payment-option" id="debit-card">
									<input type="radio" name="payment" />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/card_Icon.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>Debit Card</h4>
											<span>Most banks supported</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

								<label class="payment-option" id="coinbase">
									<input type="radio" name="payment" disabled />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/coinbase_icon.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>Coinbase</h4>
											<span>Buy crypto using your coinbase account</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

								<label class="payment-option" id="applepay">
									<input type="radio" name="payment" disabled />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/apple_icon.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>Apple Pay</h4>
											<span>Pay securely using Apple Pay</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

								<label class="payment-option" id="googlepay">
									<input type="radio" name="payment" disabled />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/googlepay_icon.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>Google Pay</h4>
											<span>Fast & secure payments with Google Pay</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

							</div>
						</div>

						<div class="payment-wrapper patment-card">
							<div class="title">
								<div class="icon"><img src="<?php echo esc_url(plugins_url('../assets/images/credit-card.png', __FILE__)); ?>" width="40" height="40" />
								</div>
								<h2>Choose your card type</h2>
								<p>Select the card network to continue securely.</p>
							</div>

							<div class="payment-list">

								<label class="payment-option">
									<input type="radio" name="payment" checked />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/visa.png', __FILE__)); ?>" width="33"
												height="22" /></span>
										<div>
											<h4>Visa</h4>
											<span>Accepted worldwide</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

								<label class="payment-option">
									<input type="radio" name="payment" />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/Mastercard.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>Mastercard</h4>
											<span>Fast & secure payments</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

								<label class="payment-option">
									<input type="radio" name="payment" disabled />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/amex.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>American express</h4>
											<span>Premium card benefits</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

								<label class="payment-option">
									<input type="radio" name="payment" disabled />
									<div class="left">
										<span class="pay-icon"><img src="<?php echo esc_url(plugins_url('../assets/images/Discover.png', __FILE__)); ?>" width="17"
												height="17" /></span>
										<div>
											<h4>Discover</h4>
											<span>Popular in the US</span>
										</div>
									</div>
									<span class="check gray"></span>
								</label>

							</div>

							<div class="card-note">
								<p><i class="fa fa-lock" aria-hidden="true"></i> Your card details are encrypted and
									secure</p>
							</div>
						</div>

						<div class="payment-wrapper payment-info">
							info
						</div>

					</div>

					<div class="Pay-content">
						Pay content
					</div>

				</div>


				<!-- Footer -->
				<div class="footer-green-border">


					<div class="footer-wrp">
						<span><i class="fa fa-info-circle" aria-hidden="true"></i> We need your date of birth to comply
							with payment
							regulations.</span>
					</div>
					<div class="modal-footer">
						<div class="footer-card">
							<div class="amount">
								<span>You’ll Pay</span>
								<div class="price">
									$103.46 <span class="arrow"><i class="fa fa-angle-down"
											aria-hidden="true"></i></span>
								</div>
							</div>

							<button class="proceed-btn">
								Proceed
								<span class="icon"><i class="fa fa-long-arrow-right" aria-hidden="true"></i></span>
							</button>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}

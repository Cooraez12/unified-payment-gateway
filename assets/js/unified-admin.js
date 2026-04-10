jQuery(document).ready(function ($) {

    if (typeof unified_admin_data === 'undefined' || typeof unified_admin_data.gateway_id === 'undefined') {
        console.error('unified_admin_data or unified_admin_data.gateway_id is not defined. Please ensure wp_localize_script is correctly set up.');
        return;
    }

    var gatewayId = unified_admin_data.gateway_id;
    var formClass = gatewayId + '-gateway-settings-form';
    var gatewaySettingsForm = $('form#mainform');

    if (gatewaySettingsForm.length && gatewaySettingsForm.find('input[name^="woocommerce_' + gatewayId + '_"]').length) {
        gatewaySettingsForm.addClass(formClass);

        runAccountSync(gatewayId);

        function showErrorMessage(inputField, message) {
            const $input = $(inputField);
            $input.addClass("error");
            $input.siblings(".error-message").remove();
            $("<div>").addClass("error-message").text(message).insertAfter($input);
        }

        function clearErrorMessages() {
            $(".error-message").remove();
            $(".error").removeClass("error");
        }

        if (typeof gatewayId === 'string' && gatewayId.trim()) {
            const gateway_id = gatewayId.trim();
            const accountClass = `.${gateway_id}-account`;
            const addAccountBtnClass = `.${gateway_id}-add-account`;
            const containerClass = `.${gateway_id}-accounts-container`;
            const deleteBtnClass = `.delete-account-btn`;
            const toggleBtnClass = `.${gateway_id}-toggle-btn`;
            const accountInfoClass = `.${gateway_id}-info`;
            const sandboxCheckboxClass = `.${gateway_id}-sandbox-checkbox`;

            $(document).on("change", sandboxCheckboxClass, function () {
                const $account = $(this).closest(accountClass);
                const $sandboxContainer = $account.find(`.${gateway_id}-sandbox-keys`);
                $sandboxContainer.toggle($(this).is(":checked"));
            });

            function updateAccountIndices() {
                $(accountClass).each(function (index) {
                    $(this).attr("data-index", index);
                    $(this).find("input, select").each(function () {
                        let name = $(this).attr("name");
                        if (name) {
                            name = name.replace(/\[.*?\]/, "[" + index + "]");
                            $(this).attr("name", name);
                        }
                    });
                });
            }

            $(document).on("click", deleteBtnClass, function () {
                const $accounts = $(accountClass);
                $(".delete-account-error").remove();

                if ($accounts.length === 1) {
                    $accounts.first().before(`<div class="delete-account-error" style="color: red; margin-bottom: 10px;">At least one account must be present.</div>`);
                    return;
                }

                const $account = $(this).closest(accountClass);
                $account.find("input").attr("name", "");
                $account.remove();
                updateAccountIndices();
            });

            function generateUniqueId() {
                return 'acc_' + Math.floor(100000 + Math.random() * 900000);
            }

            $(document).on("click", addAccountBtnClass, function () {
                const unique_id = generateUniqueId();

                const newAccountHtml = `
                    <div class="${gateway_id}-account">
                        <div class="title-blog">
                            <h4>
                                <span class="${gateway_id}-name-display account-name-display">Untitled Account</span>
                                &nbsp;<i class="fa fa-caret-down ${gateway_id}-toggle-btn" aria-hidden="true"></i>
                            </h4>
                            <div class="action-button">
                                <button type="button" class="delete-account-btn"><i class="fa fa-trash" aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="${gateway_id}-info" style="display: none;">
                            <div class="add-blog title-priority">
                                <div class="account-input account-name">
                                    <label>Account Name</label>
                                    <input type="text" class="${gateway_id}-title account-title" name="accounts[][title]" placeholder="Account Title">
                                </div>
                                <div>
                                    <input type="hidden" class="${gateway_id}-title unique-id" name="accounts[][unique_id]" value="${unique_id}" readonly>
                                </div>
                                <div class="account-input priority-name">
                                    <label>Priority</label>
                                    <input type="number" class="account-priority" name="accounts[][priority]" placeholder="Priority" min="1" value="${$(accountClass).length + 1}">
                                </div>
                            </div>

                            <div class="add-blog">
                                <div class="account-input">
                                    <label>Checkout Title</label>
                                    <input type="text" name="accounts[][checkout_title]" placeholder="Title shown to customers at checkout" value="">
                                </div>
                            </div>

                            <div class="add-blog">
                                <div class="account-input">
                                    <label>Checkout Subtitle</label>
                                    <textarea class="checkout-subtitle" name="accounts[][checkout_subtitle]" placeholder="Subtitle/description shown below the title at checkout" rows="2"></textarea>
                                </div>
                            </div>

                            <div class="add-blog ${gateway_id}-production-keys">
                                <div class="account-input">
                                    <label>Live Keys</label>
                                    <input type="text" class="live-public-key" name="accounts[][live_public_key]" placeholder="Public Key">
                                </div>
                                <div class="account-input">
                                    <input type="text" class="live-secret-key" name="accounts[][live_secret_key]" placeholder="Secret Key">
                                </div>
                            </div>

                            <div class="account-checkbox">
                                <input type="checkbox" class="${gateway_id}-sandbox-checkbox" name="accounts[][has_sandbox]">
                                Do you have the sandbox keys?
                            </div>

                            <div class="${gateway_id}-sandbox-keys" style="display: none;">
                                <div class="add-blog">
                                    <div class="account-input">
                                        <label>Sandbox Keys</label>
                                        <input type="text" class="sandbox-public-key" name="accounts[][sandbox_public_key]" placeholder="Public Key">
                                    </div>
                                    <div class="account-input">
                                        <input type="text" class="sandbox-secret-key" name="accounts[][sandbox_secret_key]" placeholder="Secret Key">
                                    </div>
                                </div>
                            </div>

                            <!-- Hidden status inputs -->
                            <input type="hidden" class="live-status" name="accounts[][live_status]" value="unknown">
                            <input type="hidden" class="live-usable" name="accounts[][live_usable]" value="0">
                            <input type="hidden" class="sandbox-status" name="accounts[][sandbox_status]" value="unknown">
                            <input type="hidden" class="sandbox-usable" name="accounts[][sandbox_usable]" value="0">

                            <!-- Visible label -->
                            <div class="account-status-label"></div>
                        </div>
                    </div>`;

                $(containerClass + " .empty-account").remove();
                $(this).closest(".add-account-btn").before(newAccountHtml);
                updateAccountIndices();
            });

            $(document).on("click", toggleBtnClass, function () {
                const $info = $(this).closest(accountClass).find(accountInfoClass);
                $info.slideToggle();
                $(this).toggleClass("rotated");
            });

            $(document).on("input", `${accountClass} .account-title`, function () {
                const newTitle = $(this).val().trim() || "Untitled Account";
                $(this).closest(accountClass).find(".account-name-display").text(newTitle);
            });
        }

        $(document).off("submit", "." + formClass).on("submit", "." + formClass, function (event) {
            clearErrorMessages();

            let allKeys = new Set();
            let prioritySet = new Set();
            let titleSet = new Set();
            let hasErrors = false;

            function validateKeyUniqueness(inputField, keyValue, label) {
                if (allKeys.has(keyValue)) {
                    showErrorMessage(inputField, `${label} must be unique across all accounts and key types.`);
                    hasErrors = true;
                } else {
                    allKeys.add(keyValue);
                }
            }

            const globalTitleField = $('#woocommerce_' + $.escapeSelector(gatewayId) + '_title');
            if (!globalTitleField.val()?.trim()) {
                showErrorMessage(globalTitleField, "Enter a name for this payment method (shown to customers at checkout).");
                hasErrors = true;
            }

            const globalDescField = $('#woocommerce_' + $.escapeSelector(gatewayId) + '_description');
            if (!globalDescField.val()?.trim()) {
                showErrorMessage(globalDescField, "Add a brief description shown to customers at checkout.");
                hasErrors = true;
            }

            $("." + gatewayId + "-account").each(function () {
                const $account = $(this);
                const livePublicKey = $account.find(".live-public-key");
                const liveSecretKey = $account.find(".live-secret-key");
                const sandboxPublicKey = $account.find(".sandbox-public-key");
                const sandboxSecretKey = $account.find(".sandbox-secret-key");
                const sandboxCheckbox = $account.find("." + gatewayId + "-sandbox-checkbox");
                const title = $account.find(".account-title");
                const priority = $account.find(".account-priority");

                const titleVal = title.val()?.trim() || '';
                const priorityVal = priority.val()?.trim() || '';
                const livePublicKeyVal = livePublicKey.val()?.trim() || '';
                const liveSecretKeyVal = liveSecretKey.val()?.trim() || '';
                const sandboxPublicKeyVal = sandboxPublicKey.val()?.trim() || '';
                const sandboxSecretKeyVal = sandboxSecretKey.val()?.trim() || '';

                if (!titleVal) { showErrorMessage(title, "Title is required."); hasErrors = true; }
                else if (titleSet.has(titleVal)) { showErrorMessage(title, "Title must be unique."); hasErrors = true; }
                else { titleSet.add(titleVal); }

                if (!priorityVal) { showErrorMessage(priority, "Priority is required."); hasErrors = true; }
                else if (prioritySet.has(priorityVal)) { showErrorMessage(priority, "Priority must be unique."); hasErrors = true; }
                else { prioritySet.add(priorityVal); }

                if (!livePublicKeyVal) { showErrorMessage(livePublicKey, "Live Public Key is required."); hasErrors = true; }
                if (!liveSecretKeyVal) { showErrorMessage(liveSecretKey, "Live Secret Key is required."); hasErrors = true; }

                const sandboxRequired = sandboxCheckbox.is(":checked");
                if (sandboxRequired) {
                    if (!sandboxPublicKeyVal) { showErrorMessage(sandboxPublicKey, "Sandbox Public Key is required."); hasErrors = true; }
                    if (!sandboxSecretKeyVal) { showErrorMessage(sandboxSecretKey, "Sandbox Secret Key is required."); hasErrors = true; }
                }

                if (livePublicKeyVal) { validateKeyUniqueness(livePublicKey, livePublicKeyVal, "Live Public Key"); }
                if (liveSecretKeyVal) { validateKeyUniqueness(liveSecretKey, liveSecretKeyVal, "Live Secret Key"); }
                if (sandboxPublicKeyVal) { validateKeyUniqueness(sandboxPublicKey, sandboxPublicKeyVal, "Sandbox Public Key"); }
                if (sandboxSecretKeyVal) { validateKeyUniqueness(sandboxSecretKey, sandboxSecretKeyVal, "Sandbox Secret Key"); }

                if (livePublicKeyVal && liveSecretKeyVal && livePublicKeyVal === liveSecretKeyVal) {
                    showErrorMessage(liveSecretKey, "Live Secret Key must be different from Live Public Key."); hasErrors = true;
                }
                if (sandboxPublicKeyVal && sandboxSecretKeyVal && sandboxPublicKeyVal === sandboxSecretKeyVal) {
                    showErrorMessage(sandboxSecretKey, "Sandbox Public Key and Sandbox Secret Key must be different."); hasErrors = true;
                }
                if (livePublicKeyVal && sandboxPublicKeyVal && livePublicKeyVal === sandboxPublicKeyVal) {
                    showErrorMessage(sandboxPublicKey, "Live Public Key and Sandbox Public Key must be different."); hasErrors = true;
                }
                if (liveSecretKeyVal && sandboxSecretKeyVal && liveSecretKeyVal === sandboxSecretKeyVal) {
                    showErrorMessage(sandboxSecretKey, "Live Secret Key and Sandbox Secret Key must be different."); hasErrors = true;
                }
            });

            if (hasErrors) {
                event.preventDefault();
                $(this).find('[type="submit"]').removeClass('is-busy');
            }
        });

        function runAccountSync(gatewayId) {
            if (!gatewayId) return;

            const $button = $(`#${gatewayId}-sync-accounts`);
            const $status = $(`#${gatewayId}-sync-status`);
            const originalButtonText = $button.text();

            $button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Syncing...');
            $status.removeClass('error success').text('Syncing accounts...').show();

            $.ajax({
                url: unified_admin_data.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: `${gatewayId}_manual_sync`,
                    nonce: unified_admin_data.nonce,
                    accounts: collectAccounts(gatewayId)
                },
                success: function (response) {
                    if (response.success) {
                        $status.removeClass('error').addClass('success').text(response.data.message || 'Sync completed successfully!').fadeIn().delay(4000).fadeOut();
                        if (typeof updateAccountStatuses === 'function') {                            
                            updateAccountStatuses(response.data.statuses, gatewayId);
                        }
                    } else {
                        $status.removeClass('success').addClass('error').text(response.data.message || 'Sync failed. Please try again.').fadeIn().delay(4000).fadeOut();
                    }
                },
                error: function (xhr, status, error) {
                    let errorMessage = 'AJAX Error: ';
                    if (xhr.responseJSON?.data?.message) { errorMessage += xhr.responseJSON.data.message; }
                    else { errorMessage += error; }
                    $status.addClass('error').text(errorMessage);
                },
                complete: function () {
                    $button.prop('disabled', false).text(originalButtonText);
                }
            });
        }

        $('#'+gatewayId+'-sync-accounts').on('click', function (e) { e.preventDefault(); runAccountSync(gatewayId); });
        $('#woocommerce_'+gatewayId+'_sandbox').on('change', function () { runAccountSync(gatewayId); });

        function updateAccountStatuses(statuses) {
		if (!Array.isArray(statuses)) return;

		statuses.forEach(function (statusItem) {
			var accountTitle = statusItem.title;
			var mode = statusItem.mode;
			var newStatus = statusItem.status;
			var usable = statusItem.usable;
			var reason = statusItem.reason || '';

			$('.' + gatewayId + '-account').each(function () {
				var $account = $(this);
				var currentTitle = $.trim($account.find('.account-title').val());
				if (currentTitle === accountTitle) {

					// Update hidden inputs
					if (mode === 'live') {
						$account.find('.live-status').val(newStatus);
						$account.find('.live-usable').val(usable ? '1' : '0');
					} else if (mode === 'sandbox') {
						$account.find('.sandbox-status').val(newStatus);
						$account.find('.sandbox-usable').val(usable ? '1' : '0');
					}

					// Visible label
					var statusLabel = $account.find('.account-status-label');
					if (!statusLabel.length) {
						statusLabel = $('<div class="account-status-label"></div>').appendTo($account.find('.account-title').closest('.account-input'));
					}

					// Tooltips
					const statusTooltips = {
						active: 'The account is valid and ready to use.',
						inactive: 'The account is currently inactive. Please check your settings.',
						invalid: 'The account credentials are incorrect or incomplete.',
						unknown: 'The account status could not be determined.',
					};

					let tooltipText = statusTooltips[newStatus.toLowerCase()] || '';
					if (!usable && reason) { tooltipText += ' (' + reason + ')'; }
                    
					// Update class and text based on usability
					statusLabel
						.removeClass('active inactive invalid unknown usable unusable')
						.addClass(usable ? 'usable' : 'unusable')
						.attr('title', tooltipText)
						.text((mode === 'sandbox' ? 'Sandbox Account Status: ' : 'Live Account Status: ') + (usable ? capitalize(newStatus) : 'Inactive'));
				}
			});
		});
	}

	function capitalize(text) { 
		if (!text) return ''; 
		return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase(); 
	}

        function capitalize(text) { if (!text) return ''; return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase(); }

    } else {
        console.log('Could not identify form for gateway: ' + gatewayId);
    }

    function collectAccounts(gatewayId) {
        let accounts = [];

        $('.' + gatewayId + '-account').each(function () {
            const $acc = $(this);

            let account = {
                title: $acc.find('.account-title').val() || '',
                priority: $acc.find('.account-priority').val() || '',
                live_public_key: $acc.find('.live-public-key').val() || '',
                live_secret_key: $acc.find('.live-secret-key').val() || '',
                sandbox_public_key: $acc.find('.sandbox-public-key').val() || '',
                sandbox_secret_key: $acc.find('.sandbox-secret-key').val() || '',
                has_sandbox: $acc.find('.' + gatewayId + '-sandbox-checkbox').is(':checked') ? 'on' : '',
                sandbox_status: $acc.find('.sandbox-status').val() || 'unknown',
                live_status: $acc.find('.live-status').val() || 'unknown',
                unique_id: $acc.find('.unique-id').val() || '',
                checkout_title: $acc.find('[name*="[checkout_title]"]').val() || '',
                checkout_subtitle: $acc.find('[name*="[checkout_subtitle]"]').val() || ''
            };

            accounts.push(account);
        });

        return accounts;
    }
});
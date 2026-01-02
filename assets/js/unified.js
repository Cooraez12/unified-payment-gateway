jQuery(function ($) {
	
	var isSubmitting = false; // Flag to track form submission
	var popupInterval; // Interval ID for checking popup status
	var paymentStatusInterval; // Interval ID for checking payment status
	var orderId; // To store the order ID
	var $button; // To store reference to the submit button
	var originalButtonText; // To store original button text
	// var isPollingActive = false; // Flag to ensure only one polling interval runs
	let isHandlerBound = false;

	/* ================= PROCESSING ================= */
	const modal = document.querySelector('.modal');
	const overlay = document.querySelector('.processing-overlay');
	const progress = document.querySelector('.progress-fill');
	const mainContent = document.querySelector('.main-content');

	function startProcessing() {
	modal.classList.add('processing-active');
	let percent = 0;
	progress.style.width = '0%';

	const timer = setInterval(() => {
		percent++;
		progress.style.width = percent + '%';

		if (percent >= 100) {
		clearInterval(timer);
		setTimeout(endProcessing, 300);
		}
	}, 3);
	}

	function endProcessing() {
	modal.classList.remove('processing-active');
	overlay.style.display = 'none';
	mainContent.style.display = 'block';
	}

	startProcessing();

	/* ================= LOADING TEXT ================= */
	const messages = [
	"Finding the best payment route for you...",
	"Securing your transaction…",
	"Optimizing approval chances…"
	];
	const loadingText = document.getElementById('loadingText');
	let msgIndex = 0;

	setInterval(() => {
	msgIndex = (msgIndex + 1) % messages.length;
	loadingText.textContent = messages[msgIndex];
	}, 2200);

	/* ================= ACCORDION ================= */
	document.querySelectorAll('.summary').forEach(card => {
	const header = card.querySelector('.toggle-summary');
	if (!header) return;

	header.addEventListener('click', () => {
		card.classList.toggle('open');
	});
	});

	/* ================= EDIT / SAVE ================= */
	document.querySelectorAll('.edit-icon').forEach(icon => {
		icon.addEventListener('click', e => {
			e.stopPropagation();
			const card = icon.closest('.summary');
			card.classList.add('open', 'editing');
		});
	});

	document.querySelectorAll('.save-btn').forEach(btn => {
		btn.addEventListener('click', e => {
			e.preventDefault();
			btn.closest('.summary').classList.remove('editing');
		});
	});

	/* ================= STEPPER ================= */
	const steps = document.querySelectorAll('.step');
	const backBtn = document.querySelector('.back-btn');
	// const footer = document.querySelector('.footer-green-border');

	const sections = [
	document.querySelector('.main-content'),
	document.querySelector('.payment-method-content'),
	document.querySelector('.Pay-content')
	];

	let currentStep = 0;

	function updateStep(index) {
	steps.forEach((step, i) => {
		step.className = 'step';

		if (i < index) step.classList.add('green');
		if (i === index) {
		step.classList.add('active');
		if (index === 2) step.classList.add('green');
		if (index === 1) step.classList.add('green');
		}
	});

	sections.forEach(sec => sec.style.display = 'none');
	sections[index].style.display = 'block';

	modal.classList.toggle('compact-modal', index > 0);
	backBtn.classList.toggle('show', index > 0);
	}

	document.querySelector('.proceed-btn').addEventListener('click', () => {
	if (currentStep < 2) currentStep++;
	updateStep(currentStep);
	});

	backBtn.addEventListener('click', () => {
	if (currentStep > 0) currentStep--;
	updateStep(currentStep);
	});

	updateStep(0);


	document.querySelectorAll('.payment-option').forEach(option => {
	option.addEventListener('click', () => {
	
		document.querySelectorAll('.payment-option')
		.forEach(o => o.classList.remove('active'));

		option.classList.add('active');
		option.querySelector('input').checked = true;
		const cardNote = document.querySelector('.card-note');

		if (cardNote) {
		setTimeout(() => {
			cardNote.style.opacity = '0';
			cardNote.style.transition = 'opacity 0.4s ease';

			setTimeout(() => {
			cardNote.style.display = 'none';
			}, 400);

		}, 2000);
		}
	});
	});


	const creditCard = document.getElementById('credit-card');
	const debitCard = document.getElementById('debit-card');
	const coinbase = document.getElementById('coinbase');

	const card = document.querySelector('.patment-card .payment-option');


	const paymentMethod = document.querySelector('.payment-method');
	const paymentCard = document.querySelector('.patment-card');
	const paymentInfo = document.querySelector('.payment-info');

	// DEFAULT STATE
	paymentMethod.style.display = 'block';
	paymentCard.style.display = 'none';
	paymentInfo.style.display = 'none';

	// CREDIT CARD CLICK
	creditCard.addEventListener('click', () => {
	paymentMethod.style.display = 'none';
	paymentCard.style.display = 'block';
	paymentInfo.style.display = 'none';
	});

	// DEBIT CARD CLICK
	debitCard.addEventListener('click', () => {
	paymentMethod.style.display = 'none';
	paymentCard.style.display = 'none';
	paymentInfo.style.display = 'block';
	});

	// COINBASE CLICK
	coinbase.addEventListener('click', () => {
	paymentMethod.style.display = 'none';
	paymentCard.style.display = 'none';
	paymentInfo.style.display = 'block';
	});

	// ALL CARD CLICK
	card.addEventListener('click', () => {
	paymentMethod.style.display = 'none';
	paymentCard.style.display = 'none';
	paymentInfo.style.display = 'block';
	});




	// Sanitize loader URL and append loader image to the body
	var loaderUrl = unified_params.unified_loader ? encodeURI(unified_params.unified_loader) : '';
	$('body').append(
		'<div class="unified-loader-background"></div>' +
		'<div class="unified-loader"><img src="' + loaderUrl + '" alt="Loading..." /></div>'
	);

	// Disable default WooCommerce checkout for your custom payment method
	$('form.checkout').on('checkout_place_order', function () {
		var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();

		// Prevent WooCommerce default behavior for your custom method
		if (selectedPaymentMethod === unified_params.payment_method) {
			return false; // Stop WooCommerce default script
		}
	});

	$('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').on('click', function () {
		var selectedPaymentMethod = $('input[name="radio-control-wc-payment-method-options"]:checked').val();
		// Prevent WooCommerce default behavior for your custom method
		if (selectedPaymentMethod === unified_params.payment_method) {
			return false; // Stop WooCommerce default script
		}
	});


	// Function to bind the form submit handler
	function bindCheckoutHandler() {
		if (isHandlerBound) return;
		isHandlerBound = true;

		// Unbind the previous handler before rebinding
		$("form.checkout").off("submit.unified").on("submit.unified", function (e) {
			// Check if the custom payment method is selected
			if ($(this).find('input[name="payment_method"]:checked').val() === unified_params.payment_method) {
				handleFormSubmit.call(this, e);
				return false; // Prevent other handlers
			}
		});

		$('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').on("click", function (e) {
			// Check if the custom payment method is selected
			if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() === unified_params.payment_method) {
				handleFormSubmit.call($('form.wc-block-checkout__form'), e);
				return false; // Prevent other handlers
			}
		});

	}

	// Rebind after checkout updates
	$(document.body).on("updated_checkout", function () {
		isHandlerBound = false; // Allow rebinding only once on the next update
		bindCheckoutHandler();
	});

	// Initial binding of the form submit handler
	bindCheckoutHandler();

	function openPaymentPopup() {
		$('#unified-payment-popup').show();
	}

	// Function to handle form submission
	function handleFormSubmit(e) {

		openPaymentPopup();
		return;
		e.preventDefault(); // Prevent the form from submitting if already in progress

		var $form = $(this);
		

		// If a submission is already in progress, prevent further submissions
		if (isSubmitting) {
			return false;
		}

		// Set the flag to true to prevent further submissions
		isSubmitting = true;

		if ($form.find('input[name="radio-control-wc-payment-method-options"]:checked').val()) {
			var selectedPaymentMethod = $form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();
			$button = $form.find('button.wc-block-components-checkout-place-order-button');
		}else{
			var selectedPaymentMethod = $form.find('input[name="payment_method"]:checked').val();
			$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
		}

		if (selectedPaymentMethod !== unified_params.payment_method) {
			isSubmitting = false; // Reset the flag if not using the custom payment method
			return true; // Allow default WooCommerce behavior
		}
		if ($form.find('input[name="radio-control-wc-payment-method-options"]:checked').val()) {
			// Disable the submit button immediately to prevent further clicks
			$button = $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button');
			originalButtonText = $button.text();
			$button.prop('disabled', true).text('Processing...');
		}else{
			// Disable the submit button immediately to prevent further clicks
			$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
			originalButtonText = $button.text();
			$button.prop('disabled', true).text('Processing...');
		}

		// Show loader
		$('.unified-loader-background, .unified-loader').show();

		var data = $form.serialize();

		if ($form.find('input[name="radio-control-wc-payment-method-options"]:checked').val()) {
			$.ajax({
				method: 'POST',
				url: unified_params.ajax_url,
				data: {
						action: 'unified_block_gateway_process',
						nonce: unified_params.unified_nonce
						
				},
				success: function (response) {
					handleResponse(response, $form);
				},
				error: function () {
					handleError($form);
				},
				complete: function () {
					isSubmitting = false; // Always reset isSubmitting to false in case of success or error
				},
			});
		}else{
			$.ajax({
				type: 'POST',
				url: wc_checkout_params.checkout_url,
				data: data,
				dataType: 'json',
				success: function (response) {
					handleResponse(response, $form);
				},
				error: function () {
					handleError($form);
				},
				complete: function () {
					isSubmitting = false; // Always reset isSubmitting to false in case of success or error
				},
			});
		}
		

		e.preventDefault(); // Prevent default form submission
		return false;
	}

	function openPaymentLink(paymentLink) {
		var sanitizedPaymentLink = encodeURI(paymentLink);
		var width = 700;
		var height = 700;
		var left = window.innerWidth / 2 - width / 2;
		var top = window.innerHeight / 2 - height / 2;
		var popupWindow = window.open(
			sanitizedPaymentLink,
			'paymentPopup',
			'width=' + width + ',height=' + height + ',scrollbars=yes,top=' + top + ',left=' + left
		);

		if (!popupWindow || popupWindow.closed || typeof popupWindow.closed === 'undefined') {
			// Redirect to the payment link if popup was blocked
			window.location.href = sanitizedPaymentLink;
			resetButton();
		} else {
			popupInterval = setInterval(function () {
				if (popupWindow.closed) {
					clearInterval(popupInterval);
					clearInterval(paymentStatusInterval);
					// isPollingActive = false; // Reset polling active flag when popup closes

					// API call when popup closes
					$.ajax({
						type: 'POST',
						url: unified_params.ajax_url, // Ensure this is localized correctly
						data: {
							action: 'unified_popup_closed_event',
							order_id: orderId,
							security: unified_params.unified_nonce, // Ensure this is valid
						},
						dataType: 'json',
						cache: false,
						processData: true,
						success: function (response) {
						    if (response.success === true) {
						        clearInterval(paymentStatusInterval);
						        clearInterval(popupInterval);

						        // Log for debugging
						        console.log('Popup closed response:', response);

								$(document.body).trigger('update_checkout');
						        $(".wc-block-components-notice-banner").remove();

						        // Redirect if redirect_url exists (for any status)
						        if (response.data && response.data.redirect_url) {
						            // Use replace() to ensure redirect works even in popup-close timing
						            window.location.replace(response.data.redirect_url);
						        }else{
									if(response.data.notices){
										$(".wc-block-checkout__form").prepend('<div class="wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg>'+response.data.notices+'<div>');
									}
									 window.scrollTo(0, 0);
								}
						    }else{
						    	$(document.body).trigger('update_checkout');
						        $(".wc-block-components-notice-banner").remove();
						    }

						    // isPollingActive = false;
						},
						error: function (xhr, status, error) {
							console.error("AJAX Error: ", error);
						},
						complete: function () {
							resetButton();
						}
					});
				}
			}, 500);

			// Start polling only if it's not already active
			// if (!isPollingActive) {
			// 	isPollingActive = true;

			// 	console.log('unified_params :', unified_params);


			// 	paymentStatusInterval = setInterval(function () {
			// 		$.ajax({
			// 			type: 'POST',
			// 			url: unified_params.ajax_url,
			// 			data: {
			// 				action: 'unified_check_payment_status',
			// 				order_id: orderId,
			// 				security: unified_params.unified_nonce,
			// 			},
			// 			dataType: 'json',
			// 			cache: false,
			// 			processData: true,
			// 			success: function (statusResponse) {
			// 				if (statusResponse.data.status === 'success') {
			// 					clearInterval(paymentStatusInterval);
			// 					clearInterval(popupInterval);
			// 					if (statusResponse.data && statusResponse.data.redirect_url) {
			// 						window.location.href = statusResponse.data.redirect_url;
			// 					}
			// 				} else if (statusResponse.data.status === 'failed') {
			// 					clearInterval(paymentStatusInterval);
			// 					clearInterval(popupInterval);
			// 					if (statusResponse.data && statusResponse.data.redirect_url) {
			// 						window.location.href = statusResponse.data.redirect_url;
			// 					}
			// 				}
			// 				isPollingActive = false; // Reset polling active flag after completion
			// 			},
			// 		});
			// 	}, 5000);
			// }
		}
	}

	function handleResponse(response, $form) {
		$('.unified-loader-background, .unified-loader').hide();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				if(response.payment_status == 'success'){
					 window.location.href = response.redirect_url
					 return false;
				}
				var paymentLink = response.payment_link;
				openPaymentLink(paymentLink);
				$form.removeAttr('data-result');
				$form.removeAttr('data-redirect-url');
			} else {
				throw response.messages || 'An error occurred during checkout.';
			}
		} catch (err) {
			displayError(err, $form);
		}
	}

	function handleError($form) {
		$('.wc_er').remove();
		$form.prepend('<div class="wc_er">An error occurred during checkout. Please try again.</div>');
		$('html, body').animate(
			{
				scrollTop: $('.wc_er').offset().top - 300,
			},
			500
		);
		resetButton();
	}

	function displayError(err, $form) {
		$('.wc_er').remove();
		$form.prepend('<div class="wc_er">' + err + '</div>');
		$('html, body').animate(
			{
				scrollTop: $('.wc_er').offset().top - 300,
			},
			500
		);
		resetButton();
	}

	function resetButton() {
		isSubmitting = false;
		if ($button) {
			$button.prop('disabled', false).text(originalButtonText);
		}
		$('.unified-loader-background, .unified-loader').hide();
	}
});

( function() {
	const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
	const { createElement } = window.wp?.element || {};

	// Bail if registry isn't ready
	if ( typeof registerPaymentMethod !== 'function' ) {
		console.error( '[Unified] wcBlocksRegistry not available yet' );
		return;
	}

	// Load settings from WC or fallback to localized params
	const settings =
		window.wc?.wcSettings?.getPaymentMethodData?.('unified') ||
		window.unified_params?.settings ||
		{};

	console.log( '[Unified] settings:', settings );

	const methodConfig = {
		name: settings.id || 'unified',
		label: settings.title || 'Unified',
		ariaLabel: settings.title || 'Unified',
		content: createElement(
			'div',
			{ className: 'unified-description' },
			settings.description || ''
		),
		edit: createElement(
			'div',
			{ className: 'unified-edit' },
			settings.title || 'Unified'
		),
		canMakePayment: async () => true,
		supports: {
			features: settings.supports || [ 'products' ],
		},
		processPayment: async (order) => {
			const loaderOverlay = document.querySelector('.processing-overlay');
			if (loaderOverlay) loaderOverlay.style.display = 'flex';

			try {
				const res = await fetch(unified_params.ajax_url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'unified_get_popup',
						order_id: order.id,
					}),
					credentials: 'same-origin'
				}).then(r => r.json());

				if (res.success) {
					window.openPaymentPopup(res.data.order_data);
				} else {
					alert(res.data?.message || 'Something went wrong.');
				}
			} catch (err) {
				alert('Failed to process payment.');
			} finally {
				if (loaderOverlay) loaderOverlay.style.display = 'none';
			}

			// Important: Block checkout expects a response object
			return { status: 'success', redirect: '' };
		}
	};
	registerPaymentMethod(methodConfig);
} )();
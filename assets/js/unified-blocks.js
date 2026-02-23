( function() {
	const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
	const { createElement,RawHTML } = window.wp?.element || {};

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
			createElement(RawHTML, {}, settings.description || '')
		),
		edit: createElement(
			'div',
			{ className: 'unified-edit' },
			settings.title || 'Unified'
		),
		canMakePayment: async () => {
			console.log( '[Unified] canMakePayment called' );
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	};

	console.log( '[Unified] Registering payment method:', methodConfig );
	registerPaymentMethod( methodConfig );
} )();
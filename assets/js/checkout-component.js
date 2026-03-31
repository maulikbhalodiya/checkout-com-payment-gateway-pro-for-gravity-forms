jQuery(document).ready(function ($) {
	const {
		publicKey,
		ajax_url,
		create_nonce,
		entry_id,
		form_id,
	} = checkoutComComponent;

	const componentContainer = document.getElementById("checkout-component-container");
	const loader = document.getElementById("checkout-loader");

	if (!componentContainer || !publicKey) {
		return;
	}

	async function initializeComponent() {
		// Show loader
		if (loader) loader.style.display = "block";
		if (componentContainer) componentContainer.style.display = "none";

		try {
			// Create payment session
			const sessionResponse = await $.ajax({
				type: "POST",
				url: ajax_url,
				data: {
					action: "gf_checkout_com_create_session",
					nonce: create_nonce,
					entry_id: entry_id,
					form_id: form_id,
				},
			});

			if (!sessionResponse.success || !sessionResponse.data.id) {
				throw new Error('Failed to create payment session.');
			}

			const paymentSession = sessionResponse.data;

			// Initialize Checkout.com Web Components
			const ckoController = await CheckoutWebComponents({
				publicKey: publicKey,
				paymentSession: paymentSession,
				environment: paymentSession.environment || 'sandbox' // Use environment from session
			});

			const flowComponent = ckoController.create('flow', {
				onPaymentCompleted: function (component, result) {

					// Use AJAX to process payment callback instead of form submission
					$.ajax({
						type: 'POST',
						url: ajax_url,
						data: {
							action: 'gf_checkout_com_process_callback',
							nonce: create_nonce,
							entry_id: entry_id,
							form_id: form_id,
							session_id: result.id,
							payment_data: JSON.stringify(result)
						},
						success: function (response) {
							if (response.success) {
								// Redirect to confirmation page
								window.location.href = response.data.redirect_url;
							} else {
								showError(response.data.message || 'Payment processing failed');
							}
						},
						error: function () {
							showError('Payment processing failed. Please try again.');
						}
					});
				},
				onError: function (component, error) {

					// For declined payments, use paymentId from error details
					const paymentId = error.details?.paymentId;

					if (paymentId) {

						// Use AJAX to process declined payment
						$.ajax({
							type: 'POST',
							url: ajax_url,
							data: {
								action: 'gf_checkout_com_process_callback',
								nonce: create_nonce,
								entry_id: entry_id,
								form_id: form_id,
								session_id: paymentId, // Use paymentId for declined payments
								payment_data: JSON.stringify({ id: paymentId, status: 'Declined' })
							},
							success: function (response) {

								if (response.success) {
									// Show error but payment was recorded
									showError("Payment was declined. Please try a different card.");
								} else {
									showError(response.data.message || 'Payment processing failed');
								}
							},
							error: function (xhr, status, error) {

								showError('Payment processing failed. Please try again.');
							}
						});
					} else {
						// Technical error without payment ID
						const errorCode = error.details?.requestErrorCodes?.[0];
						if (errorCode === 'not_enough_funds') {
							showError("Insufficient funds. Please check your balance.");
						} else if (errorCode === 'session_expired') {
							showError("Session expired. Please refresh and try again.");
						} else {
							showError("A technical error occurred. Please try again later.");
						}
					}
				},
				onReady: function () {

					// Hide loader and show component
					if (loader) loader.style.display = "none";
					if (componentContainer) componentContainer.style.display = "block";
				}
			});

			flowComponent.mount('#checkout-component-container');

		} catch (error) {

			// Hide loader and show error
			if (loader) loader.style.display = "none";
		}
	}

	// Helper function to show error messages
	function showError(message) {
		const paymentForm = document.getElementById('payment-form');
		if (!paymentForm) return;

		// Remove existing error messages
		const existingError = paymentForm.querySelector('.checkout-error-message');
		if (existingError) {
			existingError.remove();
		}

		// Create and insert error message
		const errorDiv = document.createElement('div');
		errorDiv.className = 'checkout-error-message';
		errorDiv.innerHTML = '<strong>Payment Error:</strong> ' + message;
		paymentForm.appendChild(errorDiv);

		// Hide loader
		if (loader) loader.style.display = "none";
	}

	// Initialize the component
	initializeComponent();
});

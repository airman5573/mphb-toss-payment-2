<?php
/**
 * Template for initiating Toss Payments checkout.
 *
 * This template expects 'booking_id' and 'payment_id' as GET parameters.
 * It should be used by a WordPress page (e.g., with slug '/toss-checkout').
 */

use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
use MPHB\Payments\Gateways\TossGateway;

// Ensure WordPress environment is loaded (redundant if used in a theme template, but safe)
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Check if MPHB is active
if (!function_exists('MPHB')) {
    echo '<p>Error: Hotel Booking plugin not active.</p>';
    return;
}

// --- Parameter Retrieval ---
$payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (empty($payment_id) || empty($booking_id)) {
    echo '<p>Error: Missing booking or payment information.</p>';
    // Maybe redirect back to the main checkout or booking page
    // wp_safe_redirect(MPHB()->settings()->pages()->getCheckoutPageUrl()); exit;
    return;
}

// --- Load Gateway ---
$gateway = MPHB()->paymentGateways()->getGateway(TossGateway::GATEWAY_ID);

if (!$gateway || !$gateway->isEnabled()) {
    echo '<p>Error: Toss Payments gateway is not available.</p>';
    return;
}

// --- Load Booking and Payment ---
$payment = MPHB()->paymentRepository()->findById($payment_id);
$booking = MPHB()->bookingRepository()->findById($booking_id);

if (!$payment || !$booking || $payment->getBookingId() !== $booking->getId()) {
    echo '<p>Error: Invalid booking or payment details.</p>';
    return;
}

// --- Prepare Toss Payment Data ---
$clientKey = $gateway->getOption('client_key');
$amount = round(floatval($payment->getAmount())); // Use rounded integer for KRW
$orderId = sprintf('mphb-booking-%d-payment-%d', $booking_id, $payment_id); // Consistent Order ID
$orderName = sprintf(__('Booking #%d Payment', 'mphb-toss'), $booking_id); // Example order name
$customerName = $booking->getCustomer()->getName(); // Get customer name
$customerEmail = $booking->getCustomer()->getEmail(); // Get customer email

// Construct Success URL (points back to WP init hook)
$successUrl = add_query_arg([
    'mphb_toss_return' => '1', // Trigger for our init hook
    'payment_id'       => $payment_id,
    'booking_id'       => $booking_id,
    // 'orderId' and 'amount' will be added by Toss redirect automatically
], home_url('/')); // Base URL

// Construct Fail URL (points back to checkout or a specific failure page)
$failUrl = add_query_arg([
    'mphb_toss_fail' => '1',
    'payment_id'     => $payment_id,
    'booking_id'     => $booking_id,
], $booking->getCheckoutUrl()); // Redirect back to MPHB checkout on failure


// Basic HTML structure
get_header(); // Optional: Include theme header
?>

<div id="toss-checkout-container" style="padding: 20px;">
    <h2><?php esc_html_e('Processing Payment with Toss', 'mphb-toss'); ?></h2>
    <p><?php esc_html_e('Please wait while we redirect you to Toss Payments.', 'mphb-toss'); ?></p>
    <div id="payment-widget"></div> <!-- Toss Payments SDK UI placeholder -->
</div>

<!-- Toss Payments SDK -->
<script src="https://js.tosspayments.com/v1/payment-widget"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const clientKey = <?php echo wp_json_encode($clientKey); ?>;
        const tossPayments = TossPayments(clientKey);

        // Option 1: Redirect to Toss Checkout Page
        /*
        tossPayments.requestPayment('카드', { // '카드', '가상계좌', '계좌이체', '휴대폰' 등
            amount: <?php echo wp_json_encode($amount); ?>,
            orderId: <?php echo wp_json_encode($orderId); ?>,
            orderName: <?php echo wp_json_encode($orderName); ?>,
            customerName: <?php echo wp_json_encode($customerName); ?>,
            customerEmail: <?php echo wp_json_encode($customerEmail); ?>,
            successUrl: <?php echo wp_json_encode($successUrl); ?>,
            failUrl: <?php echo wp_json_encode($failUrl); ?>
        }).catch(function (error) {
            console.error("Toss Payment Error:", error);
            // Handle failure - redirect back or display message
            alert('<?php esc_html_e('Payment initiation failed:', 'mphb-toss'); ?> ' + error.message);
            window.location.href = <?php echo wp_json_encode($failUrl); ?>;
        });
        */

        // Option 2: Render Payment Widget (Requires a div with id="payment-widget")
        // Choose this if you want to embed the payment form directly
        const widgetSelector = '#payment-widget';
        tossPayments.renderPaymentWidget(widgetSelector, clientKey, {
            amount: <?php echo wp_json_encode($amount); ?>,
            orderId: <?php echo wp_json_encode($orderId); ?>,
            orderName: <?php echo wp_json_encode($orderName); ?>,
            customerName: <?php echo wp_json_encode($customerName); ?>,
            customerEmail: <?php echo wp_json_encode($customerEmail); ?>,
            successUrl: <?php echo wp_json_encode($successUrl); ?>,
            failUrl: <?php echo wp_json_encode($failUrl); ?>
        }).catch(function (error) {
             console.error("Toss Widget Error:", error);
             alert('<?php esc_html_e('Failed to load payment widget:', 'mphb-toss'); ?> ' + error.message);
             // Optionally hide the container or redirect
             document.getElementById('toss-checkout-container').innerHTML = '<p><?php esc_html_e('Could not load payment form. Please try again or contact support.', 'mphb-toss'); ?></p>';
        });

        // If using the widget, you might need a button to trigger the actual payment request
        // Example (add a button with id="payment-button" in the HTML):
        /*
        const paymentButton = document.getElementById('payment-button');
        if (paymentButton) {
            paymentButton.addEventListener('click', () => {
                tossPayments.requestPayment('카드', { // Or get method from widget selection
                   // Parameters are usually taken from the widget instance now
                });
            });
        }
        */

    });
</script>

<?php
get_footer(); // Optional: Include theme footer
?>

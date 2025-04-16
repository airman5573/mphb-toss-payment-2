<?php

/**
* @param int $orderId
*/
public function completePaymentByWooOrder(){
$payment = // get payment by payment_id

if ( !is_null( $payment ) ) {
    $booking = MPHB()->getBookingRepository()->findById( $payment->getBookingId() );

    $needToRebook = !is_null( $booking ) && !in_array( $booking->getStatus(), MPHB()->postTypes()->booking()->statuses()->getLockedRoomStatuses() );
    $canRebook = !is_null( $booking ) && BookingUtils::canRebook( $booking );

    if ( !$needToRebook || $canRebook ) {
        // Don't check the standard transition rules. We can set any
        // status and don't get the overbooking
        MPHB()->paymentManager()->completePayment( $payment, '', true );
    } else if ( !is_null( $booking ) ) {
        // Send email to admin
        MPHB()->emails()->getEmail( 'admin_no_booking_renewal' )->trigger( $booking, array( 'payment' => $payment ) );
    }
}
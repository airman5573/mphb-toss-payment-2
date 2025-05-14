<?php

use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
use MPHB\PostTypes\BookingCPT\Statuses as BookingStatuses;

/**
 * Processes a refund for a given payment through Toss Payments.
 *
 * @param \MPHB\Entities\Payment $payment The payment object to be refunded.
 * @param float $refundAmount The amount to refund.
 * @param string $refundLogInput Optional. A log message for the refund.
 * @return array An array containing a boolean success status and a message.
 */
function mphb_toss_refund(\MPHB\Entities\Payment $payment, float $refundAmount, string $refundLogInput = ''): array
{
    $paymentId = $payment->getId(); // Get ID for logging and consistency
    $log_context = 'mphb_toss_refund';
    mphb_toss_write_log(
        "Refund process started. Payment ID: {$paymentId}, Amount: {$refundAmount}, Initial Log: \"{$refundLogInput}\"",
        $log_context
    );
    function_exists('ray') && ray('[mphb_toss_refund] 시작', ['paymentObject' => $payment, 'refundAmount' => $refundAmount, 'paymentId' => $paymentId]);

    try {
        // Payment object is now passed directly, no need to fetch it again.
        mphb_toss_write_log("Using provided Payment object. ID: {$paymentId}", $log_context);
        
        $bookingRepo = MPHB()->getBookingRepository();
        $booking = $bookingRepo->findById($payment->getBookingId());
        mphb_toss_write_log("Booking lookup. ID: " . $payment->getBookingId() . ", Found: " . ($booking ? 'Yes' : 'No'), $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] Booking 조회', ['bookingId' => $payment->getBookingId(), 'bookingFound' => (bool)$booking]);
        if (!$booking) {
            mphb_toss_write_log("Error - Booking not found for Booking ID: " . $payment->getBookingId(), $log_context . '_Error');
            return [false, '예약 정보를 찾을 수 없습니다.'];
        }

        $tossPaymentKey = $payment->getTransactionId();
        mphb_toss_write_log("Toss PaymentKey (Transaction ID): {$tossPaymentKey}", $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] Toss transactionId', ['tossPaymentKey' => $tossPaymentKey]);
        if (!$tossPaymentKey) {
            mphb_toss_write_log("Error - Toss PaymentKey not found for Payment ID: {$paymentId}", $log_context . '_Error');
            return [false, 'Toss 결제키(transactionId)가 없습니다.'];
        }
        
        $secretKey = \MPHBTOSS\TossGlobalSettingsTab::get_global_secret_key();
        function_exists('ray') && ray('[mphb_toss_refund] Toss Global Secret Key (partial)', ['secretKeySet' => !empty($secretKey)]);
        if (empty($secretKey)) {
            mphb_toss_write_log("Error - Global Toss Secret Key is not set.", $log_context . '_Error');
            return [false, 'Toss Secret Key가 설정되지 않았습니다.'];
        }

        $refundAmountFloat = round($refundAmount, 2); // Already float from signature
        $paymentAmountFloat = round((float)$payment->getAmount(), 2);
        mphb_toss_write_log("Validating refund amount. Refund: {$refundAmountFloat}, Payment Total: {$paymentAmountFloat}", $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] 환불 금액 검증', ['refundAmount' => $refundAmountFloat, 'paymentAmount' => $paymentAmountFloat]);
        if ($refundAmountFloat <= 0 || $refundAmountFloat > $paymentAmountFloat) {
            mphb_toss_write_log("Error - Invalid refund amount: {$refundAmountFloat}. Payment Total: {$paymentAmountFloat}", $log_context . '_Error');
            return [false, '환불 금액이 잘못되었습니다.'];
        }

        $is_debug_mode = \MPHBTOSS\TossGlobalSettingsTab::is_debug_mode();
        $tossApi = new TossAPI($secretKey, $is_debug_mode);
        function_exists('ray') && ray('[mphb_toss_refund] TossAPI 객체 생성', ['isDebug' => $is_debug_mode]);

        $reason = mb_substr($refundLogInput ?: '고객 요청 또는 시스템에 의한 환불', 0, 200); // Max 200 chars for Toss
        mphb_toss_write_log("Calling TossAPI::cancelPayment. PaymentKey: {$tossPaymentKey}, Reason: {$reason}, Amount: {$refundAmountFloat}", $log_context);
        $result = $tossApi->cancelPayment($tossPaymentKey, $reason, $refundAmountFloat);
        mphb_toss_write_log("Toss API cancelPayment response: " . print_r(mphb_toss_sanitize_log_data($result), true), $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] Toss API 환불 응답', ['result' => $result]);

        if (!isset($result->status) || !in_array(strtoupper($result->status), ['CANCELED', 'PARTIAL_CANCELED', 'DONE'], true)) {
            $api_message = $result->message ?? ($result->code ?? 'API 오류');
            mphb_toss_write_log("Toss refund failed. API Status: " . ($result->status ?? 'N/A') . ", Message: " . $api_message, $log_context . '_Error');
            return [false, '환불 실패: ' . $api_message];
        }
        
        mphb_toss_write_log("Toss refund successful via API. Status: " . ($result->status ?? ''), $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] 환불 상세정보 (from result)', ['transactionKey' => $tossPaymentKey, 'refundAmount' => $refundAmountFloat, 'status' => $result->status ?? '','message' => $result->message ?? '']);

        $finalRefundLog = $refundLogInput;
        if (empty($finalRefundLog)) {
            $finalRefundLog = sprintf(
                'Toss 환불 완료 | 환불금액: %s원 | 상태: %s | 토스결제키 (PaymentKey): %s',
                number_format($refundAmountFloat), $result->status ?? 'N/A', $tossPaymentKey
            );
            if (!empty($result->cancels[0]->transactionKey)) {
                 $finalRefundLog .= ' | 토스 내부 취소 트랜잭션키: ' . $result->cancels[0]->transactionKey;
            }
        }
        mphb_toss_write_log("Final refund log message for MPHB: \"{$finalRefundLog}\"", $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] 환불 로그 (MPHB)', ['refundLog' => $finalRefundLog]);

        MPHB()->paymentManager()->refundPayment($payment, $finalRefundLog, true); // Pass the object directly
        mphb_toss_write_log("MPHB payment status updated to refunded. Payment ID: {$paymentId}", $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] 결제 상태 환불로 변경 완료', ['paymentId' => $paymentId]);

        if (abs($refundAmountFloat - $paymentAmountFloat) < 0.01) { // Full refund
            $booking->setStatus(BookingStatuses::STATUS_CANCELLED);
            $bookingRepo->save($booking);
            mphb_toss_write_log("Booking status set to CANCELLED (full refund). Booking ID: {$booking->getId()}", $log_context);
        } else {
            mphb_toss_write_log("Partial refund. Booking status NOT changed. Booking ID: {$booking->getId()}", $log_context);
        }
        function_exists('ray') && ray('[mphb_toss_refund] 예약 상태 변경 완료 (if full refund)', ['bookingId' => $booking->getId()]);

        mphb_toss_write_log("Refund process completed successfully for Payment ID: {$paymentId}.", $log_context);
        return [true, '환불이 완료되었습니다.'];

    } catch (TossException $e) {
        mphb_toss_write_log("TossException during refund for Payment ID {$paymentId}: " . $e->getMessage() . " (Code: " . $e->getErrorCode() . ")", $log_context . '_Error');
        function_exists('ray') && ray('[mphb_toss_refund] TossException 발생', ['message' => $e->getMessage()]);
        return [false, '[Toss API 예외] ' . $e->getMessage()];
    } catch (\Exception $e) {
        mphb_toss_write_log("Generic Exception during refund for Payment ID {$paymentId}: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), $log_context . '_Error');
        function_exists('ray') && ray('[mphb_toss_refund] Exception 발생', ['message' => $e->getMessage()]);
        return [false, '환불 중 오류: ' . $e->getMessage()];
    }
}

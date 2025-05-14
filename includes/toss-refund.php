<?php

use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
use MPHB\PostTypes\BookingCPT\Statuses as BookingStatuses;

function mphb_toss_refund($refundAmount, $paymentId, string $refundLogInput = ''): array // Renamed $refundLog to $refundLogInput to avoid confusion
{
    $log_context = 'mphb_toss_refund';
    mphb_toss_write_log(
        "Refund process started. Payment ID: {$paymentId}, Amount: {$refundAmount}, Initial Log: \"{$refundLogInput}\"",
        $log_context
    );
    function_exists('ray') && ray('[mphb_toss_refund] 시작', ['refundAmount' => $refundAmount, 'paymentId' => $paymentId]);

    try {
        $paymentRepo = MPHB()->getPaymentRepository();
        $payment = $paymentRepo->findById($paymentId);
        mphb_toss_write_log("Payment lookup. ID: {$paymentId}, Found: " . ($payment ? 'Yes' : 'No'), $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] Payment 조회', ['paymentId' => $paymentId, 'paymentFound' => (bool)$payment]);
        if (!$payment) {
            mphb_toss_write_log("Error - Payment not found for ID: {$paymentId}", $log_context . '_Error');
            return [false, '결제 내역을 찾을 수 없습니다.'];
        }

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
        // mphb_toss_write_log("Fetched Global Secret Key (partial): " . substr($secretKey, 0, 10) . "...", $log_context); // Sensitive, API call will log sanitized version
        function_exists('ray') && ray('[mphb_toss_refund] Toss Global Secret Key (partial)', ['secretKeySet' => !empty($secretKey)]);
        if (empty($secretKey)) {
            mphb_toss_write_log("Error - Global Toss Secret Key is not set.", $log_context . '_Error');
            return [false, 'Toss Secret Key가 설정되지 않았습니다.'];
        }

        $refundAmountFloat = round((float)$refundAmount, 2);
        $paymentAmountFloat = round((float)$payment->getAmount(), 2);
        mphb_toss_write_log("Validating refund amount. Refund: {$refundAmountFloat}, Payment Total: {$paymentAmountFloat}", $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] 환불 금액 검증', ['refundAmount' => $refundAmountFloat, 'paymentAmount' => $paymentAmountFloat]);
        if ($refundAmountFloat <= 0 || $refundAmountFloat > $paymentAmountFloat) {
            mphb_toss_write_log("Error - Invalid refund amount: {$refundAmountFloat}. Payment Total: {$paymentAmountFloat}", $log_context . '_Error');
            return [false, '환불 금액이 잘못되었습니다.'];
        }

        $is_debug_mode = \MPHBTOSS\TossGlobalSettingsTab::is_debug_mode();
        $tossApi = new TossAPI($secretKey, $is_debug_mode);
        // mphb_toss_write_log("TossAPI object created for refund. Debug mode: " . ($is_debug_mode ? 'Yes':'No'), $log_context); // API constructor logs this if needed
        function_exists('ray') && ray('[mphb_toss_refund] TossAPI 객체 생성', ['isDebug' => $is_debug_mode]);

        $reason = '고객 환불 요청';
        mphb_toss_write_log("Calling TossAPI::cancelPayment. PaymentKey: {$tossPaymentKey}, Reason: {$reason}, Amount: {$refundAmountFloat}", $log_context);
        $result = $tossApi->cancelPayment($tossPaymentKey, $reason, $refundAmountFloat); // API will log its own req/resp
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
                'Toss 환불 완료 | 환불금액: %s원 | 상태: %s | 토스취소ID (PaymentKey): %s',
                number_format($refundAmountFloat), $result->status ?? 'N/A', $tossPaymentKey
            );
            if (!empty($result->cancels[0]->transactionKey)) {
                 $finalRefundLog .= ' | 토스 내부 취소 트랜잭션키: ' . $result->cancels[0]->transactionKey;
            }
            // if (!empty($result->message)) $finalRefundLog .= ' | 기타: ' . $result->message; // Message might be too generic or already in status
        }
        mphb_toss_write_log("Final refund log message for MPHB: \"{$finalRefundLog}\"", $log_context);
        function_exists('ray') && ray('[mphb_toss_refund] 환불 로그 (MPHB)', ['refundLog' => $finalRefundLog]);

        MPHB()->paymentManager()->refundPayment($payment, $finalRefundLog, true);
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

        mphb_toss_write_log("Refund process completed successfully.", $log_context);
        return [true, '환불이 완료되었습니다.'];

    } catch (TossException $e) {
        mphb_toss_write_log("TossException during refund: " . $e->getMessage() . " (Code: " . $e->getErrorCode() . ")", $log_context . '_Error');
        function_exists('ray') && ray('[mphb_toss_refund] TossException 발생', ['message' => $e->getMessage()]);
        return [false, '[Toss API 예외] ' . $e->getMessage()];
    } catch (\Exception $e) {
        mphb_toss_write_log("Generic Exception during refund: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), $log_context . '_Error');
        function_exists('ray') && ray('[mphb_toss_refund] Exception 발생', ['message' => $e->getMessage()]);
        return [false, '환불 중 오류: ' . $e->getMessage()];
    }
}

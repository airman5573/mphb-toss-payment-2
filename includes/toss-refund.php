<?php

// 이 플러그인 내의 네임스페이스 및 클래스 사용
use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
// MPHB 예약 상태 관련 상수 사용
use MPHB\PostTypes\BookingCPT\Statuses as BookingStatuses;

/**
 * 주어진 결제에 대해 토스페이먼츠를 통해 환불을 처리합니다.
 *
 * @param \MPHB\Entities\Payment $payment 환불할 결제 객체입니다.
 * @param float $refundAmount 환불할 금액입니다.
 * @param string $refundLogInput 선택 사항. 환불에 대한 로그 메시지입니다.
 * @return array 성공 여부(boolean)와 메시지(string)를 담은 배열을 반환합니다.
 */
function mphb_toss_refund(\MPHB\Entities\Payment $payment, float $refundAmount, string $refundLogInput = ''): array
{
    $paymentId = $payment->getId(); // 로깅 및 일관성을 위해 결제 ID 가져오기
    $log_context = 'mphb_toss_refund'; // 로그 컨텍스트 정의
    mphb_toss_write_log(
        "Refund process started. Payment ID: {$paymentId}, Amount: {$refundAmount}, Initial Log: \"{$refundLogInput}\"",
        $log_context
    );

    try {
        // 결제 객체는 이미 직접 전달받았으므로, 다시 가져올 필요가 없습니다.
        mphb_toss_write_log("Using provided Payment object. ID: {$paymentId}", $log_context);
        
        // 예약 정보 가져오기
        $bookingRepo = MPHB()->getBookingRepository();
        $booking = $bookingRepo->findById($payment->getBookingId());
        mphb_toss_write_log("Booking lookup. ID: " . $payment->getBookingId() . ", Found: " . ($booking ? 'Yes' : 'No'), $log_context);
        if (!$booking) { // 예약 정보를 찾을 수 없는 경우
            mphb_toss_write_log("Error - Booking not found for Booking ID: " . $payment->getBookingId(), $log_context . '_Error');
            return [false, '예약 정보를 찾을 수 없습니다.'];
        }

        // 결제 객체에서 토스 결제 키(트랜잭션 ID) 가져오기
        $tossPaymentKey = $payment->getTransactionId();
        mphb_toss_write_log("Toss PaymentKey (Transaction ID): {$tossPaymentKey}", $log_context);
        if (!$tossPaymentKey) { // 토스 결제 키가 없는 경우
            mphb_toss_write_log("Error - Toss PaymentKey not found for Payment ID: {$paymentId}", $log_context . '_Error');
            return [false, 'Toss 결제키(transactionId)가 없습니다.'];
        }
        
        // 전역 설정에서 토스 시크릿 키 가져오기
        $secretKey = \MPHBTOSS\TossGlobalSettingsTab::get_global_secret_key();
        if (empty($secretKey)) { // 시크릿 키가 설정되지 않은 경우
            mphb_toss_write_log("Error - Global Toss Secret Key is not set.", $log_context . '_Error');
            return [false, 'Toss Secret Key가 설정되지 않았습니다.'];
        }

        // 환불 금액 및 결제 금액 유효성 검사 (소수점 둘째 자리까지 반올림)
        $refundAmountFloat = round($refundAmount, 2); // 함수 시그니처에서 이미 float 타입
        $paymentAmountFloat = round((float)$payment->getAmount(), 2);
        mphb_toss_write_log("Validating refund amount. Refund: {$refundAmountFloat}, Payment Total: {$paymentAmountFloat}", $log_context);
        if ($refundAmountFloat <= 0 || $refundAmountFloat > $paymentAmountFloat) { // 환불 금액이 0 이하이거나 결제 금액을 초과하는 경우
            mphb_toss_write_log("Error - Invalid refund amount: {$refundAmountFloat}. Payment Total: {$paymentAmountFloat}", $log_context . '_Error');
            return [false, '환불 금액이 잘못되었습니다.'];
        }

        // 디버그 모드 여부 확인 후 토스 API 객체 생성
        $is_debug_mode = \MPHBTOSS\TossGlobalSettingsTab::is_debug_mode();
        $tossApi = new TossAPI($secretKey, $is_debug_mode);

        // 환불 사유 설정 (입력값이 없으면 기본 사유 사용, 최대 200자)
        $reason = mb_substr($refundLogInput ?: '고객 요청 또는 시스템에 의한 환불', 0, 200);
        mphb_toss_write_log("Calling TossAPI::cancelPayment. PaymentKey: {$tossPaymentKey}, Reason: {$reason}, Amount: {$refundAmountFloat}", $log_context);
        // 토스 API를 통해 결제 취소(환불) 요청
        $result = $tossApi->cancelPayment($tossPaymentKey, $reason, $refundAmountFloat);
        mphb_toss_write_log("Toss API cancelPayment response: " . print_r(mphb_toss_sanitize_log_data($result), true), $log_context);

        // API 응답 상태 확인 (CANCELED, PARTIAL_CANCELED, DONE 중 하나여야 함)
        if (!isset($result->status) || !in_array(strtoupper($result->status), ['CANCELED', 'PARTIAL_CANCELED', 'DONE'], true)) {
            $api_message = $result->message ?? ($result->code ?? 'API 오류');
            mphb_toss_write_log("Toss refund failed. API Status: " . ($result->status ?? 'N/A') . ", Message: " . $api_message, $log_context . '_Error');
            return [false, '환불 실패: ' . $api_message];
        }
        
        mphb_toss_write_log("Toss refund successful via API. Status: " . ($result->status ?? ''), $log_context);

        // MPHB 시스템에 기록할 최종 환불 로그 메시지 생성
        $finalRefundLog = $refundLogInput;
        if (empty($finalRefundLog)) { // 입력된 로그 메시지가 없으면 자동 생성
            $finalRefundLog = sprintf(
                'Toss 환불 완료 | 환불금액: %s원 | 상태: %s | 토스결제키 (PaymentKey): %s',
                number_format($refundAmountFloat), $result->status ?? 'N/A', $tossPaymentKey
            );
            // 토스 내부 취소 트랜잭션 키가 있으면 로그에 추가
            if (!empty($result->cancels[0]->transactionKey)) {
                 $finalRefundLog .= ' | 토스 내부 취소 트랜잭션키: ' . $result->cancels[0]->transactionKey;
            }
        }
        mphb_toss_write_log("Final refund log message for MPHB: \"{$finalRefundLog}\"", $log_context);

        // MPHB 결제 관리자를 통해 결제 상태를 환불됨으로 변경
        MPHB()->paymentManager()->refundPayment($payment, $finalRefundLog, true); // 결제 객체 직접 전달
        mphb_toss_write_log("MPHB payment status updated to refunded. Payment ID: {$paymentId}", $log_context);

        // 전액 환불인 경우 예약 상태를 '취소됨'으로 변경
        if (abs($refundAmountFloat - $paymentAmountFloat) < 0.01) { // 부동소수점 비교 오차 고려
            $booking->setStatus(BookingStatuses::STATUS_CANCELLED); // 예약 상태 변경
            $bookingRepo->save($booking); // 변경된 예약 정보 저장
            mphb_toss_write_log("Booking status set to CANCELLED (full refund). Booking ID: {$booking->getId()}", $log_context);
        } else { // 부분 환불인 경우 예약 상태는 변경하지 않음
            mphb_toss_write_log("Partial refund. Booking status NOT changed. Booking ID: {$booking->getId()}", $log_context);
        }

        mphb_toss_write_log("Refund process completed successfully for Payment ID: {$paymentId}.", $log_context);
        return [true, '환불이 완료되었습니다.'];

    } catch (TossException $e) { // 토스 API 예외 처리
        mphb_toss_write_log("TossException during refund for Payment ID {$paymentId}: " . $e->getMessage() . " (Code: " . $e->getErrorCode() . ")", $log_context . '_Error');
        return [false, '[Toss API 예외] ' . $e->getMessage()];
    } catch (\Exception $e) { // 그 외 일반적인 예외 처리
        mphb_toss_write_log("Generic Exception during refund for Payment ID {$paymentId}: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), $log_context . '_Error');
        return [false, '환불 중 오류: ' . $e->getMessage()];
    }
}


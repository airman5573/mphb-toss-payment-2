<?php

use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
use MPHB\PostTypes\BookingCPT\Statuses as BookingStatuses;

/**
 * TossPayments 환불(취소) 처리 함수 (MPHB struct 활용, 환불 로그 인자 포함, booking status는 BookingStatuses::STATUS_CANCELLED),
 * 함수 과정별 ray 디테일 로그 포함.
 *
 * @param float  $refundAmount 환불할 금액(정수, 소수점 반올림)
 * @param int    $paymentId    결제 엔티티(Post ID)
 * @param string $refundLog    (선택) 환불 로그 메시지, 비워두면 기본 메시지 생성
 *
 * @return array [성공여부(bool), 메시지(string)]
 */
function mphb_toss_refund($refundAmount, $paymentId, string $refundLog = ''): array
{
    function_exists('ray') && ray('[mphb_toss_refund] 시작', ['refundAmount' => $refundAmount, 'paymentId' => $paymentId]);

    try {
        $paymentRepo = MPHB()->getPaymentRepository();
        function_exists('ray') && ray('[mphb_toss_refund] PaymentRepository', ['class' => get_class($paymentRepo)]);
        
        $payment = $paymentRepo->findById($paymentId);
        function_exists('ray') && ray('[mphb_toss_refund] Payment 조회', ['paymentId' => $paymentId, 'paymentFound' => (bool)$payment]);

        if (!$payment) {
            function_exists('ray') && ray('[mphb_toss_refund] 에러 - 결제 내역 없음', ['paymentId' => $paymentId]);
            return [false, '결제 내역을 찾을 수 없습니다.'];
        }

        $bookingRepo = MPHB()->getBookingRepository();
        function_exists('ray') && ray('[mphb_toss_refund] BookingRepository', ['class' => get_class($bookingRepo)]);

        $booking = $bookingRepo->findById($payment->getBookingId());
        function_exists('ray') && ray('[mphb_toss_refund] Booking 조회', ['bookingId' => $payment->getBookingId(), 'bookingFound' => (bool)$booking]);

        if (!$booking) {
            function_exists('ray') && ray('[mphb_toss_refund] 에러 - 예약 정보 없음', ['bookingId' => $payment->getBookingId()]);
            return [false, '예약 정보를 찾을 수 없습니다.'];
        }

        $tossPaymentKey = $payment->getTransactionId();
        function_exists('ray') && ray('[mphb_toss_refund] Toss transactionId', ['tossPaymentKey' => $tossPaymentKey]);

        if (!$tossPaymentKey) {
            function_exists('ray') && ray('[mphb_toss_refund] 에러 - Toss 결제키 없음', ['paymentId' => $paymentId]);
            return [false, 'Toss 결제키(transactionId)가 없습니다.'];
        }

        $tossGateway = MPHB()->gatewayManager()->getGateway('toss');
        $secretKey = $tossGateway ? $tossGateway->getSecretKey() : '';
        function_exists('ray') && ray('[mphb_toss_refund] Toss Gateway 및 Secret Key', ['hasGateway' => (bool)$tossGateway, 'secretKeySet' => !empty($secretKey)]);

        if (!$secretKey) {
            function_exists('ray') && ray('[mphb_toss_refund] 에러 - Secret Key 없음');
            return [false, 'Toss Secret Key가 없습니다.'];
        }

        $refundAmount = round((float)$refundAmount);
        function_exists('ray') && ray('[mphb_toss_refund] 환불 금액 검증', ['refundAmount' => $refundAmount, 'paymentAmount' => (float)$payment->getAmount()]);

        if ($refundAmount <= 0 || $refundAmount > (float)$payment->getAmount()) {
            function_exists('ray') && ray('[mphb_toss_refund] 에러 - 환불 금액 오류', ['refundAmount' => $refundAmount]);
            return [false, '환불 금액이 잘못되었습니다.'];
        }

        $tossApi = new TossAPI($secretKey, WP_DEBUG);
        function_exists('ray') && ray('[mphb_toss_refund] TossAPI 객체 생성', ['isDebug' => WP_DEBUG]);

        $reason = '고객 환불 요청';
        $result = $tossApi->cancelPayment($tossPaymentKey, $reason, $refundAmount);
        function_exists('ray') && ray('[mphb_toss_refund] Toss API 환불 응답', ['result' => $result]);

        if (!isset($result->status) || !in_array($result->status, ['CANCELED', 'PARTIAL_CANCELED', 'DONE'], true)) {
            function_exists('ray') && ray('[mphb_toss_refund] 에러 - Toss 환불 실패', ['message' => $result->message ?? null]);
            return [false, '환불 실패: ' . ($result->message ?? 'API 오류')];
        }

        
        function_exists('ray') && ray('[mphb_toss_refund] 환불 상세정보', [
            'transactionKey' => $tossPaymentKey,
            'refundAmount' => $refundAmount,
            'status' => $result->status ?? '',
            'message' => $result->message ?? '',
        ]);

        if (empty($refundLog)) {
            $refundLog = sprintf(
                'Toss 환불 완료 | 환불금액: %s원 | 상태: %s | 토스취소ID: %s',
                number_format($refundAmount),
                $result->status ?? '',
                $tossPaymentKey
            );
            if (!empty($result->message)) {
                $refundLog .= ' | 기타: ' . $result->message;
            }
        }
        function_exists('ray') && ray('[mphb_toss_refund] 환불 로그', ['refundLog' => $refundLog]);

        MPHB()->paymentManager()->refundPayment($payment, $refundLog, true);
        function_exists('ray') && ray('[mphb_toss_refund] 결제 상태 환불로 변경 완료', ['paymentId' => $paymentId]);

        $booking->setStatus(BookingStatuses::STATUS_CANCELLED);
        $bookingRepo->save($booking);
        function_exists('ray') && ray('[mphb_toss_refund] 예약 상태 취소로 변경 완료', ['bookingId' => $booking->getId()]);

        function_exists('ray') && ray('[mphb_toss_refund] 완료', ['success' => true]);
        return [true, '환불이 완료되었습니다.'];

    } catch (TossException $e) {
        function_exists('ray') && ray('[mphb_toss_refund] TossException 발생', ['message' => $e->getMessage()]);
        return [false, '[Toss API 예외] ' . $e->getMessage()];
    } catch (\Exception $e) {
        function_exists('ray') && ray('[mphb_toss_refund] Exception 발생', ['message' => $e->getMessage()]);
        return [false, '환불 중 오류: ' . $e->getMessage()];
    }
}

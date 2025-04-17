<?php

use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
use MPHB\PostTypes\BookingCPT\Statuses as BookingStatuses;

/**
 * TossPayments 환불(취소) 처리 함수 (MPHB struct 활용, 환불 로그만, booking status는 BookingStatuses::STATUS_CANCELLED).
 *
 * @param float $refundAmount 환불할 금액(정수, 소수점 반올림)
 * @param int $paymentId 결제 엔티티(Post ID)
 * @return array [성공여부(bool), 메시지(string)]
 */
function mphb_toss_refund($refundAmount, $paymentId): array
{
    try {
        $paymentRepo = MPHB()->getPaymentRepository();
        $payment = $paymentRepo->findById($paymentId);
        if (!$payment) {
            return [false, '결제 내역을 찾을 수 없습니다.'];
        }

        $bookingRepo = MPHB()->getBookingRepository();
        $booking = $bookingRepo->findById($payment->getBookingId());
        if (!$booking) {
            return [false, '예약 정보를 찾을 수 없습니다.'];
        }

        $tossPaymentKey = $payment->getTransactionId();
        if (!$tossPaymentKey) {
            return [false, 'Toss 결제키(transactionId)가 없습니다.'];
        }

        $tossGateway = MPHB()->gatewayManager()->getGateway('toss');
        $secretKey = $tossGateway ? $tossGateway->getSecretKey() : '';
        if (!$secretKey) {
            return [false, 'Toss Secret Key가 없습니다.'];
        }

        $refundAmount = round((float)$refundAmount);
        if ($refundAmount <= 0 || $refundAmount > (float)$payment->getAmount()) {
            return [false, '환불 금액이 잘못되었습니다.'];
        }

        $tossApi = new TossAPI($secretKey, WP_DEBUG);
        $reason = '고객 환불 요청';
        $result = $tossApi->cancelPayment($tossPaymentKey, $reason, $refundAmount);

        if (!isset($result->status) || !in_array($result->status, ['CANCELED', 'PARTIAL_CANCELED', 'DONE'], true)) {
            return [false, '환불 실패: ' . ($result->message ?? 'API 오류')];
        }

        $transactionKey = 'N/A';
        $actualRefundAmount = $refundAmount;
        if (!empty($result->cancels) && is_array($result->cancels)) {
            $latestCancel = end($result->cancels);
            if (isset($latestCancel->transactionKey)) {
                $transactionKey = $latestCancel->transactionKey;
            }
            if (isset($latestCancel->cancelAmount)) {
                $actualRefundAmount = $latestCancel->cancelAmount;
            }
        }
        $log = sprintf(
            'Toss 환불 완료 | 환불금액: %s원 | 상태: %s | 토스취소ID: %s',
            number_format($actualRefundAmount),
            $result->status ?? '',
            $transactionKey
        );
        if (!empty($result->message)) {
            $log .= ' | 기타: ' . $result->message;
        }

        // Payment 상태 전환 및 환불 로그(자동 저장)
        MPHB()->paymentManager()->refundPayment($payment, $log, true);

        // Booking 상태 전환: STATUS_CANCELLED(상수 활용)
        $booking->setStatus(BookingStatuses::STATUS_CANCELLED);
        $bookingRepo->save($booking);

        return [true, '환불이 완료되었습니다.'];

    } catch (TossException $e) {
        return [false, '[Toss API 예외] ' . $e->getMessage()];
    } catch (\Exception $e) {
        return [false, '환불 중 오류: ' . $e->getMessage()];
    }
}

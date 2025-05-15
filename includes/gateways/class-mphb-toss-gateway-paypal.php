<?php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠를 통한 페이팔 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayPaypal extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_paypal' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'paypal';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('페이팔 (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('페이팔 (PayPal)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('페이팔을 통해 결제합니다. (토스페이먼츠 연동)', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 페이팔은 'PAYPAL'로 처리됩니다.
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'PAYPAL';
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 부모 클래스의 처리를 호출한 후, 페이팔 관련 정보를 결제 메타데이터로 저장합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 클래스 처리
        $log_context = get_class($this) . '::afterPaymentConfirmation'; // 로그 컨텍스트
        mphb_toss_write_log("PayPal Gateway - Payment ID: " . $payment->getId(), $log_context);

        // 토스 API 응답에 paypal 정보가 있는 경우
        if (isset($tossResult->paypal)) {
            $paypalInfo = $tossResult->paypal;
            mphb_toss_write_log(
                "Saving PayPal info: PayerID: " . ($paypalInfo->payerId ?? 'N/A') . 
                ", TransactionID (from PayPal obj): " . ($paypalInfo->transactionId ?? 'N/A'),
                $log_context
            );
            // 결제 포스트 메타로 페이팔 Payer ID 및 거래 ID 저장
            update_post_meta($payment->getId(), '_mphb_toss_paypal_payer_id', $paypalInfo->payerId ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_paypal_transaction_id', $paypalInfo->transactionId ?? ($tossResult->paymentKey ?? ''));
        } elseif (isset($tossResult->foreignCardDetails)) { // paypal 객체가 없고 foreignCardDetails가 있는 경우 (해외카드처럼 처리된 경우)
             mphb_toss_write_log("PayPal object not found, saving ForeignCardDetails as PayPal. Company: " . ($tossResult->foreignCardDetails->company ?? 'PayPal'), $log_context);
             $cardInfo = $tossResult->foreignCardDetails;
             // 카드 회사 정보를 페이팔로 간주하고 저장
             update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'PayPal');
        } else { // paypal 또는 foreignCardDetails 객체 모두 없는 경우
            mphb_toss_write_log("Neither paypal nor foreignCardDetails object found in TossResult for PayPal. Method: " . ($tossResult->method ?? 'N/A'), $log_context . '_Warning');
            // 결제 방법 상세 정보(예: 'PAYPAL')라도 저장 시도
            update_post_meta($payment->getId(), '_mphb_toss_payment_method_details', $tossResult->method ?? 'PayPal');
        }
    }
}


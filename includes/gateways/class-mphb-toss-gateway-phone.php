<?php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 휴대폰 소액결제 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayPhone extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_phone' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'phone';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('휴대폰 소액결제 (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('휴대폰 소액결제', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('휴대폰 소액결제로 간편하게 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 휴대폰 소액결제는 'MOBILE_PHONE'으로 처리됩니다.
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'MOBILE_PHONE';
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 부모 클래스의 처리를 호출한 후, 휴대폰 결제 관련 정보를 결제 메타데이터로 저장합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 클래스 처리
        $log_context = get_class($this) . '::afterPaymentConfirmation'; // 로그 컨텍스트
        mphb_toss_write_log("Mobile Phone Gateway - Payment ID: " . $payment->getId(), $log_context);

        // 토스 API 응답에 mobilePhone (휴대폰 결제) 정보가 있는 경우
        if (isset($tossResult->mobilePhone)) {
            $phoneInfo = $tossResult->mobilePhone;
            mphb_toss_write_log(
                // 고객 휴대폰 번호는 승인 응답에 없을 수 있음
                "Saving mobile phone info: SettlementStatus: " . ($phoneInfo->settlementStatus ?? 'N/A'),
                $log_context
            );
            // 결제 포스트 메타로 고객 휴대폰 번호(결제 요청 시 전달, 응답에는 없을 수 있음) 및 정산 상태 저장
            update_post_meta($payment->getId(), '_mphb_toss_phone_customer_mobile', $phoneInfo->customerMobilePhone ?? ''); 
            update_post_meta($payment->getId(), '_mphb_toss_phone_settlement_status', $phoneInfo->settlementStatus ?? '');
            // 영수증 URL이 응답에 있는 경우 저장
            if(isset($tossResult->receipt->url)){
                 update_post_meta($payment->getId(), '_mphb_toss_receipt_url', $tossResult->receipt->url);
            }
        } else { // mobilePhone 객체가 없는 경우
            mphb_toss_write_log("MobilePhone object not found in TossResult.", $log_context . '_Warning');
        }
    }
}


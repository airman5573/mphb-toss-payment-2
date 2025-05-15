<?php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Admin\Fields\FieldFactory; // MPHB 관리자 필드 팩토리 (옵션 설정 등에 사용될 수 있음)
use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 가상계좌 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayVbank extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_vbank' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'vbank';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('가상계좌 (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('가상계좌 (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('안내되는 가상계좌로 입금하여 결제를 완료합니다.', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 가상계좌는 'VIRTUAL_ACCOUNT'로 처리됩니다.
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'VIRTUAL_ACCOUNT';
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 이 클래스에서는 주로 부모 클래스(TossGatewayBase)의 afterPaymentConfirmation 메소드를 호출하여
     * 공통적인 가상계좌 정보 저장을 수행합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        // 부모 클래스(TossGatewayBase)가 VIRTUAL_ACCOUNT 메소드에 대한 로그 및 공통 가상계좌 상세 정보 저장을 처리합니다.
        $log_context = get_class($this) . '::afterPaymentConfirmation (VBank Child)'; // 로그 컨텍스트
        mphb_toss_write_log("VBank Gateway - Payment ID: " . $payment->getId() . ". Calling parent for VAccount details.", $log_context);
        
        // 부모 클래스의 afterPaymentConfirmation 메소드 호출 (가상계좌 정보 저장 로직 포함)
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        // 만약 부모 클래스에서 처리하지 않은 *추가적인* 가상계좌 관련 세부 정보가 있다면,
        // 여기서 처리하고 로그를 남깁니다.
        // 현재는 부모 클래스가 표준 virtualAccount 객체 필드를 다룹니다.
        if (!isset($tossResult->virtualAccount)) { // 가상계좌 정보가 응답에 없는 경우 (경고)
            mphb_toss_write_log("VBank specific: virtualAccount object was expected but not found in TossResult for Payment ID: " . $payment->getId() . ". This might indicate an issue or an unexpected response structure.", $log_context . '_Warning');
        }
    }
}


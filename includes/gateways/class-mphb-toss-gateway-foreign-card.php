<?php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 해외 발행 신용카드 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayForeignCard extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_foreign_card' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'foreign_card';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('해외 발행 신용카드 (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('해외 발행 신용카드 (Visa, Master, JCB 등)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('해외에서 발행된 신용카드로 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 해외 카드도 'CARD' (카드)로 처리됩니다. (JS에서 useInternationalCardOnly 플래그 사용)
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'CARD';
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 부모 클래스의 처리를 호출한 후, 해외 카드 결제 관련 정보를 결제 메타데이터로 저장합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 클래스 처리
        $log_context = get_class($this) . '::afterPaymentConfirmation'; // 로그 컨텍스트
        mphb_toss_write_log("Foreign Card Gateway - Payment ID: " . $payment->getId(), $log_context);

        // 토스 API 응답에 card (카드) 정보가 있는 경우
        if (isset($tossResult->card)) {
            $cardInfo = $tossResult->card;
            mphb_toss_write_log(
                "Saving foreign card info: Company: " . ($cardInfo->company ?? 'N/A') . 
                ", ApproveNo: " . ($cardInfo->approveNo ?? 'N/A'),
                $log_context
            );
            // 결제 포스트 메타로 카드 관련 정보 저장
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? ''); // 카드사
            update_post_meta($payment->getId(), '_mphb_toss_card_number_masked', $cardInfo->number ?? ''); // 마스킹된 카드번호
            update_post_meta($payment->getId(), '_mphb_toss_card_installment_plan_months', $cardInfo->installmentPlanMonths ?? 0); // 할부 개월 수 (해외 카드는 보통 0)
            update_post_meta($payment->getId(), '_mphb_toss_card_approve_no', $cardInfo->approveNo ?? ''); // 승인 번호
            update_post_meta($payment->getId(), '_mphb_toss_card_type', $cardInfo->cardType ?? ''); // 카드 타입
            update_post_meta($payment->getId(), '_mphb_toss_card_owner_type', $cardInfo->ownerType ?? ''); // 소유자 타입
            // 해외 카드임을 나타내는 플래그 저장
            update_post_meta($payment->getId(), '_mphb_toss_card_is_foreign', true);
        } else { // card 객체가 없는 경우
            mphb_toss_write_log("Card object (for foreign card) not found in TossResult.", $log_context . '_Warning');
        }
    }
}


<?php
namespace MPHBTOSS\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayCard extends TossGatewayBase {

    /**
     * 게이트웨이의 고유 ID를 초기화합니다. (예: "toss_card")
     * 이 ID는 MPHB 설정, URL 파라미터 등에서 사용됩니다.
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'card';
    }

    /**
     * 게이트웨이의 관리자용 제목을 설정합니다.
     * MPHB 설정 페이지의 게이트웨이 목록에 표시됩니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 setupProperties 호출 (선택적)
        $this->adminTitle = __('Toss Payments - Credit Card', 'mphb-toss-payments');
        // $this->icon = MPHB_TOSS_PAYMENTS_PLUGIN_URL . 'assets/images/card_icon.png'; // 아이콘 URL (필요시)
    }

    /**
     * 이 게이트웨이의 기본 제목을 반환합니다. (사용자에게 표시됨)
     */
    protected function getDefaultTitle(): string {
        return __('신용카드 (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 이 게이트웨이의 기본 설명을 반환합니다. (사용자에게 표시됨)
     */
    protected function getDefaultDescription(): string {
        return __('토스페이먼츠를 통해 안전하게 신용카드로 결제합니다.', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 JS SDK에 전달할 `method` 값을 반환합니다.
     */
    protected function getTossMethod(): string {
        return 'CARD'; // 토스페이먼츠 카드 결제 메소드
    }

    /**
     * (선택적) 카드 결제 완료 후 특별히 저장할 정보가 있다면 여기에 추가합니다.
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 메소드 호출

        if (isset($tossResult->card)) {
            $cardInfo = $tossResult->card;
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_number_masked', $cardInfo->number ?? ''); // 마스킹된 카드번호
            update_post_meta($payment->getId(), '_mphb_toss_card_installment_plan_months', $cardInfo->installmentPlanMonths ?? 0);
            update_post_meta($payment->getId(), '_mphb_toss_card_approve_no', $cardInfo->approveNo ?? ''); // 승인번호
            update_post_meta($payment->getId(), '_mphb_toss_card_type', $cardInfo->cardType ?? ''); // 카드 종류 (신용/체크/기프트)
            update_post_meta($payment->getId(), '_mphb_toss_card_owner_type', $cardInfo->ownerType ?? ''); // 소유자 유형 (개인/법인)
        }
    }
}

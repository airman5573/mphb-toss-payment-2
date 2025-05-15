<?php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 애플페이 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayApplepay extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_applepay' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'applepay';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('Apple Pay (Toss Payments)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('Apple Pay', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('Pay with Apple Pay via Toss Payments.', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 애플페이는 'CARD' (카드)로 처리됩니다.
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'CARD';
    }

    /**
     * 간편결제 제공사 코드를 반환합니다.
     * @return string 간편결제 제공사 코드 (애플페이)
     */
    public function getEasyPayProviderCode(): string {
        return 'APPLEPAY';
    }

    /**
     * 선호하는 결제 흐름 모드를 반환합니다. (예: 'DIRECT', 'IFRAME')
     * 애플페이는 'DIRECT' (직접 연동) 방식을 사용합니다.
     * @return string 선호 결제 흐름 모드
     */
    public function getPreferredFlowMode(): string {
        return 'DIRECT';
    }

    /**
     * 이 게이트웨이가 현재 활성화될 수 있는 조건인지 확인합니다.
     * 부모 클래스의 isEnabled 조건을 만족하고, 사용자 환경이 애플페이를 지원하는지 확인합니다.
     * @return bool 활성화 가능 여부
     */
    public function isEnabled(): bool {
        // 부모 클래스에서 기본적인 활성화 조건 (API 키 설정 등) 확인
        if (!parent::isEnabled()) {
            return false;
        }
        // HTTP_USER_AGENT 정보가 없으면 비활성화
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        $userAgent = $_SERVER['HTTP_USER_AGENT']; // 사용자 에이전트 문자열
        // 모바일 환경인 경우
        if (wp_is_mobile()) {
            // iPhone 또는 iPad인지 확인
            return (bool) preg_match("/(iPhone|iPad)/i", $userAgent);
        } else { // PC 환경인 경우
            // Mac Safari 환경인지 확인 (Chrome, Edge 제외)
            return (strpos($userAgent, 'Macintosh') !== false &&
                    strpos($userAgent, 'Safari/') !== false &&
                    strpos($userAgent, 'Chrome/') === false &&
                    strpos($userAgent, 'Edg/') === false);
        }
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 부모 클래스의 처리를 호출한 후, 애플페이 관련 정보를 결제 메타데이터로 저장합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 클래스 처리
        $log_context = get_class($this) . '::afterPaymentConfirmation'; // 로그 컨텍스트
        mphb_toss_write_log("ApplePay Gateway - Payment ID: " . $payment->getId(), $log_context);

        // 토스 API 응답에 easyPay 정보가 있는 경우 (일반적인 간편결제)
        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            mphb_toss_write_log(
                "Saving EasyPay (ApplePay) info: Provider: " . ($easyPayInfo->provider ?? 'N/A'),
                $log_context
            );
            // 결제 포스트 메타로 간편결제 제공사 및 할인 금액 저장
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'ApplePay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        } elseif (isset($tossResult->card)) { // easyPay 객체는 없지만 card 정보가 있는 경우 (대체 처리)
            mphb_toss_write_log("EasyPay object not found, saving Card info as ApplePay. Company: " . ($tossResult->card->company ?? 'ApplePay'), $log_context);
            $cardInfo = $tossResult->card;
            // 카드 정보를 애플페이 정보로 간주하고 저장
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'ApplePay');
            update_post_meta($payment->getId(), '_mphb_toss_card_number_masked', $cardInfo->number ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_installment_plan_months', $cardInfo->installmentPlanMonths ?? 0);
            update_post_meta($payment->getId(), '_mphb_toss_card_approve_no', $cardInfo->approveNo ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_type', $cardInfo->cardType ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_owner_type', $cardInfo->ownerType ?? '');
        } else { // easyPay와 card 객체 모두 없는 경우
            mphb_toss_write_log("Neither easyPay nor card object found in TossResult for ApplePay.", $log_context . '_Warning');
        }
    }
}


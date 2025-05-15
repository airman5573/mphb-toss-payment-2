<?php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 토스페이(토스머니) 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayTosspay extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_tosspay' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'tosspay';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('토스페이 (토스머니) (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('토스페이 (토스머니)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('토스페이(토스머니)로 간편하게 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 이전에는 'TOSSPAY' 였으나, 간편결제들은 'CARD' 메소드와 provider 코드를 사용하는 경우가 많습니다. 토스 문서 확인 필요.
     * 토스페이먼츠에서 'TOSSPAY' 메소드가 필요하다면 다시 변경해야 합니다.
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'CARD'; 
    }

    /**
     * 간편결제 제공사 코드를 반환합니다.
     * @return string 간편결제 제공사 코드 (토스페이)
     */
    public function getEasyPayProviderCode(): string {
        return 'TOSSPAY';
    }

    /**
     * 선호하는 결제 흐름 모드를 반환합니다.
     * 토스페이는 'DIRECT' (직접 연동) 방식을 사용합니다.
     * @return string 선호 결제 흐름 모드
     */
    public function getPreferredFlowMode(): string {
        return 'DIRECT';
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 부모 클래스의 처리를 호출한 후, 토스페이 관련 정보를 결제 메타데이터로 저장합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 클래스 처리
        $log_context = get_class($this) . '::afterPaymentConfirmation'; // 로그 컨텍스트
        mphb_toss_write_log("TossPay Gateway - Payment ID: " . $payment->getId(), $log_context);

        // 토스 API 응답에 easyPay 정보가 있는 경우
        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            mphb_toss_write_log(
                "Saving EasyPay (TossPay) info: Provider: " . ($easyPayInfo->provider ?? 'N/A'),
                $log_context
            );
            // 결제 포스트 메타로 간편결제 제공사 및 할인 금액 저장
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'TossPay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        } elseif (isset($tossResult->card) && $this->getTossMethod() === 'CARD') { // easyPay 객체가 없고, CARD 메소드를 사용하며, card 정보가 있는 경우
             mphb_toss_write_log("EasyPay object not found, saving Card info as TossPay. Company: " . ($tossResult->card->company ?? 'TossPay'), $log_context);
            $cardInfo = $tossResult->card;
            // 카드 회사 정보를 토스페이로 간주하고 저장
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'TossPay');
        } else { // easyPay (또는 토스페이 메소드에 관련된) 객체가 없는 경우
            mphb_toss_write_log("EasyPay (or relevant for TossPay method) object not found in TossResult.", $log_context . '_Warning');
        }
    }
}


<?php
// 파일 경로: includes/gateways/class-mphb-toss-gateway-lpay.php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 엘페이(L.Pay) 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayLpay extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_lpay' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'lpay';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('엘페이 (L.Pay) (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('엘페이 (L.Pay)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('엘페이(L.Pay)로 간편하게 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 엘페이와 같은 간편결제는 'CARD'로 처리되고, JS에서 easyPay provider 코드가 사용됩니다.
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'CARD';
    }

    /**
     * 간편결제 제공사 코드를 반환합니다.
     * @return string 간편결제 제공사 코드 (엘페이)
     */
    public function getEasyPayProviderCode(): string {
        return 'LPAY';
    }

    /**
     * 선호하는 결제 흐름 모드를 반환합니다.
     * 엘페이는 'DIRECT' (직접 연동) 방식을 사용합니다.
     * @return string 선호 결제 흐름 모드
     */
    public function getPreferredFlowMode(): string {
        return 'DIRECT';
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 부모 클래스의 처리를 호출한 후, 엘페이 관련 정보를 결제 메타데이터로 저장합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 클래스 처리
        $log_context = get_class($this) . '::afterPaymentConfirmation'; // 로그 컨텍스트
        mphb_toss_write_log("L.Pay Gateway - Payment ID: " . $payment->getId(), $log_context);

        // 토스 API 응답에 easyPay 정보가 있는 경우
        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            mphb_toss_write_log(
                "Saving EasyPay (L.Pay) info: Provider: " . ($easyPayInfo->provider ?? 'N/A'),
                $log_context
            );
            // 결제 포스트 메타로 간편결제 제공사 및 할인 금액 저장
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'L.Pay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        } elseif (isset($tossResult->card)) { // easyPay 객체는 없지만 card 정보가 있는 경우 (대체 처리)
            mphb_toss_write_log("EasyPay object not found, saving Card info as L.Pay. Company: " . ($tossResult->card->company ?? 'L.Pay'), $log_context);
            $cardInfo = $tossResult->card;
            // 카드 회사 정보를 L.Pay로 간주하고 저장
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'L.Pay');
        } else { // easyPay와 card 객체 모두 없는 경우
            mphb_toss_write_log("Neither easyPay nor card object found in TossResult for L.Pay.", $log_context . '_Warning');
        }
    }
}


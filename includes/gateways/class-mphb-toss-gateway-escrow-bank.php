<?php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

use MPHB\Entities\Payment; // MPHB 결제 엔티티 사용
use MPHB\Entities\Booking; // MPHB 예약 엔티티 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 실시간 계좌이체 (에스크로) 게이트웨이 클래스입니다.
 * TossGatewayBase를 상속받습니다.
 */
class TossGatewayEscrowBank extends TossGatewayBase {

    /**
     * 게이트웨이 ID를 초기화합니다.
     * 'toss_escrow_bank' 형태로 반환됩니다.
     * @return string 게이트웨이 ID
     */
    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'escrow_bank';
    }

    /**
     * 게이트웨이 속성을 설정합니다.
     * 부모 클래스의 setupProperties를 호출하고, 관리자용 제목을 설정합니다.
     */
    protected function setupProperties(): void {
        parent::setupProperties(); // 부모 클래스의 속성 설정
        // 워드프레스 관리자 화면에 표시될 제목
        $this->adminTitle = __('실시간 계좌이체 (에스크로) (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환합니다.
     * @return string 결제수단 제목
     */
    protected function getDefaultTitle(): string {
        return __('실시간 계좌이체 (에스크로)', 'mphb-toss-payments');
    }

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환합니다.
     * @return string 결제수단 설명
     */
    protected function getDefaultDescription(): string {
        return __('토스페이먼츠 에스크로를 통해 계좌이체로 안전하게 결제합니다.', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환합니다.
     * 에스크로 계좌이체는 'TRANSFER' (계좌이체)로 처리됩니다. (JS에서 useEscrow 플래그 사용)
     * @return string 토스페이먼츠 결제 수단
     */
    public function getTossMethod(): string {
        return 'TRANSFER';
    }

    /**
     * 결제 승인 후 추가 작업을 처리합니다.
     * 부모 클래스의 처리를 호출한 후, 에스크로 계좌이체 관련 정보를 결제 메타데이터로 저장합니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 클래스 처리
        $log_context = get_class($this) . '::afterPaymentConfirmation'; // 로그 컨텍스트
        mphb_toss_write_log("Escrow Bank Transfer Gateway - Payment ID: " . $payment->getId(), $log_context);

        // 토스 API 응답에 transfer (계좌이체) 정보가 있는 경우
        if (isset($tossResult->transfer)) {
            $transferInfo = $tossResult->transfer;
            // 에스크로 상태는 transfer 객체 내에 있거나, 향후 별도의 escrow 객체에 있을 수 있습니다.
            // 두 경우 모두 확인하는 예시 (?? 연산자 사용)
            $escrowStatus = $transferInfo->escrowStatus ?? ($tossResult->escrow->status ?? 'N/A');
            mphb_toss_write_log(
                "Saving escrow bank transfer info: BankCode: " . ($transferInfo->bankCode ?? 'N/A') . 
                ", SettlementStatus: " . ($transferInfo->settlementStatus ?? 'N/A') .
                ", EscrowStatus: " . $escrowStatus,
                $log_context
            );
            // 결제 포스트 메타로 은행 코드, 정산 상태, 에스크로 상태 저장
            update_post_meta($payment->getId(), '_mphb_toss_transfer_bank_code', $transferInfo->bankCode ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_transfer_settlement_status', $transferInfo->settlementStatus ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_escrow_status', $escrowStatus);
        } else { // transfer 객체가 없는 경우 (에스크로 정보 포함)
            mphb_toss_write_log("Transfer object (for escrow) not found in TossResult.", $log_context . '_Warning');
        }
    }
}


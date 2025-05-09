<?php
namespace MPHBTOSS\Gateways;

use MPHB\Admin\Fields\FieldFactory; // 가상계좌 전용 필드 추가 시 사용
use MPHB\Entities\Payment; // 이 줄 추가
use MPHB\Entities\Booking; // 이 줄 추가

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayVbank extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'vbank'; // 예: "toss_vbank"
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('가상계좌 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('가상계좌 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('안내되는 가상계좌로 입금하여 결제를 완료합니다.', 'mphb-toss-payments');
    }

    protected function getTossMethod(): string {
        return 'VIRTUAL_ACCOUNT'; // 토스페이먼츠 가상계좌 메소드
    }

    /**
     * 가상계좌의 경우, 입금 대기 상태로 주문을 처리해야 할 수 있습니다.
     * MPHB는 기본적으로 결제가 완료되어야 예약이 확정되므로, 가상계좌의 경우
     * 사용자가 입금하기 전까지 예약 상태를 "결제 대기(Pending Payment)" 등으로 유지해야 합니다.
     * Toss API 응답의 status가 "WAITING_FOR_DEPOSIT"일 때 처리.
     *
     * handleInstanceTossCallback 메소드에서 이 부분을 고려해야 합니다.
     * 현재 Toss API v2 (Standard)에서는 confirmPayment 호출 시 가상계좌 발급 결과가 바로 DONE으로 오지 않고,
     * paymentKey를 successUrl로 넘겨주면, 해당 paymentKey로 결제 조회 API를 호출하여 가상계좌 정보를 얻는 방식이 일반적입니다.
     * 또는, confirmPayment 단계에서 method가 VIRTUAL_ACCOUNT인 경우, 그 응답에 가상계좌 정보가 포함되어 옵니다.
     * 여기서는 confirmPayment 응답에 가상계좌 정보가 포함된다고 가정하고, afterPaymentConfirmation에서 처리.
     */


    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult); // 부모 호출 (중요)

        // 가상계좌 정보 저장
        if (isset($tossResult->virtualAccount)) {
            $vAccount = $tossResult->virtualAccount;
            update_post_meta($payment->getId(), '_mphb_toss_vbank_account_number', $vAccount->accountNumber ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_vbank_bank_code', $vAccount->bankCode ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_vbank_customer_name', $vAccount->customerName ?? ''); // 예금주
            update_post_meta($payment->getId(), '_mphb_toss_vbank_due_date', $vAccount->dueDate ?? ''); // 입금 마감일
            update_post_meta($payment->getId(), '_mphb_toss_vbank_status', $vAccount->status ?? ''); // WAITING_FOR_DEPOSIT 등

            // 가상계좌 발급 시 예약 상태를 '입금 대기' 등으로 변경하고, 결제 완료는 웹훅을 통해 처리해야 할 수 있음.
            // MPHB에서는 payment status가 completed가 아니면 예약이 확정되지 않을 수 있음.
            // 만약 즉시 예약 확정 후 입금 대기로 처리하려면, MPHB 예약 상태 관리에 대한 추가 작업 필요.
            // 현재 로직은 confirmPayment 응답이 DONE인 경우에만 completePayment를 호출하므로,
            // 가상계좌 발급 후 WAITING_FOR_DEPOSIT 상태라면, completePayment가 호출되지 않도록 해야 함.
            // Toss Standard JS SDK의 경우, 가상계좌 요청 후 successUrl로 왔을 때 confirmPayment를 하면
            // 응답이 status:DONE, method:VIRTUAL_ACCOUNT, virtualAccount 객체 포함 형태로 옴. (이미 승인된 것으로 간주)
            // 이 경우, 사용자는 발급된 계좌로 입금만 하면 됨. 입금 완료 웹훅 설정은 별도.
        }
    }
}

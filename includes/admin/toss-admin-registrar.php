<?php
namespace MPHB\Payments\Gateways\Toss\Service;

use MPHB\Admin\Groups;
use MPHB\Payments\Gateways\TossGateway;

/**
 * 관리자 설정 등록 및 표시 서비스
 */
class TossAdminRegistrar {

    private $gateway;
    private $fieldProvider;
    private $statusChecker;

    /**
     * 생성자
     * 
     * @param TossGateway $gateway 토스 게이트웨이 인스턴스
     * @param TossAdminFieldProvider $fieldProvider 필드 제공자
     * @param TossStatusChecker|null $statusChecker 상태 확인 서비스 (선택적)
     */
    public function __construct(
        TossGateway $gateway, 
        TossAdminFieldProvider $fieldProvider,
        ?TossStatusChecker $statusChecker = null
    ) {
        $this->gateway = $gateway;
        $this->fieldProvider = $fieldProvider;
        $this->statusChecker = $statusChecker ?? new TossStatusChecker();
    }

    /**
     * 관리자 설정 화면에 옵션 필드 등록
     * 
     * @param object $subTab 설정 탭 객체
     */
    public function registerOptionsFields(&$subTab): void {
        $this->addSettingsGroup(
            $subTab, 'main', '', $this->fieldProvider->createMainGroupFields()
        );
        $this->addSettingsGroup(
            $subTab, 'api', __('API Settings', 'mphb-toss'), $this->fieldProvider->createApiGroupFields()
        );
    }

    /**
     * 설정 그룹 추가 헬퍼
     * 
     * @param object $subTab 설정 탭 객체
     * @param string $groupIdSuffix 그룹 ID 접미사
     * @param string $groupTitle 그룹 제목
     * @param array $fields 필드 배열
     */
    private function addSettingsGroup(&$subTab, string $groupIdSuffix, string $groupTitle, array $fields): void {
        if (empty($fields)) return;
        
        $group = new Groups\SettingsGroup(
            "mphb_payments_{$this->gateway->getId()}_{$groupIdSuffix}",
            $groupTitle,
            $subTab->getOptionGroupName()
        );
        
        $group->addFields($fields);
        $subTab->addGroup($group);
    }

    /**
     * 관리자 화면 설명 생성
     * 
     * @return string 관리자 설명 HTML
     */
    public function getAdminDescription(): string {
        if ($this->gateway->isActive()) {
            return __('Toss Payments gateway is active.', 'mphb-toss');
        } else {
            $keysExist = !empty($this->gateway->getClientKey()) && !empty($this->gateway->getSecretKey());
            $currencyCode = $this->getCurrencyCode();
            $currencyIsKRW = ('KRW' === strtoupper($currencyCode));

            if (!$keysExist) {
                return __('Requires Client Key and Secret Key to be enabled.', 'mphb-toss');
            } elseif (!$currencyIsKRW) {
                return sprintf(
                    '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                    sprintf(
                        esc_html__('%1$s requires the store currency to be %2$s. Current currency is %3$s. The gateway is inactive.', 'mphb-toss'),
                        $this->gateway->getAdminTitle(), 'KRW', esc_html($currencyCode)
                    )
                );
            } else {
                return __('Gateway is currently inactive.', 'mphb-toss');
            }
        }
    }

    /**
     * 관리자 옵션 숨기기
     * 
     * @param bool $show 표시 여부
     * @param string $gatewayId 게이트웨이 ID
     * @return bool 수정된 표시 여부
     */
    public function hideAdminOption(bool $show, string $gatewayId): bool {
        return ($gatewayId === $this->gateway->getId()) ? false : $show;
    }

    /**
     * 현재 설정된 통화 코드 가져오기
     * 
     * @return string 통화 코드
     */
    private function getCurrencyCode(): string {
        if (function_exists('MPHB') && is_object(MPHB()->settings()->currency())) {
            return MPHB()->settings()->currency()->getCurrencyCode();
        }
        
        return '';
    }
}

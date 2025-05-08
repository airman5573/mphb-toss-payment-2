<?php
namespace MPHBTOSS; // თქვენი პლაგინის namespace

if ( ! defined( 'ABSPATH' ) ) {
    exit; // პირდაპირი წვდომის აკრძალვა
}

use MPHB\Admin\Tabs\SettingsTab;
use MPHB\Admin\Groups\SettingsGroup;
use MPHB\Admin\Fields\FieldFactory;

/**
 * MPHB 설정에 Toss Payments API 키를 위한 전용 탭을 추가하는 클래스
 */
class TossGlobalSettingsTab {

    const TAB_ID = 'toss_api_keys'; // 탭 ID (고유해야 함)
    const OPTION_CLIENT_KEY = 'mphb_toss_global_client_key';
    const OPTION_SECRET_KEY = 'mphb_toss_global_secret_key';

    /**
     * 클래스 초기화 및 WordPress 훅 등록
     */
    public function init() {
        add_filter( 'mphb_generate_settings_tabs', [ $this, 'add_tab_slug' ] );
        add_filter( 'mphb_custom_settings_tab', [ $this, 'render_tab_content' ], 10, 2 );
    }

    /**
     * MPHB 설정 탭 목록에 'Toss Payments API' 탭 슬러그 추가
     *
     * @param array $tabs 기존 탭 슬러그 배열
     * @return array 수정된 탭 슬러그 배열
     */
    public function add_tab_slug( $tabs ) {
        // 'Payment Gateways' 탭 뒤에 추가하거나, 해당 탭이 없으면 마지막에 추가
        $payments_tab_key = 'payments'; // MPHB 기본 결제 탭 ID
        $position = array_search( $payments_tab_key, $tabs, true );

        if ( $position !== false ) {
            array_splice( $tabs, $position + 1, 0, self::TAB_ID );
        } else {
            $tabs[] = self::TAB_ID;
        }
        return $tabs;
    }

    /**
     * 'Toss Payments API' 탭의 실제 내용(MPHB SettingsTab 객체) 생성 및 반환
     *
     * @param SettingsTab|null $tab_object 현재 탭 객체 (사용자 정의 탭의 경우 null)
     * @param string $tab_name 현재 생성 중인 탭의 이름
     * @return SettingsTab|null 생성된 탭 객체 또는 null
     */
    public function render_tab_content( $tab_object, $tab_name ) {
        if ( $tab_name !== self::TAB_ID ) {
            return $tab_object;
        }

        // MPHB 클래스 존재 여부 확인 (안정성 강화)
        if ( ! class_exists( '\MPHB\Admin\Tabs\SettingsTab' ) ||
             ! class_exists( '\MPHB\Admin\Groups\SettingsGroup' ) ||
             ! class_exists( '\MPHB\Admin\Fields\FieldFactory' ) ) {
            // 오류 로깅 또는 사용자에게 알림 (예: WP_DEBUG 활성화 시)
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                error_log('[MPHB Toss Payments] MPHB core classes not found for settings tab generation.');
            }
            return null;
        }

        // 1. 탭 객체 생성
        $tab = new SettingsTab(
            self::TAB_ID,
            __( 'Toss Payments API', 'mphb-toss-payments' ), // 탭 레이블 (번역 가능)
            'mphb_settings' // MPHB 설정 페이지의 기본 옵션 그룹 이름
        );

        // 2. 설정 그룹 생성 (API 키 그룹)
        $api_keys_group = new SettingsGroup(
            'mphb_toss_global_api_keys_group', // 그룹 ID (고유해야 함)
            __( 'Global API Keys', 'mphb-toss-payments' ), // 그룹 제목
            $tab->getOptionGroupName() // 탭의 옵션 그룹 이름을 사용해야 올바르게 저장됨
        );

        // 3. 필드 추가
        $api_keys_group->addFields( [
            FieldFactory::create( self::OPTION_CLIENT_KEY, [
                'type'        => 'text',
                'label'       => __( 'Client Key', 'mphb-toss-payments' ),
                'default'     => '',
                'description' => __( 'Enter your Toss Payments Client Key. This key will be shared by all your Toss Payments gateways.', 'mphb-toss-payments' ),
                'size'        => 'large',
            ] ),
            FieldFactory::create( self::OPTION_SECRET_KEY, [
                'type'        => 'password', // 비밀번호 필드 타입 사용
                'label'       => __( 'Secret Key', 'mphb-toss-payments' ),
                'default'     => '',
                'description' => __( 'Enter your Toss Payments Secret Key. This key will be shared by all your Toss Payments gateways.', 'mphb-toss-payments' ),
                'size'        => 'large',
            ] ),
        ] );

        $tab->addGroup( $api_keys_group );

        // 4. (선택 사항) 탭 상단에 설명 추가
        $tab->setDescription(
            __( 'Configure the global API keys for Toss Payments here. These keys will be used by all your Toss Payments gateways (e.g., Toss Credit Card, Toss Easy Pay). <br>Activation and specific settings for each Toss gateway method are managed under "Hotel Booking" > "Settings" > "Payment Gateways" tab.', 'mphb-toss-payments' )
        );

        return $tab;
    }

    /**
     * 저장된 전역 Client Key를 가져옵니다.
     * @return string
     */
    public static function get_global_client_key(): string {
        return (string) get_option( self::OPTION_CLIENT_KEY, '' );
    }

    /**
     * 저장된 전역 Secret Key를 가져옵니다.
     * @return string
     */
    public static function get_global_secret_key(): string {
        return (string) get_option( self::OPTION_SECRET_KEY, '' );
    }
}

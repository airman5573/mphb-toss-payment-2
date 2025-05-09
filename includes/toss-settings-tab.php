<?php
namespace MPHBTOSS;

// ... (use 구문들) ...
use MPHB\Admin\Fields\InputField; // InputField를 사용하기 위해 추가
use MPHB\Admin\Tabs\SettingsTab; // 명시적으로 SettingsTab 사용
use MPHB\Admin\Groups\SettingsGroup; // 명시적으로 SettingsGroup 사용
use MPHB\Admin\Fields\FieldFactory; // 명시적으로 FieldFactory 사용

class TossGlobalSettingsTab {

    const TAB_ID = 'toss_api_keys';
    const OPTION_CLIENT_KEY = 'mphb_toss_global_client_key';
    const OPTION_SECRET_KEY = 'mphb_toss_global_secret_key';

    public function init() {
        add_filter( 'mphb_generate_settings_tabs', [ $this, 'add_tab_slug' ] );
        add_filter( 'mphb_custom_settings_tab', [ $this, 'render_tab_content' ], 10, 2 );
    }

    public function add_tab_slug( $tabs ) {
        $payments_tab_key = 'payments';
        $position = array_search( $payments_tab_key, $tabs, true );

        if ( $position !== false ) {
            array_splice( $tabs, $position + 1, 0, self::TAB_ID );
        } else {
            $tabs[] = self::TAB_ID;
        }
        return $tabs;
    }

    public function render_tab_content( $tab_object, $tab_name ) {
        if ( $tab_name !== self::TAB_ID ) {
            return $tab_object;
        }

        if ( ! class_exists( '\MPHB\Admin\Tabs\SettingsTab' ) ||
             ! class_exists( '\MPHB\Admin\Groups\SettingsGroup' ) ||
             ! class_exists( '\MPHB\Admin\Fields\FieldFactory' ) ) {
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                error_log('[MPHB Toss Payments] MPHB core classes not found for settings tab generation.');
            }
            return null;
        }

        // 1. 탭 객체 생성
        $tab = new SettingsTab(
            self::TAB_ID,
            __( 'Toss Payments API', 'mphb-toss-payments' ),
            'mphb_settings' // MPHB 설정 페이지의 기본 옵션 그룹 이름
            // 네 번째 인자는 $subTabName 이므로, 여기서는 설명을 전달하지 않습니다.
        );

        // 2. 설명용 그룹 추가
        $description_text = __( '여기서 Toss Payments의 API 키를 설정하세요. 세부 설정은 "호텔 예약" > "설정" > "결제 게이트웨이"에서 할 수 있습니다.', 'mphb-toss-payments' );

        $description_group = new SettingsGroup(
            'mphb_toss_global_api_description_group', // 고유 ID
            '', // 그룹 제목 없음 (또는 'Information' 등)
            $tab->getOptionGroupName(),
            $description_text // SettingsGroup 생성자의 네 번째 인자가 설명으로 사용됨
        );
        // API 키 그룹보다 먼저 추가하여 탭 상단에 설명이 표시되도록 합니다.
        $tab->addGroup( $description_group );

        // 3. API 키 설정 그룹 생성
        $api_keys_group = new SettingsGroup(
            'mphb_toss_global_api_keys_group',
            __( 'Global API Keys', 'mphb-toss-payments' ),
            $tab->getOptionGroupName()
        );

        // 필드 생성 및 추가 로직 (이전과 동일)
        $client_key_field = FieldFactory::create( self::OPTION_CLIENT_KEY, [
            'type'        => 'text',
            'label'       => __( 'Client Key', 'mphb-toss-payments' ),
            'default'     => 'test_ck_ma60RZblrqo5YwQmZd6z3wzYWBn1',
            'description' => __( 'Toss Payments Client Key를 입력하세요. 이 키는 모든 Toss Payments 게이트웨이에서 사용됩니다.', 'mphb-toss-payments' ),
            'size'        => 'regular',
        ] );

        $secret_key_field = FieldFactory::create( self::OPTION_SECRET_KEY, [
            'type'        => 'text',
            'label'       => __( 'Secret Key', 'mphb-toss-payments' ),
            'default'     => 'test_sk_6BYq7GWPVv2Ryd2QGEm4VNE5vbo1',
            'description' => __( 'Toss Payments Secret Key를 입력하세요. 이 키는 모든 Toss Payments 게이트웨이에서 사용됩니다.', 'mphb-toss-payments' ),
            'size'        => 'regular',
        ] );

        $fields_to_add = [];
        if ( $client_key_field instanceof InputField ) {
            $fields_to_add[] = $client_key_field;
        } else {
            if ( defined('WP_DEBUG') && WP_DEBUG ) { error_log('[MPHB Toss Payments] Failed to create Client Key field. Option name: ' . self::OPTION_CLIENT_KEY); }
        }

        if ( $secret_key_field instanceof InputField ) {
            $fields_to_add[] = $secret_key_field;
        } else {
            if ( defined('WP_DEBUG') && WP_DEBUG ) { error_log('[MPHB Toss Payments] Failed to create Secret Key field. Option name: ' . self::OPTION_SECRET_KEY); }
        }

        if ( !empty($fields_to_add) ) {
            $api_keys_group->addFields( $fields_to_add );
        }
        // API 키 그룹을 탭에 추가
        $tab->addGroup( $api_keys_group );

        return $tab;
    }

    public static function get_global_client_key(): string {
        return (string) get_option( self::OPTION_CLIENT_KEY, '' );
    }

    public static function get_global_secret_key(): string {
        return (string) get_option( self::OPTION_SECRET_KEY, '' );
    }
}

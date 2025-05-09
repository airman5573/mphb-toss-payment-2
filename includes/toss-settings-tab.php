<?php
namespace MPHBTOSS;

use MPHB\Admin\Fields\InputField;
use MPHB\Admin\Tabs\SettingsTab;
use MPHB\Admin\Groups\SettingsGroup;
use MPHB\Admin\Fields\FieldFactory;

class TossGlobalSettingsTab {

    const TAB_ID = 'toss_api_keys';
    const OPTION_CLIENT_KEY   = 'mphb_toss_global_client_key';
    const OPTION_SECRET_KEY   = 'mphb_toss_global_secret_key';
    const OPTION_TEST_MODE    = 'mphb_toss_global_test_mode';
    const OPTION_DEBUG_MODE   = 'mphb_toss_global_debug_mode';

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

        if ( ! class_exists( '\MPHB\Admin\Tabs\SettingsTab' )
          || ! class_exists( '\MPHB\Admin\Groups\SettingsGroup' )
          || ! class_exists( '\MPHB\Admin\Fields\FieldFactory' ) ) {
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                error_log('[MPHB Toss Payments] MPHB core classes not found for settings tab generation.');
            }
            return null;
        }

        // 탭 객체 생성
        $tab = new SettingsTab(
            self::TAB_ID,
            __( '토스페이먼츠', 'mphb-toss-payments' ),
            'mphb_settings'
        );

        // 설명 그룹
        $description_group = new SettingsGroup(
            'mphb_toss_global_api_description_group',
            '',
            $tab->getOptionGroupName(),
            '' // 여기에 설명을 넣을 수도 있습니다
        );
        $tab->addGroup( $description_group );

        // API 키 그룹 생성
        $api_keys_group = new SettingsGroup(
            'mphb_toss_global_api_keys_group',
            __( '토스페이먼츠 공용 API 키 및 설정', 'mphb-toss-payments' ),
            $tab->getOptionGroupName()
        );

        // 테스트 모드 체크박스
        $test_mode_field = FieldFactory::create( self::OPTION_TEST_MODE, [
            'type'        => 'checkbox',
            'label'       => __( '테스트 모드 (Test Mode)', 'mphb-toss-payments' ),
            'value'       => '1',
            'default'     => '0',
            'description' => __( '테스트 결제를 사용하려면 체크하세요.', 'mphb-toss-payments' ),
        ] );
        $client_key_field = FieldFactory::create( self::OPTION_CLIENT_KEY, [
            'type'        => 'text',
            'label'       => __( '클라이언트 키', 'mphb-toss-payments' ),
            'default'     => 'test_ck_ma60RZblrqo5YwQmZd6z3wzYWBn1',
            'description' => '',
            'size'        => 'regular',
        ] );
        $secret_key_field = FieldFactory::create( self::OPTION_SECRET_KEY, [
            'type'        => 'text',
            'label'       => __( '시크릿 키', 'mphb-toss-payments' ),
            'default'     => 'test_sk_6BYq7GWPVv2Ryd2QGEm4VNE5vbo1',
            'description' => '',
            'size'        => 'regular',
        ] );
        // 디버깅 모드 체크박스
        $debug_mode_field = FieldFactory::create( self::OPTION_DEBUG_MODE, [
            'type'        => 'checkbox',
            'label'       => __( '디버깅 모드 (Debug Mode)', 'mphb-toss-payments' ),
            'value'       => '1',
            'default'     => '0',
            'description' => __( '에러 및 요청 로그를 활성화합니다.', 'mphb-toss-payments' ),
        ] );

        // 필드 배열
        $fields_to_add = [];
        if ( $client_key_field instanceof InputField ) $fields_to_add[] = $client_key_field;
        if ( $secret_key_field instanceof InputField ) $fields_to_add[] = $secret_key_field;
        if ( $test_mode_field instanceof InputField ) $fields_to_add[] = $test_mode_field;
        if ( $debug_mode_field instanceof InputField ) $fields_to_add[] = $debug_mode_field;

        if ( !empty($fields_to_add) ) {
            $api_keys_group->addFields( $fields_to_add );
        }

        $tab->addGroup( $api_keys_group );

        return $tab;
    }

    public static function get_global_client_key(): string {
        return (string) get_option( self::OPTION_CLIENT_KEY, '' );
    }
    public static function get_global_secret_key(): string {
        return (string) get_option( self::OPTION_SECRET_KEY, '' );
    }
    public static function is_test_mode(): bool {
        return get_option( self::OPTION_TEST_MODE, '0' ) === '1';
    }
    public static function is_debug_mode(): bool {
        return get_option( self::OPTION_DEBUG_MODE, '0' ) === '1';
    }
}

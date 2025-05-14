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
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] ); // 스크립트 로드 액션 추가
    }

    /**
     * 관리자 페이지에 필요한 스크립트를 로드합니다.
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        $page_param = $_GET['page'] ?? ''; // 'page' GET 파라미터
        $tab_param  = $_GET['tab'] ?? '';   // 'tab' GET 파라미터

        var_dump($page_param);
        var_dump($tab_param);

        if ($page_param !== 'mphb_settings' || $tab_param !== self::TAB_ID) {
            return;
        }

        wp_enqueue_script(
            'mphb-toss-admin-settings', // 핸들명
            MPHB_TOSS_PAYMENTS_PLUGIN_URL . 'assets/js/admin-toss-settings.js', // 파일 경로
            ['jquery'], // 의존성
            MPHB_TOSS_PAYMENTS_VERSION, // 버전
            true // 푸터에 로드
        );
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
            '' 
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
            'value'       => '1', // 체크 시 저장될 값
            'default'     => '0', // 기본값 (체크 안 됨)
            'description' => __( '테스트 결제를 사용하려면 체크하세요. 체크 시 아래 키들이 테스트용 키로 자동 입력됩니다.', 'mphb-toss-payments' ), // 설명 추가
        ] );
        // 클라이언트 키 입력 필드
        $client_key_field = FieldFactory::create( self::OPTION_CLIENT_KEY, [
            'type'        => 'text',
            'label'       => __( '클라이언트 키', 'mphb-toss-payments' ),
            'default'     => 'test_ck_ma60RZblrqo5YwQmZd6z3wzYWBn1', // 기본값은 테스트 키
            'description' => '',
            'size'        => 'regular',
        ] );
        // 시크릿 키 입력 필드
        $secret_key_field = FieldFactory::create( self::OPTION_SECRET_KEY, [
            'type'        => 'text',
            'label'       => __( '시크릿 키', 'mphb-toss-payments' ),
            'default'     => 'test_sk_6BYq7GWPVv2Ryd2QGEm4VNE5vbo1', // 기본값은 테스트 키
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
        // FieldFactory::create가 실제 필드 객체를 반환하는지, 아니면 설정을 반환하는지에 따라 조건이 달라질 수 있습니다.
        // MPHB의 FieldFactory가 InputField 또는 그 하위 클래스의 인스턴스를 반환한다고 가정합니다.
        if ( $client_key_field ) $fields_to_add[] = $client_key_field; // 타입 체크 생략 (MPHB FieldFactory 특성상)
        if ( $secret_key_field ) $fields_to_add[] = $secret_key_field;
        if ( $test_mode_field ) $fields_to_add[] = $test_mode_field;
        if ( $debug_mode_field ) $fields_to_add[] = $debug_mode_field;

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

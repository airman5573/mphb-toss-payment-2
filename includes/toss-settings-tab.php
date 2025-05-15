<?php
namespace MPHBTOSS; // 네임스페이스 선언

// MPHB 관리자 페이지 관련 클래스 사용
use MPHB\Admin\Fields\InputField; // (InputField는 직접 사용되지 않지만, FieldFactory가 생성하는 객체의 기반이 될 수 있음)
use MPHB\Admin\Tabs\SettingsTab;
use MPHB\Admin\Groups\SettingsGroup;
use MPHB\Admin\Fields\FieldFactory;

/**
 * MPHB 설정 페이지에 토스페이먼츠 전역 API 키 설정을 위한 탭을 추가하는 클래스입니다.
 */
class TossGlobalSettingsTab {
    // 탭 ID 상수
    const TAB_ID = 'toss_api_keys';
    // 옵션 이름 상수
    const OPTION_CLIENT_KEY   = 'mphb_toss_global_client_key'; // 클라이언트 키
    const OPTION_SECRET_KEY   = 'mphb_toss_global_secret_key'; // 시크릿 키
    const OPTION_TEST_MODE    = 'mphb_toss_global_test_mode';  // 테스트 모드
    const OPTION_DEBUG_MODE   = 'mphb_toss_global_debug_mode'; // 디버그 모드

    /**
     * 클래스 초기화 시 실행됩니다.
     * 필요한 워드프레스 액션 및 필터 훅을 등록합니다.
     */
    public function init() {
        // MPHB 설정 탭 목록에 이 탭의 슬러그를 추가하는 필터
        add_filter( 'mphb_generate_settings_tabs', [ $this, 'add_tab_slug' ] );
        // 특정 탭 이름에 해당하는 탭 내용을 렌더링하는 필터
        add_filter( 'mphb_custom_settings_tab', [ $this, 'render_tab_content' ], 10, 2 );
        // 관리자 페이지 스크립트 로드 액션
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }

    /**
     * 관리자 페이지에 필요한 JavaScript 파일을 로드합니다.
     * 이 탭이 표시될 때만 로드됩니다.
     * @param string $hook_suffix 현재 관리자 페이지의 훅 접미사
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        // 현재 페이지가 MPHB 설정 페이지이고, 현재 탭이 토스 설정 탭인지 확인
        $page_param = $_GET['page'] ?? ''; // 'page' GET 파라미터
        $tab_param  = $_GET['tab'] ?? '';   // 'tab' GET 파라미터

        if ($page_param !== 'mphb_settings' || $tab_param !== self::TAB_ID) {
            return; // 해당되지 않으면 스크립트 로드 안함
        }

        // 관리자용 JS 파일 로드
        wp_enqueue_script(
            'mphb-toss-admin-settings', // 스크립트 핸들 이름
            MPHB_TOSS_PAYMENTS_PLUGIN_URL . 'assets/js/admin-toss-settings.js', // 파일 경로
            ['jquery'], // 의존성 (jQuery)
            time(), // 버전 (캐시 방지를 위해 현재 시간 사용 - 배포 시에는 플러그인 버전 등으로 변경 권장)
            true // 푸터에 로드
        );
    }

    /**
     * MPHB 설정 탭 목록에 'toss_api_keys' 탭 슬러그를 추가합니다.
     * 'payments' 탭 바로 다음에 위치하도록 시도합니다.
     * @param array $tabs 기존 탭 슬러그 배열
     * @return array 수정된 탭 슬러그 배열
     */
    public function add_tab_slug( $tabs ) {
        $payments_tab_key = 'payments'; // 'payments' 탭의 키
        // 'payments' 탭의 위치를 찾습니다.
        $position = array_search( $payments_tab_key, $tabs, true );

        if ( $position !== false ) { // 'payments' 탭을 찾은 경우
            // 'payments' 탭 바로 다음에 이 탭을 추가합니다.
            array_splice( $tabs, $position + 1, 0, self::TAB_ID );
        } else { // 'payments' 탭을 찾지 못한 경우 (드문 경우)
            // 배열 끝에 추가합니다.
            $tabs[] = self::TAB_ID;
        }
        return $tabs;
    }

    /**
     * 'toss_api_keys' 탭의 내용을 렌더링합니다.
     * MPHB의 SettingsTab, SettingsGroup, FieldFactory 클래스를 사용하여 설정 필드를 구성합니다.
     * @param SettingsTab|null $tab_object 기존 탭 객체 (이 함수에서는 새로 생성)
     * @param string $tab_name 현재 렌더링할 탭의 이름
     * @return SettingsTab|null 생성된 탭 객체 또는 탭 이름이 다르면 기존 객체
     */
    public function render_tab_content( $tab_object, $tab_name ) {
        // 현재 요청된 탭 이름이 이 클래스에서 처리하는 탭(self::TAB_ID)이 아니면,
        // 기존 탭 객체를 그대로 반환하여 다른 핸들러가 처리하도록 합니다.
        if ( $tab_name !== self::TAB_ID ) {
            return $tab_object;
        }

        // MPHB 코어 클래스 존재 여부 확인 (안정성 확보)
        if ( ! class_exists( '\MPHB\Admin\Tabs\SettingsTab' )
          || ! class_exists( '\MPHB\Admin\Groups\SettingsGroup' )
          || ! class_exists( '\MPHB\Admin\Fields\FieldFactory' ) ) {
            if ( defined('WP_DEBUG') && WP_DEBUG ) { // WP_DEBUG 모드일 때 PHP 에러 로그 기록
                error_log('[MPHB Toss Payments] MPHB core classes not found for settings tab generation.');
            }
            return null; // 클래스가 없으면 탭 생성 불가
        }

        // 새로운 설정 탭 객체 생성
        $tab = new SettingsTab(
            self::TAB_ID, // 탭 ID
            __( '토스페이먼츠', 'mphb-toss-payments' ), // 탭 제목
            'mphb_settings' // 이 탭이 속한 설정 페이지 슬러그
        );

        // 설명 그룹 (특별한 필드 없이 설명만 표시할 경우 사용 가능, 현재는 비어있음)
        $description_group = new SettingsGroup(
            'mphb_toss_global_api_description_group', // 그룹 ID
            '', // 그룹 제목 (비워둠)
            $tab->getOptionGroupName(), // 옵션 그룹 이름 (탭에서 가져옴)
            ''  // 그룹 설명 (비워둠)
        );
        $tab->addGroup( $description_group ); // 탭에 그룹 추가

        // API 키 설정 그룹 생성
        $api_keys_group = new SettingsGroup(
            'mphb_toss_global_api_keys_group', // 그룹 ID
            __( '토스페이먼츠 공용 API 키 및 설정', 'mphb-toss-payments' ), // 그룹 제목
            $tab->getOptionGroupName() // 옵션 그룹 이름
        );

        // 테스트 모드 체크박스 필드 생성
        $test_mode_field = FieldFactory::create( self::OPTION_TEST_MODE, [
            'type'        => 'checkbox', // 필드 타입: 체크박스
            'label'       => __( '테스트 모드 (Test Mode)', 'mphb-toss-payments' ), // 레이블
            'value'       => '1', // 체크 시 저장될 값
            'default'     => '0', // 기본값 (체크 안 됨)
            'description' => __( '테스트 결제를 사용하려면 체크하세요. 체크 시 아래 키들이 테스트용 키로 자동 입력됩니다.', 'mphb-toss-payments' ), // 설명
        ] );
        // 클라이언트 키 입력 필드 생성
        $client_key_field = FieldFactory::create( self::OPTION_CLIENT_KEY, [
            'type'        => 'text', // 필드 타입: 텍스트 입력
            'label'       => __( '클라이언트 키', 'mphb-toss-payments' ),
            'default'     => 'test_ck_ma60RZblrqo5YwQmZd6z3wzYWBn1', // 기본값: 테스트 클라이언트 키
            'description' => '',
            'size'        => 'regular', // 입력 필드 크기
        ] );
        // 시크릿 키 입력 필드 생성
        $secret_key_field = FieldFactory::create( self::OPTION_SECRET_KEY, [
            'type'        => 'text', // 필드 타입: 텍스트 입력
            'label'       => __( '시크릿 키', 'mphb-toss-payments' ),
            'default'     => 'test_sk_6BYq7GWPVv2Ryd2QGEm4VNE5vbo1', // 기본값: 테스트 시크릿 키
            'description' => '',
            'size'        => 'regular',
        ] );
        // 디버깅 모드 체크박스 필드 생성
        $debug_mode_field = FieldFactory::create( self::OPTION_DEBUG_MODE, [
            'type'        => 'checkbox', // 필드 타입: 체크박스
            'label'       => __( '디버깅 모드 (Debug Mode)', 'mphb-toss-payments' ),
            'value'       => '1', // 체크 시 저장될 값
            'default'     => '0', // 기본값 (체크 안 됨)
            'description' => __( '에러 및 요청 로그를 활성화합니다.', 'mphb-toss-payments' ),
        ] );

        // 생성된 필드들을 배열에 담기
        $fields_to_add = [];
        // FieldFactory::create가 실제 필드 객체를 반환하는지, 아니면 설정을 반환하는지에 따라 조건이 달라질 수 있습니다.
        // MPHB의 FieldFactory가 InputField 또는 그 하위 클래스의 인스턴스를 반환한다고 가정합니다.
        // (실제 MPHB FieldFactory는 유효한 필드 객체를 반환하므로, null 체크만으로 충분할 수 있습니다.)
        if ( $client_key_field ) $fields_to_add[] = $client_key_field;
        if ( $secret_key_field ) $fields_to_add[] = $secret_key_field;
        if ( $test_mode_field ) $fields_to_add[] = $test_mode_field;
        if ( $debug_mode_field ) $fields_to_add[] = $debug_mode_field;

        // 필드 배열이 비어있지 않으면 그룹에 추가
        if ( !empty($fields_to_add) ) {
            $api_keys_group->addFields( $fields_to_add );
        }

        // API 키 그룹을 탭에 추가
        $tab->addGroup( $api_keys_group );

        // 완성된 탭 객체 반환
        return $tab;
    }

    /**
     * 저장된 전역 토스페이먼츠 클라이언트 키를 반환합니다.
     * @return string 클라이언트 키 (없으면 빈 문자열)
     */
    public static function get_global_client_key(): string {
        return (string) get_option( self::OPTION_CLIENT_KEY, '' );
    }
    /**
     * 저장된 전역 토스페이먼츠 시크릿 키를 반환합니다.
     * @return string 시크릿 키 (없으면 빈 문자열)
     */
    public static function get_global_secret_key(): string {
        return (string) get_option( self::OPTION_SECRET_KEY, '' );
    }
    /**
     * 테스트 모드가 활성화되어 있는지 확인합니다.
     * @return bool 테스트 모드 활성화 여부 (true/false)
     */
    public static function is_test_mode(): bool {
        // 저장된 값이 '1'이면 true, 아니면 false
        return get_option( self::OPTION_TEST_MODE, '0' ) === '1';
    }
    /**
     * 디버깅 모드가 활성화되어 있는지 확인합니다.
     * @return bool 디버깅 모드 활성화 여부 (true/false)
     */
    public static function is_debug_mode(): bool {
        return get_option( self::OPTION_DEBUG_MODE, '0' ) === '1';
    }
}


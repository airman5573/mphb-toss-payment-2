jQuery(document).ready(function($) {
    alert("LOADED");
    const testModeCheckboxId = 'mphb_toss_global_test_mode';
    const clientKeyInputId   = 'mphb_toss_global_client_key';
    const secretKeyInputId   = 'mphb_toss_global_secret_key';

    const testClientKey = 'test_ck_ma60RZblrqo5YwQmZd6z3wzYWBn1';
    const testSecretKey = 'test_sk_6BYq7GWPVv2Ryd2QGEm4VNE5vbo1';

    const $testModeCheckbox = $('#' + testModeCheckboxId);
    const $clientKeyInput   = $('#' + clientKeyInputId);
    const $secretKeyInput   = $('#' + secretKeyInputId);

    // 페이지 로드 시 초기 상태에 따른 실행 (선택 사항: 만약 기본적으로 체크되어 있다면 키를 채울 수 있음)
    // if ($testModeCheckbox.is(':checked')) {
    //     $clientKeyInput.val(testClientKey);
    //     $secretKeyInput.val(testSecretKey);
    // }

    $testModeCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            $clientKeyInput.val(testClientKey);
            $secretKeyInput.val(testSecretKey);
        }
        // 체크 해제 시 동작은 정의되지 않았으므로, 현재는 아무 작업도 하지 않습니다.
        // 필요하다면 여기에 로직을 추가할 수 있습니다. (예: 필드 비우기)
        // else {
        //     $clientKeyInput.val('');
        //     $secretKeyInput.val('');
        // }
    });
});

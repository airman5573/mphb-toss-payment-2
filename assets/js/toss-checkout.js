
jQuery(function ($) {
    // 필수값: 서버에서 wp_localize_script로 전달됨
    if (typeof MPHBTossCheckoutData === 'undefined') {
        alert('Toss 결제 세션이 비정상입니다. 새로고침 후 재시도 해주세요.');
        return;
    }

    const {
        clientKey,
        amount,
        orderId,
        orderName,
        customerEmail,
        customerName,
        successUrl,
        failUrl,
        i18n
    } = MPHBTossCheckoutData;

    // 안전성: TossPayments JS 라이브러리 로드 확인
    if (typeof TossPayments !== 'function') {
        alert((i18n && i18n.init_error) || 'TossPayments 라이브러리를 불러올 수 없습니다. 새로고침 해주세요.');
        return;
    }

    // 기존 버튼/폼 제거, 다시 그리기
    const $wrap = $('#toss-payment-widget');
    $wrap.empty();

    // 결제 버튼 생성
    const $btn = $('<button>')
        .prop('type', 'button')
        .addClass('mphb-toss-btn')
        .text((i18n && i18n.pay_by_toss) || '토스로 결제하기')
        .css({
            padding: '12px 28px',
            fontSize: '18px',
            cursor: 'pointer',
            margin: '32px auto',
            display: 'block',
            borderRadius: '8px',
            background: '#005be2',
            color: '#fff',
            border: 'none'
        });

    $wrap.append($btn);

    // 결제 버튼 클릭시 Toss 결제창 띄우기
    $btn.on('click', function () {
        try {
            const tossPayments = TossPayments(clientKey);
            const payment = tossPayments.payment({ customerKey: customerName || customerEmail || orderId });

            (async function () {
                try {
                    await payment.requestPayment({
                        method: "CARD",
                        amount: {
                            currency: "KRW",
                            value: parseInt(amount, 10) || amount
                        },
                        orderId,
                        orderName,
                        customerEmail,
                        customerName,
                        successUrl,
                        failUrl,
                        card: { useEscrow: false, flowMode: "DEFAULT", useCardPoint: false, useAppCardOnly: false }
                    });
                } catch (error) {
                    // TossPayments JS 내부 실패시
                    let errMsg = error && error.message ? error.message : ((i18n && i18n.init_error) || '결제 요청 중 오류가 발생했습니다.');
                    window.parent.postMessage({
                        tosspayments: true,
                        type: 'fail',
                        message: errMsg
                    }, '*');
                    alert(errMsg);
                }
            })();
        } catch (initError) {
            let errMsg = initError && initError.message ? initError.message : ((i18n && i18n.init_error) || '토스 페이먼츠 초기화 오류입니다.');
            alert(errMsg);
        }
    });
});

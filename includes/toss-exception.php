<?php
namespace MPHBTOSS; // 네임스페이스 선언

/**
 * 토스 API 예외 클래스입니다.
 * - 토스 API 통신에서 발생하는 모든 예외 상황을 표현하기 위해 사용됩니다.
 */
class TossException extends \Exception // PHP 기본 Exception 클래스를 상속
{
    /**
     * 토스 API에서 전달된 오류 코드 또는 상태값을 저장합니다.
     * @var string|int|null
     */
    protected $errorCode;

    /**
     * TossException 생성자입니다.
     *
     * @param string $message 오류 메시지입니다.
     * @param string|int $code 토스 API의 오류 코드입니다. (정수형 또는 문자열 형태 모두 가능)
     * @param \Throwable|null $previous 이전 예외 객체입니다 (예외 체이닝용).
     */
    public function __construct($message = '', $code = 0, $previous = null)
    {
        // 부모 Exception 클래스의 생성자를 호출합니다.
        // $code는 부모 클래스에서 int로 형변환되므로, 원래 $code 값은 $errorCode에 별도 저장합니다.
        parent::__construct($message, (int)$code, $previous);
        $this->errorCode = $code; // 원래의 오류 코드 (문자열일 수 있음) 저장
    }

    /**
     * 토스 API에서 반환된 원본 오류 코드를 가져옵니다.
     * @return string|int|null 오류 코드 (문자열 또는 정수) 또는 설정되지 않은 경우 null.
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }
}


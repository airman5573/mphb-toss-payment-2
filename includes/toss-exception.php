<?php
namespace MPHBTOSS;

/**
 * Toss API Exception
 * - Toss API 통신에서 발생하는 모든 예외를 위해
 */
class TossException extends \Exception
{
    /**
     * Toss API에서 전달된 오류 코드/상태값
     * @var string|null
     */
    protected $errorCode;

    /**
     * TossException 생성자
     *
     * @param string $message 오류 메시지
     * @param string|int $code Toss 오류코드 (int, string 모두 가능)
     * @param \Throwable|null $previous
     */
    public function __construct($message = '', $code = 0, $previous = null)
    {
        parent::__construct($message, (int)$code, $previous);
        $this->errorCode = $code;
    }

    /**
     * Toss API 오류 코드.
     * @return string|int|null
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }
}

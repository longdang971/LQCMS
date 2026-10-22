<?php
declare(strict_types=1);

namespace LQ\AI;

/**
 * Thử lại khi nhà cung cấp lỗi tạm thời (quá tải 503, giới hạn tần suất 429, lỗi máy chủ 5xx, rớt mạng).
 * SDK của Claude đã tự thử lại; thư viện cộng đồng của ChatGPT/Gemini thì không, nên bọc bằng lớp này.
 * Tổng thời gian chờ tối đa ~6 giây để người dùng không phải đợi quá lâu trước khi thấy lỗi.
 */
final class Retry
{
    private const WAITS_MS = [1500, 4000];

    /**
     * @template T
     * @param callable(): T $fn
     * @param callable(\Throwable): bool $retryable
     * @return T
     */
    public static function run(callable $fn, callable $retryable): mixed
    {
        foreach ([...self::WAITS_MS, null] as $wait) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                if ($wait === null || !$retryable($e)) {
                    throw $e;
                }
                usleep($wait * 1000);
            }
        }
        throw new \LogicException('unreachable');
    }
}

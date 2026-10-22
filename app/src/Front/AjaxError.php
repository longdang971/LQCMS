<?php
declare(strict_types=1);

namespace LQ\Front;

/** Lỗi có thông báo cho người dùng, ném từ callback AJAX (xem lq_ajax_error()). */
final class AjaxError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

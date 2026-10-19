<?php
declare(strict_types=1);

namespace LQ\Core;

use Normalizer;

final class Slug
{
    private const VI = [
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ',
        'e' => 'èéẹẻẽêềếệểễ',
        'i' => 'ìíịỉĩ',
        'o' => 'òóọỏõôồốộổỗơờớợởỡ',
        'u' => 'ùúụủũưừứựửữ',
        'y' => 'ỳýỵỷỹ',
        'd' => 'đ',
    ];

    /** "Bài viết đầu tiên!" -> "bai-viet-dau-tien" */
    public static function make(string $text, int $max = 190): string
    {
        // Chuỗi copy từ macOS hay ở dạng NFD (dấu tách rời) -> gộp lại trước khi thay.
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }
        $s = mb_strtolower($text, 'UTF-8');
        foreach (self::VI as $to => $chars) {
            $s = preg_replace('/[' . $chars . ']/u', $to, $s);
        }
        if (function_exists('transliterator_transliterate')) {
            $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s) ?: $s;
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        if (strlen($s) > $max) {
            $s = rtrim(substr($s, 0, $max), '-');
        }
        return $s;
    }

    /**
     * Thêm hậu tố -2, -3... nếu slug đã tồn tại.
     * $exists nhận slug ứng viên, trả true nếu đã bị dùng.
     */
    public static function unique(string $slug, callable $exists): string
    {
        $base = $slug !== '' ? $slug : 'khong-tieu-de';
        $candidate = $base;
        for ($i = 2; $exists($candidate); $i++) {
            $candidate = $base . '-' . $i;
        }
        return $candidate;
    }
}

<?php
declare(strict_types=1);

namespace LQ\Core;

/**
 * So sánh hai bản nội dung để hiển thị lịch sử phiên bản.
 * HTML được tách thành từng dòng theo khối (đoạn văn, tiêu đề, mục danh sách…), so theo dòng bằng LCS,
 * rồi trong cặp dòng bị sửa thì tô sáng đúng những từ thay đổi.
 */
final class Diff
{
    /** Tách HTML thành các dòng chữ dễ đọc (giữ chỉ dấu cho ảnh). */
    public static function htmlLines(string $html): array
    {
        $html = preg_replace('#<img[^>]*?src="([^"]*)"[^>]*>#i', "\n[Ảnh: $1]\n", $html);
        $html = preg_replace('#<(br|hr)\b[^>]*>#i', "\n", (string) $html);
        $html = preg_replace('#</(p|h[1-6]|li|blockquote|pre|div|figure|figcaption|tr|table|ul|ol)>#i', "$0\n", (string) $html);
        $lines = [];
        foreach (explode("\n", (string) $html) as $line) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        return $lines;
    }

    /**
     * @return array<int, array{op:string, text:string, html?:string}> op: "=" giữ nguyên, "-" bị xoá, "+" thêm mới
     */
    public static function lines(array $old, array $new): array
    {
        $ops = self::lcs($old, $new);
        $out = [];
        $n = count($ops);
        for ($i = 0; $i < $n;) {
            if ($ops[$i]['op'] === '=') {
                $out[] = $ops[$i] + ['html' => e($ops[$i]['text'])];
                $i++;
                continue;
            }
            // Gom một khối thay đổi liền nhau: các dòng bị xoá và các dòng được thêm.
            $del = $add = [];
            while ($i < $n && $ops[$i]['op'] !== '=') {
                $ops[$i]['op'] === '-' ? $del[] = $ops[$i]['text'] : $add[] = $ops[$i]['text'];
                $i++;
            }
            // Ghép dòng xoá thứ k với dòng thêm thứ k (dòng bị sửa) để tô sáng từ thay đổi.
            $pairs = min(count($del), count($add));
            for ($k = 0; $k < $pairs; $k++) {
                [$a, $b] = self::words($del[$k], $add[$k]);
                $out[] = ['op' => '-', 'text' => $del[$k], 'html' => $a];
                $out[] = ['op' => '+', 'text' => $add[$k], 'html' => $b];
            }
            foreach (array_slice($del, $pairs) as $t) {
                $out[] = ['op' => '-', 'text' => $t, 'html' => e($t)];
            }
            foreach (array_slice($add, $pairs) as $t) {
                $out[] = ['op' => '+', 'text' => $t, 'html' => e($t)];
            }
        }
        return $out;
    }

    /** So sánh từng từ của hai dòng, trả về [html dòng cũ, html dòng mới] có <del>/<ins>. */
    public static function words(string $a, string $b): array
    {
        $wa = preg_split('/(\s+)/u', $a, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $wb = preg_split('/(\s+)/u', $b, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $oldHtml = $newHtml = '';
        foreach (self::lcs($wa, $wb) as $op) {
            $t = e($op['text']);
            match ($op['op']) {
                '=' => [$oldHtml .= $t, $newHtml .= $t],
                '-' => $oldHtml .= trim($op['text']) === '' ? $t : "<del>$t</del>",
                '+' => $newHtml .= trim($op['text']) === '' ? $t : "<ins>$t</ins>",
            };
        }
        return [$oldHtml, $newHtml];
    }

    /** Chuỗi thao tác theo dãy con chung dài nhất. Bản dài quá thì so thô (thêm/xoá cả khối) để không treo. */
    private static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n * $m > 4_000_000) {
            return [...array_map(fn ($t) => ['op' => '-', 'text' => $t], $a), ...array_map(fn ($t) => ['op' => '+', 'text' => $t], $b)];
        }
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['op' => '=', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['op' => '-', 'text' => $a[$i++]];
            } else {
                $ops[] = ['op' => '+', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['op' => '-', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['op' => '+', 'text' => $b[$j++]];
        }
        return $ops;
    }
}

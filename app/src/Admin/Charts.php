<?php
declare(strict_types=1);

namespace LQ\Admin;

/**
 * Biểu đồ nhỏ cho Bảng tin, sinh bằng HTML/SVG thuần để không phụ thuộc thư viện.
 * Màu lấy từ biến CSS (--chart-*) nên tự đổi theo chế độ sáng/tối.
 * Mọi điểm dữ liệu có data-tip để admin.js hiện tooltip khi hover.
 */
final class Charts
{
    /** Đường sparkline (2px) kèm vùng tô nhạt và chấm cuối. */
    public static function sparkline(array $values, string $label): string
    {
        $values = array_values($values);
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $w = 120;
        $h = 36;
        $pad = 4;
        $max = max(1, max($values));
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = [round($pad + $i * ($w - 2 * $pad) / ($n - 1), 1), round($h - $pad - ($v / $max) * ($h - 2 * $pad), 1)];
        }
        $line = 'M' . implode(' L', array_map(fn ($p) => $p[0] . ',' . $p[1], $pts));
        $area = $line . " L{$pts[$n - 1][0]},{$h} L{$pts[0][0]},{$h} Z";
        [$lx, $ly] = $pts[$n - 1];
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . e($label) . '">'
            . '<path class="spark-area" d="' . $area . '"/>'
            . '<path class="spark-line" d="' . $line . '" vector-effect="non-scaling-stroke"/>'
            . '<circle class="spark-dot" cx="' . $lx . '" cy="' . $ly . '" r="3.5"/>'
            . '</svg>';
    }

    /**
     * Biểu đồ cột một chuỗi. $data = [['label' => 'T10', 'full' => 'Tháng 10/2026', 'value' => 3], ...]
     * Chỉ ghi số trên cột cao nhất và cột cuối (nhãn chọn lọc); các cột khác xem qua tooltip.
     */
    public static function columns(array $data, string $unit, string $caption): string
    {
        $max = max(1, ...array_column($data, 'value'));
        // Trục: làm tròn lên số "đẹp" để vạch lưới không lẻ.
        $step = max(1, (int) ceil($max / 4));
        $top = $step * 4;
        $last = count($data) - 1;
        $maxIdx = array_search(max(array_column($data, 'value')), array_column($data, 'value'), true);

        $html = '<figure class="colchart" aria-label="' . e($caption) . '">';
        $html .= '<div class="colchart-grid" aria-hidden="true">';
        for ($i = 4; $i >= 0; $i--) {
            $html .= '<span><em>' . ($step * $i) . '</em></span>';
        }
        $html .= '</div><div class="colchart-cols">';
        foreach ($data as $i => $d) {
            $pct = $top ? round($d['value'] / $top * 100, 2) : 0;
            $showLabel = $d['value'] > 0 && ($i === $last || $i === $maxIdx);
            $html .= '<div class="col" data-tip="' . e($d['full'] . ': ' . $d['value'] . ' ' . $unit) . '" tabindex="0">'
                . '<div class="col-track"><div class="col-bar' . ($i === $last ? ' is-current' : '') . '" style="--h:' . $pct . '%;--i:' . $i . '">'
                . ($showLabel ? '<b>' . $d['value'] . '</b>' : '')
                . '</div></div><span class="col-label">' . e($d['label']) . '</span></div>';
        }
        $html .= '</div>';
        // Bảng dữ liệu cho trình đọc màn hình.
        $html .= '<table class="sr-only"><caption>' . e($caption) . '</caption><tbody>';
        foreach ($data as $d) {
            $html .= '<tr><th>' . e($d['full']) . '</th><td>' . (int) $d['value'] . '</td></tr>';
        }
        return $html . '</tbody></table></figure>';
    }

    /**
     * Thanh xếp chồng 100% + chú giải có số. $parts = [['key','label','value'], ...]
     */
    public static function stack(array $parts): string
    {
        $total = array_sum(array_column($parts, 'value'));
        $html = '<div class="stackbar" role="img" aria-label="' . e(implode(', ', array_map(fn ($p) => $p['label'] . ' ' . $p['value'], $parts))) . '">';
        foreach ($parts as $p) {
            if ($p['value'] <= 0 || !$total) {
                continue;
            }
            $pct = round($p['value'] / $total * 100, 2);
            $html .= '<span class="seg seg-' . e($p['key']) . '" style="flex-grow:' . $pct . '" data-tip="' . e($p['label'] . ': ' . $p['value'] . ' (' . round($pct) . '%)') . '"></span>';
        }
        if (!$total) {
            $html .= '<span class="seg seg-empty" style="flex-grow:1"></span>';
        }
        $html .= '</div><ul class="legend">';
        foreach ($parts as $p) {
            $html .= '<li><span class="key seg-' . e($p['key']) . '"></span>' . e($p['label'])
                . '<b>' . (int) $p['value'] . '</b></li>';
        }
        return $html . '</ul>';
    }

    /** Thanh ngang một màu (độ lớn), số ghi ở đầu thanh. $rows = [['label','value','url','id'], ...] */
    public static function hbars(array $rows): string
    {
        $max = max(1, ...array_column($rows, 'value'));
        $html = '<ul class="hbars">';
        foreach ($rows as $r) {
            $pct = round($r['value'] / $max * 100, 2);
            $html .= '<li><a href="' . e($r['url']) . '" data-tip="' . e($r['label'] . ': ' . $r['value'] . ' bài') . '">'
                . '<span class="hbar-label">' . cat_dot((int) $r['id']) . e($r['label']) . '</span>'
                . '<span class="hbar-track"><span class="hbar" style="--w:' . $pct . '%"></span></span>'
                . '<b>' . (int) $r['value'] . '</b></a></li>';
        }
        return $html . '</ul>';
    }
}

<?php
/**
 * AdminURL 趋势图组件
 *
 * 生成纯 SVG 面积折线图，无外部依赖；颜色由 CSS 变量控制，自动适配浅/深色主题。
 * $values 为关联数组：['2026-11-01' => 3, '2026-11-02' => 7, ...]
 */

function trend_svg(array $values, string $label = '趋势图'): string
{
    if (count($values) === 0) {
        return '';
    }

    $w = 640;
    $h = 186;
    $padL = 34;
    $padR = 8;
    $baseY = 150;
    $plotH = 122;

    $max = max(1, (int)max($values));
    $count = count($values);
    $points = [];
    $i = 0;
    foreach ($values as $day => $value) {
        $x = $padL + $i * (($w - $padL - $padR) / max(1, $count - 1));
        $y = $baseY - ((int)$value / $max) * $plotH;
        $points[] = ['x' => $x, 'y' => $y, 'day' => (string)$day, 'value' => (int)$value];
        $i++;
    }

    $line = '';
    foreach ($points as $k => $p) {
        $line .= ($k === 0 ? 'M' : ' L') . round($p['x'], 1) . ',' . round($p['y'], 1);
    }
    $lastX = round($points[count($points) - 1]['x'], 1);
    $area = $line . ' L' . $lastX . ',' . $baseY . ' L' . $padL . ',' . $baseY . ' Z';

    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($label) . '">';
    $svg .= '<defs><linearGradient id="au-area" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="#6366f1" stop-opacity=".28"/>'
        . '<stop offset="100%" stop-color="#6366f1" stop-opacity="0"/>'
        . '</linearGradient></defs>';

    for ($g = 0; $g <= 3; $g++) {
        $gy = $baseY - $g * ($plotH / 3);
        $svg .= '<line class="chart-grid-line" x1="' . $padL . '" y1="' . round($gy, 1)
            . '" x2="' . ($w - $padR) . '" y2="' . round($gy, 1) . '"/>';
        $svg .= '<text class="chart-label" x="0" y="' . round($gy + 4, 1) . '">'
            . (int)round($max * $g / 3) . '</text>';
    }

    $svg .= '<path class="chart-area" d="' . e($area) . '"/>';
    $svg .= '<path class="chart-line" d="' . e($line) . '"/>';

    foreach ($points as $k => $p) {
        if ($k === count($points) - 1 || $p['value'] === $max) {
            $svg .= '<circle class="chart-dot" cx="' . round($p['x'], 1)
                . '" cy="' . round($p['y'], 1) . '" r="4"/>';
        }
        if ($k % 3 === 0 || $k === count($points) - 1) {
            $svg .= '<text class="chart-label" x="' . round($p['x'], 1)
                . '" y="' . ($baseY + 22) . '" text-anchor="middle">'
                . e(date('m/d', strtotime($p['day']))) . '</text>';
        }
    }

    return $svg . '</svg>';
}

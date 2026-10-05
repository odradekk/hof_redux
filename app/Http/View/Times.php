<?php

declare(strict_types=1);

namespace App\Http\View;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class Times
{
    public static function present(DateTimeInterface|string|null $at, string $mode = 'short'): array
    {
        if ($at === null || $at === '') {
            return ['iso' => '', 'title' => '', 'text' => '现在'];
        }
        $date = CarbonImmutable::parse($at, 'UTC')->setTimezone(config('hof_ui.display_timezone'));
        $text = $date->format($mode === 'full' ? 'Y-m-d H:i:s' : 'm-d H:i');
        if ($mode === 'relative') {
            $seconds = max(0, $date->getTimestamp() - CarbonImmutable::now()->getTimestamp());
            $text = match (true) {
                $seconds >= 3600 => intdiv($seconds, 3600).'小时'.intdiv($seconds % 3600, 60).'分',
                $seconds >= 60 => intdiv($seconds, 60).'分',
                $seconds > 0 => $seconds.'秒', default => '已到时间',
            };
        }

        return ['iso' => $date->toIso8601String(), 'title' => $date->format('Y-m-d H:i:s T'), 'text' => $text];
    }
}

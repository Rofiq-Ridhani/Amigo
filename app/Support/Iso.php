<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Format waktu kontrak §0.2: ISO-8601 UTC, contoh 2026-10-12T02:30:00Z */
class Iso
{
    public static function format(?CarbonInterface $date): ?string
    {
        return $date?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}

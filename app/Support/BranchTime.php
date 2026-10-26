<?php

namespace App\Support;

use App\Models\Branch;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Branch-local calendar and datetime handling for America/Vancouver (both branches).
 *
 * Work dates and weekly boundaries use the branch timezone. Instants (starts_at / ends_at)
 * are stored in UTC in the database and converted at boundaries using the branch timezone.
 */
class BranchTime
{
    public const DEFAULT_TIMEZONE = 'America/Vancouver';

    public static function timezoneFor(?Branch $branch = null): string
    {
        return $branch?->timezone ?? self::DEFAULT_TIMEZONE;
    }

    public static function weekStart(CarbonInterface|string|null $date = null, ?Branch $branch = null): Carbon
    {
        $tz = self::timezoneFor($branch);
        $d = $date instanceof CarbonInterface
            ? $date->copy()->timezone($tz)
            : Carbon::parse($date ?? 'now', $tz);

        return $d->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    public static function weekEnd(Carbon $weekStart): Carbon
    {
        return $weekStart->copy()->addDays(6)->endOfDay();
    }

    public static function parseLocalDate(string $date, ?Branch $branch = null): Carbon
    {
        return Carbon::parse($date, self::timezoneFor($branch))->startOfDay();
    }

    public static function combineLocalDateAndTime(string $workDate, string $time, ?Branch $branch = null): Carbon
    {
        $tz = self::timezoneFor($branch);

        return Carbon::parse("{$workDate} {$time}", $tz);
    }

    public static function toUtc(CarbonInterface $local): Carbon
    {
        return $local->copy()->utc();
    }

    public static function fromUtc(CarbonInterface $utc, ?Branch $branch = null): Carbon
    {
        return $utc->copy()->timezone(self::timezoneFor($branch));
    }

    public static function workDateFromStartsAt(CarbonInterface $startsAtUtc, ?Branch $branch = null): string
    {
        return self::fromUtc($startsAtUtc, $branch)->toDateString();
    }
}

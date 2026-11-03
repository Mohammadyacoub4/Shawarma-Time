<?php

namespace App\Support;

use App\Models\Branch;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Branch-local calendar and datetime handling for fixed UTC-07:00 (Etc/GMT+7).
 *
 * Work dates and weekly boundaries use the fixed business timezone (Etc/GMT+7 = UTC-07:00).
 * Instants (starts_at / ends_at) are stored in UTC in the database and converted
 * at boundaries using the fixed business timezone.
 */
class BranchTime
{
    public const DEFAULT_TIMEZONE = 'Etc/GMT+7';

    public static function timezoneFor(?Branch $branch = null): string
    {
        if (! $branch) {
            return self::DEFAULT_TIMEZONE;
        }

        $tz = $branch->timezone;

        if ($tz === null || trim($tz) === '') {
            return self::DEFAULT_TIMEZONE;
        }

        if ($tz === self::DEFAULT_TIMEZONE) {
            return self::DEFAULT_TIMEZONE;
        }

        if ($tz === 'Canada/Pacific' || $tz === 'America/Vancouver') {
            return self::DEFAULT_TIMEZONE;
        }

        Log::warning('Unexpected branch timezone encountered; falling back to Etc/GMT+7.', [
            'branch_id' => $branch->id,
            'timezone' => $tz,
        ]);

        return self::DEFAULT_TIMEZONE;
    }

    public static function weekStart(CarbonInterface|string|null $date = null, ?Branch $branch = null): Carbon
    {
        $tz = self::timezoneFor($branch);
        if ($date instanceof CarbonInterface) {
            $d = $date->copy()->timezone($tz);
        } else {
            try {
                $d = Carbon::parse($date ?? 'now', $tz);
            } catch (\Exception) {
                $d = Carbon::now($tz);
            }
        }

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

    /**
     * Combine work_date and local wall-clock time string (H:i) into a localized Carbon instance.
     *
     * Validates that the input represents a valid calendar date and wall-clock time
     * in the fixed UTC-07:00 (Etc/GMT+7) business timezone.
     *
     * @param string $workDate YYYY-MM-DD
     * @param string $time HH:MM or H:i:s
     * @param Branch|null $branch
     * @param string $fieldName Field name to attach ValidationException to ('start_time' or 'end_time')
     * @return Carbon
     * @throws ValidationException
     */
    public static function combineLocalDateAndTime(
        string $workDate,
        string $time,
        ?Branch $branch = null,
        string $fieldName = 'time'
    ): Carbon {
        $tzName = self::timezoneFor($branch);

        // Strict format checks
        if (! is_string($workDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            throw ValidationException::withMessages([
                $fieldName => ['التاريخ المحدد غير صالح / The specified date is invalid.'],
            ]);
        }

        if (! is_string($time) || ! preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
            throw ValidationException::withMessages([
                $fieldName => ['الوقت المحدد غير صالح / The specified time is invalid.'],
            ]);
        }

        $timeParts = explode(':', $time);
        $normalizedTime = sprintf('%02d:%02d:%02d', (int) $timeParts[0], (int) $timeParts[1], (int) ($timeParts[2] ?? 0));
        $targetWallClock = "{$workDate} {$normalizedTime}";

        try {
            $timeZone = new DateTimeZone($tzName);
        } catch (\Exception) {
            $timeZone = new DateTimeZone(self::DEFAULT_TIMEZONE);
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $targetWallClock, $timeZone);

        // Validate that DateTimeImmutable parsed it without date overflow/rollover (e.g. 2026-02-30 or 25:00)
        if (! $dt || $dt->format('Y-m-d H:i:s') !== $targetWallClock) {
            throw ValidationException::withMessages([
                $fieldName => ['الوقت أو التاريخ المحدد غير صالح / The specified date or time is invalid.'],
            ]);
        }

        return Carbon::instance($dt);
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

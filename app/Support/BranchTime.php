<?php

namespace App\Support;

use App\Models\Branch;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * Branch-local calendar and datetime handling for Canada/Pacific (both branches).
 *
 * Work dates and weekly boundaries use the branch timezone. Instants (starts_at / ends_at)
 * are stored in UTC in the database and converted at boundaries using the branch timezone.
 */
class BranchTime
{
    public const DEFAULT_TIMEZONE = 'Canada/Pacific';

    public static function timezoneFor(?Branch $branch = null): string
    {
        return $branch?->timezone ?? self::DEFAULT_TIMEZONE;
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
     * Validates that the wall clock time is neither nonexistent (skipped due to spring-forward DST transition)
     * nor ambiguous (repeated due to fall-back DST transition) unless explicitly disambiguated.
     *
     * @param string $workDate YYYY-MM-DD
     * @param string $time HH:MM
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
        $timeZone = new DateTimeZone($tzName);

        // Standardize time format (H:i:00)
        $normalizedTime = preg_match('/^\d{1,2}:\d{2}$/', $time) ? sprintf('%02d:%02d:00', ...explode(':', $time)) : $time;
        $targetWallClock = "{$workDate} {$normalizedTime}";

        // 1. Check for nonexistent time (e.g. spring forward gap like 02:00 -> 03:00)
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $targetWallClock, $timeZone);
        if (! $dt || $dt->format('Y-m-d H:i:s') !== $targetWallClock) {
            throw ValidationException::withMessages([
                $fieldName => ['الوقت المحدد غير صالح بسبب التوقيت الصيفي (ساعة غير موجودة) / The specified time is nonexistent due to Daylight Saving Time spring-forward transition.'],
            ]);
        }

        // 2. Check for ambiguous time (e.g. fall back 01:00 -> 01:59 repeated)
        $dayStartTs = strtotime("{$workDate} 00:00:00 UTC") - 86400;
        $dayEndTs = strtotime("{$workDate} 23:59:59 UTC") + 86400;
        $transitions = $timeZone->getTransitions($dayStartTs, $dayEndTs);

        if (is_array($transitions)) {
            foreach ($transitions as $k => $tr) {
                if ($k === 0) {
                    continue;
                }
                $prevOffset = $transitions[$k - 1]['offset'];
                $newOffset = $tr['offset'];

                // Fall-back transition detected where clock moves backward
                if ($newOffset < $prevOffset) {
                    $repeatStartLocal = gmdate('Y-m-d H:i:s', $tr['ts'] + $newOffset);
                    $repeatEndLocal = gmdate('Y-m-d H:i:s', $tr['ts'] + $prevOffset);

                    if ($targetWallClock >= $repeatStartLocal && $targetWallClock < $repeatEndLocal) {
                        throw ValidationException::withMessages([
                            $fieldName => ['الوقت المحدد مكرر وغامض بسبب التوقيت الصيفي / The specified time is ambiguous due to Daylight Saving Time fall-back transition.'],
                        ]);
                    }
                }
            }
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

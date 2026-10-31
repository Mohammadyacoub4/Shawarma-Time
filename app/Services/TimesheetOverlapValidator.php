<?php

namespace App\Services;

use App\Enums\TimesheetStatus;
use App\Models\TimesheetEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class TimesheetOverlapValidator
{
    /**
     * Check if a candidate timesheet interval or shift overlaps with existing entries.
     *
     * Active statuses considered for overlap: clocked_in, pending_approval, approved.
     * Excluded statuses: rejected.
     * Excluded entry: the current entry being updated ($ignoreEntryId).
     *
     * Overlap condition (UTC half-open interval):
     * existing.starts_at < candidate.ends_at AND (existing.ends_at > candidate.starts_at OR existing.ends_at IS NULL)
     *
     * @param int $userId
     * @param CarbonInterface $startsAtUtc
     * @param CarbonInterface $endsAtUtc
     * @param int|null $ignoreEntryId
     * @param int|null $shiftId
     * @throws ValidationException
     */
    public function validate(
        int $userId,
        CarbonInterface $startsAtUtc,
        CarbonInterface $endsAtUtc,
        ?int $ignoreEntryId = null,
        ?int $shiftId = null
    ): void {
        // 1. Check duplicate shift_id claim if shift_id is provided
        if ($shiftId !== null) {
            $duplicateShiftQuery = TimesheetEntry::query()
                ->where('shift_id', $shiftId)
                ->when($ignoreEntryId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreEntryId));

            if ($duplicateShiftQuery->exists()) {
                throw ValidationException::withMessages([
                    'shift_id' => ['تم تقديم سجل دوام لهذه الوردية مسبقاً / A timesheet has already been submitted for this shift.'],
                ]);
            }
        }

        // 2. Check interval overlap across all branches for same employee
        // Statuses checked: clocked_in, pending_approval, approved
        $overlappingEntry = TimesheetEntry::query()
            ->where('user_id', $userId)
            ->when($ignoreEntryId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreEntryId))
            ->whereIn('status', [
                TimesheetStatus::ClockedIn,
                TimesheetStatus::PendingApproval,
                TimesheetStatus::Approved,
            ])
            ->where('starts_at', '<', $endsAtUtc)
            ->where(function (Builder $q) use ($startsAtUtc) {
                $q->where('ends_at', '>', $startsAtUtc)
                  ->orWhereNull('ends_at');
            })
            ->first();

        if ($overlappingEntry) {
            $isClockInSession = $overlappingEntry->status === TimesheetStatus::ClockedIn || $overlappingEntry->ends_at === null;

            $message = $isClockInSession
                ? 'يوجد جلسة تسجيل دوام نشطة تتعارض مع هذا الوقت / There is an active clock-in session that overlaps with this time.'
                : 'يوجد سجل دوام متعارض مع هذا التوقيت / A timesheet entry already exists that overlaps with this time period.';

            throw ValidationException::withMessages([
                'start_time' => [$message],
            ]);
        }
    }

    /**
     * Helper to return boolean whether an overlap exists without throwing.
     */
    public function hasOverlap(
        int $userId,
        CarbonInterface $startsAtUtc,
        CarbonInterface $endsAtUtc,
        ?int $ignoreEntryId = null
    ): bool {
        return TimesheetEntry::query()
            ->where('user_id', $userId)
            ->when($ignoreEntryId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreEntryId))
            ->whereIn('status', [
                TimesheetStatus::ClockedIn,
                TimesheetStatus::PendingApproval,
                TimesheetStatus::Approved,
            ])
            ->where('starts_at', '<', $endsAtUtc)
            ->where(function (Builder $q) use ($startsAtUtc) {
                $q->where('ends_at', '>', $startsAtUtc)
                  ->orWhereNull('ends_at');
            })
            ->exists();
    }
}


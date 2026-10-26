<?php

namespace App\Services;

use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ShiftOverlapValidator
{
    public function hasOverlap(
        int $userId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreShiftId = null
    ): bool {
        return Shift::query()
            ->where('user_id', $userId)
            ->when($ignoreShiftId, fn (Builder $q) => $q->where('id', '!=', $ignoreShiftId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}

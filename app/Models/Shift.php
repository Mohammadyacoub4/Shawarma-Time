<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @use HasFactory<\Database\Factories\ShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'schedule_week_id',
        'work_date',
        'starts_at',
        'ends_at',
        'grace_minutes',
        'notes',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'grace_minutes' => 'integer',
        ];
    }

    protected function startsAt(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (! $value) {
                    return null;
                }
                return $value instanceof CarbonInterface ? $value->copy()->utc() : Carbon::parse($value, 'UTC');
            },
            set: function ($value) {
                if (! $value) {
                    return null;
                }
                if ($value instanceof CarbonInterface) {
                    return $value->copy()->utc()->format('Y-m-d H:i:s');
                }
                return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
            }
        );
    }

    protected function endsAt(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (! $value) {
                    return null;
                }
                return $value instanceof CarbonInterface ? $value->copy()->utc() : Carbon::parse($value, 'UTC');
            },
            set: function ($value) {
                if (! $value) {
                    return null;
                }
                if ($value instanceof CarbonInterface) {
                    return $value->copy()->utc()->format('Y-m-d H:i:s');
                }
                return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
            }
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scheduleWeek(): BelongsTo
    {
        return $this->belongsTo(ScheduleWeek::class);
    }

    public function timesheetEntries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }
}

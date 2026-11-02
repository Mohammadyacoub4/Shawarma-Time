<?php

namespace App\Models;

use App\Enums\TimesheetStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TimesheetEntry extends Model
{
    /** @use HasFactory<\Database\Factories\TimesheetEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'shift_id',
        'work_date',
        'starts_at',
        'ends_at',
        'total_minutes',
        'is_late',
        'late_minutes',
        'status',
        'submitted_at',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'employee_notes',
        'clock_in_ip',
        'device_info',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_late' => 'boolean',
            'late_minutes' => 'integer',
            'status' => TimesheetStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function isCurrentlyClockedIn(): bool
    {
        return $this->status === TimesheetStatus::ClockedIn || ($this->starts_at !== null && $this->ends_at === null);
    }

    public function currentDurationMinutes(): int
    {
        if ($this->ends_at) {
            return (int) $this->total_minutes;
        }

        if ($this->starts_at) {
            return (int) $this->starts_at->diffInMinutes(now());
        }

        return 0;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(TimesheetAuditLog::class);
    }

    public function latestAuditLog(): HasOne
    {
        return $this->hasOne(TimesheetAuditLog::class)->latestOfMany('id');
    }
}

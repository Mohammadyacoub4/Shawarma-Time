<?php

namespace App\Models;

use App\Enums\TimesheetStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_late' => 'boolean',
            'late_minutes' => 'integer',
            'status' => TimesheetStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
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
}

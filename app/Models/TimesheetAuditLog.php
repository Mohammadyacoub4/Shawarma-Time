<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetAuditLog extends Model
{
    protected $fillable = [
        'timesheet_entry_id',
        'performed_by',
        'action',
        'old_values',
        'new_values',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimesheetEntry::class, 'timesheet_entry_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}

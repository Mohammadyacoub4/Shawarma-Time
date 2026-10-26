<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleWeek extends Model
{
    /** @use HasFactory<\Database\Factories\ScheduleWeekFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'week_start',
        'is_published',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}

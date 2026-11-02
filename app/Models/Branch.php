<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    /** @use HasFactory<\Database\Factories\BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'city',
        'timezone',
        'grace_minutes',
    ];

    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Branch $branch) {
            if ($branch->timesheetEntries()->exists()) {
                throw new \DomainException('Cannot delete a branch with associated timesheet records.');
            }

            if ($branch->shifts()->exists()) {
                throw new \DomainException('Cannot delete a branch with associated shift records.');
            }

            if ($branch->scheduleWeeks()->exists()) {
                throw new \DomainException('Cannot delete a branch with associated schedule weeks.');
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scheduleWeeks(): HasMany
    {
        return $this->hasMany(ScheduleWeek::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function timesheetEntries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }
}

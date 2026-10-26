<?php

namespace App\Policies;

use App\Enums\TimesheetStatus;
use App\Models\TimesheetEntry;
use App\Models\User;

class TimesheetEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TimesheetEntry $timesheetEntry): bool
    {
        return $user->isAdmin() || $timesheetEntry->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isEmployee() && $user->is_active;
    }

    public function update(User $user, TimesheetEntry $timesheetEntry): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $timesheetEntry->user_id === $user->id
            && $timesheetEntry->status === TimesheetStatus::Rejected;
    }

    public function approve(User $user, TimesheetEntry $timesheetEntry): bool
    {
        return $user->isAdmin() && $timesheetEntry->status === TimesheetStatus::PendingApproval;
    }

    public function reject(User $user, TimesheetEntry $timesheetEntry): bool
    {
        return $this->approve($user, $timesheetEntry);
    }
}

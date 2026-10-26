<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Shift $shift): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->employeeCanViewPublishedShift($user, $shift);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Shift $shift): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->isAdmin();
    }

    private function employeeCanViewPublishedShift(User $user, Shift $shift): bool
    {
        $week = $shift->scheduleWeek;

        return $week?->is_published === true;
    }
}

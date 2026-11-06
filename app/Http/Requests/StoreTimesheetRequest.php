<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\Shift;
use App\Support\BranchTime;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployee() ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
            'start_time' => ['required', 'regex:/^(?:[01]?\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['required', 'regex:/^(?:[01]?\d|2[0-3]):[0-5]\d$/'],
            'employee_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            if (! $user) {
                return;
            }

            // 1. Branch validity and assignment check
            $branchId = $this->input('branch_id');
            if (! $branchId || $validator->errors()->has('branch_id')) {
                return;
            }

            $branch = Branch::find($branchId);
            if (! $branch) {
                return;
            }

            if (! $user->isAssignedToBranch($branch->id)) {
                abort(403, 'Unauthorized branch assignment.');
            }

            // 2. Future-date validation using branch timezone
            $workDate = $this->input('work_date');
            if ($workDate && ! $validator->errors()->has('work_date')) {
                $branchTz = BranchTime::timezoneFor($branch);
                $todayLocal = now($branchTz)->toDateString();

                try {
                    $parsedWorkDate = Carbon::parse($workDate)->toDateString();
                    if ($parsedWorkDate > $todayLocal) {
                        $validator->errors()->add(
                            'work_date',
                            'تاريخ العمل لا يمكن أن يكون في المستقبل / The work date cannot be in the future.'
                        );
                    }
                } catch (\Throwable) {
                }
            }

            // 3. Shift integrity checks if shift_id provided
            $shiftId = $this->input('shift_id');
            if ($shiftId && ! $validator->errors()->has('shift_id')) {
                $shift = Shift::with('scheduleWeek')->find($shiftId);
                if (! $shift) {
                    return;
                }

                if ($shift->user_id !== $user->id || (int) $shift->branch_id !== (int) $branch->id) {
                    abort(403, 'Unauthorized shift association.');
                }

                if (! $shift->scheduleWeek?->is_published) {
                    $validator->errors()->add(
                        'shift_id',
                        'لا يمكن تقديم سجل دوام لوردية غير منشورة / Cannot submit a timesheet for an unpublished shift.'
                    );
                }

                if ($workDate) {
                    try {
                        $parsedWorkDate = Carbon::parse($workDate)->toDateString();
                        if ($shift->work_date->toDateString() !== $parsedWorkDate) {
                            $validator->errors()->add(
                                'work_date',
                                'تاريخ العمل يجب أن يتطابق مع تاريخ الوردية المجدولة / The work date must match the scheduled shift date.'
                            );
                        }
                    } catch (\Throwable) {
                    }
                }
            }
        });
    }
}


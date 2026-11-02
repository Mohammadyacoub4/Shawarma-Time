<?php

namespace App\Http\Requests;

use App\Models\Shift;
use App\Models\TimesheetEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'employee_notes' => ['nullable', 'string', 'max:2000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $shiftId = $this->input('shift_id');
            if (! $shiftId || $validator->errors()->has('shift_id')) {
                return;
            }

            $shift = Shift::find($shiftId);
            if (! $shift) {
                return;
            }

            $timesheet = $this->route('timesheet');
            $timesheetUserId = $timesheet instanceof TimesheetEntry
                ? $timesheet->user_id
                : TimesheetEntry::find($timesheet)?->user_id;

            if ($timesheetUserId && $shift->user_id !== $timesheetUserId) {
                $validator->errors()->add('shift_id', 'The shift does not belong to the timesheet employee.');
            }

            $branchId = $this->input('branch_id');
            if ($branchId && (int) $shift->branch_id !== (int) $branchId) {
                $validator->errors()->add('shift_id', 'The shift does not belong to the selected branch.');
            }

            $workDate = $this->input('work_date');
            if ($workDate) {
                try {
                    $parsedWorkDate = Carbon::parse($workDate)->toDateString();
                    if ($shift->work_date->toDateString() !== $parsedWorkDate) {
                        $validator->errors()->add('work_date', 'The work date must match the scheduled shift date.');
                    }
                } catch (\Throwable) {
                }
            }
        });
    }
}


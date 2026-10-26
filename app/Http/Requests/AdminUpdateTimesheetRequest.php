<?php

namespace App\Http\Requests;

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
        ];
    }
}

<?php

namespace App\Exports;

use App\Enums\TimesheetStatus;
use App\Models\TimesheetEntry;
use App\Services\WorkPeriodCalculator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TimesheetReportExport implements FromArray, WithHeadings
{
    public function __construct(
        private readonly array $report,
        private readonly WorkPeriodCalculator $calculator = new WorkPeriodCalculator,
    ) {}

    public function headings(): array
    {
        return [
            'Employee',
            'Branch',
            'Work Date',
            'Start Time',
            'End Time',
            'Recorded Duration',
            'Approved Hours',
            'Status',
            'Late?',
            'Late Minutes',
            'Submitted At',
        ];
    }

    public function array(): array
    {
        /** @var Collection<int, TimesheetEntry> $entries */
        $entries = $this->report['entries'];

        $rows = [];

        foreach ($entries as $row) {
            $isApproved = $row->status === TimesheetStatus::Approved;
            $approvedHours = $isApproved ? $this->calculator->formatHours($row->total_minutes) : '0:00';

            $rows[] = [
                $row->user->name,
                $row->branch->name,
                $row->work_date->toDateString(),
                $row->starts_at?->toDateTimeString() ?? '-',
                $row->ends_at?->toDateTimeString() ?? 'Clocked In (Active)',
                $this->calculator->formatHours($row->total_minutes),
                $approvedHours,
                $row->status instanceof TimesheetStatus ? $row->status->label() : (string) $row->status,
                $row->is_late ? 'Yes' : 'No',
                $row->late_minutes ?? 0,
                optional($row->submitted_at)?->toDateTimeString(),
            ];
        }

        // Summary calculations from integer minutes then formatted as HH:MM
        $approvedMinutes = (int) ($this->report['approved_combined_minutes'] ?? $entries->where('status', TimesheetStatus::Approved)->sum('total_minutes'));
        $pendingMinutes = (int) ($this->report['pending_minutes'] ?? $entries->where('status', TimesheetStatus::PendingApproval)->sum('total_minutes'));
        $rejectedMinutes = (int) ($this->report['rejected_minutes'] ?? $entries->where('status', TimesheetStatus::Rejected)->sum('total_minutes'));

        // Blank separator row
        $rows[] = array_fill(0, 11, '');

        // Summary header row
        $rows[] = [
            'SUMMARY TOTALS',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        // Summary rows (server-calculated static values, avoiding formula injection)
        $rows[] = [
            'Total Approved Hours',
            $this->calculator->formatHours($approvedMinutes),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        $rows[] = [
            'Total Pending Hours',
            $this->calculator->formatHours($pendingMinutes),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        $rows[] = [
            'Total Rejected Hours',
            $this->calculator->formatHours($rejectedMinutes),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        return $rows;
    }
}

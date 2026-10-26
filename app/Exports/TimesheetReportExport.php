<?php

namespace App\Exports;

use App\Enums\TimesheetStatus;
use App\Models\TimesheetEntry;
use App\Services\WorkPeriodCalculator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TimesheetReportExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly array $report,
        private readonly WorkPeriodCalculator $calculator = new WorkPeriodCalculator,
    ) {}

    public function collection(): Collection
    {
        /** @var Collection<int, TimesheetEntry> $entries */
        $entries = $this->report['entries'];

        return $entries;
    }

    public function headings(): array
    {
        return [
            'Employee',
            'Branch',
            'Work Date',
            'Start Time',
            'End Time',
            'Hours',
            'Status',
            'Late?',
            'Late Minutes',
            'Submitted At',
        ];
    }

    /**
     * @param  TimesheetEntry  $row
     */
    public function map($row): array
    {
        return [
            $row->user->name,
            $row->branch->name,
            $row->work_date->toDateString(),
            $row->starts_at?->toDateTimeString() ?? '-',
            $row->ends_at?->toDateTimeString() ?? 'Clocked In (Active)',
            $this->calculator->formatHours($row->total_minutes),
            $row->status instanceof TimesheetStatus ? $row->status->label() : (string) $row->status,
            $row->is_late ? 'Yes' : 'No',
            $row->late_minutes ?? 0,
            optional($row->submitted_at)?->toDateTimeString(),
        ];
    }
}

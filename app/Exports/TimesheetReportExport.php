<?php

namespace App\Exports;

use App\Enums\TimesheetStatus;
use App\Models\TimesheetEntry;
use App\Services\WorkPeriodCalculator;
use App\Support\BranchTime;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TimesheetReportExport implements FromGenerator, WithHeadings
{
    private readonly WorkPeriodCalculator $calculator;
    private readonly ?Builder $entriesQuery;

    /**
     * @param array<string, mixed> $report
     * @param Builder<TimesheetEntry>|WorkPeriodCalculator|null $queryOrCalculator
     * @param WorkPeriodCalculator|null $calculator
     */
    public function __construct(
        private readonly array $report,
        Builder|WorkPeriodCalculator|null $queryOrCalculator = null,
        ?WorkPeriodCalculator $calculator = null,
    ) {
        if ($queryOrCalculator instanceof WorkPeriodCalculator) {
            $this->entriesQuery = null;
            $this->calculator = $queryOrCalculator;
        } else {
            $this->entriesQuery = $queryOrCalculator;
            $this->calculator = $calculator ?? new WorkPeriodCalculator;
        }
    }

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

    /**
     * Yields data rows in memory-friendly chunks with eager-loaded relationships, followed by summary totals.
     * Works identically for both XLSX and CSV streaming writers.
     *
     * @return Generator<int, array<int, mixed>>
     */
    public function generator(): Generator
    {
        // 1. Stream data rows using lazy chunks (enables proper eager loading for relations)
        if ($this->entriesQuery !== null) {
            $chunkQuery = (clone $this->entriesQuery)
                ->with(['user', 'branch'])
                ->orderBy('work_date')
                ->orderBy('id');

            foreach ($chunkQuery->lazy(500) as $row) {
                yield $this->mapRow($row);
            }
        } elseif (! empty($this->report['entries'])) {
            // Backward-compatibility fallback if passed a pre-built entries collection
            foreach ($this->report['entries'] as $row) {
                yield $this->mapRow($row);
            }
        } elseif (! empty($this->report['filters'])) {
            // Query dynamically from filters in report if entriesQuery wasn't passed directly
            $chunkQuery = app(\App\Services\ReportService::class)
                ->filteredEntriesQuery($this->report['filters'])
                ->with(['user', 'branch'])
                ->orderBy('work_date')
                ->orderBy('id');

            foreach ($chunkQuery->lazy(500) as $row) {
                yield $this->mapRow($row);
            }
        }

        // 2. Summary calculations from integer minutes then formatted as HH:MM
        $approvedMinutes = (int) ($this->report['approved_combined_minutes'] ?? 0);
        $pendingMinutes = (int) ($this->report['pending_minutes'] ?? 0);
        $rejectedMinutes = (int) ($this->report['rejected_minutes'] ?? 0);

        // Blank separator row
        yield array_fill(0, 11, '');

        // Summary header row
        yield [
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
        yield [
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

        yield [
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

        yield [
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
    }

    /**
     * Backward-compatibility helper for any legacy caller expecting array().
     *
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return iterator_to_array($this->generator(), false);
    }

    /**
     * @param TimesheetEntry $row
     * @return array<int, mixed>
     */
    private function mapRow(TimesheetEntry $row): array
    {
        $isApproved = $row->status === TimesheetStatus::Approved;
        $approvedHours = $isApproved ? $this->calculator->formatHours($row->total_minutes) : '0:00';
        $tz = BranchTime::timezoneFor($row->branch);

        return [
            $row->user->name,
            $row->branch->name,
            $row->work_date->toDateString(),
            $row->starts_at ? $row->starts_at->timezone($tz)->toDateTimeString() : '-',
            $row->ends_at ? $row->ends_at->timezone($tz)->toDateTimeString() : 'Clocked In (Active)',
            $this->calculator->formatHours($row->total_minutes),
            $approvedHours,
            $row->status instanceof TimesheetStatus ? $row->status->label() : (string) $row->status,
            $row->is_late ? 'Yes' : 'No',
            $row->late_minutes ?? 0,
            $row->submitted_at ? $row->submitted_at->timezone($tz)->toDateTimeString() : null,
        ];
    }
}

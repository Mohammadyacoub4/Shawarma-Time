<?php

namespace App\Services;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Support\BranchTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportService
{
    public function __construct(
        private readonly WorkPeriodCalculator $calculator,
    ) {}

    /**
     * @param  array{branch_id?: int|null, user_id?: int|null, status?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  bool  $includeEntries
     * @return array<string, mixed>
     */
    public function build(array $filters, bool $includeEntries = false): array
    {
        $from = null;
        if (! empty($filters['from']) && is_string($filters['from'])) {
            try {
                $from = Carbon::parse($filters['from'])->toDateString();
            } catch (\Exception) {
                $from = null;
            }
        }
        $to = null;
        if (! empty($filters['to']) && is_string($filters['to'])) {
            try {
                $to = Carbon::parse($filters['to'])->toDateString();
            } catch (\Exception) {
                $to = null;
            }
        }

        // Fast SQL aggregation without hydrating full timesheet entry models
        $metrics = $this->filteredEntriesQuery($filters)
            ->selectRaw("
                COUNT(*) as attendance_count,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN total_minutes ELSE 0 END), 0) as approved_combined_minutes,
                COALESCE(SUM(CASE WHEN status = 'pending_approval' THEN total_minutes ELSE 0 END), 0) as pending_minutes,
                COALESCE(SUM(CASE WHEN status = 'rejected' THEN total_minutes ELSE 0 END), 0) as rejected_minutes,
                COALESCE(SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END), 0) as late_count,
                COALESCE(SUM(CASE WHEN is_late = 1 THEN late_minutes ELSE 0 END), 0) as late_minutes,
                COALESCE(SUM(CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END), 0) as missing_checkout_count
            ")
            ->first();

        // Branch-level timesheet aggregates
        $branchMetrics = $this->filteredEntriesQuery($filters)
            ->selectRaw("
                branch_id,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN total_minutes ELSE 0 END), 0) as approved_minutes,
                COALESCE(SUM(CASE WHEN status = 'pending_approval' THEN total_minutes ELSE 0 END), 0) as pending_minutes,
                COALESCE(SUM(CASE WHEN status = 'rejected' THEN total_minutes ELSE 0 END), 0) as rejected_minutes
            ")
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        // Scheduled shift minutes per branch
        $shifts = $this->filteredShiftsQuery($filters)->get(['branch_id', 'starts_at', 'ends_at']);
        $shiftsByBranch = $shifts->groupBy('branch_id');
        $totalScheduledMinutes = $this->shiftMinutes($shifts);

        $byBranch = [];
        foreach (Branch::orderBy('name')->get() as $branch) {
            $bm = $branchMetrics->get($branch->id);
            $branchShifts = $shiftsByBranch->get($branch->id, collect());

            $byBranch[$branch->id] = [
                'branch' => $branch,
                'approved_minutes' => (int) ($bm->approved_minutes ?? 0),
                'pending_minutes' => (int) ($bm->pending_minutes ?? 0),
                'rejected_minutes' => (int) ($bm->rejected_minutes ?? 0),
                'scheduled_minutes' => $this->shiftMinutes($branchShifts),
            ];
        }

        $approvedCombined = (int) ($metrics->approved_combined_minutes ?? 0);
        $totalWorkingMinutes = $approvedCombined;
        $lateCount = (int) ($metrics->late_count ?? 0);
        $lateMinutes = (int) ($metrics->late_minutes ?? 0);
        $missingCheckoutCount = (int) ($metrics->missing_checkout_count ?? 0);
        $attendanceCount = (int) ($metrics->attendance_count ?? 0);
        $pendingMinutes = (int) ($metrics->pending_minutes ?? 0);
        $rejectedMinutes = (int) ($metrics->rejected_minutes ?? 0);

        $result = [
            'filters' => compact('from', 'to') + $filters,
            'by_branch' => $byBranch,
            'total_working_minutes' => $totalWorkingMinutes,
            'approved_combined_minutes' => $approvedCombined,
            'pending_minutes' => $pendingMinutes,
            'rejected_minutes' => $rejectedMinutes,
            'scheduled_minutes' => $totalScheduledMinutes,
            'late_count' => $lateCount,
            'late_minutes' => $lateMinutes,
            'missing_checkout_count' => $missingCheckoutCount,
            'attendance_count' => $attendanceCount,
        ];

        if ($includeEntries) {
            $result['entries'] = $this->filteredEntriesQuery($filters)
                ->with(['user', 'branch'])
                ->orderBy('work_date')
                ->get();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredEntriesQuery(array $filters): Builder
    {
        return TimesheetEntry::query()
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['late_only'] ?? false, fn (Builder $q) => $q->where('is_late', true))
            ->when($filters['missing_checkout'] ?? false, fn (Builder $q) => $q->whereNull('ends_at'))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('work_date', '<=', $to));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredShiftsQuery(array $filters): Builder
    {
        return Shift::query()
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('work_date', '<=', $to));
    }

    public function employeeHourTotals(int $userId, ?Carbon $reference = null): array
    {
        $ref = $reference ? $reference->copy() : now(BranchTime::DEFAULT_TIMEZONE);

        $dayStart = $ref->copy()->startOfDay()->toDateString();
        $dayEndExclusive = $ref->copy()->startOfDay()->addDay()->toDateString();

        $weekStartCarbon = BranchTime::weekStart($ref);
        $weekStart = $weekStartCarbon->toDateString();
        $weekEndExclusive = BranchTime::weekEnd($weekStartCarbon)->copy()->startOfDay()->addDay()->toDateString();

        $monthStart = $ref->copy()->startOfMonth()->toDateString();
        $monthEndExclusive = $ref->copy()->endOfMonth()->copy()->startOfDay()->addDay()->toDateString();

        $overallStart = min($dayStart, $weekStart, $monthStart);
        $overallEndExclusive = max($dayEndExclusive, $weekEndExclusive, $monthEndExclusive);

        $row = TimesheetEntry::query()
            ->where('user_id', $userId)
            ->where('status', TimesheetStatus::Approved)
            ->where('work_date', '>=', $overallStart)
            ->where('work_date', '<', $overallEndExclusive)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN work_date >= ? AND work_date < ? THEN total_minutes ELSE 0 END), 0) AS daily_approved_minutes,
                COALESCE(SUM(CASE WHEN work_date >= ? AND work_date < ? THEN total_minutes ELSE 0 END), 0) AS weekly_approved_minutes,
                COALESCE(SUM(CASE WHEN work_date >= ? AND work_date < ? THEN total_minutes ELSE 0 END), 0) AS monthly_approved_minutes
            ", [
                $dayStart, $dayEndExclusive,
                $weekStart, $weekEndExclusive,
                $monthStart, $monthEndExclusive,
            ])
            ->first();

        return [
            'daily_approved_minutes' => (int) ($row?->daily_approved_minutes ?? 0),
            'weekly_approved_minutes' => (int) ($row?->weekly_approved_minutes ?? 0),
            'monthly_approved_minutes' => (int) ($row?->monthly_approved_minutes ?? 0),
        ];
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     */
    private function shiftMinutes(Collection $shifts): int
    {
        return (int) $shifts->sum(fn (Shift $s) => $s->starts_at->diffInMinutes($s->ends_at));
    }
}

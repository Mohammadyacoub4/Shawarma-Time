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
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->toDateString() : null;
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->toDateString() : null;

        $entries = $this->filteredEntriesQuery($filters)->with(['user', 'branch'])->orderBy('work_date')->get();
        $shifts = $this->filteredShiftsQuery($filters)->with(['user', 'branch'])->get();

        $byBranch = [];
        foreach (Branch::orderBy('name')->get() as $branch) {
            $branchEntries = $entries->where('branch_id', $branch->id);
            $byBranch[$branch->id] = [
                'branch' => $branch,
                'approved_minutes' => $branchEntries->where('status', TimesheetStatus::Approved)->sum('total_minutes'),
                'pending_minutes' => $branchEntries->where('status', TimesheetStatus::PendingApproval)->sum('total_minutes'),
                'rejected_minutes' => $branchEntries->where('status', TimesheetStatus::Rejected)->sum('total_minutes'),
                'scheduled_minutes' => $this->shiftMinutes($shifts->where('branch_id', $branch->id)),
            ];
        }

        $approvedCombined = $entries->where('status', TimesheetStatus::Approved)->sum('total_minutes');
        $totalWorkingMinutes = $entries->sum('total_minutes');
        $lateCount = $entries->where('is_late', true)->count();
        $lateMinutes = (int) $entries->where('is_late', true)->sum('late_minutes');
        $missingCheckoutCount = $entries->whereNull('ends_at')->count();

        return [
            'filters' => compact('from', 'to') + $filters,
            'entries' => $entries,
            'by_branch' => $byBranch,
            'total_working_minutes' => $totalWorkingMinutes,
            'approved_combined_minutes' => $approvedCombined,
            'pending_minutes' => $entries->where('status', TimesheetStatus::PendingApproval)->sum('total_minutes'),
            'rejected_minutes' => $entries->where('status', TimesheetStatus::Rejected)->sum('total_minutes'),
            'scheduled_minutes' => $this->shiftMinutes($shifts),
            'late_count' => $lateCount,
            'late_minutes' => $lateMinutes,
            'missing_checkout_count' => $missingCheckoutCount,
            'attendance_count' => $entries->count(),
        ];
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
        $ref = $reference ?? now(BranchTime::DEFAULT_TIMEZONE);
        $dayStart = $ref->copy()->startOfDay()->toDateString();
        $weekStart = BranchTime::weekStart($ref)->toDateString();
        $weekEnd = BranchTime::weekEnd(BranchTime::weekStart($ref))->toDateString();
        $monthStart = $ref->copy()->startOfMonth()->toDateString();
        $monthEnd = $ref->copy()->endOfMonth()->toDateString();

        $approved = fn (string $from, string $to) => (int) TimesheetEntry::query()
            ->where('user_id', $userId)
            ->where('status', TimesheetStatus::Approved)
            ->whereBetween('work_date', [$from, $to])
            ->sum('total_minutes');

        return [
            'daily_approved_minutes' => $approved($dayStart, $dayStart),
            'weekly_approved_minutes' => $approved($weekStart, $weekEnd),
            'monthly_approved_minutes' => $approved($monthStart, $monthEnd),
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

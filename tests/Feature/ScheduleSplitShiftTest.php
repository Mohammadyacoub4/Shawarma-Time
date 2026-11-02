<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\ScheduleService;
use App\Support\BranchTime;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ScheduleSplitShiftTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $richmond;

    protected Branch $vancouver;

    protected User $admin;

    protected ScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BranchSeeder::class);
        $this->richmond = Branch::where('slug', 'richmond')->firstOrFail();
        $this->vancouver = Branch::where('slug', 'vancouver')->firstOrFail();
        $this->admin = User::factory()->admin()->create();
        $this->service = app(ScheduleService::class);
    }

    private function makeEmployee(array $branchIds = []): User
    {
        $employee = User::factory()->employee()->create();
        if (! empty($branchIds)) {
            $employee->branches()->sync($branchIds);
        }

        return $employee;
    }

    /**
     * Requirement 1 & 6: Copying a previous week must preserve every split shift on the same day.
     */
    public function test_copy_previous_week_preserves_two_split_shifts_on_same_day(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $targetMonday = now($tz)->startOfWeek()->addWeeks(2)->toDateString();
        $prevMonday = Carbon::parse($targetMonday, $tz)->subDays(7)->toDateString();

        // Previous week: create 2 split shifts on Monday
        $shift1 = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'grace_minutes' => 15,
            'notes' => 'Morning shift',
        ]);

        $shift2 = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '16:00',
            'end_time' => '20:00',
            'grace_minutes' => 20,
            'notes' => 'Evening shift',
        ]);

        $this->assertSame(2, Shift::where('branch_id', $this->richmond->id)->where('user_id', $employee->id)->whereDate('work_date', $prevMonday)->count());

        // Copy previous week to target week
        $copiedCount = $this->service->copyPreviousWeek($this->richmond, $targetMonday, $this->admin);

        $this->assertSame(2, $copiedCount);

        // Assert target week has both split shifts
        $targetShifts = Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $targetMonday)
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(2, $targetShifts);

        $this->assertSame('08:00', $targetShifts[0]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('12:00', $targetShifts[0]->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame('Morning shift', $targetShifts[0]->notes);
        $this->assertSame(15, $targetShifts[0]->grace_minutes);

        $this->assertSame('16:00', $targetShifts[1]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('20:00', $targetShifts[1]->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame('Evening shift', $targetShifts[1]->notes);
        $this->assertSame(20, $targetShifts[1]->grace_minutes);
    }

    /**
     * Requirement 2 & 3: Batch-add a second non-overlapping shift on the same date while preserving the first.
     */
    public function test_batch_upsert_shifts_adds_second_non_overlapping_shift_on_same_day(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);
        $monday = now($tz)->startOfWeek()->addWeek()->toDateString();

        // 1. Employee already has a morning shift on Monday
        $initialShift = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $monday,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'grace_minutes' => 15,
            'notes' => 'Existing morning shift',
        ]);

        $initialId = $initialShift->id;

        // 2. Admin uses batch schedule to assign evening shifts across Monday
        $saved = $this->service->batchUpsertShifts([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'days' => [$monday],
            'start_time' => '17:00',
            'end_time' => '21:00',
            'grace_minutes' => 15,
            'notes' => 'New evening shift',
        ]);

        $this->assertCount(1, $saved);

        // 3. Verify BOTH shifts exist on Monday
        $allMondayShifts = Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $monday)
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(2, $allMondayShifts);

        // First shift is completely intact with original ID and times
        $this->assertSame($initialId, $allMondayShifts[0]->id);
        $this->assertSame('08:00', $allMondayShifts[0]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('12:00', $allMondayShifts[0]->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame('Existing morning shift', $allMondayShifts[0]->notes);

        // Second shift is created
        $this->assertNotSame($initialId, $allMondayShifts[1]->id);
        $this->assertSame('17:00', $allMondayShifts[1]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('21:00', $allMondayShifts[1]->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame('New evening shift', $allMondayShifts[1]->notes);
    }

    /**
     * Requirement 4 & 5: Reject overlapping shifts without modifying or overwriting original shifts.
     */
    public function test_batch_upsert_shifts_rejects_overlapping_shift_without_modifying_original(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);
        $monday = now($tz)->startOfWeek()->addWeek()->toDateString();

        // Original shift: 09:00 - 15:00
        $original = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $monday,
            'start_time' => '09:00',
            'end_time' => '15:00',
            'grace_minutes' => 25,
            'notes' => 'Do not overwrite me',
            'internal_notes' => 'Confidential note',
        ]);

        $originalId = $original->id;

        // Try batch-inserting an overlapping shift: 12:00 - 18:00
        $exceptionThrown = false;
        try {
            $this->service->batchUpsertShifts([
                'branch_id' => $this->richmond->id,
                'user_id' => $employee->id,
                'days' => [$monday],
                'start_time' => '12:00',
                'end_time' => '18:00',
            ]);
        } catch (InvalidArgumentException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('overlaps another shift', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected InvalidArgumentException for overlapping shift was not thrown.');

        // Original shift must be completely unmodified
        $original->refresh();
        $this->assertSame($originalId, $original->id);
        $this->assertSame('09:00', $original->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('15:00', $original->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame(25, $original->grace_minutes);
        $this->assertSame('Do not overwrite me', $original->notes);
        $this->assertSame('Confidential note', $original->internal_notes);

        $this->assertSame(1, Shift::where('user_id', $employee->id)->whereDate('work_date', $monday)->count());
    }

    /**
     * Requirement: Multiple shifts for the same employee across different branches on the same day.
     */
    public function test_multiple_shifts_across_different_branches_on_same_day(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id, $this->vancouver->id]);
        $tzRichmond = BranchTime::timezoneFor($this->richmond);
        $tzVancouver = BranchTime::timezoneFor($this->vancouver);
        $monday = now($tzRichmond)->startOfWeek()->addWeek()->toDateString();

        // 1. Shift at Richmond: 08:00 - 12:00
        $shiftRichmond = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $monday,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'notes' => 'Richmond morning',
        ]);

        // 2. Shift at Vancouver: 13:00 - 17:00 (via batchUpsertShifts)
        $this->service->batchUpsertShifts([
            'branch_id' => $this->vancouver->id,
            'user_id' => $employee->id,
            'days' => [$monday],
            'start_time' => '13:00',
            'end_time' => '17:00',
            'notes' => 'Vancouver afternoon',
        ]);

        // 3. Second Shift at Vancouver: 18:00 - 22:00 (via batchUpsertShifts)
        $this->service->batchUpsertShifts([
            'branch_id' => $this->vancouver->id,
            'user_id' => $employee->id,
            'days' => [$monday],
            'start_time' => '18:00',
            'end_time' => '22:00',
            'notes' => 'Vancouver evening',
        ]);

        // Total shifts for employee on Monday must be 3
        $allShifts = Shift::where('user_id', $employee->id)
            ->whereDate('work_date', $monday)
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(3, $allShifts);
        $this->assertSame($this->richmond->id, $allShifts[0]->branch_id);
        $this->assertSame('08:00', $allShifts[0]->starts_at->timezone($tzRichmond)->format('H:i'));

        $this->assertSame($this->vancouver->id, $allShifts[1]->branch_id);
        $this->assertSame('13:00', $allShifts[1]->starts_at->timezone($tzVancouver)->format('H:i'));

        $this->assertSame($this->vancouver->id, $allShifts[2]->branch_id);
        $this->assertSame('18:00', $allShifts[2]->starts_at->timezone($tzVancouver)->format('H:i'));

        // Attempt cross-branch overlapping shift: Richmond 16:00 - 20:00 overlaps Vancouver's shifts
        $this->expectException(InvalidArgumentException::class);
        $this->service->batchUpsertShifts([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'days' => [$monday],
            'start_time' => '16:00',
            'end_time' => '20:00',
        ]);
    }

    /**
     * Requirement 7: Repeating the copy must not create duplicate shifts (Idempotency).
     */
    public function test_copy_previous_week_is_idempotent_on_repeated_runs(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $targetMonday = now($tz)->startOfWeek()->addWeeks(3)->toDateString();
        $prevMonday = Carbon::parse($targetMonday, $tz)->subDays(7)->toDateString();
        $prevTuesday = Carbon::parse($prevMonday, $tz)->addDay()->toDateString();

        // Previous week: 2 split shifts on Monday, 1 shift on Tuesday
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'notes' => 'Mon Morning',
        ]);
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '16:00',
            'end_time' => '20:00',
            'notes' => 'Mon Evening',
        ]);
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevTuesday,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'notes' => 'Tue Full',
        ]);

        // First copy
        $count1 = $this->service->copyPreviousWeek($this->richmond, $targetMonday, $this->admin);
        $this->assertSame(3, $count1);

        $targetShiftsRun1 = Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereBetween('work_date', [$targetMonday, Carbon::parse($targetMonday, $tz)->addDays(6)->toDateString()])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $targetShiftsRun1);
        $idsRun1 = $targetShiftsRun1->pluck('id')->all();

        // Second copy (repeated)
        $count2 = $this->service->copyPreviousWeek($this->richmond, $targetMonday, $this->admin);
        $this->assertSame(3, $count2);

        $targetShiftsRun2 = Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereBetween('work_date', [$targetMonday, Carbon::parse($targetMonday, $tz)->addDays(6)->toDateString()])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $targetShiftsRun2);
        $idsRun2 = $targetShiftsRun2->pluck('id')->all();

        // The IDs must be identical: updated in-place without duplicate shift rows
        $this->assertSame($idsRun1, $idsRun2);
    }

    /**
     * Requirement 8: If a target shift conflicts with a copied shift, reject the operation and roll back
     * the transaction completely without partial copying or modification.
     */
    public function test_copy_previous_week_rolls_back_completely_on_target_conflict(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $targetMonday = now($tz)->startOfWeek()->addWeeks(4)->toDateString();
        $targetWednesday = Carbon::parse($targetMonday, $tz)->addDays(2)->toDateString();

        $prevMonday = Carbon::parse($targetMonday, $tz)->subDays(7)->toDateString();
        $prevTuesday = Carbon::parse($prevMonday, $tz)->addDay()->toDateString();
        $prevWednesday = Carbon::parse($prevMonday, $tz)->addDays(2)->toDateString();

        // Previous week: shifts on Mon, Tue, Wed
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevTuesday,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevWednesday,
            'start_time' => '10:00',
            'end_time' => '16:00',
        ]);

        // Target week already has a pre-existing shift on Wednesday from 12:00 - 18:00 (conflicts with 10:00 - 16:00)
        $preExistingTargetShift = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $targetWednesday,
            'start_time' => '12:00',
            'end_time' => '18:00',
            'notes' => 'Existing target shift',
        ]);

        $targetWeekEnd = Carbon::parse($targetMonday, $tz)->addDays(6)->toDateString();

        $initialTargetCount = Shift::where('branch_id', $this->richmond->id)
            ->whereBetween('work_date', [$targetMonday, $targetWeekEnd])
            ->count();
        $this->assertSame(1, $initialTargetCount);

        // Run copyPreviousWeek - should throw InvalidArgumentException due to Wednesday conflict
        $exceptionThrown = false;
        try {
            $this->service->copyPreviousWeek($this->richmond, $targetMonday, $this->admin);
        } catch (InvalidArgumentException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('overlaps another shift', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected InvalidArgumentException was not thrown on copy conflict.');

        // Verify COMPLETE rollback: target week must still have exactly 1 shift (Monday & Tuesday were not committed)
        $finalTargetShifts = Shift::where('branch_id', $this->richmond->id)
            ->whereBetween('work_date', [$targetMonday, $targetWeekEnd])
            ->get();

        $this->assertCount(1, $finalTargetShifts);
        $this->assertSame($preExistingTargetShift->id, $finalTargetShifts[0]->id);
        $this->assertSame('12:00', $finalTargetShifts[0]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('18:00', $finalTargetShifts[0]->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame('Existing target shift', $finalTargetShifts[0]->notes);
    }

    /**
     * Requirement 9: Preserve existing shift ID and linked timesheet data.
     */
    public function test_preserve_existing_shift_id_and_linked_timesheet_data(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);
        $monday = now($tz)->startOfWeek()->addWeek()->toDateString();

        // Create shift
        $shift = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $monday,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'notes' => 'Initial notes',
        ]);

        // Create a timesheet entry linked to this shift
        $timesheet = TimesheetEntry::factory()->create([
            'user_id' => $employee->id,
            'branch_id' => $this->richmond->id,
            'shift_id' => $shift->id,
            'work_date' => $monday,
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'total_minutes' => 480,
            'status' => TimesheetStatus::Approved,
        ]);

        // 1. Run batchUpsertShifts with exact same start and end time (updating notes)
        $this->service->batchUpsertShifts([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'days' => [$monday],
            'start_time' => '09:00',
            'end_time' => '17:00',
            'notes' => 'Updated shift notes',
        ]);

        // The shift ID must remain identical
        $shift->refresh();
        $this->assertSame('Updated shift notes', $shift->notes);

        // The timesheet entry must remain linked to the exact same shift
        $timesheet->refresh();
        $this->assertSame($shift->id, $timesheet->shift_id);
        $this->assertSame(TimesheetStatus::Approved, $timesheet->status);

        // 2. Add an additional non-overlapping shift on the same day (18:00 - 22:00)
        $this->service->batchUpsertShifts([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'days' => [$monday],
            'start_time' => '18:00',
            'end_time' => '22:00',
            'notes' => 'Second shift',
        ]);

        // Assert the original shift and timesheet link are unaffected
        $timesheet->refresh();
        $this->assertSame($shift->id, $timesheet->shift_id);

        $shiftsOnMonday = Shift::where('user_id', $employee->id)->whereDate('work_date', $monday)->get();
        $this->assertCount(2, $shiftsOnMonday);
        $this->assertTrue($shiftsOnMonday->contains('id', $shift->id));
    }

    /**
     * Requirement: Verify overnight shifts and branch-local date/time conversion.
     */
    public function test_overnight_shifts_and_branch_local_conversion(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $targetMonday = now($tz)->startOfWeek()->addWeeks(5)->toDateString();
        $prevMonday = Carbon::parse($targetMonday, $tz)->subDays(7)->toDateString();

        // 1. In previous week, create an overnight shift: Monday 22:00 to Tuesday 06:00
        $overnightShift = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '22:00',
            'end_time' => '06:00',
            'notes' => 'Overnight graveyard shift',
        ]);

        // 2. Add a split daytime shift on Monday: 08:00 - 14:00 (via batchUpsertShifts)
        $this->service->batchUpsertShifts([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'days' => [$prevMonday],
            'start_time' => '08:00',
            'end_time' => '14:00',
            'notes' => 'Daytime prep shift',
        ]);

        // Verify previous week has both shifts on Monday
        $prevShifts = Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $prevMonday)
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(2, $prevShifts);

        // Daytime shift
        $this->assertSame('08:00', $prevShifts[0]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('14:00', $prevShifts[0]->ends_at->timezone($tz)->format('H:i'));

        // Overnight shift: starts Monday 22:00, ends Tuesday 06:00
        $this->assertSame('22:00', $prevShifts[1]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('06:00', $prevShifts[1]->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame(
            Carbon::parse($prevMonday, $tz)->addDay()->toDateString(),
            $prevShifts[1]->ends_at->timezone($tz)->toDateString()
        );

        // 3. Copy to target week
        $copied = $this->service->copyPreviousWeek($this->richmond, $targetMonday, $this->admin);
        $this->assertSame(2, $copied);

        $targetShifts = Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $targetMonday)
            ->orderBy('starts_at')
            ->get();

        $this->assertCount(2, $targetShifts);

        $this->assertSame('08:00', $targetShifts[0]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('14:00', $targetShifts[0]->ends_at->timezone($tz)->format('H:i'));

        $this->assertSame('22:00', $targetShifts[1]->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('06:00', $targetShifts[1]->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame(
            Carbon::parse($targetMonday, $tz)->addDay()->toDateString(),
            $targetShifts[1]->ends_at->timezone($tz)->toDateString()
        );

        // 4. Test repeated copy on overnight shifts (idempotency)
        $copiedAgain = $this->service->copyPreviousWeek($this->richmond, $targetMonday, $this->admin);
        $this->assertSame(2, $copiedAgain);

        $this->assertSame(2, Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $targetMonday)
            ->count());
    }

    /**
     * HTTP controller test: copy previous week via admin route preserves split shifts.
     */
    public function test_admin_controller_copy_previous_week_preserves_split_shifts_via_http(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $targetMonday = now($tz)->startOfWeek()->addWeeks(6)->toDateString();
        $prevMonday = Carbon::parse($targetMonday, $tz)->subDays(7)->toDateString();

        // 2 split shifts in previous week
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '07:00',
            'end_time' => '11:00',
        ]);
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '15:00',
            'end_time' => '19:00',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.schedules.copy-previous'), [
            'branch_id' => $this->richmond->id,
            'week_start' => $targetMonday,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $targetShifts = Shift::where('branch_id', $this->richmond->id)
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $targetMonday)
            ->get();

        $this->assertCount(2, $targetShifts);
    }

    /**
     * HTTP controller test: copy previous week rolls back cleanly and returns validation error on conflict.
     */
    public function test_admin_controller_copy_previous_week_returns_error_on_conflict_via_http(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $targetMonday = now($tz)->startOfWeek()->addWeeks(7)->toDateString();
        $prevMonday = Carbon::parse($targetMonday, $tz)->subDays(7)->toDateString();

        // Previous week shift: 09:00 - 17:00
        $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $prevMonday,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        // Target week conflicting shift: 12:00 - 20:00
        $existing = $this->service->upsertShift([
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => $targetMonday,
            'start_time' => '12:00',
            'end_time' => '20:00',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.schedules.copy-previous'), [
            'branch_id' => $this->richmond->id,
            'week_start' => $targetMonday,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['shift']);

        // Assert target week still only has the 1 pre-existing shift
        $targetShifts = Shift::where('branch_id', $this->richmond->id)
            ->whereDate('work_date', $targetMonday)
            ->get();

        $this->assertCount(1, $targetShifts);
        $this->assertSame($existing->id, $targetShifts[0]->id);
    }
}

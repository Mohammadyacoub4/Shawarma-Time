<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Enums\UserRole;
use App\Exports\TimesheetReportExport;
use App\Models\Branch;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\ReportService;
use App\Services\TimesheetService;
use App\Services\WorkPeriodCalculator;
use App\Support\BranchTime;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FixedUtcMinusSevenTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $employee;
    private User $admin;
    private WorkPeriodCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create([
            'name' => 'Shawarma Time Test',
            'timezone' => 'Etc/GMT+7',
        ]);

        $this->employee = User::factory()->create([
            'role' => UserRole::Employee,
            'is_active' => true,
        ]);
        $this->employee->branches()->attach($this->branch->id);

        $this->admin = User::factory()->admin()->create();

        $this->calculator = app(WorkPeriodCalculator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 1. Default timezone identifier and mapping to UTC-07:00 */
    public function test_default_business_timezone_is_etc_gmt_plus_seven_representing_utc_minus_seven(): void
    {
        $this->assertSame('Etc/GMT+7', BranchTime::DEFAULT_TIMEZONE);
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor(null));
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($this->branch));
    }

    /** 1b. Strict enforcement of Etc/GMT+7 across all branch states, legacy values, and unauthorized timezones */
    public function test_timezone_for_strictly_enforces_etc_gmt_plus_seven_across_all_cases(): void
    {
        // 1. Null branch
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor(null));

        // 2. Branch with empty or null timezone
        $emptyBranch = new Branch(['timezone' => '']);
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($emptyBranch));

        $nullTzBranch = new Branch(['timezone' => null]);
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($nullTzBranch));

        $whitespaceBranch = new Branch(['timezone' => '   ']);
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($whitespaceBranch));

        // 3. Branch with authorized Etc/GMT+7
        $standardBranch = new Branch(['timezone' => 'Etc/GMT+7']);
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($standardBranch));

        // 4. Legacy branches: Canada/Pacific and America/Vancouver
        $legacyBranch = new Branch(['timezone' => 'Canada/Pacific']);
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($legacyBranch));

        $legacyVancouver = new Branch(['timezone' => 'America/Vancouver']);
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($legacyVancouver));

        // 5. Invalid and unauthorized timezones log warning and safely return Etc/GMT+7
        Log::spy();

        $invalidBranch = new Branch(['timezone' => 'Invalid/Timezone']);
        $invalidBranch->id = 201;
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($invalidBranch));

        $ammanBranch = new Branch(['timezone' => 'Asia/Amman']);
        $ammanBranch->id = 202;
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($ammanBranch));

        $nyBranch = new Branch(['timezone' => 'America/New_York']);
        $nyBranch->id = 203;
        $this->assertSame('Etc/GMT+7', BranchTime::timezoneFor($nyBranch));

        Log::shouldHaveReceived('warning')->with(
            'Unexpected branch timezone encountered; falling back to Etc/GMT+7.',
            ['branch_id' => 201, 'timezone' => 'Invalid/Timezone']
        )->once();

        Log::shouldHaveReceived('warning')->with(
            'Unexpected branch timezone encountered; falling back to Etc/GMT+7.',
            ['branch_id' => 202, 'timezone' => 'Asia/Amman']
        )->once();

        Log::shouldHaveReceived('warning')->with(
            'Unexpected branch timezone encountered; falling back to Etc/GMT+7.',
            ['branch_id' => 203, 'timezone' => 'America/New_York']
        )->once();
    }

    /** 2. Fixed offset in both winter and summer with zero DST transitions */
    public function test_timezone_offset_is_always_minus_seven_hours_in_winter_and_summer(): void
    {
        $winterDate = Carbon::parse('2026-01-15 12:00:00', 'Etc/GMT+7');
        $this->assertSame(-420, $winterDate->utcOffset()); // -420 minutes = -7 hours
        $this->assertSame(-25200, (new DateTimeZone('Etc/GMT+7'))->getOffset(new DateTime('2026-01-15 12:00:00', new DateTimeZone('UTC'))));

        $summerDate = Carbon::parse('2026-07-15 12:00:00', 'Etc/GMT+7');
        $this->assertSame(-420, $summerDate->utcOffset());
        $this->assertSame(-25200, (new DateTimeZone('Etc/GMT+7'))->getOffset(new DateTime('2026-07-15 12:00:00', new DateTimeZone('UTC'))));

        // Zero DST transitions across the full calendar year 2026
        $tz = new DateTimeZone('Etc/GMT+7');
        $transitions = $tz->getTransitions(strtotime('2026-01-01 00:00:00 UTC'), strtotime('2026-12-31 23:59:59 UTC'));
        $this->assertCount(1, $transitions); // 1 = static baseline, no change events
        $this->assertSame(-25200, $transitions[0]['offset']);
        $this->assertFalse((bool) $transitions[0]['isdst']);
    }

    /** 3. Inverted POSIX sign guard: ensure Etc/GMT-7 (+07:00) is never used */
    public function test_guard_against_accidental_etc_gmt_minus_seven_positive_offset(): void
    {
        $businessTz = BranchTime::DEFAULT_TIMEZONE;
        $this->assertNotSame('Etc/GMT-7', $businessTz);

        $offsetSeconds = (new DateTimeZone($businessTz))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
        $this->assertSame(-25200, $offsetSeconds); // Must be strictly -25200, NEVER +25200
    }

    /** 4. Amman (UTC+03:00) is exactly 10 hours ahead of application business time (UTC-07:00) */
    public function test_amman_is_exactly_ten_hours_ahead_of_application_business_timezone(): void
    {
        // Example from requirements: 2026-11-03 02:45 in Amman equals 2026-11-02 16:45 in application timezone
        $ammanTime = Carbon::parse('2026-11-03 02:45:00', 'Asia/Amman');
        $appTime = $ammanTime->copy()->timezone('Etc/GMT+7');

        $this->assertSame('2026-11-02 16:45:00', $appTime->format('Y-m-d H:i:s'));
        $wallClockDiff = (int) abs(Carbon::parse($ammanTime->format('Y-m-d H:i:s'))->diffInHours(Carbon::parse($appTime->format('Y-m-d H:i:s')), false));
        $this->assertSame(10, $wallClockDiff);
        $this->assertSame(10, (int) (($ammanTime->utcOffset() - $appTime->utcOffset()) / 60));

        // Summer verification: 2026-06-15 14:00 in Amman equals 2026-06-15 04:00 in application timezone
        $ammanSummer = Carbon::parse('2026-06-15 14:00:00', 'Asia/Amman');
        $appSummer = $ammanSummer->copy()->timezone('Etc/GMT+7');

        $this->assertSame('2026-06-15 04:00:00', $appSummer->format('Y-m-d H:i:s'));
        $summerWallClockDiff = (int) abs(Carbon::parse($ammanSummer->format('Y-m-d H:i:s'))->diffInHours(Carbon::parse($appSummer->format('Y-m-d H:i:s')), false));
        $this->assertSame(10, $summerWallClockDiff);
        $this->assertSame(10, (int) (($ammanSummer->utcOffset() - $appSummer->utcOffset()) / 60));
    }

    /** 5. Local wall-clock input converts to UTC for storage and back for display without double conversion */
    public function test_local_wall_clock_input_converts_to_utc_for_storage_and_back_for_display(): void
    {
        $period = $this->calculator->fromStartEnd('2026-11-02', '16:45', '23:45', $this->branch);

        // Local 16:45 (-07:00) = UTC 23:45 (+00:00)
        $this->assertSame('2026-11-02 23:45:00', $period['starts_at']->toDateTimeString());
        // Local 23:45 (-07:00) = UTC 06:45 (+00:00) next day
        $this->assertSame('2026-11-03 06:45:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(420, $period['total_minutes']);
        $this->assertSame('2026-11-02', $period['work_date']);

        $entry = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $period['work_date'],
            'starts_at' => $period['starts_at'],
            'ends_at' => $period['ends_at'],
            'total_minutes' => $period['total_minutes'],
            'status' => TimesheetStatus::Approved,
        ]);

        $entry->refresh();

        // Stored in UTC
        $this->assertSame('2026-11-02 23:45:00', $entry->starts_at->toDateTimeString());
        $this->assertSame('2026-11-03 06:45:00', $entry->ends_at->toDateTimeString());

        // Converted once to business timezone for display
        $tz = BranchTime::timezoneFor($entry->branch);
        $this->assertSame('16:45', $entry->starts_at->timezone($tz)->format('H:i'));
        $this->assertSame('23:45', $entry->ends_at->timezone($tz)->format('H:i'));
        $this->assertSame('4:45 PM', $entry->starts_at->timezone($tz)->format('g:i A'));
    }

    /** 6. Overnight shifts calculate correct elapsed duration crossing midnight */
    public function test_overnight_shift_calculates_correct_elapsed_minutes(): void
    {
        $period = $this->calculator->fromStartEnd('2026-11-02', '22:00', '06:00', $this->branch);

        // Local 22:00 (-07:00) = UTC 05:00 next day
        $this->assertSame('2026-11-03 05:00:00', $period['starts_at']->toDateTimeString());
        // Local 06:00 (-07:00) = UTC 13:00 next day
        $this->assertSame('2026-11-03 13:00:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(480, $period['total_minutes']);
        $this->assertSame('2026-11-02', $period['work_date']);
    }

    /** 7. Sunday evening local week boundaries resolve to the correct Monday-Sunday week */
    public function test_sunday_evening_local_week_boundaries_resolve_to_correct_week(): void
    {
        // Sunday 2026-11-08 20:00:00 in Etc/GMT+7
        // Equivalent in UTC is Monday 2026-11-09 03:00:00
        Carbon::setTestNow(Carbon::parse('2026-11-08 20:00:00', 'Etc/GMT+7'));

        $weekStart = BranchTime::weekStart(null, $this->branch);
        $this->assertSame('2026-11-02', $weekStart->toDateString());

        $weekEnd = BranchTime::weekEnd($weekStart);
        $this->assertSame('2026-11-08', $weekEnd->toDateString());
    }

    /** 8. Branch-null schedule fallback uses fixed business timezone */
    public function test_schedule_controller_branch_null_fallback_uses_fixed_business_timezone(): void
    {
        // Sunday 2026-11-08 21:00:00 in Etc/GMT+7 (Monday 04:00:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-11-08 21:00:00', 'Etc/GMT+7'));

        // Query admin schedules with invalid branch_id so it hits the null-branch fallback
        $response = $this->actingAs($this->admin)->get(route('admin.schedules.index', [
            'branch_id' => 999999,
        ]));

        $response->assertOk();
        // Fallback weekStart must be Monday Nov 2, NOT Monday Nov 9
        $response->assertViewHas('weekStart', '2026-11-02');
        $response->assertViewHas('weekEnd', '2026-11-08');
    }

    /** 9. Report export timestamps use fixed UTC-07:00 */
    public function test_report_export_timestamps_use_fixed_utc_minus_seven(): void
    {
        $entry = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-02',
            'starts_at' => '2026-11-02 23:45:00', // 16:45 local in Etc/GMT+7
            'ends_at' => '2026-11-03 06:45:00',   // 23:45 local in Etc/GMT+7
            'total_minutes' => 420,
            'status' => TimesheetStatus::Approved,
            'submitted_at' => '2026-11-03 07:00:00', // 00:00 local in Etc/GMT+7
        ]);

        $reportService = app(ReportService::class);
        $report = $reportService->build([]);
        $export = new TimesheetReportExport($report, TimesheetEntry::where('id', $entry->id));

        $rows = $export->array();
        $this->assertNotEmpty($rows);

        $row = $rows[0];
        $this->assertSame('2026-11-02', $row[2]); // work_date
        $this->assertSame('2026-11-02 16:45:00', $row[3]); // starts_at in Etc/GMT+7
        $this->assertSame('2026-11-02 23:45:00', $row[4]); // ends_at in Etc/GMT+7
        $this->assertSame('7:00', $row[5]); // duration
        $this->assertSame('2026-11-03 00:00:00', $row[10]); // submitted_at in Etc/GMT+7
    }

    /** 10. DST-specific rejection behavior is no longer applied under fixed UTC-07:00 */
    public function test_dst_specific_times_are_no_longer_rejected(): void
    {
        $service = app(TimesheetService::class);

        // In DST zones, 2026-03-08 02:30:00 was nonexistent. Under Etc/GMT+7, it is 100% valid.
        $entry1 = $service->submit($this->employee, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-03-08',
            'start_time' => '02:30',
            'end_time' => '06:00',
        ]);
        $this->assertNotNull($entry1->id);

        // In DST zones, 2026-11-01 01:30:00 was ambiguous. Under Etc/GMT+7, it is 100% valid.
        $entry2 = $service->submit($this->employee, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '01:30',
            'end_time' => '06:00',
        ]);
        $this->assertNotNull($entry2->id);
    }

    /** 11. Invalid date and time inputs are still strictly rejected */
    public function test_invalid_date_and_time_inputs_are_still_strictly_rejected(): void
    {
        // Invalid leap-day / non-existent day
        $this->expectException(ValidationException::class);
        BranchTime::combineLocalDateAndTime('2026-02-30', '10:00', $this->branch);
    }

    public function test_invalid_hour_is_strictly_rejected(): void
    {
        $this->expectException(ValidationException::class);
        BranchTime::combineLocalDateAndTime('2026-05-10', '25:00', $this->branch);
    }

    public function test_invalid_minute_is_strictly_rejected(): void
    {
        $this->expectException(ValidationException::class);
        BranchTime::combineLocalDateAndTime('2026-05-10', '10:75', $this->branch);
    }
}

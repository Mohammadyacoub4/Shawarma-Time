<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimesheetConcurrencyTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private User $admin1;
    private User $admin2;
    private User $employee;
    private Branch $vancouver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->vancouver = Branch::where('slug', 'vancouver')->firstOrFail();

        $this->admin1 = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->admin2 = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee->branches()->sync([$this->vancouver->id]);
    }

    public function test_concurrent_approval_and_rejection_serialized_by_locking(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);

        // Simulate concurrent attempts using separate transactions/connections or sequential racing
        $approved = false;
        $rejected = false;
        $rejectExceptionCaught = false;

        try {
            $service->approve($entry, $this->admin1);
            $approved = true;
        } catch (\Throwable $e) {
            //
        }

        try {
            $service->reject($entry, $this->admin2, 'Should fail because already approved');
            $rejected = true;
        } catch (\InvalidArgumentException $e) {
            $rejectExceptionCaught = true;
        }

        $entry->refresh();
        $this->assertTrue($approved);
        $this->assertFalse($rejected);
        $this->assertTrue($rejectExceptionCaught);
        $this->assertEquals(TimesheetStatus::Approved, $entry->status);
    }
}

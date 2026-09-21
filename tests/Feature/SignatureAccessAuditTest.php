<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    private function seedCard(int $branchId, User $creator, string $account): Member
    {
        $member = Member::create([
            'account_number' => $account,
            'name' => 'Jane Member',
            'created_by' => $creator->id,
        ]);

        Signature::create([
            'member_id' => $member->id,
            'image_path' => 'signatures/test.jpg',
            'created_by' => $creator->id,
        ]);

        return $member;
    }

    public function test_signature_image_access_writes_audit_log(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('signatures/test.jpg', 'fake-image-bytes');

        $branch = Branch::create(['name' => 'Main', 'location' => 'Downtown']);
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $branch->id]);
        $member = $this->seedCard($branch->id, $staff, '100001');

        $this->actingAs($staff)
            ->get(route('members.signature.show', $member))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $staff->id,
            'action' => 'VIEW_MEMBER',
        ]);
    }

    public function test_member_show_page_does_not_write_activity_log(): void
    {
        $branch = Branch::create(['name' => 'Main', 'location' => 'Downtown']);
        $staff = User::factory()->create(['role' => 'staff', 'branch_id' => $branch->id]);
        $member = $this->seedCard($branch->id, $staff, '100002');

        $this->actingAs($staff)
            ->get(route('members.show', $member))
            ->assertOk();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_cross_branch_signature_access_is_forbidden_and_not_logged(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('signatures/test.jpg', 'fake-image-bytes');

        $branchA = Branch::create(['name' => 'A', 'location' => 'A']);
        $branchB = Branch::create(['name' => 'B', 'location' => 'B']);

        $staffA = User::factory()->create(['role' => 'staff', 'branch_id' => $branchA->id]);
        $staffB = User::factory()->create(['role' => 'staff', 'branch_id' => $branchB->id]);

        $member = $this->seedCard($branchA->id, $staffA, '100003');

        $this->actingAs($staffB)
            ->get(route('members.signature.show', $member))
            ->assertForbidden();

        $this->assertDatabaseCount('activity_logs', 0);
    }
}

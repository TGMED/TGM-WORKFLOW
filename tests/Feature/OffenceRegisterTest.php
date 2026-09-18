<?php

namespace Tests\Feature;

use App\Enums\OffenceSeverity;
use App\Enums\ReportStatus;
use App\Enums\SanctionAction;
use App\Models\Offence;
use App\Models\Policy;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What counts as an offence, and what the policy says follows it.
 */
class OffenceRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'D4',
            'title' => 'Absence without leave',
            'description' => 'Not turning up, and not saying why.',
            'severity' => OffenceSeverity::Serious->value,
            'ladder' => [
                ['occurrence' => 1, 'action' => SanctionAction::WrittenWarning->value, 'notes' => null],
                ['occurrence' => 2, 'action' => SanctionAction::FinalWarning->value, 'notes' => null],
                ['occurrence' => 3, 'action' => SanctionAction::Dismissal->value, 'notes' => null],
            ],
            ...$overrides,
        ];
    }

    public function test_an_offence_is_added_with_its_ladder(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/offences', $this->payload())
            ->assertSessionHasNoErrors();

        $offence = Offence::query()->with('sanctions')->firstOrFail();

        $this->assertSame('D4', $offence->code);
        $this->assertSame(OffenceSeverity::Serious, $offence->severity);
        $this->assertCount(3, $offence->sanctions);
        $this->assertTrue($offence->canEndEmployment());
    }

    public function test_an_offence_can_be_tied_to_a_policy_in_force(): void
    {
        $policy = Policy::factory()->create();

        $this->actingAs($this->admin())
            ->post('/admin/offences', $this->payload(['policy_id' => $policy->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($policy->id, Offence::query()->firstOrFail()->policy_id);
    }

    public function test_a_retired_policy_cannot_be_cited(): void
    {
        $retired = Policy::factory()->retired()->create();

        $this->actingAs($this->admin())
            ->post('/admin/offences', $this->payload(['policy_id' => $retired->id]))
            ->assertSessionHasErrors('policy_id');
    }

    public function test_a_code_is_not_used_twice(): void
    {
        Offence::factory()->create(['code' => 'D4']);

        $this->actingAs($this->admin())
            ->post('/admin/offences', $this->payload())
            ->assertSessionHasErrors('code');
    }

    public function test_editing_rewrites_the_ladder(): void
    {
        $offence = Offence::factory()->withLadder()->create();

        $this->actingAs($this->admin())
            ->put("/admin/offences/{$offence->id}", $this->payload([
                'code' => 'D9',
                'ladder' => [
                    ['occurrence' => 1, 'action' => SanctionAction::Counselling->value, 'notes' => 'A quiet word.'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $offence->refresh()->load('sanctions');

        $this->assertCount(1, $offence->sanctions);
        $this->assertSame(SanctionAction::Counselling, $offence->sanctions->first()->action);
        $this->assertFalse($offence->canEndEmployment());
    }

    public function test_the_ladder_answers_for_an_occurrence_past_its_end(): void
    {
        $offence = Offence::factory()->withLadder()->create()->load('sanctions');

        $this->assertSame(SanctionAction::VerbalWarning, $offence->sanctionFor(1)->action);
        $this->assertSame(SanctionAction::Dismissal, $offence->sanctionFor(3)->action);
        // Past the last rung, the last rung stands.
        $this->assertSame(SanctionAction::Dismissal, $offence->sanctionFor(9)->action);
    }

    public function test_an_offence_with_no_ladder_answers_nothing(): void
    {
        $offence = Offence::factory()->create()->load('sanctions');

        $this->assertNull($offence->sanctionFor(1));
    }

    public function test_an_offence_is_retired_rather_than_deleted(): void
    {
        $offence = Offence::factory()->create();

        $this->actingAs($this->admin())->patch("/admin/offences/{$offence->id}/toggle");

        $this->assertFalse($offence->refresh()->is_active);
        $this->assertDatabaseHas('offences', ['id' => $offence->id, 'deleted_at' => null]);
    }

    public function test_the_reports_desk_can_close_a_case_against_an_offence(): void
    {
        $offence = Offence::factory()->withLadder()->create();
        $report = Report::factory()->create();

        $this->actingAs($this->admin())
            ->put("/admin/reports/{$report->id}", [
                'status' => ReportStatus::Resolved->value,
                'offence_id' => $offence->id,
                'resolution_note' => 'Upheld after a hearing, and a warning issued.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($offence->id, $report->refresh()->offence_id);
    }

    public function test_a_case_cannot_cite_an_offence_off_the_register(): void
    {
        $offence = Offence::factory()->create(['is_active' => false]);
        $report = Report::factory()->create();

        $this->actingAs($this->admin())
            ->put("/admin/reports/{$report->id}", [
                'status' => ReportStatus::Resolved->value,
                'offence_id' => $offence->id,
                'resolution_note' => 'Upheld after a hearing.',
            ])
            ->assertSessionHasErrors('offence_id');
    }

    public function test_staff_cannot_touch_the_register(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get('/admin/offences')->assertForbidden();
        $this->actingAs($staff)->post('/admin/offences', $this->payload())->assertForbidden();
    }
}

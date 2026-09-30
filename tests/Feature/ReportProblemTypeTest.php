<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Problem Type on Create Report.
 *
 * WHAT THIS ADDS: a report now carries a structured category of maintenance
 * concern (Electrical, Plumbing, ...) alongside — not instead of — its title
 * and description. Title names the specific issue, description explains it,
 * problem_type classifies it.
 *
 * WHY TWO COLUMNS. The requirement is simultaneously "restrict the accepted
 * values to the approved list" and "store the user's own words when they pick
 * Other". One column cannot do both: an `in:` rule would reject every custom
 * value, and dropping the rule would turn each typo into its own category.
 * So problem_type holds the approved value (and is therefore groupable and
 * queryable) and problem_type_other holds free text that only ever applies to
 * the single "Other" category.
 *
 * WHY BOTH COLUMNS ARE NULLABLE even though the field is "required". Required
 * is a rule about the Create Report request, not about the table. Reports that
 * predate this change have no category, and three internal creators
 * (PreventiveMaintenanceService, DispatchService, DamageReportService) write
 * maintenance_reports rows without ever touching this form. A NOT NULL column
 * would have broken all of them. This is the same split location already uses:
 * nullable in the schema, required at the HTTP boundary.
 *
 * The vocabulary itself lives in config/maintenance_reports.php, which is the
 * single source read by the backend rule, both card grids, and the detail
 * view. Several tests below assert against that config rather than against a
 * hardcoded list, so adding a category there cannot leave a test asserting the
 * old set.
 */
class ReportProblemTypeTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_problem_type_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // =================================================================
    // 1. Every approved category can actually be used.
    // =================================================================

    /**
     * The task names eight categories; this runs the full create path once per
     * category rather than spot-checking one. Driven from the config file, so
     * it covers exactly the list the application offers — if a category were
     * added to the config but rejected by the validation rule, this fails.
     *
     * @dataProvider approvedProblemTypeProvider
     */
    public function test_a_report_can_be_created_with_each_approved_problem_type(string $problemType): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => $problemType,
            ]))
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        $this->assertSame($problemType, $report->problem_type);
        // Only "Other" carries free text; everything else stores null rather
        // than an empty string, so "has a custom value" is a single null check
        // for every consumer.
        $this->assertNull($report->problem_type_other);
    }

    public static function approvedProblemTypeProvider(): array
    {
        $config = require __DIR__ . '/../../config/maintenance_reports.php';

        $cases = [];
        foreach ($config['problem_types'] as $problemType) {
            // "Other" is exercised by its own tests below, because it is the
            // only category that requires a second field.
            if ($problemType['value'] === $config['problem_type_other_value']) {
                continue;
            }
            $cases[$problemType['value']] = [$problemType['value']];
        }

        return $cases;
    }

    public function test_the_config_offers_exactly_the_eight_approved_categories(): void
    {
        $config = require __DIR__ . '/../../config/maintenance_reports.php';
        $values = array_column($config['problem_types'], 'value');

        // Pinned deliberately. The task says not to add categories beyond
        // these, so a well-meaning addition should have to come here and make
        // that decision explicitly rather than slipping in unnoticed.
        $this->assertSame([
            'Electrical',
            'Plumbing',
            'HVAC / Aircon',
            'Carpentry',
            'Furniture',
            'Grounds',
            'Roofing',
            'Other',
        ], $values);

        // Every category needs an icon, since the card renders one.
        foreach ($config['problem_types'] as $problemType) {
            $this->assertNotSame('', trim((string) $problemType['icon']));
        }
    }

    // =================================================================
    // 2. "Other" — the free-text branch.
    // =================================================================

    public function test_selecting_other_stores_the_user_supplied_problem_type(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Other',
                'problem_type_other' => 'Pest control',
            ]))
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        // The category stays "Other" rather than being replaced by the custom
        // text — that is what keeps the column restricted to the approved
        // vocabulary and still groupable.
        $this->assertSame('Other', $report->problem_type);
        $this->assertSame('Pest control', $report->problem_type_other);
    }

    public function test_the_custom_problem_type_is_trimmed(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Other',
                'problem_type_other' => '   Pest control   ',
            ]))
            ->assertStatus(201);

        $this->assertSame(
            'Pest control',
            DB::table('maintenance_reports')->first()->problem_type_other
        );
    }

    /**
     * A custom value sent alongside a fixed category is discarded, not stored.
     * Otherwise a report could claim to be Electrical while carrying the text
     * "Pest control", and every consumer would have to decide which to trust.
     */
    public function test_a_custom_value_sent_with_a_non_other_category_is_discarded(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Electrical',
                'problem_type_other' => 'Pest control',
            ]))
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        $this->assertSame('Electrical', $report->problem_type);
        $this->assertNull($report->problem_type_other);
    }

    // =================================================================
    // 3. Validation. Every case here POSTs straight to the API with no
    //    browser involved, which is the point: the frontend checks are
    //    convenience, these are the enforcement.
    // =================================================================

    public function test_a_report_without_a_problem_type_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $payload = $this->reportPayload($deptId);
        unset($payload['problem_type']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type'])
            // The exact sentence the task specifies, so the message a user
            // sees is the same whether the browser or the server caught it.
            ->assertJsonPath('errors.problem_type.0', 'Please select a problem type.');

        $this->assertNoReportWasCreated();
    }

    public function test_a_problem_type_outside_the_approved_list_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // The crafted-request case: a value that never appears on any card.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Landscaping',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type']);

        $this->assertNoReportWasCreated();
    }

    public function test_the_approved_list_is_matched_case_sensitively(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // Worth pinning: if 'electrical' were accepted, the column would end
        // up holding two spellings of the same category and any grouping by
        // problem_type would silently split.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'electrical',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type']);

        $this->assertNoReportWasCreated();
    }

    public function test_selecting_other_without_a_custom_value_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Other',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type_other'])
            ->assertJsonPath('errors.problem_type_other.0', 'Please specify the problem type.');

        $this->assertNoReportWasCreated();
    }

    public function test_selecting_other_with_a_blank_custom_value_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // Whitespace is not a specification. Without this, the required_if
        // rule alone would accept '   ' as "provided".
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Other',
                'problem_type_other' => '   ',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type_other']);

        $this->assertNoReportWasCreated();
    }

    public function test_an_overlong_custom_value_is_rejected(): void
    {
        $config = require __DIR__ . '/../../config/maintenance_reports.php';
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        // The rule's limit is read from the same config value the column width
        // and the input's maxlength come from, so a value that passes
        // validation always fits the column.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Other',
                'problem_type_other' => str_repeat('a', $config['problem_type_other_max'] + 1),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type_other']);

        $this->assertNoReportWasCreated();
    }

    // =================================================================
    // 4. The asset-linked create branch gets the same treatment. It is a
    //    different method on the controller and a different service, so
    //    it could easily have been missed.
    // =================================================================

    public function test_an_asset_linked_report_also_stores_the_problem_type(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'HVAC / Aircon',
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
            ]))
            ->assertStatus(201);

        $this->assertSame(
            'HVAC / Aircon',
            DB::table('maintenance_reports')->first()->problem_type
        );
        // The asset branch still does its own job unchanged.
        $this->assertSame(1, DB::table('damage_reports')->count());
    }

    public function test_an_asset_linked_report_without_a_problem_type_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $payload = $this->reportPayload($deptId, [
            'item_id' => $itemId,
            'room_id' => $roomId,
            'severity_level' => 'high',
        ]);
        unset($payload['problem_type']);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type']);

        // Validation runs before anything is written, so neither table gains a
        // half-created record.
        $this->assertNoReportWasCreated();
        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // =================================================================
    // 5. Reading it back.
    // =================================================================

    public function test_the_detail_endpoint_returns_the_problem_type(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, [
            'problem_type' => 'Other',
            'problem_type_other' => 'Pest control',
        ]);

        // This one endpoint feeds both the report detail page and the Edit
        // Report modal's pre-selection, so it has to expose both columns.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/reports/' . $reportId)
            ->assertStatus(200)
            ->assertJsonPath('data.report.problem_type', 'Other')
            ->assertJsonPath('data.report.problem_type_other', 'Pest control');
    }

    // =================================================================
    // 6. Editing. The task requires Edit Report to show and update the
    //    category "according to their existing permissions" — so these
    //    tests check both that it updates AND that the permission rules
    //    are exactly the ones that were already in force.
    // =================================================================

    public function test_an_authorized_user_can_update_the_problem_type(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, ['problem_type' => 'Electrical']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, ['problem_type' => 'Plumbing'])
            ->assertStatus(200);

        $this->assertSame(
            'Plumbing',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('problem_type')
        );
    }

    public function test_updating_to_other_stores_the_custom_value(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, ['problem_type' => 'Electrical']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, [
                'problem_type' => 'Other',
                'problem_type_other' => 'Pest control',
            ])
            ->assertStatus(200);

        $row = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('Other', $row->problem_type);
        $this->assertSame('Pest control', $row->problem_type_other);
    }

    /**
     * The case that makes a shared resolver worth having. Editing away from
     * "Other" must clear the old custom text — leaving it would produce a
     * report categorised Plumbing that still says "Pest control" underneath,
     * and the detail view would have to guess which one is current.
     */
    public function test_updating_away_from_other_clears_the_stale_custom_value(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, [
            'problem_type' => 'Other',
            'problem_type_other' => 'Pest control',
        ]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, ['problem_type' => 'Plumbing'])
            ->assertStatus(200);

        $row = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('Plumbing', $row->problem_type);
        $this->assertNull($row->problem_type_other);
    }

    public function test_updating_to_an_unapproved_problem_type_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, ['problem_type' => 'Electrical']);

        // The allow-list applies on edit too, or a value blocked at creation
        // could simply be introduced a second later by editing.
        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, ['problem_type' => 'Landscaping'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type']);

        $this->assertSame(
            'Electrical',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('problem_type')
        );
    }

    public function test_updating_to_other_without_a_custom_value_is_rejected(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, ['problem_type' => 'Electrical']);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, ['problem_type' => 'Other'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type_other']);

        $this->assertSame(
            'Electrical',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('problem_type')
        );
    }

    /**
     * An edit that does not mention problem_type leaves it alone. This is what
     * makes the field safe to add to an endpoint other callers already use —
     * the rule is 'sometimes' on update, not 'required', so every pre-existing
     * PATCH body remains valid and non-destructive.
     */
    public function test_an_update_that_omits_the_problem_type_leaves_it_untouched(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId, [
            'problem_type' => 'Other',
            'problem_type_other' => 'Pest control',
        ]);

        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, ['title' => 'Renamed report'])
            ->assertStatus(200);

        $row = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('Renamed report', $row->title);
        $this->assertSame('Other', $row->problem_type);
        $this->assertSame('Pest control', $row->problem_type_other);
    }

    // =================================================================
    // 7. RBAC is unchanged. problem_type rides the existing edit gate; it
    //    does not introduce a permission of its own, and it must not
    //    become a way around the ones already there.
    // =================================================================

    public function test_a_user_from_another_department_cannot_change_the_problem_type(): void
    {
        $deptA = $this->seedDepartment();
        $deptB = $this->seedDepartment();
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptA]);
        $outsiderId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptB]);
        $reportId = $this->seedReport($ownerId, $deptA, ['problem_type' => 'Electrical']);

        $this
            ->actingAsSessionUser($outsiderId, 'maintenance_staff')
            ->patchJson('/api/reports/' . $reportId, ['problem_type' => 'Plumbing'])
            ->assertStatus(403);

        $this->assertSame(
            'Electrical',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('problem_type')
        );
    }

    public function test_a_staff_member_cannot_change_the_problem_type_of_someone_elses_report(): void
    {
        $deptId = $this->seedDepartment();
        $ownerId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $reportId = $this->seedReport($ownerId, $deptId, ['problem_type' => 'Electrical']);

        // Same department, but staff may only modify their own reports. That
        // pre-existing rule still decides the outcome — problem_type does not
        // get its own, looser gate.
        $this
            ->actingAsSessionUser($otherStaffId, 'maintenance_staff')
            ->patchJson('/api/reports/' . $reportId, ['problem_type' => 'Plumbing'])
            ->assertStatus(403);

        $this->assertSame(
            'Electrical',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('problem_type')
        );
    }

    public function test_report_creation_permissions_are_unchanged(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'super_admin', 'department_id' => $deptId]);

        // Administrator still cannot submit reports, and a perfectly valid
        // problem type does not change that.
        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/reports', $this->reportPayload($deptId))
            ->assertStatus(403);

        $this->assertNoReportWasCreated();
    }

    // =================================================================
    // 8. Backward compatibility. Reports that predate this field must
    //    stay readable and editable.
    // =================================================================

    public function test_a_legacy_report_without_a_problem_type_is_still_readable(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        // Exactly what every row in the existing table looks like: both new
        // columns null.
        $reportId = $this->seedReport($staffId, $deptId);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/reports/' . $reportId)
            ->assertStatus(200)
            ->assertJsonPath('data.report.problem_type', null);

        // And it still appears in the list, rather than being filtered out by
        // a join or a condition that assumes the field is present.
        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->getJson('/api/reports')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_a_legacy_report_can_be_given_a_problem_type_by_editing_it(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId);

        // This is the intended migration path for existing data: a human who
        // is already looking at the report classifies it. Nothing guesses a
        // category on their behalf.
        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, ['problem_type' => 'Roofing'])
            ->assertStatus(200);

        $this->assertSame(
            'Roofing',
            DB::table('maintenance_reports')->where('report_id', $reportId)->value('problem_type')
        );
    }

    public function test_a_legacy_report_can_still_be_edited_without_supplying_a_problem_type(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $reportId = $this->seedReport($staffId, $deptId);

        // Otherwise every unrelated edit to an old report would be blocked
        // until someone categorised it, which would turn a data improvement
        // into an obstruction.
        $this
            ->actingAsSessionUser($headId, 'maintenance_admin')
            ->patchJson('/api/reports/' . $reportId, ['priority' => 'high'])
            ->assertStatus(200);

        $row = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('high', $row->priority);
        $this->assertNull($row->problem_type);
    }

    // =================================================================
    // 9. The rest of the report is untouched. Problem Type is an
    //    addition, not a replacement — the task is explicit that Title
    //    and Description keep their own distinct jobs.
    // =================================================================

    public function test_the_title_and_description_are_stored_alongside_the_problem_type(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Plumbing',
                'title' => 'Leaking faucet in the science lab',
                'description' => 'Water drips continuously from the cold tap.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
            ])
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        $this->assertSame('Plumbing', $report->problem_type);
        $this->assertSame('Leaking faucet in the science lab', $report->title);
        $this->assertSame('Water drips continuously from the cold tap.', $report->description);
        $this->assertSame('Room 204', $report->location);
        $this->assertSame('high', $report->priority);
        // Creation still enters the workflow at the same point it always did.
        $this->assertSame('submitted', $report->status);
    }

    /**
     * problem_type must not be confused with report_category. They look
     * similar and sit next to each other in the table, but report_category is
     * workflow provenance ('general' vs 'repair_replacement') written by the
     * system, and ReportController::index() already filters on it. Overloading
     * it to carry a trade category would have broken that filter — which is
     * why a new column was added instead of reusing it.
     */
    public function test_the_problem_type_does_not_disturb_the_report_category(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', $this->reportPayload($deptId, [
                'problem_type' => 'Carpentry',
            ]))
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        $this->assertSame('Carpentry', $report->problem_type);
        $this->assertSame('general', $report->report_category);
    }

    // =================================================================
    // 10. Frontend contract. The card selector has specific structural
    //     requirements that a passing backend cannot guarantee.
    // =================================================================

    public function test_the_create_report_page_renders_a_card_for_every_approved_category(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/create-report.php'));
        $config = require base_path('config/maintenance_reports.php');

        // The grid is generated from the config rather than hand-written, so
        // this asserts the loop exists and reads the right list.
        $this->assertStringContainsString('$problemTypes as $problemType', $markup);
        $this->assertStringContainsString(
            "require __DIR__ . '/../../../config/maintenance_reports.php'",
            $markup
        );
        $this->assertStringContainsString('name="problem_type"', $markup);

        foreach ($config['problem_types'] as $problemType) {
            // Each category's icon must exist in the shared registry, or the
            // card renders with an empty space where the icon should be.
            $this->assertStringContainsString(
                "'" . $problemType['icon'] . "'",
                file_get_contents(base_path('public/frontend/includes/icon-paths.php')),
                'Missing icon "' . $problemType['icon'] . '" for ' . $problemType['value']
            );
        }
    }

    /**
     * Regression. The "Other" radio's id is derived by slugging its value, which
     * produces "problem-type-other" — exactly the id the free-text input used to
     * carry. With both present, getElementById() returned the radio (first in
     * document order), so the custom value read back as the literal "Other":
     * the "Please specify the problem type." message could never fire, and the
     * stored custom type would have been "Other" instead of what was typed.
     * Caught by driving the real rendered page, not by the API-level tests,
     * which post JSON and never touch these ids.
     */
    public function test_the_problem_type_other_text_input_does_not_collide_with_the_other_radio(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/create-report.php'));
        $config = require base_path('config/maintenance_reports.php');

        // Reproduce the page's own slug rule for the "Other" category.
        $otherValue = $config['problem_type_other_value'];
        $radioId = 'problem-type-' . trim(
            strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $otherValue)),
            '-'
        );

        // Every id="..." in the page must be unique.
        preg_match_all('/id="([^"]+)"/', $markup, $matches);
        $ids = $matches[1];
        $duplicates = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));

        $this->assertNotContains(
            $radioId,
            $duplicates,
            'The "Other" radio id "' . $radioId . '" is reused by another control.'
        );

        // And the JS must read the free-text box, not the radio.
        $this->assertStringContainsString("getElementById('problem-type-other-value')", $markup);
        $this->assertStringNotContainsString("getElementById('problem-type-other')", $markup);
        $this->assertStringContainsString('for="problem-type-other-value"', $markup);
    }

    /**
     * The radios are clipped rather than display:none, because a hidden input
     * cannot receive focus — the cards would be unreachable by keyboard and
     * the focus ring would never appear.
     */
    public function test_the_problem_type_radios_remain_focusable(): void
    {
        $css = file_get_contents(
            base_path('public/frontend/assets/css/problem-type-selector.css')
        );

        $this->assertStringContainsString('clip: rect(0, 0, 0, 0)', $css);
        $this->assertStringContainsString(':focus-visible + .problem-type-card', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.problem-type-input\s*\{[^}]*display:\s*none/s',
            $css
        );
    }

    /**
     * Regression. Both validation messages are <span>s, and styles.css carries a
     * broad light-theme rule
     * (:root[data-theme-resolved='light'] h1,h2,h3,p,label,span { color:#1e293b })
     * whose specificity beats a bare .problem-type-error class. Without the
     * re-assertion the error text rendered in body navy and did not read as an
     * error. The shared stylesheet is off-limits, so the override lives here.
     */
    public function test_the_problem_type_error_text_keeps_the_danger_colour_in_light_theme(): void
    {
        $css = file_get_contents(
            base_path('public/frontend/assets/css/problem-type-selector.css')
        );

        $this->assertMatchesRegularExpression(
            "/:root\[data-theme-resolved='light'\]\s+\.problem-type-error\s*\{[^}]*color:\s*var\(--danger-color/s",
            $css
        );
    }

    /**
     * "Responsive, no horizontal overflow" is delivered by auto-fit + minmax
     * deriving the column count from the available width. A fixed column count
     * per breakpoint would leave viewports between breakpoints overflowing.
     */
    public function test_the_problem_type_grid_is_fluid_rather_than_breakpoint_driven(): void
    {
        $css = file_get_contents(
            base_path('public/frontend/assets/css/problem-type-selector.css')
        );

        $this->assertStringContainsString('repeat(auto-fit, minmax(', $css);
        $this->assertStringNotContainsString('@media', $css);
    }

    public function test_the_edit_report_modal_renders_the_same_selector(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/reports.php'));

        // Same config file, same component classes — not a second hand-written
        // copy of the option list.
        $this->assertStringContainsString(
            "require __DIR__ . '/../../../config/maintenance_reports.php'",
            $markup
        );
        $this->assertStringContainsString('$problemTypes as $problemType', $markup);
        $this->assertStringContainsString('name="report_edit_problem_type"', $markup);
        $this->assertStringContainsString('problem-type-selector.css', $markup);
    }

    public function test_the_report_detail_page_displays_the_problem_type(): void
    {
        $markup = file_get_contents(
            base_path('public/frontend/pages/maintenance-report-detail.php')
        );

        $this->assertStringContainsString('<dt>Problem Type</dt>', $markup);
        $this->assertStringContainsString('function renderProblemType(report)', $markup);
        // Legacy rows read as "Not specified" rather than blank or broken.
        $this->assertStringContainsString('Not specified', $markup);
    }

    /**
     * The task explicitly says not to add another column to All Reports, which
     * is already dense. This pins that decision so a later change has to make
     * it deliberately.
     */
    public function test_the_all_reports_table_did_not_gain_a_problem_type_column(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/reports.php'));

        $this->assertStringNotContainsString('<th>Problem Type</th>', $markup);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid('', true),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
    }

    private function seedReport(int $createdBy, int $departmentId, array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Existing report',
            'description' => 'Already filed.',
            // Null by default, which is what every row created before this
            // feature looks like.
            'problem_type' => null,
            'problem_type_other' => null,
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $createdBy,
            'department_id' => $departmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }

    /** The minimum valid POST body, plus whatever the test is actually about. */
    private function reportPayload(int $departmentId, array $overrides = []): array
    {
        return array_merge([
            'problem_type' => 'Electrical',
            'title' => 'Broken projector',
            'description' => 'The projector will not power on.',
            'location' => 'Room 204',
            'priority' => 'medium',
            'department_id' => $departmentId,
        ], $overrides);
    }

    private function assertNoReportWasCreated(): void
    {
        $this->assertSame(
            0,
            DB::table('maintenance_reports')->count(),
            'A report was persisted despite an invalid problem type.'
        );
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->addMaintenanceReportAssetColumns();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createDamageReportsTable();
        $this->createDamageReportHistoriesTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createRoomsTable(): void
    {
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    private function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Room ' . uniqid('', true),
            'capacity' => 30,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function addMaintenanceReportAssetColumns(): void
    {
        Schema::table('maintenance_reports', function ($table): void {
            $table->string('report_category', 30)->default('general')->after('description');
            $table->unsignedInteger('item_id')->nullable()->after('report_category');
            $table->unsignedBigInteger('source_dispatch_id')->nullable()->after('item_id');
        });
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    private function createDamageReportsTable(): void
    {
        Schema::create('damage_reports', function ($table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('pending');
            $table->string('image_path')->nullable();
            $table->text('repair_notes')->nullable();
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDamageReportHistoriesTable(): void
    {
        Schema::create('damage_report_histories', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('damage_report_id');
            $table->string('action_type')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta_json')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}

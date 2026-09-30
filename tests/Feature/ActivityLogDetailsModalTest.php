<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 5 — Activity Logs "Details → View" now opens an in-page modal instead
 * of navigating to public/frontend/pages/activity-log-detail.php.
 *
 * The modal is fed from the row data the list endpoint already returned:
 * ActivityLogController::index() applies no ->select() and App\Models\
 * ActivityLog declares neither $hidden nor $visible, so every column the old
 * detail page rendered (entity_type, entity_id, ip_address, user_agent,
 * meta_json) is already in the list payload. That makes the change purely
 * presentational — no new endpoint, no second request, and no new field is
 * exposed to anyone who could not already receive it.
 *
 * These tests pin down three things:
 *   1. the data-flow premise above (if index() ever starts trimming columns,
 *      the modal would silently lose fields — this fails loudly instead);
 *   2. that activity-log authorization is completely unchanged; and
 *   3. the front-end contract, by source assertion, because no browser
 *      automation is available in this environment (see the task report).
 */
class ActivityLogDetailsModalTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('activity_log_details_modal_testing');
        $this->createUsersTable();
        $this->createActivityLogsTable();

        // See ConfiguresIsolatedSqliteConnection::forceLocalTestUrl() for why
        // this is required for HTTP test requests in this environment.
        $this->forceLocalTestUrl();
    }

    private function seedActivityLog(array $overrides = []): int
    {
        return DB::table('activity_logs')->insertGetId(array_merge([
            'user_id' => null,
            'user_role' => 'super_admin',
            'action' => 'UPDATE_ITEM',
            'module' => 'inventory',
            'entity_type' => 'item',
            'entity_id' => 42,
            'details' => 'Updated stock level from 1 to 5',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (TestRunner)',
            'meta_json' => json_encode(['from' => 1, 'to' => 5]),
            'dedupe_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function activityLogPageSource(): string
    {
        $path = base_path('public/frontend/pages/activity-log.php');
        $this->assertFileExists($path, 'The Activity Logs page is missing.');

        return (string) file_get_contents($path);
    }

    // -----------------------------------------------------------------
    // Existing behaviour — the list still loads
    // -----------------------------------------------------------------

    public function test_activity_logs_list_still_loads_for_the_administrator(): void
    {
        $this->seedActivityLog();

        $userId = $this->seedUser(['role' => 'super_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'super_admin')
            ->getJson('/api/activity-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertCount(
            1,
            $response->json('data.logs.data'),
            'The Administrator should still receive the activity log list.'
        );
    }

    // 2026-09-30 — Activity Logs was narrowed to Administrator-only at the
    // user's request; Head Maintenance (maintenance_admin) previously had
    // list/detail access alongside super_admin (see the removed
    // test_activity_logs_list_still_loads_for_both_authorized_roles /
    // test_detail_endpoint_remains_available_to_both_authorized_roles
    // above/below), now retired the same way maintenance_staff already was.
    public function test_head_maintenance_can_no_longer_list_activity_logs(): void
    {
        $this->seedActivityLog();
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson('/api/activity-logs')
            ->assertStatus(403);
    }

    public function test_head_maintenance_can_no_longer_read_a_single_activity_log(): void
    {
        $logId = $this->seedActivityLog();
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_admin')
            ->getJson("/api/activity-logs/{$logId}")
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Data flow — the modal's premise
    // -----------------------------------------------------------------

    public function test_list_row_already_carries_every_field_the_details_modal_renders(): void
    {
        $this->seedActivityLog();
        $userId = $this->seedUser(['role' => 'super_admin']);

        $row = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'super_admin')
            ->getJson('/api/activity-logs')
            ->assertStatus(200)
            ->json('data.logs.data.0');

        // Exactly the field set the standalone detail page rendered, which the
        // modal now reproduces from this same row.
        $this->assertSame('UPDATE_ITEM', $row['action']);
        $this->assertSame('inventory', $row['module']);
        $this->assertSame('super_admin', $row['user_role']);
        $this->assertSame('item', $row['entity_type']);
        $this->assertSame(42, (int) $row['entity_id']);
        $this->assertSame('Updated stock level from 1 to 5', $row['details']);
        $this->assertSame('203.0.113.7', $row['ip_address']);
        $this->assertSame('Mozilla/5.0 (TestRunner)', $row['user_agent']);
        $this->assertSame(['from' => 1, 'to' => 5], $row['meta_json']);
        $this->assertNotEmpty($row['created_at']);

        $this->assertArrayHasKey(
            'id',
            $row,
            'The modal keys its row cache by id; the list payload must expose it.'
        );
    }

    public function test_list_and_detail_endpoints_agree_on_the_detail_fields(): void
    {
        $logId = $this->seedActivityLog();
        $userId = $this->seedUser(['role' => 'super_admin']);

        $fromList = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'super_admin')
            ->getJson('/api/activity-logs')
            ->assertStatus(200)
            ->json('data.logs.data.0');

        $fromShow = $this
            ->actingAsSessionUserWithFlatKeys($userId, 'super_admin')
            ->getJson("/api/activity-logs/{$logId}")
            ->assertStatus(200)
            ->json('data.log');

        foreach (['action', 'module', 'user_role', 'entity_type', 'entity_id', 'details', 'ip_address', 'user_agent', 'meta_json'] as $field) {
            $this->assertSame(
                $fromShow[$field],
                $fromList[$field],
                "Field '{$field}' differs between the list and detail endpoints, so sourcing "
                . 'the modal from the list row would show something different from the '
                . 'standalone detail page.'
            );
        }
    }

    // -----------------------------------------------------------------
    // Authorization — unchanged
    // -----------------------------------------------------------------

    public function test_maintenance_staff_still_cannot_list_activity_logs(): void
    {
        $this->seedActivityLog();
        $userId = $this->seedUser(['role' => 'maintenance_staff']);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_staff')
            ->getJson('/api/activity-logs')
            ->assertStatus(403);
    }

    public function test_maintenance_staff_still_cannot_read_a_single_activity_log(): void
    {
        $logId = $this->seedActivityLog();
        $userId = $this->seedUser(['role' => 'maintenance_staff']);

        $this->actingAsSessionUserWithFlatKeys($userId, 'maintenance_staff')
            ->getJson("/api/activity-logs/{$logId}")
            ->assertStatus(403);
    }

    public function test_guests_cannot_reach_activity_logs(): void
    {
        $logId = $this->seedActivityLog();

        $this->getJson('/api/activity-logs')->assertStatus(401);
        $this->getJson("/api/activity-logs/{$logId}")->assertStatus(401);
    }

    public function test_detail_endpoint_remains_available_to_the_administrator(): void
    {
        $logId = $this->seedActivityLog();

        $userId = $this->seedUser(['role' => 'super_admin']);

        $this->actingAsSessionUserWithFlatKeys($userId, 'super_admin')
            ->getJson("/api/activity-logs/{$logId}")
            ->assertStatus(200)
            ->assertJsonPath('data.log.id', $logId);
    }

    // -----------------------------------------------------------------
    // Front-end contract (source assertions — no browser automation)
    // -----------------------------------------------------------------

    public function test_details_view_control_no_longer_navigates_away(): void
    {
        $source = $this->activityLogPageSource();

        $this->assertStringNotContainsString(
            'href="${ACTIVITY_LOGS_PAGE_BASE}/${log.id}"',
            $source,
            'The Details "View" control must not be a link that navigates off the '
            . 'Activity Logs page.'
        );

        $this->assertStringContainsString(
            'activity-log-view-btn',
            $source,
            'The Details column should render a button that opens the modal.'
        );

        $this->assertStringContainsString(
            'data-activity-log-id',
            $source,
            'The View button must carry the log id the modal looks up.'
        );

        $this->assertStringContainsString(
            'openActivityLogDetailModal(',
            $source,
            'Clicking View must open the details modal.'
        );
    }

    public function test_details_modal_reuses_the_shared_modal_mechanism(): void
    {
        $source = $this->activityLogPageSource();

        // Both classes matter: `modal` is what main.js initializeModals()
        // binds backdrop-click / close-button / Escape / focus-trap to, and
        // `activity-log-modal` keeps the page's styling scoped.
        $this->assertStringContainsString(
            'class="modal activity-log-modal"',
            $source,
            'The modal must carry the generic `modal` class so the shared '
            . 'close/Escape/focus-trap behaviour in main.js applies to it.'
        );

        $this->assertStringContainsString(
            'id="activityLogDetailModal"',
            $source,
            'The modal needs a stable id for UI.toggleModal().'
        );

        $this->assertStringContainsString(
            "UI.toggleModal('activityLogDetailModal', true)",
            $source,
            'Opening should go through the shared UI.toggleModal() helper rather '
            . 'than hand-rolled show/hide logic.'
        );

        $this->assertStringContainsString(
            'modal-close',
            $source,
            'The close button must use the shared .modal-close hook.'
        );
    }

    public function test_details_modal_declares_dialog_semantics(): void
    {
        $source = $this->activityLogPageSource();

        foreach (['role="dialog"', 'aria-modal="true"', 'aria-labelledby="activityLogDetailModalTitle"', 'aria-hidden="true"'] as $attribute) {
            $this->assertStringContainsString(
                $attribute,
                $source,
                "The details modal is missing the `{$attribute}` accessibility attribute."
            );
        }

        $this->assertStringContainsString(
            'Activity Log Details',
            $source,
            'The modal needs its visible title, which aria-labelledby points at.'
        );
    }

    // -----------------------------------------------------------------
    // TASK 5A — the modal must follow the active theme, and no page text
    // may drop below the 12px system size.
    // -----------------------------------------------------------------

    public function test_details_modal_surface_is_theme_aware_rather_than_hardcoded_dark(): void
    {
        $source = $this->activityLogPageSource();

        // The original modal painted itself dark unconditionally, so in light
        // theme it rendered dark-on-dark inside a light page.
        foreach ([
            'color: #ffffff;',
            'color: #cbb9f2;',
            'color: #a78bfa;',
            'color: #f8fafc;',
            'background: rgba(11, 17, 32, 0.6);',
        ] as $hardcoded) {
            $this->assertStringNotContainsString(
                $hardcoded,
                $source,
                "The modal still hardcodes `{$hardcoded}` outside a theme block, so it "
                . 'cannot follow the light theme.'
            );
        }

        $this->assertStringContainsString(
            ":root[data-theme-resolved='dark'] .activity-log-modal",
            $source,
            'Dark theme must be restored through an explicit data-theme-resolved block '
            . 'so the Enterprise Dark appearance is preserved.'
        );

        $this->assertStringContainsString(
            'background: var(--alm-surface);',
            $source,
            'The modal surface must resolve through a theme-aware variable.'
        );

        // The original violet gradient is kept, but only as a dark-theme value:
        // it must appear after the dark selector, never as an unconditional
        // `background:` on the modal content.
        $darkBlockStart = strpos($source, ":root[data-theme-resolved='dark'] .activity-log-modal");
        $gradientAt = strpos($source, 'linear-gradient(180deg, #221634 0%, #171225 100%)');

        $this->assertNotFalse(
            $gradientAt,
            'The Enterprise Dark gradient should be preserved as the dark-theme value.'
        );
        $this->assertGreaterThan(
            $darkBlockStart,
            $gradientAt,
            'The dark gradient must live inside the dark-theme block, not as an '
            . 'unconditional modal background.'
        );
    }

    public function test_no_activity_log_text_is_styled_below_twelve_pixels(): void
    {
        $source = $this->activityLogPageSource();

        preg_match_all('/font-size:\s*([0-9.]+)px/i', $source, $matches);

        $this->assertNotEmpty($matches[1], 'Expected the page to declare font sizes.');

        foreach ($matches[1] as $size) {
            $this->assertGreaterThanOrEqual(
                12.0,
                (float) $size,
                "Found a {$size}px font-size on the Activity Logs page; the system text "
                . 'floor is 12px.'
            );
        }
    }

    /**
     * The standalone detail page is intentionally NOT removed: it is still a
     * valid deep link, the sidebar highlights it as an Activity Logs page, and
     * LegacyLogDirectoryHttpExposureTest asserts its URL stays reachable.
     */
    public function test_standalone_detail_page_and_its_route_remain_intact(): void
    {
        $this->assertFileExists(
            base_path('public/frontend/pages/activity-log-detail.php'),
            'The standalone detail page must remain for existing deep links.'
        );

        $this->assertStringContainsString(
            "Route::get('/activity-logs/{id}'",
            (string) file_get_contents(base_path('routes/web.php')),
            'The activity-logs.show page route must remain registered.'
        );
    }
}

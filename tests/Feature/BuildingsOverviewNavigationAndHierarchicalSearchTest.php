<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 101 — Buildings Overview becomes a sidebar module, and its search
 * becomes a three-level Building -> Floor -> Room hierarchy.
 *
 * Two requirements from the Dean/checker:
 *
 *  1. Buildings Overview must not be presented as a Dashboard shortcut; it is
 *     its own sidebar destination.
 *  2. The search must be progressive and scoped: Floor search is limited to the
 *     selected Building, Room search to the selected Floor of that Building.
 *
 * The UX half of requirement 2 was then corrected: the three controls must all
 * be usable from the OUTER Buildings Overview view, as a persistent cascading
 * filter toolbar. Narrowing to a floor must not require opening a building, and
 * narrowing to a room must not require opening a floor. The tests that used to
 * pin the floor search to the drill-down floor level were rewritten for that
 * corrected requirement — the underlying hierarchy and server-side scoping
 * assertions they protected are all still here, and there are now more of them.
 *
 * What this file pins down:
 *
 *  - The sidebar entry exists exactly once and links to the pre-existing
 *    /buildings-overview route (no duplicate page, no duplicate nav item).
 *  - dashboard.php's pure navigation card is gone, while the three dashboards
 *    that show a real building COUNT keep their stat cards — those display data
 *    rather than merely linking, so they were deliberately left alone.
 *  - All three filters render inside the outer toolbar, and none of the filter
 *    handlers navigates into a drill-down level.
 *  - The scoping is proven against the real API with real rows, not just by
 *    reading markup: floors of another building and rooms of another floor or
 *    another building cannot appear, and a mismatched building/floor pair
 *    yields nothing.
 *  - The parent-change reset rules hold in the page's own JS.
 *
 * RBAC is deliberately NOT re-specified here. BuildingsRbacTest already owns
 * the full TASK 35 matrix (all three roles may view; only super_admin may
 * mutate). The two tests at the bottom assert only that THIS task did not move
 * those boundaries.
 */
class BuildingsOverviewNavigationAndHierarchicalSearchTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private const VIEW_ROLES = ['super_admin', 'maintenance_admin', 'maintenance_staff'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('buildings_overview_hierarchy_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    private function sidebar(): string
    {
        $markup = file_get_contents(base_path('public/frontend/includes/sidebar.php'));
        $this->assertNotFalse($markup, 'sidebar.php could not be read.');

        return $markup;
    }

    private function overviewPage(): string
    {
        $markup = file_get_contents(base_path('public/frontend/pages/buildings-overview.php'));
        $this->assertNotFalse($markup, 'buildings-overview.php could not be read.');

        return $markup;
    }

    /**
     * The slice of the page between the outer toolbar wrapper and the results
     * container. Everything a user can reach WITHOUT drilling into a building or
     * a floor lives in here, because #buildingToolbarWrap is only ever revealed
     * by setBuildingChromeVisibility(true), which only loadBuildings() calls.
     */
    private function outerFilterToolbar(): string
    {
        $markup = $this->overviewPage();

        $start = strpos($markup, 'id="buildingToolbarWrap"');
        $this->assertNotFalse($start, 'The outer Buildings Overview toolbar must exist.');

        $end = strpos($markup, 'id="overviewContainer"', $start);
        $this->assertNotFalse($end, 'The results container must follow the toolbar.');

        return substr($markup, $start, $end - $start);
    }

    // ------------------------------------------------------------------
    // A. NAVIGATION
    // ------------------------------------------------------------------

    public function test_the_sidebar_offers_buildings_overview_as_its_own_module(): void
    {
        $markup = $this->sidebar();

        $this->assertStringContainsString('data-page="buildings-overview"', $markup);
        $this->assertStringContainsString('<span class="nav-text">Buildings Overview</span>', $markup);

        // Adding it must not have cost any other destination.
        // TASK 12 — "Repair Requests" left this list because that module's
        // user-facing half was retired deliberately, not because Buildings
        // Overview displaced it. The remaining four still guard this test's
        // actual concern: adding a module must cost no existing destination.
        foreach (['Dashboard', 'All Reports', 'Inventory', 'Dispatches'] as $navItem) {
            $this->assertStringContainsString(
                '<span class="nav-text">' . $navItem . '</span>',
                $markup,
                "Adding Buildings Overview must not remove the {$navItem} nav item."
            );
        }
    }

    public function test_the_sidebar_link_points_at_the_existing_buildings_overview_route(): void
    {
        $markup = $this->sidebar();

        $idPos = strpos($markup, 'data-page="buildings-overview"');
        $this->assertNotFalse($idPos);

        $start = strrpos(substr($markup, 0, $idPos), '<a ');
        $anchor = substr($markup, $start, $idPos - $start);

        $this->assertStringContainsString("public_url('/buildings-overview')", $anchor, 'Reuse the shared URL helper and the existing named route.');
        $this->assertStringContainsString('htmlspecialchars(', $anchor, 'Match the escaping convention used by every other nav href.');

        // The route and the page it fronts must both already exist — this task
        // reused them rather than creating a second Buildings Overview.
        $this->assertTrue(Route::has('buildings.overview'), 'The named /buildings-overview route must stay registered.');
        $this->assertFileExists(base_path('public/frontend/pages/buildings-overview.php'));
    }

    public function test_the_dashboard_no_longer_presents_the_buildings_overview_navigation_shortcut(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/dashboard.php'));
        $this->assertNotFalse($markup);

        $this->assertStringNotContainsString(
            'navigateToBuildingsOverview',
            $markup,
            'The dashboard navigation shortcut and its handler must be gone.'
        );
        $this->assertStringNotContainsString(
            'summary-card-buildings',
            $markup,
            'The pure "Buildings overview / Open" navigation card must be gone from dashboard.php.'
        );
    }

    /**
     * The card removed from dashboard.php had no data in it — its value was the
     * literal word "Open". The other dashboards show a real building count, so
     * they were intentionally preserved. This test stops a later cleanup from
     * deleting those statistics on the assumption they were part of the same
     * navigation change.
     *
     * staff-dashboard.php's buildings card was later removed on explicit
     * request (Buildings Overview is in the sidebar) — see
     * test_the_staff_dashboard_no_longer_shows_a_buildings_card() below.
     */
    public function test_the_dashboards_that_show_a_real_building_count_keep_their_stat_cards(): void
    {
        $expectations = [
            'super-admin-dashboard.php' => 'buildingsOverview',
            'maintenance-dashboard.php' => 'today-buildings-count',
        ];

        foreach ($expectations as $page => $countHook) {
            $markup = file_get_contents(base_path('public/frontend/pages/' . $page));
            $this->assertNotFalse($markup);

            $this->assertStringContainsString(
                $countHook,
                $markup,
                "{$page}'s building-count statistic must be preserved; it displays data rather than merely linking."
            );
        }
    }

    public function test_the_staff_dashboard_no_longer_shows_a_buildings_card(): void
    {
        $markup = file_get_contents(base_path('public/frontend/pages/staff-dashboard.php'));
        $this->assertNotFalse($markup);

        $this->assertStringNotContainsString('id="my-buildings"', $markup, 'The Staff dashboard buildings stat card was removed on request.');
        $this->assertStringNotContainsString('FROM buildings', $markup, 'Its now-unused buildings COUNT(*) query must go with it.');
    }

    public function test_no_duplicate_buildings_overview_navigation_entry_exists(): void
    {
        $markup = $this->sidebar();

        $this->assertSame(
            1,
            substr_count($markup, 'data-page="buildings-overview"'),
            'Buildings Overview must appear exactly once in the sidebar.'
        );
        $this->assertSame(
            1,
            substr_count($markup, '<span class="nav-text">Buildings Overview</span>'),
            'The Buildings Overview label must not be duplicated.'
        );
    }

    // ------------------------------------------------------------------
    // B. LEVEL 1 — BUILDING SEARCH
    // ------------------------------------------------------------------

    public function test_the_building_search_exists_and_searches_only_buildings(): void
    {
        $markup = $this->overviewPage();

        $this->assertStringContainsString('id="buildingSearchInput"', $markup);

        // It filters the buildings cache by building name only — it must not
        // reach into floors or rooms.
        $this->assertMatchesRegularExpression(
            '/function getSortedFilteredBuildings\(\).{0,400}?buildingsRawCache\.filter/s',
            $markup,
            'The building search must filter the buildings collection.'
        );
    }

    public function test_the_building_search_endpoint_matches_only_building_names(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this->seedBuilding(['name' => 'Rizal Hall']);
        $this->seedBuilding(['name' => 'Bonifacio Hall']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson('/api/buildings?q=Rizal')
            ->assertOk();

        $names = array_column($response->json('data.buildings'), 'name');

        $this->assertContains('Rizal Hall', $names);
        $this->assertNotContains('Bonifacio Hall', $names, 'Building search must not return unmatched buildings.');
    }

    // ------------------------------------------------------------------
    // B2. THE THREE CONTROLS LIVE TOGETHER ON THE OUTER VIEW
    // ------------------------------------------------------------------

    public function test_all_three_search_controls_are_available_on_the_outer_view(): void
    {
        $toolbar = $this->outerFilterToolbar();

        foreach (['buildingSearchInput', 'floorFilterInput', 'roomFilterInput'] as $control) {
            $this->assertStringContainsString(
                'id="' . $control . '"',
                $toolbar,
                "{$control} must render inside the outer Buildings Overview toolbar, not behind a drill-down level."
            );
        }

        // They must read as one labelled filter row, not three unrelated boxes.
        foreach (['Search Building', 'Search Floor', 'Search Room'] as $label) {
            $this->assertStringContainsString($label, $toolbar, "The '{$label}' control must be labelled on the outer view.");
        }

        $this->assertStringContainsString(
            'id="buildingCascadeToolbar"',
            $toolbar,
            'The three controls must share one cascading filter row.'
        );
    }

    /**
     * The correction's core point. The old implementation put the floor search
     * inside a wrapper that only appeared once loadFloors() had navigated into a
     * building; that control must be gone, not merely shown earlier.
     */
    public function test_the_drill_down_only_floor_search_no_longer_exists(): void
    {
        $markup = $this->overviewPage();

        $this->assertStringNotContainsString(
            'id="floorSearchWrap"',
            $markup,
            'The floor-level-only search wrapper must be gone; the Floor filter belongs to the outer toolbar.'
        );
        $this->assertStringNotContainsString(
            'id="floorSearchInput"',
            $markup,
            'The floor-level-only search input must be gone.'
        );
        $this->assertStringNotContainsString(
            'setFloorSearchVisibility',
            $markup,
            'The show/hide-by-level helper for the floor search must be gone with it.'
        );
    }

    /**
     * "Available on the outer view" is only meaningful if using the control does
     * not then navigate away. None of the three handlers may call a drill-down
     * entry point, and the renderer they share refuses to run anywhere but the
     * outer view.
     */
    public function test_using_the_filters_never_navigates_into_another_level(): void
    {
        $markup = $this->overviewPage();

        $handlers = [
            'async function onBuildingFilterChanged(',
            'async function onFloorFilterChanged(',
            'function onRoomFilterChanged(',
        ];

        foreach ($handlers as $signature) {
            $body = $this->functionBody($markup, $signature);

            $this->assertStringNotContainsString('loadFloors(', $body, "{$signature} must not drill into the floor level.");
            $this->assertStringNotContainsString('loadRooms(', $body, "{$signature} must not drill into the room level.");
            $this->assertStringNotContainsString('loadItems(', $body, "{$signature} must not drill into the items level.");
        }

        $renderer = $this->functionBody($markup, 'function renderCascadeResults(');
        $this->assertStringContainsString(
            "currentLevel !== 'building'",
            $renderer,
            'The cascade results must only ever be drawn on the outer Buildings Overview view.'
        );
    }

    /**
     * Requirement 2 and 3 of the correction, stated structurally: the Floor
     * filter's options come from the building the FILTER resolved
     * (filterBuildingId), and the Room filter's from the floor the FILTER
     * resolved (filterFloorId) — never from the drill-down's currentBuildingId /
     * currentFloorId. That is what decouples the filters from navigation.
     */
    public function test_the_child_filters_read_their_parent_from_the_filter_not_the_drill_down(): void
    {
        $markup = $this->overviewPage();

        $floorOptions = $this->functionBody($markup, 'async function loadFloorFilterOptions(');
        $this->assertStringContainsString('filterBuildingId', $floorOptions, 'Floor options must be scoped by the filtered building.');
        $this->assertStringNotContainsString('currentBuildingId', $floorOptions, 'Floor options must not depend on having navigated into a building.');

        $roomOptions = $this->functionBody($markup, 'async function loadRoomFilterOptions(');
        $this->assertStringContainsString('filterBuildingId', $roomOptions, 'Room options must be scoped by the filtered building.');
        $this->assertStringContainsString('filterFloorId', $roomOptions, 'Room options must be scoped by the filtered floor.');
        $this->assertStringNotContainsString('currentFloorId', $roomOptions, 'Room options must not depend on having navigated into a floor.');

        // And the room options request carries BOTH ids, so the pairing is
        // enforced server-side rather than by the client list.
        $this->assertMatchesRegularExpression(
            '#/api/rooms\?building_id=.{0,140}?floor_id=#s',
            $roomOptions,
            'The room option request must carry both the selected building and the selected floor.'
        );
    }

    // ------------------------------------------------------------------
    // C. LEVEL 2 — FLOOR FILTER, SCOPED TO THE SELECTED BUILDING
    // ------------------------------------------------------------------

    public function test_the_floor_filter_exists_and_filters_the_building_scoped_cache(): void
    {
        $markup = $this->overviewPage();

        $this->assertStringContainsString('id="floorFilterInput"', $markup);
        $this->assertStringContainsString('Search Floor...', $markup);

        $this->assertMatchesRegularExpression(
            '/function getCascadeFloors\(\).{0,400}?filterFloorsCache/s',
            $markup,
            'The floor filter must filter the building-scoped floors cache.'
        );
    }

    /**
     * The Floor search operates on floorsRawCache, and floorsRawCache is only
     * ever filled from /api/buildings/{id}/floors. So the guarantee that a
     * floor of another building can never be searched rests on this endpoint
     * being server-side scoped. That is what this test proves, with rows.
     */
    public function test_selecting_a_building_limits_floors_to_that_building(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $buildingOne = $this->seedBuilding(['name' => 'Building 1']);
        $buildingTwo = $this->seedBuilding(['name' => 'Building 2']);

        $this->seedFloor($buildingOne, ['name' => '2nd Floor']);
        $this->seedFloor($buildingOne, ['name' => '3rd Floor']);
        $foreignFloor = $this->seedFloor($buildingTwo, ['name' => 'Penthouse']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson("/api/buildings/{$buildingOne}/floors")
            ->assertOk();

        $floors = $response->json('data.floors');
        $this->assertNotEmpty($floors, 'Fixture must return floors for this assertion to mean anything.');

        $names = array_column($floors, 'name');
        sort($names);
        $this->assertSame(['2nd Floor', '3rd Floor'], $names);

        foreach ($floors as $floor) {
            $this->assertSame(
                $buildingOne,
                (int) $floor['building_id'],
                'Every returned floor must belong to the selected building.'
            );
            $this->assertNotSame($foreignFloor, (int) $floor['id']);
        }
    }

    public function test_floors_of_another_building_cannot_appear_in_the_scoped_result(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $buildingOne = $this->seedBuilding(['name' => 'Building 1']);
        $buildingTwo = $this->seedBuilding(['name' => 'Building 2']);
        $this->seedFloor($buildingOne, ['name' => '2nd Floor']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson("/api/buildings/{$buildingTwo}/floors")
            ->assertOk();

        $this->assertSame(
            [],
            $response->json('data.floors'),
            'A building with no floors of its own must not inherit another building\'s floors.'
        );
    }

    /**
     * Case A of the cascade: changing the Building clears the Floor AND the Room
     * below it. The reset must happen before any new child data is fetched, so
     * no stale child result can ever be on screen.
     */
    public function test_changing_the_building_resets_the_floor_and_the_room(): void
    {
        $markup = $this->overviewPage();

        $handler = $this->functionBody($markup, 'async function onBuildingFilterChanged(');

        $this->assertStringContainsString(
            'nextBuildingId !== filterBuildingId',
            $handler,
            'The reset must be triggered by the building actually changing.'
        );
        $this->assertStringContainsString('resetFloorFilter()', $handler, 'Changing the building must reset the floor filter.');

        // Ordering matters: clear first, fetch second.
        $this->assertLessThan(
            strpos($handler, 'loadFloorFilterOptions()'),
            strpos($handler, 'resetFloorFilter()'),
            'The stale floor context must be cleared before new floor options are fetched.'
        );

        // resetFloorFilter clears the floor state, the visible input, the option
        // list, re-disables the control, and cascades into the room.
        $reset = $this->functionBody($markup, 'function resetFloorFilter(');
        $this->assertStringContainsString('filterFloorId = null', $reset);
        $this->assertStringContainsString('filterFloorsCache = []', $reset);
        $this->assertStringContainsString("floorFilterTerm = ''", $reset);
        $this->assertStringContainsString("input.value = ''", $reset, 'The visible floor box must be cleared, not just the state.');
        $this->assertStringContainsString('input.disabled = true', $reset, 'With no building, the floor control must go back to disabled.');
        $this->assertStringContainsString("setCascadeOptions('floorFilterOptions', [])", $reset, 'The floor option list must be emptied.');
        $this->assertStringContainsString('resetRoomFilter()', $reset, 'Changing the building must also clear the room beneath it.');
    }

    /**
     * The drill-down path still resets its own state. This used to be the only
     * reset; it is kept so the navigation path cannot regress while the filter
     * path is the one being exercised.
     */
    public function test_the_drill_down_floor_level_still_resets_its_own_state(): void
    {
        $markup = $this->overviewPage();

        $loadFloors = $this->functionBody($markup, 'async function loadFloors(');

        $this->assertStringContainsString('currentFloorId = null', $loadFloors, 'Entering the floor level must clear the selected floor.');
        $this->assertStringContainsString('floorsRawCache = []', $loadFloors, 'Entering the floor level must drop the previous building\'s floors.');
        $this->assertStringContainsString('roomSearchTerm = \'\'', $loadFloors, 'Entering the floor level must clear the room search.');
    }

    // ------------------------------------------------------------------
    // D. LEVEL 3 — ROOM SEARCH, SCOPED TO BUILDING + FLOOR
    // ------------------------------------------------------------------

    public function test_the_room_request_is_scoped_by_both_building_and_floor(): void
    {
        $markup = $this->overviewPage();

        $this->assertStringContainsString('id="roomSearchInput"', $markup);
        $this->assertMatchesRegularExpression(
            '#/api/rooms\?building_id=.{0,120}?floor_id=#s',
            $markup,
            'The room request must carry BOTH the selected building and the selected floor.'
        );
    }

    public function test_selecting_a_floor_limits_rooms_to_that_floor_and_building(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $buildingOne = $this->seedBuilding(['name' => 'Building 1']);
        $buildingTwo = $this->seedBuilding(['name' => 'Building 2']);

        $secondFloor = $this->seedFloor($buildingOne, ['name' => '2nd Floor']);
        $thirdFloor  = $this->seedFloor($buildingOne, ['name' => '3rd Floor']);
        $otherFloor  = $this->seedFloor($buildingTwo, ['name' => '2nd Floor']);

        $target = $this->seedRoom($buildingOne, $secondFloor, ['name' => 'Room 202']);
        $sameBuildingOtherFloor = $this->seedRoom($buildingOne, $thirdFloor, ['name' => 'Room 302']);
        $otherBuilding = $this->seedRoom($buildingTwo, $otherFloor, ['name' => 'Room B202']);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson("/api/rooms?building_id={$buildingOne}&floor_id={$secondFloor}")
            ->assertOk();

        $rooms = $response->json('data.rooms');
        $ids = array_map('intval', array_column($rooms, 'id'));

        $this->assertSame([$target], $ids, 'Only the selected floor\'s room may be returned.');
        $this->assertNotContains($sameBuildingOtherFloor, $ids, 'A room on another floor of the same building must not appear.');
        $this->assertNotContains($otherBuilding, $ids, 'A room in another building must not appear.');
    }

    /**
     * Case B of the cascade: changing the Floor clears the Room below it.
     */
    public function test_changing_the_floor_resets_the_room(): void
    {
        $markup = $this->overviewPage();

        $handler = $this->functionBody($markup, 'async function onFloorFilterChanged(');

        $this->assertStringContainsString(
            'nextFloorId !== filterFloorId',
            $handler,
            'The reset must be triggered by the floor actually changing.'
        );
        $this->assertStringContainsString('resetRoomFilter()', $handler, 'Changing the floor must reset the room filter.');
        $this->assertLessThan(
            strpos($handler, 'loadRoomFilterOptions()'),
            strpos($handler, 'resetRoomFilter()'),
            'The stale room context must be cleared before new room options are fetched.'
        );

        $reset = $this->functionBody($markup, 'function resetRoomFilter(');
        $this->assertStringContainsString('filterRoomsCache = []', $reset);
        $this->assertStringContainsString("roomFilterTerm = ''", $reset);
        $this->assertStringContainsString("input.value = ''", $reset, 'The visible room box must be cleared, not just the state.');
        $this->assertStringContainsString('input.disabled = true', $reset, 'With no floor, the room control must go back to disabled.');
        $this->assertStringContainsString("setCascadeOptions('roomFilterOptions', [])", $reset, 'The room option list must be emptied.');
    }

    /**
     * The drill-down room level still resets its own state.
     */
    public function test_the_drill_down_room_level_still_resets_its_own_state(): void
    {
        $markup = $this->overviewPage();

        $loadRooms = $this->functionBody($markup, 'async function loadRooms(');

        $this->assertStringContainsString('currentRoomId = null', $loadRooms, 'Changing floor must clear the selected room.');
        $this->assertStringContainsString('currentRoomsCache = []', $loadRooms, 'Changing floor must drop the previous floor\'s rooms.');
        $this->assertStringContainsString("roomSearchTerm = ''", $loadRooms, 'Changing floor must clear the room search term.');
        $this->assertStringContainsString('setRoomSearchVisibility(true', $loadRooms);

        $setter = $this->functionBody($markup, 'function setRoomSearchVisibility(');
        $this->assertStringContainsString("input.value = ''", $setter, 'The visible room search box must be cleared, not just the state.');
    }

    /**
     * A child control must not be able to operate without its parent context.
     * Three independent guards: the control ships disabled, its listener returns
     * early, and its handler returns early.
     */
    public function test_child_filters_cannot_operate_without_their_parent(): void
    {
        $markup = $this->overviewPage();
        $toolbar = $this->outerFilterToolbar();

        // 1. Shipped disabled, with an empty option list, before anything is chosen.
        foreach (['floorFilterInput', 'roomFilterInput'] as $control) {
            $this->assertMatchesRegularExpression(
                '/id="' . $control . '"[^>]*\sdisabled/',
                $toolbar,
                "{$control} must start disabled, before its parent has been chosen."
            );
        }
        foreach (['floorFilterOptions', 'roomFilterOptions'] as $list) {
            $this->assertStringContainsString(
                '<datalist id="' . $list . '"></datalist>',
                $toolbar,
                "{$list} must start empty, so the child offers no options without a parent."
            );
        }

        // 2. The listeners refuse to fire without a resolved parent.
        $this->assertMatchesRegularExpression(
            "/floorFilterInput\.addEventListener\('input'.{0,220}?!filterBuildingId\) return;/s",
            $markup,
            'The floor filter listener must be inert until a building is resolved.'
        );
        $this->assertMatchesRegularExpression(
            "/roomFilterInput\.addEventListener\('input'.{0,220}?!filterFloorId\) return;/s",
            $markup,
            'The room filter listener must be inert until a floor is resolved.'
        );

        // 3. The handlers guard themselves too, so a directly invoked call is
        //    refused as well.
        $floorHandler = $this->functionBody($markup, 'async function onFloorFilterChanged(');
        $this->assertStringContainsString('if (!filterBuildingId) return;', $floorHandler);

        $roomHandler = $this->functionBody($markup, 'function onRoomFilterChanged(');
        $this->assertStringContainsString('if (!filterFloorId) return;', $roomHandler);

        // The pre-existing drill-down room/item search keeps its own level guard.
        $this->assertMatchesRegularExpression(
            "/roomSearchInput\.addEventListener\('input'.{0,300}?currentLevel === 'room'/s",
            $markup,
            'The drill-down room search must still only act at the room level.'
        );
    }

    // ------------------------------------------------------------------
    // E. API / DATA RELATIONSHIP
    // ------------------------------------------------------------------

    public function test_a_mismatched_building_and_floor_pair_returns_no_rooms(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $buildingOne = $this->seedBuilding(['name' => 'Building 1']);
        $buildingTwo = $this->seedBuilding(['name' => 'Building 2']);

        $floorOfTwo = $this->seedFloor($buildingTwo, ['name' => '2nd Floor']);
        $this->seedRoom($buildingTwo, $floorOfTwo, ['name' => 'Room B202']);

        // Building 1 combined with a floor that belongs to Building 2.
        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson("/api/rooms?building_id={$buildingOne}&floor_id={$floorOfTwo}")
            ->assertOk();

        $this->assertSame(
            [],
            $response->json('data.rooms'),
            'An impossible building/floor combination must yield nothing rather than leaking rooms.'
        );
    }

    public function test_inactive_rooms_stay_excluded_from_the_scoped_result(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $building = $this->seedBuilding(['name' => 'Building 1']);
        $floor = $this->seedFloor($building, ['name' => '2nd Floor']);

        $active = $this->seedRoom($building, $floor, ['name' => 'Room 202']);
        $this->seedRoom($building, $floor, ['name' => 'Retired Room', 'is_active' => 0]);

        $response = $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->getJson("/api/rooms?building_id={$building}&floor_id={$floor}")
            ->assertOk();

        $ids = array_map('intval', array_column($response->json('data.rooms'), 'id'));

        $this->assertSame([$active], $ids, 'Scoping must not have changed the pre-existing active-only default.');
    }

    // ------------------------------------------------------------------
    // F. RBAC — unchanged by this task
    // ------------------------------------------------------------------

    public function test_buildings_overview_view_access_is_unchanged_for_every_role(): void
    {
        foreach (self::VIEW_ROLES as $role) {
            $userId = $this->seedUser(['role' => $role]);
            $building = $this->seedBuilding();
            $floor = $this->seedFloor($building);

            $this->actingAsSessionUser($userId, $role)->getJson('/api/buildings')->assertOk();
            $this->actingAsSessionUser($userId, $role)->getJson("/api/buildings/{$building}/floors")->assertOk();
            $this->actingAsSessionUser($userId, $role)
                ->getJson("/api/rooms?building_id={$building}&floor_id={$floor}")
                ->assertOk();
        }
    }

    /**
     * The sidebar entry must not have become the security boundary, and it must
     * not have widened who may change buildings.
     */
    public function test_building_modification_remains_restricted_to_super_admin(): void
    {
        foreach (['maintenance_admin', 'maintenance_staff'] as $role) {
            $userId = $this->seedUser(['role' => $role]);

            $this
                ->actingAsSessionUser($userId, $role)
                ->postJson('/api/buildings', ['name' => 'Unauthorized Building'])
                ->assertForbidden();
        }

        $this->assertSame(
            0,
            DB::table('buildings')->where('name', 'Unauthorized Building')->count(),
            'A denied request must not have written a building.'
        );

        // The sidebar gate mirrors the read policy and must not name a role
        // that cannot view the module.
        $this->assertMatchesRegularExpression(
            '/\$showBuildingsOverview\s*=\s*in_array\(\s*\(\$user\[\'role\'\] \?\? \'\'\),\s*\[\'super_admin\', \'maintenance_admin\', \'maintenance_staff\'\],\s*true\s*\)\s*;/',
            $this->sidebar(),
            'The sidebar gate must reuse the existing view-access role set.'
        );
    }

    // ------------------------------------------------------------------

    /**
     * Returns the source of a JS function by brace matching, so assertions can
     * be scoped to one function instead of the whole 2k-line page.
     */
    private function functionBody(string $markup, string $signature): string
    {
        $start = strpos($markup, $signature);
        $this->assertNotFalse($start, "Function not found: {$signature}");

        $open = strpos($markup, '{', $start);
        $this->assertNotFalse($open);

        $depth = 0;
        $length = strlen($markup);

        for ($i = $open; $i < $length; $i++) {
            if ($markup[$i] === '{') {
                $depth++;
            } elseif ($markup[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($markup, $start, $i - $start + 1);
                }
            }
        }

        $this->fail("Unbalanced braces while reading: {$signature}");
    }

    /**
     * Minimal Buildings Overview schema: the three hierarchy tables plus users,
     * and items because BuildingController::floors() aggregates item counts
     * through rooms.
     */
    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();

        Schema::create('buildings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('floors', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });

        $this->createItemsTable();

        // Needed so DeployedItemsQuery::perRoom() (used by BuildingController::
        // floors() and RoomController::index()'s with_item_counts) has tables
        // to union against — these tests don't exercise dispatches directly,
        // but the query still runs against them on every request.
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::enableForeignKeyConstraints();
    }

    private function seedBuilding(array $overrides = []): int
    {
        return DB::table('buildings')->insertGetId(array_merge([
            'name' => 'Building ' . uniqid('', true),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedFloor(int $buildingId, array $overrides = []): int
    {
        return DB::table('floors')->insertGetId(array_merge([
            'building_id' => $buildingId,
            'name' => 'Floor ' . uniqid('', true),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedRoom(int $buildingId, int $floorId, array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'name' => 'Room ' . uniqid('', true),
            'capacity' => null,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

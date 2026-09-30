<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 1 — Dispatch Inventory Automation, re-anchored by TASK 13.
 *
 * Task 1's five guarantees are all still asserted here, unchanged in
 * substance. What changed is WHICH step they attach to: TASK 13 split the old
 * atomic approve-and-release into an Administrator decision (approve) and a
 * physical hand-off by the assigned Maintenance Staff (release), and moved the
 * whole deduction pipeline to the latter. So every scenario below that used to
 * end at "approve" now runs approve THEN release:
 *   1. Pending dispatch -> inventory unchanged                    (unchanged)
 *   2. Approval alone -> still no deduction                       (NEW — the
 *      explicit guard for TASK 13's "deduction happens ONLY when Release is
 *      confirmed, NOT during approval")
 *   3. Release -> inventory deducted, InventoryTransaction created, status
 *      updated
 *   4. Insufficient inventory -> RELEASE blocked, no inventory changes
 *   5. Release same dispatch twice -> no duplicate deduction
 *   6. Rollback simulation -> no partial inventory update
 */
class DispatchApprovalInventoryTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_approval_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_pending_dispatch_leaves_inventory_unchanged(): void
    {
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $this->seedDispatch($itemId, 3);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(10, (int) $item->quantity);
        $this->assertSame(0, DB::table('inventory_transactions')->count());
    }

    /**
     * TASK 13 §6 — "Inventory deduction happens ONLY when Release is
     * confirmed. NOT during approval." This is the direct assertion of that
     * rule and the single most important regression guard in this file: if
     * anyone ever re-adds stock movement to approveDispatch(), this fails.
     */
    public function test_approval_alone_does_not_deduct_inventory(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 4, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(10, (int) $item->quantity, 'Approval must not move stock.');
        $this->assertSame(0, DB::table('inventory_transactions')->count(), 'Approval must not create a deploy transaction.');

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('approved', $dispatch->status, 'Approval must stop at approved, not jump to released.');
        $this->assertSame($adminId, (int) $dispatch->approved_by);
        $this->assertNotNull($dispatch->approved_at);
        // TASK 3 — released_by means "who actually released". Nobody has, yet.
        $this->assertNull($dispatch->released_by);
    }

    public function test_releasing_dispatch_deducts_inventory_creates_transaction_and_updates_status(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Pedro Releaser']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 4, [
            'dispatch_code' => 'DSP-TEST-0001',
            'requested_by' => $headId,
            'release_assigned_to' => $staffId,
            'release_assigned_by' => $headId,
        ]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(6, (int) $item->quantity);

        $tx = DB::table('inventory_transactions')->where('item_id', $itemId)->first();
        $this->assertNotNull($tx);
        $this->assertSame('deploy', $tx->transaction_type);
        // "Reference Type = Dispatch, Reference Number = dispatch code" is
        // conveyed via the dispatch_id FK (queryable) + the reference_note
        // text (human-readable), asserted below.
        $this->assertSame($dispatchId, (int) $tx->dispatch_id);
        $this->assertSame(4, (int) $tx->quantity);
        $this->assertStringContainsString('Released via Dispatch DSP-TEST-0001', (string) $tx->reference_note);
        // TASK 13 — performed_by is the releasing STAFF member, not the
        // approving Administrator. Before this task it was the approver.
        $this->assertSame($staffId, (int) $tx->performed_by);
        $this->assertNotSame($adminId, (int) $tx->performed_by);

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('released', $dispatch->status);
        $this->assertSame($adminId, (int) $dispatch->approved_by);
        // TASK 3 — Dispatch Personnel Audit Trail: released_by must reflect
        // who actually released the item, never the approving Administrator.
        $this->assertSame($staffId, (int) $dispatch->released_by);
        $this->assertNotSame((int) $dispatch->released_by, $adminId);
        $this->assertNotNull($dispatch->approved_at);

        // TASK 13 §7 — the audit trail must separately identify who approved
        // and who released.
        $approveLog = DB::table('activity_logs')->where('action', 'APPROVE_DISPATCH')->first();
        $this->assertNotNull($approveLog);
        $this->assertSame($adminId, (int) $approveLog->user_id);
        $this->assertStringContainsString('DSP-TEST-0001', (string) $approveLog->details);

        $releaseLog = DB::table('activity_logs')->where('action', 'RELEASE_DISPATCH')->first();
        $this->assertNotNull($releaseLog);
        $this->assertSame($staffId, (int) $releaseLog->user_id);
        $this->assertStringContainsString('DSP-TEST-0001', (string) $releaseLog->details);
        $this->assertStringContainsString('Pedro Releaser', (string) $releaseLog->details);
    }

    /**
     * TASK 13 KNOWN BEHAVIOUR CHANGE — a stock shortfall no longer blocks
     * approval, it blocks release. The guarantee Task 1 actually cared about
     * (a request the bodega cannot fulfil never moves any stock) is unchanged;
     * only the step that reports it has moved, so this test now asserts it at
     * the release call.
     */
    public function test_insufficient_inventory_blocks_release(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 5, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 5, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        // Stock drops after approval (e.g. another release), so the release-
        // time re-check is what must stop this one.
        DB::table('items')->where('id', $itemId)->update(['quantity' => 3]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", []);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        // TASK — message reworded to plain language (see
        // DispatchService::formatInsufficientStockMessage()); still names the
        // item and the real available/requested numbers, just phrased as a
        // sentence instead of the old "Insufficient Inventory for X.
        // Available Stock: N. Requested: N." template.
        $message = $response->json('message');
        $this->assertStringContainsString('Not enough stock for Projector Lamp', $message);
        $this->assertStringContainsString('Only 3 available', $message);
        $this->assertStringContainsString('5 requested', $message);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(3, (int) $item->quantity);
        $this->assertSame(0, DB::table('inventory_transactions')->count());

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('approved', $dispatch->status, 'A failed release must leave the dispatch approved, not released.');
        $this->assertNull($dispatch->released_by);
    }

    public function test_releasing_same_dispatch_twice_does_not_duplicate_deduction(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 4, ['release_assigned_to' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertOk();

        $second = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", []);
        $second->assertStatus(400);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(6, (int) $item->quantity, 'Stock must only be deducted once across two release attempts.');
        $this->assertSame(1, DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->count());
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'RELEASE_DISPATCH')->count());
    }

    /**
     * TASK 13 — approving a dispatch nobody is assigned to would create a
     * permanently stuck record: canReleaseDispatch() requires an identity
     * match against release_assigned_to, so a null assignment means no user
     * on earth can release it. Only rows created before this task can be in
     * that state, and they must be refused rather than silently advanced.
     */
    public function test_dispatch_without_assigned_personnel_cannot_be_approved(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 10, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 4);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId]);

        $response->assertStatus(400);
        $this->assertStringContainsString('no assigned release personnel', (string) $response->json('message'));

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('pending', $dispatch->status);
        $this->assertNull($dispatch->approved_by);
    }

    /**
     * Simulates a mid-transaction failure: this dispatch has two
     * dispatch_items rows pointing at the SAME item, requesting 3 and then
     * 4 units. TASK 48: DispatchService::releaseDispatch() now aggregates
     * requested quantity per item_id before the stock pre-check, so the
     * combined total (7) against the 5 available is caught up front as a
     * clean ValidationException (HTTP 400) — no InventoryTransaction is
     * ever created, so there is nothing to roll back. This replaces the
     * pre-Task-48 behavior where the first InventoryTransaction committed
     * inside the DB transaction, reduced stock 5 -> 2, and the second then
     * failed InventoryTransactionObserver's row-locked re-validation,
     * throwing and rolling back the entire DB::transaction (surfacing as an
     * unhandled HTTP 500). The assertions below still confirm "no partial
     * updates", now via prevention rather than rollback.
     */
    public function test_duplicate_item_rows_are_aggregated_and_rejected_cleanly(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 7, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 3, ['release_assigned_to' => $staffId]);
        $this->addDispatchItem($dispatchId, $itemId, 4);

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId])
            ->assertOk();

        // Stock drops after approval, so the release-time re-check applies.
        DB::table('items')->where('id', $itemId)->update(['quantity' => 5]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", []);

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'message' => 'Not enough stock for Projector Lamp. Only 5 available, but 7 requested.',
        ]);

        $item = DB::table('items')->where('id', $itemId)->first();
        $this->assertSame(5, (int) $item->quantity, 'Rollback must restore the pre-transaction quantity exactly.');
        $this->assertSame(0, (int) $item->reserved_quantity);
        $this->assertSame(0, DB::table('inventory_transactions')->count(), 'No transaction row may survive the rollback.');

        // TASK 13 — the rolled-back step is now the release, so the dispatch
        // must be left exactly as approval left it: approved, unreleased.
        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('approved', $dispatch->status);
        $this->assertNull($dispatch->released_by);
    }

    public function test_insufficient_inventory_blocks_approval(): void
    {
        // 2026-09-27 — "check stock at approval, deduct at hand-off": a
        // dispatch the inventory cannot fulfil is refused at approval, with
        // the same message release uses, and nothing changes.
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Projector Lamp', 'quantity' => 3, 'reserved_quantity' => 0]);
        $dispatchId = $this->seedDispatch($itemId, 5, ['release_assigned_to' => $staffId]);

        $response = $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$dispatchId}/approve", ['approved_by' => $adminId]);

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'message' => 'Not enough stock for Projector Lamp. Only 3 available, but 5 requested.',
        ]);

        $dispatch = DB::table('dispatches')->where('id', $dispatchId)->first();
        $this->assertSame('pending', $dispatch->status);
        $this->assertNull($dispatch->approved_by);
        $this->assertSame(3, (int) DB::table('items')->where('id', $itemId)->value('quantity'));
    }

    private function seedDispatch(int $itemId, int $quantity, array $overrides = []): int
    {
        $dispatchId = DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        $this->addDispatchItem($dispatchId, $itemId, $quantity);

        return $dispatchId;
    }

    private function addDispatchItem(int $dispatchId, int $itemId, int $quantity): void
    {
        DB::table('dispatch_items')->insert([
            'dispatch_id' => $dispatchId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();

        // BuildsSharedTestSchema::createInventoryTransactionsTable() predates
        // the dispatch_id FK (added by the real
        // 2026_07_28_002000_add_report_id_relationships_for_maintenance_report_centralization
        // migration), so add it here the same way that migration does.
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispatch_id')->nullable()->after('report_id');
        });

        $this->createDispatchesTable();
        $this->createDispatchItemsTable();

        Schema::enableForeignKeyConstraints();
    }
}

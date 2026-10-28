<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Recommended Supplier field validation (requested 2026-10-27):
 *
 *   Field            Validation
 *   Supplier Name    Required, 3-100 characters, no duplicates
 *   Contact Person   Letters, spaces, hyphens, apostrophes, periods
 *   Phone            Valid Philippine mobile or landline format
 *   Email            Valid email format when supplied
 *   Address          Maximum 255 characters
 *   Notes            Maximum 500 characters
 *
 * Deliberately permissive on Supplier Name's characters -- only length and
 * the pre-existing duplicate check are enforced there, since legitimate
 * business names use "&", commas, and periods ("ABC Hardware & Supply",
 * "J&J Trading", "ABC Co., Ltd.") and a character whitelist would reject them.
 */
class SupplierValidationTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('supplier_validation_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        $this->createUsersTable();
        $this->createSuppliersTable();
        $this->createSupplierHistoriesTable();
        $this->createActivityLogsTable();
        $this->forceLocalTestUrl();
    }

    private function admin(): int
    {
        return $this->seedUser(['role' => 'super_admin']);
    }

    // ------------------------------------------------------------------
    // Supplier Name
    // ------------------------------------------------------------------

    public function test_name_shorter_than_3_characters_is_rejected(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', ['name' => 'AB']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('errors'));
        $this->assertSame(0, DB::table('suppliers')->count());
    }

    public function test_name_longer_than_100_characters_is_rejected(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', ['name' => str_repeat('A', 101)]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    /**
     * The explicit "don't be too strict" case: real supplier names routinely
     * carry "&", commas, and periods. None of these should be rejected for
     * their characters -- only length/duplicate rules apply to this field.
     */
    public function test_legitimate_business_names_with_special_characters_are_accepted(): void
    {
        $adminId = $this->admin();

        foreach (['ABC Hardware & Supply', 'J&J Trading', 'ABC Co., Ltd.'] as $name) {
            $response = $this
                ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
                ->postJson('/api/suppliers', ['name' => $name]);

            $response->assertStatus(201);
        }

        $this->assertSame(3, DB::table('suppliers')->count());
    }

    public function test_duplicate_name_is_rejected_case_insensitively(): void
    {
        $adminId = $this->admin();
        DB::table('suppliers')->insert([
            'name' => 'ABC Hardware',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', ['name' => 'abc hardware']);

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('suppliers')->count());
    }

    /**
     * 2026-10-27 feedback item C: "existing na ang ABC Hardware Supply,
     * dapat hindi na makagawa ng abc hardware supply o ABC  Hardware Supply"
     * (double space) -- the old LOWER(name) = ? comparison only normalized
     * case, not whitespace, so the double-space variant slipped through.
     */
    public function test_duplicate_name_is_rejected_with_whitespace_variants(): void
    {
        $adminId = $this->admin();
        DB::table('suppliers')->insert([
            'name' => 'ABC Hardware Supply',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['abc hardware supply', 'ABC  Hardware Supply', '  ABC Hardware Supply  '] as $variant) {
            $response = $this
                ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
                ->postJson('/api/suppliers', ['name' => $variant]);

            $response->assertStatus(409);
        }

        $this->assertSame(1, DB::table('suppliers')->count());
    }

    /**
     * Companion to the whitespace-variant check above: real business names
     * with "&", commas, and periods must still compare correctly (and still
     * be rejected as duplicates of themselves) -- the whitespace fix must
     * not regress the "don't be too strict on Supplier Name" rule.
     */
    public function test_duplicate_name_with_special_characters_is_still_caught(): void
    {
        $adminId = $this->admin();
        DB::table('suppliers')->insert([
            'name' => 'ABC Hardware & Supply',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', ['name' => 'abc hardware  &  supply']);

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('suppliers')->count());
    }

    /**
     * Updating a supplier's name to merely re-cased/re-spaced whitespace of
     * its own current name (no-op rename) must not falsely trip the
     * duplicate check against itself.
     */
    public function test_update_with_whitespace_only_change_to_own_name_is_not_a_duplicate(): void
    {
        $adminId = $this->admin();
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'ABC Hardware Supply',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->patchJson("/api/suppliers/{$supplierId}", ['name' => 'abc  hardware  supply']);

        $response->assertStatus(200);
    }

    public function test_update_rejects_renaming_to_another_suppliers_name_as_a_whitespace_variant(): void
    {
        $adminId = $this->admin();
        DB::table('suppliers')->insert([
            'name' => 'ABC Hardware Supply',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'XYZ Trading',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->patchJson("/api/suppliers/{$supplierId}", ['name' => 'ABC  Hardware Supply']);

        $response->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // Contact Person
    // ------------------------------------------------------------------

    public function test_contact_person_with_digits_or_symbols_is_rejected(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'contact_person' => 'Juan123 #2',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('contact_person', $response->json('errors'));
    }

    public function test_contact_person_with_letters_spaces_hyphens_apostrophes_periods_is_accepted(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'contact_person' => "Dr. Jose Santos-Reyes O'Brien",
            ]);

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Phone
    // ------------------------------------------------------------------

    public function test_phone_with_an_invalid_format_is_rejected(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'phone' => '12345',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('phone', $response->json('errors'));
    }

    public function test_valid_philippine_mobile_number_is_accepted(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'phone' => '0917-123-4567',
            ]);

        $response->assertStatus(201);
        $this->assertSame('09171234567', DB::table('suppliers')->value('phone'));
    }

    public function test_valid_philippine_landline_number_with_formatting_is_accepted(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'phone' => '(02) 8123-4567',
            ]);

        $response->assertStatus(201);
        $this->assertSame('0281234567', DB::table('suppliers')->value('phone'));
    }

    // ------------------------------------------------------------------
    // Email
    // ------------------------------------------------------------------

    public function test_malformed_email_is_rejected(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'contact_email' => 'not-an-email',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('contact_email', $response->json('errors'));
    }

    public function test_valid_email_is_accepted(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
            'contact_email' => 'supplier@gmail.com',
            ]);

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Address / Notes length caps
    // ------------------------------------------------------------------

    public function test_address_longer_than_255_characters_is_rejected(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'address' => str_repeat('A', 256),
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('address', $response->json('errors'));
    }

    public function test_notes_longer_than_500_characters_is_rejected(): void
    {
        $adminId = $this->admin();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/suppliers', [
                'name' => 'Valid Supplier Name',
                'notes' => str_repeat('A', 501),
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('notes', $response->json('errors'));
    }

    // ------------------------------------------------------------------
    // Update (PATCH) shares the same rules
    // ------------------------------------------------------------------

    public function test_update_rejects_an_invalid_phone_too(): void
    {
        $adminId = $this->admin();
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Existing Supplier',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->patchJson("/api/suppliers/{$supplierId}", ['phone' => 'not-a-number']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('phone', $response->json('errors'));
    }
}

<?php

namespace Tests\Feature;

use App\Services\SmsService;
use App\Support\UserNamePolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

class UserNameHandlingTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $admin;
    private int $department;
    private array $nativeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->useInMemoryDatabase('user_name_handling_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        Log::swap(new NullLogger());
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->mock(SmsService::class)->shouldNotReceive('send');
        $this->nativeBefore = $_SESSION ?? [];
        $_SESSION = [];
        $this->forceLocalTestUrl();

        // No schema operations before the environment and PDO guards above.
        $this->createUsersTable();
        Schema::table('users', function (Blueprint $table): void { $table->string('verified_email')->nullable()->unique(); });
        $this->createDepartmentsTable();
        $this->createActivityLogsTable();
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id(); $table->unsignedInteger('user_id'); $table->string('title'); $table->text('message');
            $table->string('entity_type')->nullable(); $table->unsignedInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false); $table->timestamps();
        });
        $this->department = DB::table('departments')->insertGetId(['name' => 'Synthetic department', 'status' => 'active'], 'department_id');
        $this->admin = $this->seedUser(['full_name' => 'Synthetic Administrator', 'role' => 'super_admin', 'username' => 'synthetic_admin']);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->nativeBefore;
        parent::tearDown();
    }

    public static function suffixes(): array
    {
        return [['', ''], ['Jr.', 'Jr.'], ['Sr.', 'Sr.'], ['II', 'II'], ['III', 'III'], ['IV', 'IV'], ['V', 'V'],
            ['jr', 'Jr.'], ['Jr', 'Jr.'], ['JR.', 'Jr.'], ['sr', 'Sr.'], ['SR.', 'Sr.'],
            ['ii', 'II'], ['iii', 'III'], ['iv', 'IV'], ['v', 'V']];
    }

    #[DataProvider('suffixes')]
    public function test_structured_suffix_is_canonical_persisted_and_returned(string $suffix, string $canonical): void
    {
        $name = 'Dela Cruz, Juan D.' . ($canonical !== '' ? ' ' . $canonical : '');
        $response = $this->add(['last_name' => '  Dela   Cruz ', 'first_name' => ' Juan ',
            'middle_initial' => 'd.', 'suffix' => $suffix, 'full_name' => 'Conflicting Client Name']);
        $response->assertCreated()->assertJsonPath('data.full_name', $name);
        $id = $response->json('data.user_id');
        $this->assertSame($name, DB::table('users')->where('user_id', $id)->value('full_name'));
        $list = $this->getJson('/api/users')->assertOk()->json('data.users');
        $this->assertSame($name, collect($list)->firstWhere('user_id', $id)['full_name']);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString('  ', $name);
    }

    public static function invalidSuffixes(): array
    {
        return [['123'], ['VI'], ['Random'], ['Jr123'], ['Jr..'], ['Jr.,'], [['Jr.']]];
    }

    public static function optionalParts(): array
    {
        return [['', '', 'Reyes, Maria'], ['', 'III', 'Reyes, Maria III'],
            ["n\u{0303}", 'jr', 'Reyes, Maria Ñ. Jr.']];
    }

    #[DataProvider('optionalParts')]
    public function test_optional_name_parts_do_not_add_spaces_or_repeated_punctuation(string $initial, string $suffix, string $expected): void
    {
        $response = $this->add(['last_name' => ' Reyes ', 'first_name' => ' Maria ', 'middle_initial' => $initial, 'suffix' => $suffix]);
        $response->assertCreated()->assertJsonPath('data.full_name', $expected);
        $this->assertSame($expected, DB::table('users')->where('user_id', $response->json('data.user_id'))->value('full_name'));
    }

    #[DataProvider('invalidSuffixes')]
    public function test_explicit_invalid_suffix_cannot_be_bypassed_by_full_name(mixed $suffix): void
    {
        $this->add(['last_name' => 'Cruz', 'first_name' => 'Juan', 'suffix' => $suffix])
            ->assertStatus(422)->assertJsonValidationErrors('suffix');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_structured_components_are_required_and_cannot_be_hidden_in_full_name(): void
    {
        $this->add(['suffix' => 'Jr.'])->assertStatus(422)->assertJsonValidationErrors(['last_name', 'first_name']);
        $this->add(['last_name' => '<script>', 'first_name' => 'Juan', 'full_name' => 'Safe Name'])
            ->assertStatus(422)->assertJsonValidationErrors('last_name');
        $this->assertSame(1, DB::table('users')->count());
    }

    public static function matchingNames(): array
    {
        return [
            ['Dela Cruz, Juan D. Jr.', 'Dela Cruz, Juan D. Jr.'],
            ['Dela Cruz, Juan D. Jr.', 'DELA CRUZ, JUAN D. JR'],
            ['Dela Cruz, Juan D. Jr.', ' Dela   Cruz ,  Juan   D.   jr. '],
            ['Juan Dela Cruz III', 'JUAN  DELA  CRUZ iii'],
            ['Cruz, Juan Jr.', 'Juan Cruz JR'],
            ['Juan Cruz', 'Cruz, Juan'],
            ['Cruz, Juan D. Jr.', 'Juan D. Cruz JR'],
            ["Peña, José", "Pen\u{0303}a, Jose\u{0301}"],
            // UM-04.1: a comma boundary anchors complete multi-word groups.
            ['Mariah Redjie Galising', 'Galising, Mariah Redjie'],
            ['Galising, Mariah Redjie', 'Mariah Redjie Galising'],
            ['Galising Mariah Redjie', 'Galising, Mariah Redjie'],
            ['Galising, Mariah Redjie', 'Galising Mariah Redjie'],
            ['Dela Cruz, Juan', 'Juan Dela Cruz'],
            ['Juan Dela Cruz', 'Dela Cruz, Juan'],
            ['Dela Cruz, Juan', 'Dela Cruz Juan'],
            ['Galising, Mariah Redjie M.', 'Mariah Redjie M. Galising'],
            ['Galising, Mariah Redjie M. Jr.', 'Galising Mariah Redjie M. JR'],
            ['O’Neil-Santos, María Luisa III', 'María Luisa O’Neil-Santos iii'],
            ["Pen\u{0303}a, Jose\u{0301} Mari\u{0301}a Jr", 'José María Peña JR.'],
        ];
    }

    #[DataProvider('matchingNames')]
    public function test_complete_normalized_matches_are_blocked(string $stored, string $submitted): void
    {
        $this->seedUser(['full_name' => $stored]);
        $this->add(['full_name' => $submitted])->assertStatus(422)->assertJsonValidationErrors('full_name');
        $this->assertSame(2, DB::table('users')->count());
    }

    public static function distinctNames(): array
    {
        return [
            ['Dela Cruz, Juan Jr.', 'Dela Cruz, Juan Sr.'],
            ['Dela Cruz, Juan II', 'Dela Cruz, Juan III'],
            ['Dela Cruz, Juan III', 'Dela Cruz, Juan IV'],
            ['Dela Cruz, Juan IV', 'Dela Cruz, Juan V'],
            ['Dela Cruz, Juan D.', 'Dela Cruz, Juan P.'],
            ['Cruz, Juan D.', 'Juan P. Cruz'],
            ['Dela Cruz, Juan D.', 'Dela Cruz, Juan Carlos D.'],
            ['Manzo, Kristine D.', 'Kristine, Manzo D.'],
            ['Dela Cruz, Juan', 'Cruz, Juan Dela'],
            ['Peña, José', 'Pena, Jose'], // Meaningful accents are not removed.
            ['Mariah Redjie Galising', 'Galising Mariah Redjie'], // Neither bare name establishes a boundary.
            ['Galising, Mariah Redjie', 'Redjie Mariah Galising'],
            ['Galising, Mariah Redjie', 'Galising, Redjie Mariah'],
            ['Galising, Mariah Redjie', 'Mariah Redjie, Galising'], // Explicit assignments must not be swapped.
            ['Dela Cruz, Juan', 'Juan Cruz Dela'],
            ['Dela Cruz, Juan', 'Cruz Dela, Juan'],
            ['Galising, Mariah Redjie D. Jr.', 'Mariah Redjie P. Galising Jr.'],
            ['Galising, Mariah Redjie', 'Mariah Redjie D. Galising'],
            ['Peña, José María', 'Jose Maria Pena'],
            ["O'Neil, Anne-Marie", 'Anne-Marie O’Neil'], // Punctuation is not discarded.
        ];
    }

    #[DataProvider('distinctNames')]
    public function test_meaningful_components_are_not_discarded_or_sorted(string $stored, string $submitted): void
    {
        $this->seedUser(['full_name' => $stored]);
        $this->assertNotSame(UserNamePolicy::key($stored), UserNamePolicy::key($submitted));
        $this->add(['full_name' => $submitted])->assertCreated();
        $this->assertSame(3, DB::table('users')->count());
    }

    public static function anchoredAccountCases(): array
    {
        return [['maintenance_staff', 'active'], ['maintenance_admin', 'pending'], ['super_admin', 'inactive']];
    }

    #[DataProvider('anchoredAccountCases')]
    public function test_structured_add_blocks_anchored_duplicates_across_roles_departments_and_statuses(string $role, string $status): void
    {
        $otherDepartment = DB::table('departments')->insertGetId(['name' => 'Other synthetic department', 'status' => 'active'], 'department_id');
        $existing = $this->seedUser(['full_name' => 'Mariah Redjie Galising', 'role' => $role,
            'department_id' => $otherDepartment, 'status' => $status, 'username' => 'private_identity']);
        $before = (array) DB::table('users')->where('user_id', $existing)->first();
        $response = $this->add(['last_name' => 'Galising', 'first_name' => 'Mariah Redjie',
            'middle_initial' => '', 'suffix' => '', 'full_name' => 'Conflicting Client Name']);
        $response->assertStatus(422)->assertJsonValidationErrors('full_name');
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame($before, (array) DB::table('users')->where('user_id', $existing)->first());
        foreach (['private_identity', $role, $status, 'Mariah'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
        }
    }

    #[DataProvider('suffixes')]
    public function test_anchored_structured_add_retains_each_suffix_in_matching(string $input, string $canonical): void
    {
        $this->seedUser(['full_name' => 'Mariah Redjie Galising' . ($canonical !== '' ? ' ' . $canonical : '')]);
        $this->add(['last_name' => 'Galising', 'first_name' => 'Mariah Redjie', 'middle_initial' => '', 'suffix' => $input])
            ->assertStatus(422)->assertJsonValidationErrors('full_name');
        $this->assertSame(2, DB::table('users')->count());
    }

    public static function differentAnchoredSuffixes(): array
    {
        return [['', 'Jr.'], ['Jr.', 'Sr.'], ['Sr.', 'II'], ['II', 'III'], ['III', 'IV'], ['IV', 'V'], ['V', '']];
    }

    #[DataProvider('differentAnchoredSuffixes')]
    public function test_different_suffixes_remain_distinct_across_anchored_layouts(string $storedSuffix, string $newSuffix): void
    {
        $this->seedUser(['full_name' => 'Galising, Mariah Redjie' . ($storedSuffix !== '' ? ' ' . $storedSuffix : '')]);
        $this->add(['full_name' => 'Mariah Redjie Galising' . ($newSuffix !== '' ? ' ' . $newSuffix : '')])->assertCreated();
        $this->assertSame(3, DB::table('users')->count());
    }

    public static function anchoredWriteBoundaries(): array
    {
        return [
            ['edit', 'Mariah Redjie Galising', 'Galising, Mariah Redjie'],
            ['profile', 'Mariah Redjie Galising', 'Galising, Mariah Redjie'],
            ['signup', 'Mariah Redjie Galising', 'Galising, Mariah Redjie'],
            ['edit', 'Galising, Mariah Redjie', 'Galising Mariah Redjie'],
            ['profile', 'Galising, Mariah Redjie', 'Mariah Redjie Galising'],
            ['signup', 'Galising, Mariah Redjie', 'Galising Mariah Redjie'],
        ];
    }

    #[DataProvider('anchoredWriteBoundaries')]
    public function test_anchored_matching_applies_to_changed_edit_profile_and_public_signup(string $flow, string $stored, string $candidate): void
    {
        $id = $this->seedUser(['full_name' => 'Original Person', 'role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->seedUser(['full_name' => $stored, 'status' => 'inactive', 'username' => 'private_identity']);
        $response = match ($flow) {
            'edit' => $this->edit($id, $candidate),
            'profile' => $this->profile($id, ['full_name' => $candidate]),
            'signup' => $this->signup(['full_name' => $candidate]),
        };
        $response->assertStatus(422)->assertJsonValidationErrors('full_name');
        $this->assertSame(3, DB::table('users')->count());
        $this->assertSame('Original Person', DB::table('users')->where('user_id', $id)->value('full_name'));
        foreach (['private_identity', 'inactive', 'Mariah'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
        }
    }

    public function test_anchored_legacy_duplicates_allow_unchanged_names_and_exclude_self_on_reformat(): void
    {
        $id = $this->seedUser(['full_name' => 'Mariah Redjie Galising', 'role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->edit($id, 'Galising, Mariah Redjie')->assertOk();
        $this->profile($id, ['full_name' => 'Mariah Redjie Galising'])->assertOk();
        $this->seedUser(['full_name' => 'Galising, Mariah Redjie', 'status' => 'inactive']);
        $this->edit($id, 'Mariah Redjie Galising', ['designation' => 'Updated designation'])->assertOk();
        $this->profile($id, ['full_name' => 'Mariah Redjie Galising', 'phone' => '09171234567'])->assertOk();
        $this->assertSame('Mariah Redjie Galising', DB::table('users')->where('user_id', $id)->value('full_name'));
        $this->assertSame('Updated designation', DB::table('users')->where('user_id', $id)->value('designation'));
        $this->assertSame(3, DB::table('users')->count());
    }

    public static function accountStatuses(): array { return [['pending'], ['inactive'], ['active']]; }

    #[DataProvider('accountStatuses')]
    public function test_duplicate_check_includes_every_account_status(string $status): void
    {
        $this->seedUser(['full_name' => 'Cruz, Juan III', 'status' => $status]);
        $this->add(['full_name' => 'Cruz, Juan III'])->assertStatus(422)->assertJsonValidationErrors('full_name');
    }

    public function test_signup_blocks_duplicate_without_disclosing_account_details(): void
    {
        $this->seedUser(['full_name' => 'Peña, José III', 'username' => 'private_identity',
            'email' => 'private@example.test', 'role' => 'maintenance_staff', 'status' => 'inactive']);
        $response = $this->signup(['full_name' => 'PEÑA, JOSÉ iii'])->assertStatus(422)->assertJsonValidationErrors('full_name');
        foreach (['private_identity', 'private@example.test', 'maintenance_staff', 'inactive', 'Peña'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame(2, DB::table('users')->count());
    }

    public static function validNames(): array
    {
        return [['Peña, Ñora Jr.'], ["Pen\u{0303}a, Jose\u{0301}"], ["O’Neil-Santos, María Luisa III"],
            ["O'Neil, Anne-Marie"], ['Juan Dela Cruz'], ['Dela Cruz, Juan Jr.']];
    }

    #[DataProvider('validNames')]
    public function test_add_edit_signup_and_profile_share_unicode_name_policy(string $name): void
    {
        $added = $this->add(['full_name' => $name])->assertCreated();
        $id = (int) $added->json('data.user_id');
        $normalized = UserNamePolicy::normalize($name);
        $this->assertSame($normalized, DB::table('users')->where('user_id', $id)->value('full_name'));
        $this->edit($id, $normalized)->assertOk();
        DB::table('users')->where('user_id', $id)->update(['force_profile_update' => 0]);
        $this->profile($id, ['full_name' => $normalized])->assertOk();
        // A different synthetic person exercises registration without removing
        // or modifying the existing account merely to bypass duplicate policy.
        $this->signup(['full_name' => $name . ' Extra'])->assertCreated();
    }

    public function test_profile_name_change_and_admin_edit_cannot_bypass_duplicates(): void
    {
        $id = $this->seedUser(['full_name' => 'Original Person', 'role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->seedUser(['full_name' => 'Dela Cruz, Juan III', 'status' => 'pending']);
        $this->edit($id, 'Dela Cruz, Juan III')->assertStatus(422)->assertJsonValidationErrors('full_name');
        $this->profile($id, ['full_name' => 'DELA CRUZ, JUAN iii'])->assertStatus(422)->assertJsonValidationErrors('full_name');
        $this->assertSame('Original Person', DB::table('users')->where('user_id', $id)->value('full_name'));
    }

    public function test_self_is_excluded_when_a_name_is_explicitly_changed(): void
    {
        $id = $this->seedUser(['full_name' => 'Cruz, Juan Jr.', 'role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->edit($id, 'CRUZ, JUAN JR')->assertOk();
        $this->assertSame('CRUZ, JUAN Jr.', DB::table('users')->where('user_id', $id)->value('full_name'));
        $this->profile($id, ['full_name' => '  Cruz ,  Juan   jr '])->assertOk();
        $this->assertSame('Cruz, Juan Jr.', DB::table('users')->where('user_id', $id)->value('full_name'));
    }

    public static function invalidNames(): array
    {
        return [['Juan123'], ['<img src=x>'], ['...'], ['123'], ['A']];
    }

    #[DataProvider('invalidNames')]
    public function test_changed_invalid_name_is_rejected_by_every_backend_flow(string $name): void
    {
        $id = $this->seedUser(['full_name' => 'Original Person', 'role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->add(['full_name' => $name])->assertStatus(422);
        $this->edit($id, $name)->assertStatus(422);
        $this->profile($id, ['full_name' => $name])->assertStatus(422);
        $this->signup(['full_name' => $name])->assertStatus(422);
        $this->assertSame('Original Person', DB::table('users')->where('user_id', $id)->value('full_name'));
        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_unchanged_legacy_duplicates_and_suffixes_do_not_block_unrelated_updates(): void
    {
        foreach (['Legacy 123 <name> VI', 'Cruz, Juan Jr.'] as $name) {
            $id = $this->seedUser(['full_name' => $name, 'role' => 'maintenance_staff', 'department_id' => $this->department]);
            $this->seedUser(['full_name' => $name, 'status' => 'inactive']);
            $this->edit($id, $name, ['designation' => 'Updated designation'])->assertOk();
            $this->profile($id, ['full_name' => $name, 'phone' => '09171234567'])->assertOk();
            $this->assertSame($name, DB::table('users')->where('user_id', $id)->value('full_name'));
            $this->assertSame('Updated designation', DB::table('users')->where('user_id', $id)->value('designation'));
        }
    }

    public function test_forced_password_completion_preserves_unchanged_legacy_name(): void
    {
        $name = 'Legacy 123 <name> VI';
        $id = $this->seedUser(['full_name' => $name, 'role' => 'maintenance_staff', 'force_profile_update' => 1,
            'phone' => '09171234567', 'verified_email' => 'verified@example.test', 'password' => Hash::make('Temporary!123')]);
        $this->seedUser(['full_name' => $name]);
        $this->profile($id, ['full_name' => $name, 'current_password' => 'Temporary!123', 'new_password' => 'Permanent!123'])
            ->assertOk()->assertJsonPath('data.requires_reauthentication', true);
        $row = DB::table('users')->where('user_id', $id)->first();
        $this->assertSame($name, $row->full_name);
        $this->assertSame(0, (int) $row->force_profile_update);
        $this->assertSame(1, (int) $row->auth_version);
        $this->assertTrue(Hash::check('Permanent!123', $row->password));
        $this->assertFalse($this->app['session']->has('auth_user'));
    }

    public function test_255_character_name_round_trips_without_truncation(): void
    {
        $last = str_repeat('Ñ', 125);
        $first = str_repeat('A', 128);
        $name = $last . ', ' . $first;
        $this->assertSame(255, mb_strlen($name));
        $added = $this->add(['last_name' => $last, 'first_name' => $first])->assertCreated();
        $id = (int) $added->json('data.user_id');
        $this->edit($id, $name)->assertOk();
        DB::table('users')->where('user_id', $id)->update(['force_profile_update' => 0]);
        $this->profile($id, ['full_name' => $name])->assertOk();
        $this->assertSame($name, DB::table('users')->where('user_id', $id)->value('full_name'));
        $this->signup(['full_name' => str_repeat('B', 255)])->assertCreated();
    }

    public function test_overlength_names_are_rejected_at_every_write_boundary(): void
    {
        $name = str_repeat('Ñ', 256);
        $id = $this->seedUser(['full_name' => 'Original Name', 'role' => 'maintenance_staff', 'department_id' => $this->department]);
        $this->add(['full_name' => $name])->assertStatus(422);
        $this->add(['last_name' => str_repeat('A', 128), 'first_name' => str_repeat('B', 128)])->assertStatus(422);
        $this->edit($id, $name)->assertStatus(422);
        $this->profile($id, ['full_name' => $name])->assertStatus(422);
        $this->signup(['full_name' => $name])->assertStatus(422);
        $this->assertSame('Original Name', DB::table('users')->where('user_id', $id)->value('full_name'));
        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_username_and_email_uniqueness_and_administrator_permissions_remain(): void
    {
        $this->seedUser(['full_name' => 'Other Person', 'username' => 'taken_username', 'email' => 'taken@example.test']);
        $this->add(['username' => 'taken_username'])->assertStatus(422)->assertJsonValidationErrors('username');
        $this->signup(['username' => 'taken_username'])->assertStatus(422);
        $this->signup(['email' => 'taken@example.test'])->assertStatus(422);
        $staff = $this->seedUser(['full_name' => 'Synthetic Staff', 'role' => 'maintenance_staff']);
        $_SESSION = [];
        $this->actingAsSessionUser($staff, 'super_admin')->postJson('/api/users', $this->addPayload())->assertForbidden();
        $this->assertSame(3, DB::table('users')->count());
    }

    private function addPayload(array $extra = []): array
    {
        return array_replace(['full_name' => 'Dela Cruz, Juan', 'username' => 'synthetic_new', 'password' => 'Synthetic!123',
            'role' => 'maintenance_staff', 'department_id' => $this->department], $extra);
    }

    private function add(array $extra = [])
    {
        $_SESSION = [];
        return $this->actingAsSessionUser($this->admin, 'super_admin')->postJson('/api/users', $this->addPayload($extra));
    }

    private function edit(int $id, string $name, array $extra = [])
    {
        $_SESSION = [];
        return $this->actingAsSessionUser($this->admin, 'super_admin')->patchJson('/api/users/' . $id,
            array_replace(['full_name' => $name, 'role' => 'maintenance_staff', 'department_id' => $this->department], $extra));
    }

    private function profile(int $id, array $extra)
    {
        $_SESSION = [];
        return $this->actingAsSessionUser($id, 'maintenance_staff')->postJson('/api/users/profile', array_replace(['_method' => 'PATCH'], $extra));
    }

    private function signup(array $extra = [])
    {
        $_SESSION = [];
        $this->app['session']->flush();
        return $this->postJson('/api/auth/register', array_replace(['full_name' => 'Signup Person', 'username' => 'synthetic_signup',
            'email' => 'signup@example.test', 'password' => 'Synthetic!123'], $extra));
    }
}

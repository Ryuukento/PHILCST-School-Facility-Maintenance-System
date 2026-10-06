<?php

namespace App\Services;

use App\Models\DeployedAsset;
use App\Models\Dispatch;
use App\Models\DispatchItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-unit asset identity for items released through a Dispatch.
 *
 * Replaces AssetCodeGenerator for the deployed_assets registry. That older
 * service is left untouched (it is unused) but is NOT safe for this purpose:
 * it scans `LIKE prefix% / MAX()` without a lock, embeds the room in the
 * code, and uses ambiguous item initials and a 2-digit sequence.
 *
 * Codes:
 *   - Generated: SFMS-{YYYY}-{NNNNNN}, from a per-year counter row in
 *     asset_code_sequences that is locked FOR UPDATE while it is incremented,
 *     so concurrent releases can never receive the same code. The year is the
 *     year the code was issued; nothing location- or category-dependent is
 *     encoded, because assets move.
 *   - Existing (official school codes): accepted as entered after
 *     normalization, but the "SFMS-" prefix is reserved for generated codes so
 *     a typed code can never collide with, or be mistaken for, one.
 *
 * Codes are immutable: this service has no update path, and DeployedAsset
 * refuses any change to asset_code/code_source.
 */
class AssetRegistryService
{
    public const GENERATED_PREFIX = 'SFMS';

    public const MIN_CODE_LENGTH = 3;
    public const MAX_CODE_LENGTH = 50;

    /** Largest sequence a 6-digit code can carry for one year. */
    public const MAX_SEQUENCE = 999999;

    /**
     * Upper bound on units registered in one release, so a single request
     * cannot create an unbounded number of rows. Far above any realistic
     * classroom/laboratory deployment.
     */
    public const MAX_TRACKED_UNITS_PER_RELEASE = 500;

    /**
     * Roles that may see deployed assets and link reports to them — the same
     * three roles that already see Buildings Overview / deployed items.
     */
    public const VIEWER_ROLES = ['super_admin', 'maintenance_admin', 'maintenance_staff'];

    /**
     * Upper-case letters and digits, with single inner separators
     * (space . _ / -). Must start and end with a letter or digit.
     */
    private const MANUAL_CODE_PATTERN = '/^[A-Z0-9]+(?:[ ._\/-][A-Z0-9]+)*$/';

    /**
     * Trims, collapses inner whitespace to single spaces and upper-cases, so
     * "pc-2025-001 " and "PC-2025-001" are recognised as the same code.
     * Returns '' for blank input (meaning "auto-generate").
     */
    public function normalize(?string $raw): string
    {
        $code = trim((string) $raw);
        if ($code === '') {
            return '';
        }

        $code = preg_replace('/\s+/u', ' ', $code) ?? $code;

        return mb_strtoupper($code, 'UTF-8');
    }

    /**
     * Rules for a code typed by a user (an official/existing school code).
     * Expects a value already passed through normalize().
     */
    public function validateManualCode(string $code, string $field = 'assets'): void
    {
        $length = mb_strlen($code, 'UTF-8');
        if ($length < self::MIN_CODE_LENGTH || $length > self::MAX_CODE_LENGTH) {
            throw ValidationException::withMessages([
                $field => 'Asset code "' . $code . '" must be between ' . self::MIN_CODE_LENGTH . ' and ' . self::MAX_CODE_LENGTH . ' characters.',
            ]);
        }

        if (!preg_match(self::MANUAL_CODE_PATTERN, $code)) {
            throw ValidationException::withMessages([
                $field => 'Asset code "' . $code . '" may only contain letters, numbers, spaces, and the characters - _ . / between them.',
            ]);
        }

        if (preg_match('/^' . self::GENERATED_PREFIX . '[ ._\/-]/', $code)) {
            throw ValidationException::withMessages([
                $field => 'Asset codes starting with "' . self::GENERATED_PREFIX . '-" are reserved for codes the system generates. Leave the field blank to auto-generate one.',
            ]);
        }
    }

    /**
     * The code must not belong to another deployed asset, nor to a legacy
     * room_asset row in items.asset_code. Expects a normalized code.
     */
    public function assertAvailable(string $code, string $field = 'assets'): void
    {
        if ($this->isTaken($code)) {
            throw ValidationException::withMessages([
                $field => 'Asset code "' . $code . '" is already assigned to another asset.',
            ]);
        }
    }

    public function isTaken(string $code): bool
    {
        if (DeployedAsset::query()->where('asset_code', $code)->exists()) {
            return true;
        }

        return DB::table('items')
            ->whereNotNull('asset_code')
            ->whereRaw('UPPER(asset_code) = ?', [$code])
            ->exists();
    }

    /**
     * Issues the next SFMS-{YYYY}-{NNNNNN} code.
     *
     * The year's counter row is locked FOR UPDATE for the remainder of the
     * caller's transaction, so concurrent callers serialize on it and each
     * receives a distinct value. The deployed_assets UNIQUE index remains the
     * final guard. A value that collides with a pre-existing code (only
     * possible through legacy items.asset_code data) is skipped.
     */
    public function generate(?int $year = null): string
    {
        $year = $year ?? (int) now()->format('Y');

        return DB::transaction(function () use ($year): string {
            $row = $this->lockSequenceRow($year);
            $value = (int) $row->last_value;

            do {
                $value++;
                if ($value > self::MAX_SEQUENCE) {
                    throw ValidationException::withMessages([
                        'assets' => 'No more asset codes can be generated for ' . $year . '.',
                    ]);
                }
                $code = sprintf('%s-%04d-%06d', self::GENERATED_PREFIX, $year, $value);
            } while ($this->isTaken($code));

            DB::table('asset_code_sequences')
                ->where('year', $year)
                ->update(['last_value' => $value, 'updated_at' => now()]);

            return $code;
        });
    }

    private function lockSequenceRow(int $year): object
    {
        $row = DB::table('asset_code_sequences')->where('year', $year)->lockForUpdate()->first();
        if ($row !== null) {
            return $row;
        }

        // First code of a new year. insertOrIgnore lets two first-time callers
        // race safely: one inserts, the other is a no-op, and both then lock
        // the same row.
        DB::table('asset_code_sequences')->insertOrIgnore([
            'year' => $year,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('asset_code_sequences')->where('year', $year)->lockForUpdate()->first();
    }

    /**
     * Validates the release payload `assets[dispatch_item_id][] = code|blank`
     * against the dispatch's own lines, BEFORE any stock moves. Returns a plan
     * of normalized codes (null = generate) for registerReleasePlan().
     *
     * Every key must be a line of this dispatch, every tracked line must carry
     * exactly one entry per unit, and no code may repeat within the request or
     * already exist.
     *
     * @param  Collection<int, DispatchItem>  $dispatchItems
     * @return list<array{dispatch_item: DispatchItem, codes: list<string|null>}>
     */
    public function buildReleasePlan(Collection $dispatchItems, array $assets): array
    {
        if ($assets === []) {
            return [];
        }

        $linesById = $dispatchItems->keyBy(fn (DispatchItem $line) => (int) $line->id);

        $plan = [];
        $seen = [];
        $totalUnits = 0;

        foreach ($assets as $dispatchItemId => $entries) {
            $line = filter_var($dispatchItemId, FILTER_VALIDATE_INT) !== false
                ? $linesById->get((int) $dispatchItemId)
                : null;

            if ($line === null) {
                throw ValidationException::withMessages([
                    'assets' => 'Asset details were submitted for an item that is not part of this dispatch.',
                ]);
            }

            $itemName = $line->item?->name ?? ('Item #' . $line->item_id);

            if (!is_array($entries) || !array_is_list($entries)) {
                throw ValidationException::withMessages([
                    'assets' => 'Asset details for ' . $itemName . ' are invalid.',
                ]);
            }

            $quantity = (int) $line->quantity;
            if (count($entries) !== $quantity) {
                throw ValidationException::withMessages([
                    'assets' => $itemName . ': enter one asset code entry for each of the ' . $quantity
                        . ' unit' . ($quantity === 1 ? '' : 's') . ' (received ' . count($entries) . ').',
                ]);
            }

            $totalUnits += $quantity;
            if ($totalUnits > self::MAX_TRACKED_UNITS_PER_RELEASE) {
                throw ValidationException::withMessages([
                    'assets' => 'At most ' . self::MAX_TRACKED_UNITS_PER_RELEASE . ' units can be tracked as assets in a single release.',
                ]);
            }

            $codes = [];
            foreach ($entries as $entry) {
                if ($entry !== null && !is_string($entry) && !is_int($entry)) {
                    throw ValidationException::withMessages([
                        'assets' => 'Asset details for ' . $itemName . ' are invalid.',
                    ]);
                }

                $code = $this->normalize($entry === null ? null : (string) $entry);
                if ($code === '') {
                    $codes[] = null;
                    continue;
                }

                $this->validateManualCode($code);

                if (isset($seen[$code])) {
                    throw ValidationException::withMessages([
                        'assets' => 'Asset code "' . $code . '" was entered more than once.',
                    ]);
                }
                $seen[$code] = true;

                $this->assertAvailable($code);
                $codes[] = $code;
            }

            $plan[] = ['dispatch_item' => $line, 'codes' => $codes];
        }

        return $plan;
    }

    /**
     * Creates one deployed_assets row per planned unit. Must run inside the
     * caller's release transaction so a failure anywhere rolls the whole
     * release back.
     *
     * @param  list<array{dispatch_item: DispatchItem, codes: list<string|null>}>  $plan
     * @return Collection<int, DeployedAsset>
     */
    public function registerReleasePlan(Dispatch $dispatch, array $plan, int $actorUserId): Collection
    {
        $registered = collect();

        foreach ($plan as $entry) {
            /** @var DispatchItem $line */
            $line = $entry['dispatch_item'];

            foreach ($entry['codes'] as $code) {
                $registered->push($this->registerForDispatchItem($dispatch, $line, $code, $actorUserId));
            }
        }

        return $registered;
    }

    /**
     * Registers a single unit of a dispatch line. A null/blank $existingCode
     * means "generate an SFMS code"; a supplied code is stored as-is
     * (normalized) with code_source = existing and is never replaced.
     */
    public function registerForDispatchItem(
        Dispatch $dispatch,
        DispatchItem $line,
        ?string $existingCode,
        int $actorUserId
    ): DeployedAsset {
        $code = $this->normalize($existingCode);

        if ($code === '') {
            $code = $this->generate();
            $source = DeployedAsset::CODE_SOURCE_GENERATED;
        } else {
            $this->validateManualCode($code);
            $this->assertAvailable($code);
            $source = DeployedAsset::CODE_SOURCE_EXISTING;
        }

        try {
            return DeployedAsset::query()->create([
                'asset_code' => $code,
                'code_source' => $source,
                'item_id' => (int) $line->item_id,
                'dispatch_id' => (int) $dispatch->id,
                'dispatch_item_id' => (int) $line->id,
                'room_id' => $dispatch->room_id !== null ? (int) $dispatch->room_id : null,
                'status' => DeployedAsset::STATUS_ACTIVE,
                'deployed_at' => now(),
                'created_by' => $actorUserId > 0 ? $actorUserId : null,
            ]);
        } catch (QueryException $e) {
            // The availability check above ran without a lock, so a concurrent
            // release can still claim the same existing code first. The UNIQUE
            // index catches it; report it as the same clean validation error
            // rather than exposing the database message.
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'assets' => 'Asset code "' . $code . '" is already assigned to another asset.',
                ]);
            }

            throw $e;
        }
    }

    public function canAccess(array $authUser, ?DeployedAsset $asset = null): bool
    {
        $role = RoleNormalizerService::normalize((string) ($authUser['role'] ?? ''));

        return in_array($role, self::VIEWER_ROLES, true);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // 23000 = integrity constraint violation (MySQL 1062 duplicate entry;
        // SQLite reports UNIQUE failures under the same SQLSTATE).
        return $sqlState === '23000' && ($driverCode === 1062 || $driverCode === 0 || $driverCode === 19 || $driverCode === 2067);
    }
}

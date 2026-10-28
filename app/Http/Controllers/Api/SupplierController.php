<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierHistory;
use App\Services\ActivityLogService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    use ApiResponder;

    /**
     * Philippine mobile (09XXXXXXXXX / +639XXXXXXXXX) or landline (area code +
     * 7-8 digit subscriber number, Metro Manila's "2" or a provincial 2-digit
     * code) number, applied AFTER normalizePhone() strips spaces/hyphens/
     * parentheses -- so "(02) 8123-4567" and "02-8123-4567" both validate the
     * same way a user would actually type them.
     */
    private const PHONE_REGEX = '/^(\+63|0)(9\d{9}|2\d{7,8}|[3-8]\d{7,8})$/';

    /**
     * Letters (incl. ñ/accented via \p{L}), spaces, hyphens, apostrophes, and
     * periods only -- covers "Juan Dela Cruz", "Maria O'Brien",
     * "Jose Santos-Reyes", "Dr. Jose Rizal Jr." etc.
     */
    private const CONTACT_PERSON_REGEX = "/^[\p{L}\s'.-]+$/u";

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    /**
     * Validation rules shared by store()/update(). Deliberately lenient on
     * `name`: no character-class restriction, only length + the existing
     * case-insensitive duplicate check below -- legitimate business names
     * routinely use "&", commas, and periods ("ABC Hardware & Supply",
     * "J&J Trading", "ABC Co., Ltd."), so a character whitelist there would
     * reject real suppliers.
     */
    private function supplierValidationRules(bool $isUpdate, ?string $existingEmail = null): array
    {
        $wrap = fn (array $rules) => $isUpdate ? array_merge(['sometimes'], $rules) : $rules;

        return [
            'name' => $isUpdate
                ? ['sometimes', 'string', 'min:3', 'max:100']
                : ['required', 'string', 'min:3', 'max:100'],
            'contact_person' => $wrap(['nullable', 'string', 'max:255', 'regex:' . self::CONTACT_PERSON_REGEX]),
            'contact_email' => $wrap(['nullable', 'email', 'max:255', function ($attribute, $value, $fail) use ($isUpdate, $existingEmail) {
                // Only the persisted, unchanged legacy address is exempt from the domain policy.
                if ($isUpdate && $value === $existingEmail) {
                    return;
                }
                if (!is_string($value) || !preg_match('/@(?:gmail\.com|yahoo\.com)\z/i', $value)) {
                    $fail('Contact email must use gmail.com or yahoo.com.');
                }
            }]),
            'phone' => $wrap(['nullable', 'string', 'max:50', 'regex:' . self::PHONE_REGEX]),
            'address' => $wrap(['nullable', 'string', 'max:255']),
            'notes' => $wrap(['nullable', 'string', 'max:500']),
            'status' => $wrap(['nullable', 'string', 'in:active,inactive']),
        ];
    }

    private function supplierValidationMessages(): array
    {
        return [
            'name.min' => 'Supplier name must be at least 3 characters.',
            'name.max' => 'Supplier name may not exceed 100 characters.',
            'contact_person.regex' => 'Contact person may only contain letters, spaces, hyphens, apostrophes, and periods.',
            'phone.regex' => 'Phone must be a valid Philippine mobile or landline number.',
            'address.max' => 'Address may not exceed 255 characters.',
            'notes.max' => 'Notes may not exceed 500 characters.',
        ];
    }

    /**
     * Strips spaces, hyphens, and parentheses from the phone input before
     * validation/storage, so "(02) 8123-4567" is accepted by PHONE_REGEX and
     * stored in one consistent shape instead of rejecting every format a
     * user might reasonably type.
     */
    private function normalizePhone(Request $request): void
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => preg_replace('/[\s\-()]/', '', (string) $request->input('phone'))]);
        }
    }

    /**
     * 2026-10-27 — duplicate-name requirement explicitly includes whitespace
     * variants ("ABC Hardware Supply" vs "ABC  Hardware Supply" with a double
     * space), which the old `LOWER(name) = ?` SQL comparison does not catch.
     * Collapsing runs of whitespace and trimming before lowercasing closes
     * that gap. Deliberately does NOT touch character content -- per the
     * standing "don't be too strict on Supplier Name" instruction, names like
     * "ABC Hardware & Supply" / "J&J Trading" / "ABC Co., Ltd." must keep
     * comparing on their own merits, only normalized for stray whitespace.
     * Done in PHP (not SQL) so the comparison behaves identically on SQLite
     * (tests) and MySQL/MariaDB (production) without a DB-specific regex
     * function; suppliers tables are small, so loading id+name is cheap.
     */
    private function normalizeNameForComparison(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    private function findDuplicateSupplierId(string $name, ?int $excludeId = null): ?int
    {
        $normalizedNew = $this->normalizeNameForComparison($name);

        $query = Supplier::query()->select(['id', 'name']);
        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }

        foreach ($query->get() as $candidate) {
            if ($this->normalizeNameForComparison($candidate->name) === $normalizedNew) {
                return $candidate->id;
            }
        }

        return null;
    }

    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        // suppliers-manage.php sends `search`; Components.SearchableSelect
        // (used by the New Receipt supplier field) always sends `q` --
        // same name filter, two established param-name conventions already
        // in use elsewhere in this app (e.g. DepartmentController uses `q`),
        // so both are accepted rather than forcing either caller to change.
        $searchTerm = $request->filled('search')
            ? $request->string('search')->toString()
            : ($request->filled('q') ? $request->string('q')->toString() : null);
        if ($searchTerm !== null) {
            $query->where('name', 'like', "%{$searchTerm}%");
        }

        $suppliers = $query->orderBy('name')->paginate((int)$request->integer('per_page', 20));

        return $this->ok('Suppliers retrieved', $suppliers);
    }

    public function store(Request $request)
    {
        $this->normalizePhone($request);

        $validated = $request->validate(
            $this->supplierValidationRules(isUpdate: false),
            $this->supplierValidationMessages()
        );

        // Prevent duplicates by name (case-insensitive, whitespace-normalized)
        if ($this->findDuplicateSupplierId($validated['name']) !== null) {
            return $this->fail('A supplier with this name already exists', 409);
        }

        $supplier = Supplier::query()->create($validated);

        SupplierHistory::query()->create([
            'supplier_id' => $supplier->id,
            'action' => 'created',
            'details' => 'Supplier created',
            'performed_by' => $request->session()->get('user_id'),
        ]);

        $this->activityLogService->log([
            'user_id' => $request->session()->get('user_id'),
            'user_role' => $request->session()->get('role'),
            'action' => 'CREATE_SUPPLIER',
            'module' => 'supplier',
            'entity_type' => 'supplier',
            'entity_id' => $supplier->id,
            'details' => 'Created supplier ' . $supplier->name . '.',
        ], $request);

        return $this->ok('Supplier created', ['supplier_id' => $supplier->id], 201);
    }

    public function show(Request $request, Supplier $supplier)
    {
        $supplier->load('histories');
        return $this->ok('Supplier retrieved', ['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $this->normalizePhone($request);

        $validated = $request->validate(
            $this->supplierValidationRules(isUpdate: true, existingEmail: $supplier->contact_email),
            $this->supplierValidationMessages()
        );

        if (
            isset($validated['name'])
            && $this->normalizeNameForComparison($validated['name']) !== $this->normalizeNameForComparison($supplier->name)
            && $this->findDuplicateSupplierId($validated['name'], excludeId: $supplier->id) !== null
        ) {
            return $this->fail('A supplier with this name already exists', 409);
        }

        $supplier->update($validated);

        SupplierHistory::query()->create([
            'supplier_id' => $supplier->id,
            'action' => 'updated',
            'details' => 'Supplier updated',
            'performed_by' => $request->session()->get('user_id'),
        ]);

        $this->activityLogService->log([
            'user_id' => $request->session()->get('user_id'),
            'user_role' => $request->session()->get('role'),
            'action' => 'UPDATE_SUPPLIER',
            'module' => 'supplier',
            'entity_type' => 'supplier',
            'entity_id' => $supplier->id,
            'details' => 'Updated supplier ' . $supplier->name . '.',
        ], $request);

        return $this->ok('Supplier updated successfully');
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        // Prevent deletion if linked to inventory_stock_entries
        $hasLink = DB::table('inventory_stock_entries')->where('supplier_id', $supplier->id)->exists();
        if ($hasLink) {
            return $this->fail('Cannot delete supplier with linked inventory entries', 400);
        }

        DB::transaction(function () use ($request, $supplier): void {
            SupplierHistory::query()->create([
                'supplier_id' => $supplier->id,
                'action' => 'deleted',
                'details' => 'Supplier deleted',
                'performed_by' => $request->session()->get('user_id'),
            ]);

            $this->activityLogService->log([
                'user_id' => $request->session()->get('user_id'),
                'user_role' => $request->session()->get('role'),
                'action' => 'DELETE_SUPPLIER',
                'module' => 'supplier',
                'entity_type' => 'supplier',
                'entity_id' => $supplier->id,
                'details' => 'Deleted supplier ' . $supplier->name . '.',
            ], $request);

            $supplier->delete();
        });

        return $this->ok('Supplier deleted successfully');
    }

    public function history(Request $request, Supplier $supplier)
    {
        $histories = SupplierHistory::query()->where('supplier_id', $supplier->id)->orderByDesc('created_at')->get();

        $stockEntries = DB::table('inventory_stock_entries')->where('supplier_id', $supplier->id)->orderByDesc('created_at')->get();

        // Purchase Receipts "View Supplier History" -- the New Receipt form's
        // supplier field needs to show what's been bought from this supplier
        // before. purchase_receipts.supplier_id only exists going forward from
        // 2026_10_27_000100_add_supplier_id_to_purchase_receipts.php (nullable,
        // not backfilled), so older receipts that only ever had the free-text
        // supplier_name column simply won't appear here -- acceptable, matches
        // how that migration already documented the tradeoff.
        $purchaseReceipts = DB::table('purchase_receipts')
            ->where('purchase_receipts.supplier_id', $supplier->id)
            ->leftJoin('purchase_receipt_items', 'purchase_receipt_items.purchase_receipt_id', '=', 'purchase_receipts.id')
            ->selectRaw('purchase_receipts.id, purchase_receipts.or_number, purchase_receipts.receipt_date, purchase_receipts.status, purchase_receipts.remarks, purchase_receipts.created_at, COUNT(purchase_receipt_items.id) as item_count')
            ->groupBy('purchase_receipts.id', 'purchase_receipts.or_number', 'purchase_receipts.receipt_date', 'purchase_receipts.status', 'purchase_receipts.remarks', 'purchase_receipts.created_at')
            ->orderByDesc('purchase_receipts.receipt_date')
            ->orderByDesc('purchase_receipts.id')
            ->get();

        return $this->ok('Supplier history retrieved', [
            'histories' => $histories,
            'stock_entries' => $stockEntries,
            'purchase_receipts' => $purchaseReceipts,
        ]);
    }
}

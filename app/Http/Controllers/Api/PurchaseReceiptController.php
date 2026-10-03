<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\PurchaseReceiptPostingService;
use App\Services\RoleNormalizerService;
use App\Support\ApiResponder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptController extends Controller
{
    use ApiResponder;

    private const ALLOWED_POST_ROLES = ['super_admin', 'maintenance_admin', 'maintenance_staff'];

    /**
     * TASK H — upper bound on one bulk add. Not a business rule, just a guard so
     * a malformed or hostile payload cannot ask for an unbounded number of
     * INSERTs inside a single transaction. The brief asks the modal to stay
     * comfortable at 10–20 rows, so 50 leaves plenty of headroom.
     */
    private const MAX_BULK_LINES = 50;

    public function __construct(
        private readonly PurchaseReceiptPostingService $postingService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    private function sessionUser(Request $request): array
    {
        return (array) $request->session()->get('auth_user', $request->session()->get('user', []));
    }

    private function sessionUserId(Request $request): int
    {
        return (int) ($this->sessionUser($request)['user_id'] ?? 0);
    }

    private function resolveRole(Request $request): string
    {
        return RoleNormalizerService::normalize($this->sessionUser($request)['role'] ?? '');
    }

    public function index(Request $request): JsonResponse
    {
        $receipts = DB::table('purchase_receipts as pr')
            ->select([
                'pr.id',
                'pr.or_number',
                'pr.receipt_date',
                'pr.supplier_name',
                'u.full_name as received_by_name',
                'd.name as department_name',
                'pr.status',
                'pr.proof_image',
                'pr.created_at',
                DB::raw('COUNT(pri.id) as item_count'),
                // INVENTORY REPORTS SEMESTRAL RECONCILIATION -- additive field.
                // item_count above is a line-count (how many distinct item rows
                // this receipt has), not a unit total, so it cannot answer "how
                // many units were received this period." This sums the actual
                // quantity_received across those lines instead.
                DB::raw('COALESCE(SUM(pri.quantity_received), 0) as total_quantity'),
            ])
            ->join('users as u', 'u.user_id', '=', 'pr.received_by')
            ->leftJoin('departments as d', 'd.department_id', '=', 'pr.department_id')
            ->leftJoin('purchase_receipt_items as pri', 'pri.purchase_receipt_id', '=', 'pr.id')
            ->when($request->filled('date_from'), function ($q) use ($request) {
                $q->whereDate('pr.receipt_date', '>=', $request->input('date_from'));
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                $q->whereDate('pr.receipt_date', '<=', $request->input('date_to'));
            })
            ->groupBy(
                'pr.id',
                'pr.or_number',
                'pr.receipt_date',
                'pr.supplier_name',
                'u.full_name',
                'd.name',
                'pr.status',
                'pr.proof_image',
                'pr.created_at'
            )
            ->orderByDesc('pr.created_at')
            ->get();

        return $this->ok('Receipts retrieved', [
            'receipts' => $receipts,
            'pagination' => ['total' => $receipts->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $receivedBy = $this->sessionUserId($request);
        if ($receivedBy <= 0) {
            return $this->fail('Invalid session user', 401);
        }

        $rawReceivedBy = $request->input('received_by_user_id');
        if ($rawReceivedBy !== null && $rawReceivedBy !== '') {
            $overrideId = (int) $rawReceivedBy;
            // Only an active account can be recorded as the receiver.
            if ($overrideId > 0 && DB::table('users')->where('user_id', $overrideId)->where('status', 'active')->exists()) {
                $receivedBy = $overrideId;
            }
        }

        $orNumber = trim((string) $request->input('or_number', ''));
        $receiptDate = trim((string) $request->input('receipt_date', ''));
        $supplierName = trim((string) $request->input('supplier_name', ''));
        $rawDept = $request->input('department_id');
        $departmentId = ($rawDept !== null && $rawDept !== '') ? (int) $rawDept : null;
        $remarks = trim((string) $request->input('remarks', ''));

        if ($orNumber === '' || $receiptDate === '' || $supplierName === '') {
            return $this->fail('OR number, receipt date, and supplier name are required', 422);
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $receiptDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $receiptDate) {
            return $this->fail('Invalid receipt date. Use YYYY-MM-DD format', 422);
        }

        // A receipt records a delivery that already happened.
        if ($receiptDate > now()->toDateString()) {
            return $this->fail('Receipt date cannot be in the future', 422);
        }

        if ($departmentId !== null
            && !DB::table('departments')->where('department_id', $departmentId)->where('status', 'active')->exists()) {
            return $this->fail('Please choose an active department', 422);
        }

        if (DB::table('purchase_receipts')->where('or_number', $orNumber)->exists()) {
            return $this->fail('OR number already exists', 409);
        }

        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'or_number' => $orNumber,
            'receipt_date' => $receiptDate,
            'supplier_name' => $supplierName,
            'department_id' => $departmentId,
            'received_by' => $receivedBy,
            'remarks' => $remarks !== '' ? $remarks : null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok('Purchase receipt created', [
            'id' => $receiptId,
            'or_number' => $orNumber,
            'status' => 'draft',
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $receipt = DB::table('purchase_receipts as pr')
            ->select([
                'pr.id', 'pr.or_number', 'pr.receipt_date', 'pr.supplier_name',
                'pr.department_id', 'd.name as department_name',
                'pr.received_by', 'u.full_name as received_by_name',
                'pr.remarks', 'pr.status', 'pr.proof_image', 'pr.created_at', 'pr.updated_at',
            ])
            ->join('users as u', 'u.user_id', '=', 'pr.received_by')
            ->leftJoin('departments as d', 'd.department_id', '=', 'pr.department_id')
            ->where('pr.id', $id)
            ->first();

        if (!$receipt) {
            return $this->fail('Purchase receipt not found', 404);
        }

        $items = DB::table('purchase_receipt_items as pri')
            ->select([
                'pri.id', 'pri.item_id', 'pri.item_name',
                'pri.quantity_received', 'pri.unit',
                'pri.category_id', 'ic.name as category_name',
            ])
            ->leftJoin('inventory_categories as ic', 'ic.id', '=', 'pri.category_id')
            ->where('pri.purchase_receipt_id', $id)
            ->orderBy('pri.id')
            ->get();

        return $this->ok('Receipt retrieved', [
            'receipt' => $receipt,
            'items' => $items,
        ]);
    }

    /**
     * POST /api/purchase-receipts/{id}/proof-image
     *
     * Proof-of-Receipt photo upload, suggested by the school's IT reviewer:
     * let whoever records a purchase receipt attach a photo of the physical
     * OR/receipt as documentation. Deliberately mirrors ReportController's
     * completion_proof_image handling — same MIME whitelist, same 5MB cap,
     * same raw $file->move() storage pattern, same public-URL string saved
     * to the database — so there is only one convention for "an uploaded
     * proof image" across the app.
     *
     * Independent of draft/posted status on purpose: this is documentation,
     * not a stock-affecting field, so it may be attached or replaced at any
     * time. Uploading again simply overwrites the stored path; the old file
     * is left on disk (same behaviour as completion_proof_image today).
     */
    public function uploadProofImage(Request $request, int $id): JsonResponse
    {
        $receipt = DB::table('purchase_receipts')->select('id')->where('id', $id)->first();
        if (!$receipt) {
            return $this->fail('Purchase receipt not found', 404);
        }

        if (!$request->hasFile('proof_image')) {
            return $this->fail('Please choose an image to upload', 422);
        }

        $file = $request->file('proof_image');
        $allowedMimes = [
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
            'image/png'  => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        ];
        $mime = $file->getMimeType();
        if (!isset($allowedMimes[$mime])) {
            return $this->fail('Only JPG, PNG, WEBP, or GIF images are allowed', 422);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->fail('Proof image must be 5MB or smaller', 422);
        }

        $uploadDir = public_path('frontend/uploads/purchase-receipts');
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        $ext = $allowedMimes[$mime];
        $fileName = 'receipt-' . $id
                  . '-' . date('YmdHis')
                  . '-' . bin2hex(random_bytes(4))
                  . '.' . $ext;
        $file->move($uploadDir, $fileName);
        $publicPath = '/School_Facility_Maintenance_System/frontend/uploads/purchase-receipts/' . $fileName;

        DB::table('purchase_receipts')->where('id', $id)->update([
            'proof_image' => $publicPath,
            'updated_at' => now(),
        ]);

        $this->activityLogService->log([
            'action' => 'UPLOAD_PURCHASE_RECEIPT_PROOF',
            'module' => 'purchase_receipts',
            'entity_type' => 'purchase_receipt',
            'entity_id' => $id,
            'details' => 'Uploaded proof-of-receipt image for purchase receipt #' . $id . '.',
        ], $request);

        return $this->ok('Proof of receipt uploaded', ['id' => $id, 'proof_image' => $publicPath]);
    }

    /**
     * Single-line entry. Still the canonical one-item endpoint, so existing API
     * callers and the current tests are unaffected.
     *
     * TASK H — the normalisation / validation / insert steps it used to inline
     * now live in the private helpers below, which addItems() calls verbatim.
     * There is deliberately only ONE implementation of "what a purchase receipt
     * line is" and only ONE place that decides whether a line is acceptable;
     * bulk entry is a loop over that logic, not a second copy of it. The order
     * of the checks below is unchanged from the original inline version, so the
     * error messages and status codes this endpoint returns are identical.
     */
    public function addItem(Request $request, int $id): JsonResponse
    {
        $line = $this->normalizeLineInput($request->all());

        if ($shapeError = $this->validateLineShape($line)) {
            return $this->fail($shapeError, 422);
        }

        if ($receiptError = $this->draftReceiptError($id)) {
            return $this->fail($receiptError[0], $receiptError[1]);
        }

        if ($refError = $this->validateLineReferences($line)) {
            return $this->fail($refError, 422);
        }

        return $this->ok('Item added to receipt', ['id' => $this->insertLine($id, $line)], 201);
    }

    /**
     * TASK H — bulk line entry. Adding ten different items used to mean ten
     * round trips through addItem(); this accepts them in one request.
     *
     * Behaviourally this is "as if the user had added each line individually":
     * the same normalisation, the same validation, the same INSERT, and the
     * same resulting rows in purchase_receipt_items. Posting is untouched —
     * PurchaseReceiptPostingService still sees ordinary line rows and still
     * creates one InventoryTransaction per line.
     *
     * Two deliberate differences from a loop of individual calls:
     *
     *  1. ATOMICITY. Every row is validated BEFORE anything is written, and the
     *     writes run inside a single DB::transaction. A bad row therefore means
     *     nothing at all was saved, rather than "the first four went in and then
     *     it stopped". The client keeps the user's typed rows and shows which
     *     index failed, so no entries are silently lost.
     *  2. DUPLICATE COMBINING. The same item listed twice in one batch is merged
     *     into a single line with the quantities summed (see
     *     combineDuplicateLines()). That is safe here because posting resolves
     *     stock per line and adds the quantity, so 2+3 on one line and 2 and 3
     *     on two lines produce exactly the same inventory result.
     */
    /**
     * DELETE /api/purchase-receipts/{id}/items/{lineId}
     *
     * Removes a line that was added by mistake. Only while the receipt is
     * still a draft: a draft line has not touched inventory yet (posting is
     * what adds stock), so removing it has no stock effect. Posted receipts
     * stay unchanged — a wrong posted quantity is corrected through a stock
     * adjustment, which keeps its own audit trail.
     */
    public function removeItem(Request $request, int $id, int $lineId): JsonResponse
    {
        if ($receiptError = $this->draftReceiptError($id)) {
            $message = $receiptError[1] === 422 ? 'Cannot remove items from a posted receipt' : $receiptError[0];
            return $this->fail($message, $receiptError[1]);
        }

        $deleted = DB::table('purchase_receipt_items')
            ->where('id', $lineId)
            ->where('purchase_receipt_id', $id)
            ->delete();

        if ($deleted === 0) {
            return $this->fail('Line item not found on this receipt', 404);
        }

        DB::table('purchase_receipts')->where('id', $id)->update(['updated_at' => now()]);

        return $this->ok('Item removed from receipt', ['id' => $lineId]);
    }

    public function addItems(Request $request, int $id): JsonResponse
    {
        $rawLines = $request->input('items');

        if (!is_array($rawLines) || $rawLines === []) {
            return $this->fail('items must be a non-empty array of line items', 422);
        }
        if (count($rawLines) > self::MAX_BULK_LINES) {
            return $this->fail(
                'A maximum of ' . self::MAX_BULK_LINES . ' items can be added in one operation',
                422
            );
        }

        if ($receiptError = $this->draftReceiptError($id)) {
            return $this->fail($receiptError[0], $receiptError[1]);
        }

        // Validate every row first — nothing is written until all of them pass.
        $lines = [];
        $errors = [];

        foreach (array_values($rawLines) as $index => $raw) {
            if (!is_array($raw)) {
                $errors[] = ['index' => $index, 'message' => 'Invalid line item payload'];
                continue;
            }

            $line = $this->normalizeLineInput($raw);
            $error = $this->validateLineShape($line) ?? $this->validateLineReferences($line);

            if ($error !== null) {
                $errors[] = ['index' => $index, 'message' => 'Row ' . ($index + 1) . ': ' . $error];
                continue;
            }

            $lines[] = $line;
        }

        if ($errors !== []) {
            return $this->fail($errors[0]['message'], 422, ['errors' => $errors]);
        }

        [$lines, $combinedCount, $conflict] = $this->combineDuplicateLines($lines);

        if ($conflict !== null) {
            return $this->fail($conflict, 422);
        }

        $ids = DB::transaction(function () use ($id, $lines): array {
            $inserted = [];
            foreach ($lines as $line) {
                $inserted[] = $this->insertLine($id, $line);
            }

            return $inserted;
        });

        $count = count($ids);

        return $this->ok(
            $count === 1 ? '1 item added to receipt' : $count . ' items added to receipt',
            ['ids' => $ids, 'count' => $count, 'combined' => $combinedCount],
            201
        );
    }

    /**
     * Turns raw request input into the canonical shape of a receipt line.
     * Shared by addItem() and addItems() so both accept exactly the same keys.
     */
    private function normalizeLineInput(array $input): array
    {
        $rawItemId = $input['item_id'] ?? null;
        $rawCatId = $input['category_id'] ?? null;

        return [
            'item_name' => trim((string) ($input['item_name'] ?? '')),
            'item_id' => ($rawItemId !== null && $rawItemId !== '') ? (int) $rawItemId : null,
            'category_id' => ($rawCatId !== null && $rawCatId !== '') ? (int) $rawCatId : null,
            'quantity_received' => (int) ($input['quantity_received'] ?? 0),
            'unit' => trim((string) ($input['unit'] ?? 'pc')) ?: 'pc',
        ];
    }

    /**
     * Checks that do not touch the database. Returns null when the line is fine.
     */
    private function validateLineShape(array $line): ?string
    {
        if ($line['item_name'] === '' || $line['quantity_received'] <= 0) {
            return 'item_name and quantity_received (> 0) are required';
        }

        return null;
    }

    /**
     * Foreign-key checks. Returns null when every reference resolves.
     */
    private function validateLineReferences(array $line): ?string
    {
        if ($line['item_id'] !== null && !DB::table('items')->where('id', $line['item_id'])->exists()) {
            return 'Item not found';
        }

        return null;
    }

    /**
     * Returns [message, status] when the receipt cannot accept new lines,
     * or null when it can. Draft-only is existing business logic, preserved.
     */
    private function draftReceiptError(int $id): ?array
    {
        $receipt = DB::table('purchase_receipts')
            ->select('id', 'status')
            ->where('id', $id)
            ->first();

        if (!$receipt) {
            return ['Purchase receipt not found', 404];
        }
        if ($receipt->status !== 'draft') {
            return ['Cannot add items to a posted receipt', 422];
        }

        return null;
    }

    /**
     * The one and only write path for a purchase receipt line.
     */
    private function insertLine(int $receiptId, array $line): int
    {
        return DB::table('purchase_receipt_items')->insertGetId([
            'purchase_receipt_id' => $receiptId,
            'item_id' => $line['item_id'],
            'item_name' => $line['item_name'],
            'category_id' => $line['category_id'],
            'quantity_received' => $line['quantity_received'],
            'unit' => $line['unit'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Merges rows that refer to the same item, summing their quantities.
     *
     * Identity mirrors how PurchaseReceiptPostingService::resolveInventoryItem()
     * already decides which stock row a line belongs to. That method falls back
     * to a case-insensitive NAME match whenever it has no usable item_id, which
     * is why identity here is the name rather than the id: one row picked from
     * the search box (so it carries an item_id) and one row where the user just
     * typed the same name are the same stock item as far as posting is
     * concerned, and must be treated as the same item here too. Keying on the
     * id would have missed exactly that pairing.
     *
     * The one case where the name is not enough is two rows that carry
     * DIFFERENT explicit item_ids under the same name. Those are two distinct
     * stock rows, so they are deliberately left unmerged.
     *
     * Quantities are only merged when the unit matches. "2 box" and "3 pc" of
     * the same item are not 5 of anything, so that case is reported back to the
     * user instead of being silently coerced.
     *
     * Returns [lines, mergedRowCount, conflictMessage|null].
     */
    private function combineDuplicateLines(array $lines): array
    {
        $merged = [];
        $combined = 0;

        foreach ($lines as $line) {
            $identity = 'name:' . mb_strtolower($line['item_name']);
            $existing = $merged[$identity] ?? null;

            $differentStockRows = $existing !== null
                && $existing['item_id'] !== null
                && $line['item_id'] !== null
                && $existing['item_id'] !== $line['item_id'];

            if ($existing === null || $differentStockRows) {
                // Distinct rows that happen to share a name keep their own slot.
                $merged[$differentStockRows ? $identity . '#' . count($merged) : $identity] = $line;
                continue;
            }

            if ($existing['unit'] !== $line['unit']) {
                return [[], 0, sprintf(
                    '"%s" is listed more than once with different units (%s and %s). Use one unit per item.',
                    $line['item_name'],
                    $existing['unit'],
                    $line['unit']
                )];
            }

            $merged[$identity]['quantity_received'] += $line['quantity_received'];
            // Keep whichever row actually identified the stock record.
            $merged[$identity]['item_id'] ??= $line['item_id'];
            $merged[$identity]['category_id'] ??= $line['category_id'];
            $combined++;
        }

        return [array_values($merged), $combined, null];
    }

    public function postReceipt(Request $request, int $id): JsonResponse
    {
        if (!in_array($this->resolveRole($request), self::ALLOWED_POST_ROLES, true)) {
            return $this->fail(
                'Forbidden: Only admins and maintenance staff can post receipts',
                403
            );
        }

        try {
            $this->postingService->postReceipt($id, $this->sessionUserId($request), $request->ip());
        } catch (ModelNotFoundException $e) {
            return $this->fail('Purchase receipt not found', 404);
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Unable to post receipt', 422);
        }

        return $this->ok('Receipt posted. Stock has been updated.', [
            'id' => $id,
            'status' => 'posted',
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $query = DB::table('purchase_receipts as pr')
            ->select([
                'pr.id',
                DB::raw('pr.or_number AS name'),
                DB::raw('pr.supplier_name AS code'),
                'pr.or_number',
                'pr.supplier_name',
                'pr.receipt_date',
                'pr.status',
            ])
            ->orderByDesc('pr.receipt_date')
            ->orderBy('pr.or_number')
            ->limit(50);

        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder->where('pr.or_number', 'LIKE', '%' . $q . '%')
                    ->orWhere('pr.supplier_name', 'LIKE', '%' . $q . '%');
            });
        }

        return $this->ok('Search results', ['receipts' => $query->get()]);
    }
}

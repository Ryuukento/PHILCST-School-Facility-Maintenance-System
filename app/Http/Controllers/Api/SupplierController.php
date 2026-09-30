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

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $q = $request->string('search')->toString();
            $query->where('name', 'like', "%{$q}%");
        }

        $suppliers = $query->orderBy('name')->paginate((int)$request->integer('per_page', 20));

        return $this->ok('Suppliers retrieved', $suppliers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        // Prevent duplicates by name (case-insensitive)
        $exists = Supplier::query()->whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])->exists();
        if ($exists) {
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
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,inactive'],
        ]);

        if (isset($validated['name']) && strtolower($validated['name']) !== strtolower($supplier->name)) {
            $exists = Supplier::query()->whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])->where('id', '<>', $supplier->id)->exists();
            if ($exists) {
                return $this->fail('A supplier with this name already exists', 409);
            }
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

        return $this->ok('Supplier history retrieved', ['histories' => $histories, 'stock_entries' => $stockEntries]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DeliveryType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class DeliveryTypeController extends Controller
{
    private function authorizeDeliveryTypeAccess()
    {
        $admin = auth('admin')->user();

        if (!$admin || !in_array(strtolower($admin->role), ['superadmin', 'manager'])) {
            abort(response()->json([
                'success' => false,
                'message' => 'You do not have permission.',
            ], 403));
        }
    }

    public function index()
    {
        $this->authorizeDeliveryTypeAccess();

        $deliveryTypes = DeliveryType::orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Delivery types retrieved successfully.',
            'data' => $deliveryTypes,
        ]);
    }

    public function show($id)
    {
        $this->authorizeDeliveryTypeAccess();

        $deliveryType = DeliveryType::find($id);

        if (!$deliveryType) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery type not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery type retrieved successfully.',
            'data' => $deliveryType,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeDeliveryTypeAccess();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:delivery_types,name'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:delivery_types,code'],
            'fee' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $deliveryType = DeliveryType::create([
            'name' => trim($validated['name']),
            'code' => strtolower(trim($validated['code'])),
            'fee' => $validated['fee'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Delivery type created successfully.',
            'data' => $deliveryType,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeDeliveryTypeAccess();

        $deliveryType = DeliveryType::find($id);

        if (!$deliveryType) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery type not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('delivery_types', 'name')->ignore($deliveryType->id),
            ],
            'code' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('delivery_types', 'code')->ignore($deliveryType->id),
            ],
            'fee' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $deliveryType->update([
            'name' => trim($validated['name']),
            'code' => strtolower(trim($validated['code'])),
            'fee' => $validated['fee'],
            'is_active' => $validated['is_active'] ?? $deliveryType->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Delivery type updated successfully.',
            'data' => $deliveryType->fresh(),
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        $this->authorizeDeliveryTypeAccess();

        $deliveryType = DeliveryType::find($id);

        if (!$deliveryType) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery type not found.',
            ], 404);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $deliveryType->update([
            'is_active' => $validated['is_active'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $deliveryType->is_active
                ? 'Delivery type enabled successfully.'
                : 'Delivery type disabled successfully.',
            'data' => $deliveryType->fresh(),
        ]);
    }

    public function customerIndex()
    {
        $items = DB::table('delivery_types')
            ->select('id', 'name', 'code', 'fee')
            ->where('is_active', true)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                    'fee' => (float) $item->fee,
                ];
            });

        return response()->json([
            'success' => true,
            'delivery_types' => $items,
        ]);
    }
}
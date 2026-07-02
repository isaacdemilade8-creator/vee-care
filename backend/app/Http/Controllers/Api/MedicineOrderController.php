<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MedicineOrder;
use App\Models\MedicineStockMovement;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MedicineOrderController extends Controller
{
    public function medicines(Request $request): JsonResponse
    {
        return response()->json([
            'medicines' => Medicine::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->paginate($request->integer('per_page', 50)),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = MedicineOrder::query()
            ->with(['medicine', 'patient', 'preparedBy'])
            ->when($request->user()->isRole('patient'), fn ($query) => $query->where('patient_id', $request->user()->id))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json(['orders' => $orders]);
    }

    public function store(Request $request, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'medicine_id' => ['required', 'exists:medicines,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $medicine = Medicine::findOrFail($data['medicine_id']);

        if ($medicine->stock < $data['quantity']) {
            return response()->json(['message' => 'The requested quantity is not available right now.'], 422);
        }

        $order = MedicineOrder::create([
            'organization_id' => null,
            'medicine_id' => $medicine->id,
            'patient_id' => $request->user()->id,
            'quantity' => $data['quantity'],
            'status' => 'pending',
            'pickup_code' => $this->pickupCode(),
            'notes' => $data['notes'] ?? null,
        ])->load(['medicine', 'patient']);

        $audit->record($request, 'medicine_order.created', $order, [
            'medicine' => $medicine->name,
            'quantity' => $order->quantity,
        ]);

        User::query()
            ->whereIn('role', ['admin', 'pharmacist', 'super_admin'])
            ->get()
            ->each(fn (User $user) => $notifications->send(
                $user,
                'medicine_order.created',
                'New medicine pickup order',
                "{$request->user()->name} ordered {$order->quantity} x {$medicine->name}.",
                ['order_id' => $order->id, 'pickup_code' => $order->pickup_code],
            ));

        return response()->json(['message' => 'Medicine order sent to pharmacy.', 'order' => $order], 201);
    }

    public function update(Request $request, MedicineOrder $medicineOrder, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready', 'completed', 'cancelled'])],
            'pharmacist_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = DB::transaction(function () use ($data, $medicineOrder, $request): MedicineOrder {
            $order = MedicineOrder::query()
                ->with(['medicine', 'patient'])
                ->lockForUpdate()
                ->findOrFail($medicineOrder->id);

            $medicine = Medicine::query()->lockForUpdate()->findOrFail($order->medicine_id);
            $updates = [
                'status' => $data['status'],
                'pharmacist_note' => $data['pharmacist_note'] ?? $order->pharmacist_note,
            ];

            if ($data['status'] === 'ready' && $order->prepared_at === null) {
                if ($medicine->stock < $order->quantity) {
                    abort(422, 'Not enough stock to prepare this order.');
                }

                $before = $medicine->stock;
                $medicine->decrement('stock', $order->quantity);
                $medicine->refresh();
                MedicineStockMovement::create([
                    'medicine_id' => $medicine->id,
                    'user_id' => $request->user()->id,
                    'type' => 'dispense',
                    'delta' => -$order->quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $medicine->stock,
                    'reason' => 'Pickup order prepared',
                    'reference' => $order->pickup_code,
                ]);
                $updates['prepared_by'] = $request->user()->id;
                $updates['prepared_at'] = now();
            }

            if ($data['status'] === 'completed') {
                $updates['picked_up_at'] = now();
            }

            if ($data['status'] === 'cancelled' && $order->prepared_at !== null && $order->picked_up_at === null) {
                $before = $medicine->stock;
                $medicine->increment('stock', $order->quantity);
                $medicine->refresh();
                MedicineStockMovement::create([
                    'medicine_id' => $medicine->id,
                    'user_id' => $request->user()->id,
                    'type' => 'return',
                    'delta' => $order->quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $medicine->stock,
                    'reason' => 'Prepared pickup order cancelled',
                    'reference' => $order->pickup_code,
                ]);
                $updates['prepared_at'] = null;
                $updates['prepared_by'] = null;
            }

            $order->update($updates);

            return $order->refresh()->load(['medicine', 'patient', 'preparedBy']);
        });

        $audit->record($request, 'medicine_order.updated', $order, [
            'status' => $order->status,
        ]);

        $notifications->send(
            $order->patient,
            'medicine_order.updated',
            'Medicine order updated',
            "Your {$order->medicine->name} order is now {$order->status}.",
            ['order_id' => $order->id, 'pickup_code' => $order->pickup_code],
        );

        return response()->json(['message' => 'Medicine order updated.', 'order' => $order]);
    }

    private function pickupCode(): string
    {
        do {
            $code = 'RX-' . Str::upper(Str::random(6));
        } while (MedicineOrder::where('pickup_code', $code)->exists());

        return $code;
    }
}

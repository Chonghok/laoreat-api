<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        $role = strtolower((string) ($admin->role ?? ''));

        if (!in_array($role, ['superadmin', 'manager'])) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission.'
            ], 403);
        }

        $payments = Payment::query()
            ->with([
                'order:id,order_number,status,customer_id',
                'customer:id,username,phone_number'
            ])
            ->orderByDesc('id')
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'order_number' => $payment->order?->order_number,
                    'order_status' => $payment->order?->status,

                    'customer_id' => $payment->customer_id,
                    'customer_username' => $payment->customer?->username,
                    'customer_phone' => $payment->customer?->phone_number,

                    'method' => $payment->method,
                    'provider' => $payment->provider,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,

                    'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                    'card_brand' => $payment->card_brand,
                    'card_last4' => $payment->card_last4,
                    'paid_at' => optional($payment->paid_at)?->format('Y-m-d H:i:s'),
                    'created_at' => optional($payment->created_at)?->format('Y-m-d H:i:s'),
                    'updated_at' => optional($payment->updated_at)?->format('Y-m-d H:i:s'),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $payments,
        ]);
    }
}
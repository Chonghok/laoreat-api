<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function overview(Request $request)
    {
        $tz = 'Asia/Phnom_Penh';
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay()->timezone('UTC');
        $todayEnd = $now->copy()->endOfDay()->timezone('UTC');
        $sevenDaysStart = $now->copy()->subDays(6)->startOfDay();

        $summary = [
            'total_orders' => DB::table('orders')->count(),
            'orders_today' => DB::table('orders')
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->count(),

            'paid_revenue' => DB::table('payments')
                ->where('status', 'paid')
                ->sum('amount'),

            'revenue_today' => DB::table('payments')
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$todayStart, $todayEnd])
                ->sum('amount'),

            'active_customers' => DB::table('customers')
                ->where('is_active', true)
                ->count(),

            'new_customers_today' => DB::table('customers')
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->count(),

            'active_products' => DB::table('products')
                ->where('is_active', true)
                ->count(),

            'active_promotions' => DB::table('promo_codes')
                ->where('is_active', true)
                ->where(function ($query) use ($now) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->count(),
        ];

        $orderStatuses = DB::table('orders')
            ->select('status as label', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc('count')
            ->get();

        $paymentStatuses = DB::table('payments')
            ->select('status as label', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc('count')
            ->get();

        $deliveryTypeUsage = DB::table('orders')
            ->join('delivery_types', 'orders.delivery_type_id', '=', 'delivery_types.id')
            ->select('delivery_types.name as label', DB::raw('COUNT(orders.id) as count'))
            ->groupBy('delivery_types.name')
            ->orderByDesc('count')
            ->get();

        $recentOrders = DB::table('orders')
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
            ->select(
                'orders.id',
                'orders.order_number',
                'orders.total_amount',
                'orders.status as order_status',
                'orders.created_at',
                'customers.username as customer_name',
                DB::raw("COALESCE(payments.status, 'pending') as payment_status")
            )
            ->orderByDesc('orders.created_at')
            ->limit(8)
            ->get()
            ->map(function ($order) use ($tz) {
                $order->created_at_display = Carbon::parse($order->created_at)
                    ->timezone($tz)
                    ->format('d/m/Y h:i A');

                return $order;
            });

        $last7Days = collect();
        for ($i = 0; $i < 7; $i++) {
            $day = $sevenDaysStart->copy()->addDays($i);
            $utcStart = $day->copy()->startOfDay()->timezone('UTC');
            $utcEnd = $day->copy()->endOfDay()->timezone('UTC');

            $ordersCount = DB::table('orders')
                ->whereBetween('created_at', [$utcStart, $utcEnd])
                ->count();

            $paidRevenue = DB::table('payments')
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$utcStart, $utcEnd])
                ->sum('amount');

            $last7Days->push([
                'date' => $day->format('Y-m-d'),
                'label' => $day->format('d M'),
                'orders_count' => $ordersCount,
                'paid_revenue' => (float) $paidRevenue,
            ]);
        }

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'order_statuses' => $orderStatuses,
            'payment_statuses' => $paymentStatuses,
            'delivery_type_usage' => $deliveryTypeUsage,
            'last_7_days' => $last7Days,
            'recent_orders' => $recentOrders,
        ]);
    }
}
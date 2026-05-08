<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminPaymentController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $query = PaymentOrder::with(['user:id,name,email', 'plan:id,name,price'])
            ->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return Inertia::render('Admin/Payments/Index', [
            'orders'  => $query->paginate(20)->withQueryString(),
            'filters' => ['status' => $status],
            'counts'  => [
                'pending'   => PaymentOrder::where('status', PaymentOrderStatus::Pending)->count(),
                'verified'  => PaymentOrder::where('status', PaymentOrderStatus::Verified)->count(),
                'rejected'  => PaymentOrder::where('status', PaymentOrderStatus::Rejected)->count(),
            ],
        ]);
    }

    public function verify(Request $request, PaymentOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'is_verified' => ['required', 'boolean'],
            'notes'       => ['nullable', 'string', 'max:1000', 'required_if:is_verified,false'],
        ]);

        if ($order->status->isFinal()) {
            return back()->withErrors(['order' => 'Order ini sudah diproses sebelumnya.']);
        }

        if ($validated['is_verified']) {
            $this->subscriptionService->verifyPayment($order, $request->user());
            $message = 'Pembayaran berhasil diverifikasi. Subscription telah diaktifkan.';
        } else {
            $order->reject($request->user(), $validated['notes'] ?? null);
            $message = 'Pembayaran ditolak.';
        }

        return back()->with('success', $message);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RenewalCheckoutStatus;
use App\Http\Controllers\Controller;
use App\Models\RenewalCheckout;
use App\Services\RenewalPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRenewalController extends Controller
{
    public function __construct(
        private readonly RenewalPaymentService $renewalPaymentService,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'awaiting_review');

        $query = RenewalCheckout::query()
            ->with([
                'user:id,name,email',
                'plan:id,name,slug,price,currency,duration_days',
                'reviewer:id,name,email',
            ])
            ->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $counts = [
            'pending_payment' => RenewalCheckout::where('status', RenewalCheckoutStatus::PendingPayment)->count(),
            'awaiting_review' => RenewalCheckout::where('status', RenewalCheckoutStatus::AwaitingReview)->count(),
            'verified' => RenewalCheckout::where('status', RenewalCheckoutStatus::Verified)->count(),
            'rejected' => RenewalCheckout::where('status', RenewalCheckoutStatus::Rejected)->count(),
            'all' => RenewalCheckout::count(),
        ];

        $paginator = $query->paginate(20)->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(function (RenewalCheckout $checkout) {
                return [
                    'id' => $checkout->id,
                    'period' => $checkout->period->value,
                    'amount' => $checkout->amount,
                    'currency' => $checkout->currency,
                    'status' => $checkout->status->value,
                    'status_label' => $checkout->status->label(),
                    'payment_proof_submitted_at' => $checkout->payment_proof_submitted_at?->toIso8601String(),
                    'reviewed_at' => $checkout->reviewed_at?->toIso8601String(),
                    'has_proof' => $checkout->proof_path !== null,
                    'user' => $checkout->user ? [
                        'id' => $checkout->user->id,
                        'name' => $checkout->user->name,
                        'email' => $checkout->user->email,
                    ] : null,
                    'plan' => $checkout->plan ? [
                        'id' => $checkout->plan->id,
                        'name' => $checkout->plan->name,
                        'slug' => $checkout->plan->slug,
                    ] : null,
                ];
            }),
        );

        return Inertia::render('Admin/Renewals/Index', [
            'checkouts' => $paginator,
            'filters' => ['status' => $status],
            'counts' => $counts,
        ]);
    }

    public function proof(RenewalCheckout $checkout): StreamedResponse
    {
        if ($checkout->proof_path === null || $checkout->proof_disk === null) {
            abort(404);
        }

        $disk = Storage::disk($checkout->proof_disk);

        if (! $disk->exists($checkout->proof_path)) {
            abort(404);
        }

        return $disk->response($checkout->proof_path);
    }

    public function verify(Request $request, RenewalCheckout $checkout): RedirectResponse
    {
        $validated = $request->validate([
            'is_verified' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:is_verified,false'],
        ]);

        if ($checkout->status->isFinal()) {
            return back()->withErrors(['checkout' => 'Checkout ini sudah diproses sebelumnya.']);
        }

        try {
            if ($validated['is_verified']) {
                $this->renewalPaymentService->adminReview($checkout, $request->user(), true, null);
                $message = 'Perpanjangan disetujui. Langganan user telah diperbarui.';
            } else {
                $this->renewalPaymentService->adminReview(
                    $checkout,
                    $request->user(),
                    false,
                    $validated['notes'] ?? null,
                );
                $message = 'Perpanjangan ditolak.';
            }
        } catch (\RuntimeException $e) {
            return back()->withErrors(['checkout' => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }
}

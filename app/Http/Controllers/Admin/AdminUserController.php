<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\VoucherRedemption;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    public function index(Request $request): Response
    {
        $query = User::latest();

        if ($request->filled('search')) {
            $term = $request->query('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%")
                  ->orWhere('external_user_id', 'like', "%{$term}%")
                  ->orWhere('username', 'like', "%{$term}%");
            });
        }

        if ($request->filled('is_admin')) {
            $query->where('is_admin', (bool) $request->query('is_admin'));
        }

        $subFilter = $request->query('subscription');
        if ($subFilter === 'active') {
            $query->whereHas('subscriptions', fn ($q) => $q
                ->where('status', SubscriptionStatus::Active)
                ->where('end_date', '>=', Carbon::today()));
        } elseif ($subFilter === 'trial') {
            $query->whereHas('subscriptions', fn ($q) => $q
                ->where('status', SubscriptionStatus::Trial)
                ->where('end_date', '>=', Carbon::today()));
        } elseif ($subFilter === 'expired') {
            $query->whereDoesntHave('subscriptions', fn ($q) => $q
                ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
                ->where('end_date', '>=', Carbon::today()));
        }

        // Eager-load only current (non-expired active/trial) subscriptions to avoid N+1.
        $query->with(['subscriptions' => function ($q) {
            $q->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
              ->where('end_date', '>=', Carbon::today())
              ->with('plan:id,name')
              ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [SubscriptionStatus::Active->value]);
        }]);

        $paginator = $query->paginate(25)->withQueryString();

        // Flatten current subscription without sending the full collection to the frontend.
        $paginator->getCollection()->transform(function (User $user) {
            $sub = $user->subscriptions->first();
            $arr = $user->makeHidden('subscriptions')->toArray();
            $arr['current_subscription'] = $sub ? [
                'status'   => $sub->status->value,
                'end_date' => $sub->end_date->toDateString(),
                'plan'     => $sub->plan?->name,
            ] : null;
            return $arr;
        });

        return Inertia::render('Admin/Users/Index', [
            'users'   => $paginator,
            'filters' => $request->only(['search', 'is_admin', 'subscription']),
            'counts'  => [
                'total'  => User::count(),
                'admin'  => User::where('is_admin', true)->count(),
                'active' => Subscription::where('status', SubscriptionStatus::Active)
                                ->where('end_date', '>=', Carbon::today())->count(),
            ],
        ]);
    }

    public function show(User $user): Response
    {
        $subscriptions = $user->subscriptions()
            ->with('plan:id,name')
            ->latest()
            ->paginate(10);

        $orders = $user->paymentOrders()
            ->with('plan:id,name,price')
            ->latest()
            ->limit(10)
            ->get();

        $redemptions = VoucherRedemption::where('user_id', $user->id)
            ->with(['voucher:id,code,duration_days', 'subscription:id,status,end_date'])
            ->latest('redeemed_at')
            ->limit(10)
            ->get();

        $plans = SubscriptionPlan::active()->orderBy('price')->get(['id', 'name', 'price']);

        $currentSub = $user->getCurrentSubscription();

        return Inertia::render('Admin/Users/Show', [
            'user'         => $user->makeHidden(['remember_token']),
            'currentSub'   => $currentSub ? [
                'id'       => $currentSub->id,
                'status'   => $currentSub->status->value,
                'end_date' => $currentSub->end_date->toDateString(),
                'plan'     => $currentSub->plan?->name,
            ] : null,
            'subscriptions' => $subscriptions,
            'orders'        => $orders,
            'redemptions'   => $redemptions,
            'plans'         => $plans,
        ]);
    }

    public function toggleAdmin(User $user, Request $request): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'Anda tidak dapat mengubah status admin akun sendiri.']);
        }

        $user->is_admin = ! $user->is_admin;
        $user->save();

        $label = $user->is_admin ? 'dijadikan admin' : 'dicabut hak adminnya';

        return back()->with('success', "User {$user->name} berhasil {$label}.");
    }

    public function activateSubscription(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);
        $this->subscriptionService->activateSubscription($user, $plan);

        return back()->with('success', "Subscription {$plan->name} berhasil diaktifkan untuk {$user->name}.");
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommissionPayoutStatus;
use App\Enums\CommissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\ReferralSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminReferralController extends Controller
{
    public function commissions(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $query = Commission::with([
            'referrer:id,name,email',
            'referred:id,name,email',
        ])->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return Inertia::render('Admin/Referrals/Commissions', [
            'commissions' => $query->paginate(20)->withQueryString(),
            'filters'     => ['status' => $status],
            'counts'      => [
                'pending'   => Commission::where('status', CommissionStatus::Pending)->count(),
                'credited'  => Commission::where('status', CommissionStatus::Credited)->count(),
                'paid_out'  => Commission::where('status', CommissionStatus::PaidOut)->count(),
                'cancelled' => Commission::where('status', CommissionStatus::Cancelled)->count(),
            ],
        ]);
    }

    public function creditCommission(Commission $commission): RedirectResponse
    {
        if ($commission->status !== CommissionStatus::Pending) {
            return back()->withErrors(['commission' => 'Hanya komisi berstatus pending yang bisa di-credit.']);
        }

        $commission->credit();

        return back()->with('success', 'Komisi berhasil di-credit ke saldo referrer.');
    }

    public function cancelCommission(Request $request, Commission $commission): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($commission->status->isFinal()) {
            return back()->withErrors(['commission' => 'Komisi ini sudah dalam status final.']);
        }

        $commission->cancel($validated['reason']);

        return back()->with('success', 'Komisi berhasil dibatalkan.');
    }

    public function payouts(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $query = CommissionPayout::with('user:id,name,email')->latest();

        if ($status && $status !== 'all') {
            if ($status === 'pending') {
                $query->whereIn('status', [
                    CommissionPayoutStatus::Pending->value,
                    CommissionPayoutStatus::Processing->value,
                ]);
            } else {
                $query->where('status', $status);
            }
        }

        return Inertia::render('Admin/Referrals/Payouts', [
            'payouts' => $query->paginate(20)->withQueryString(),
            'filters' => ['status' => $status],
            'counts'  => [
                'pending'   => CommissionPayout::whereIn('status', [
                    CommissionPayoutStatus::Pending->value,
                    CommissionPayoutStatus::Processing->value,
                ])->count(),
                'completed' => CommissionPayout::where('status', CommissionPayoutStatus::Completed)->count(),
                'failed'    => CommissionPayout::where('status', CommissionPayoutStatus::Failed)->count(),
            ],
        ]);
    }

    public function processPayout(Request $request, CommissionPayout $payout): RedirectResponse
    {
        $validated = $request->validate([
            'success' => ['required', 'boolean'],
            'notes'   => ['nullable', 'string', 'max:500', 'required_if:success,false'],
        ]);

        if ($payout->status->isFinal()) {
            return back()->withErrors(['payout' => 'Payout ini sudah dalam status final.']);
        }

        if ($validated['success']) {
            $payout->markCompleted($request->user());
            $message = 'Payout berhasil diselesaikan.';
        } else {
            $payout->markFailed($validated['notes'], $request->user());
            $message = 'Payout ditandai gagal.';
        }

        return back()->with('success', $message);
    }

    public function settings(): Response
    {
        $settings = ReferralSetting::pluck('value', 'key');

        return Inertia::render('Admin/Referrals/Settings', [
            'settings' => $settings,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'commission_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_payout_amount'     => ['sometimes', 'numeric', 'min:0'],
            'payout_method'         => ['sometimes', 'string', 'in:manual,auto'],
            'referral_bonus'        => ['sometimes', 'numeric', 'min:0'],
        ]);

        foreach ($validated as $key => $value) {
            ReferralSetting::where('key', $key)->update(['value' => $value]);
        }

        return back()->with('success', 'Pengaturan referral berhasil disimpan.');
    }
}

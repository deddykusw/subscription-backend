<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminVoucherController extends Controller
{
    public function __construct(private readonly VoucherService $voucherService) {}

    public function index(Request $request): Response
    {
        $query = Voucher::with('plan:id,name')
            ->withCount('redemptions')
            ->latest();

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->query('is_active'));
        }

        if ($request->filled('search')) {
            $query->where('code', 'like', '%'.strtoupper($request->query('search')).'%');
        }

        return Inertia::render('Admin/Vouchers/Index', [
            'vouchers' => $query->paginate(20)->withQueryString(),
            'filters'  => $request->only(['search', 'is_active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code'          => ['nullable', 'string', 'max:50'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'plan_id'       => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'valid_from'    => ['nullable', 'date'],
            'valid_until'   => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->voucherService->create($request->user(), $validated);
            return redirect()->route('admin.vouchers')->with('success', 'Voucher berhasil dibuat.');
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }
    }

    public function bulkGenerate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'quantity'      => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'plan_id'       => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'valid_from'    => ['nullable', 'date'],
            'valid_until'   => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        $codes = $this->voucherService->bulkGenerate($request->user(), $validated);

        return redirect()->route('admin.vouchers')
            ->with('success', count($codes).' voucher berhasil di-generate.')
            ->with('codes', $codes);
    }

    public function show(Voucher $voucher, Request $request): Response
    {
        $redemptions = $voucher->redemptions()
            ->with(['user:id,name,email', 'subscription:id,status,end_date'])
            ->latest('redeemed_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Vouchers/Show', [
            'voucher'     => $voucher->load('plan:id,name')->toArray() + [
                'is_redeemed' => $voucher->used_count > 0,
                'is_usable'   => $voucher->isUsable(),
            ],
            'redemptions' => $redemptions,
        ]);
    }

    public function toggle(Voucher $voucher): RedirectResponse
    {
        $voucher = $this->voucherService->toggle($voucher);
        $label   = $voucher->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return back()->with('success', "Voucher berhasil {$label}.");
    }

    public function destroy(Voucher $voucher): RedirectResponse
    {
        if ($voucher->used_count > 0) {
            return back()->withErrors(['voucher' => 'Voucher yang sudah digunakan tidak dapat dihapus.']);
        }

        $voucher->delete();

        return redirect()->route('admin.vouchers')->with('success', 'Voucher berhasil dihapus.');
    }
}

import { Head, useForm } from '@inertiajs/react'
import AdminLayout from '../../../Layouts/AdminLayout'

export default function ReferralSettings({ settings }) {
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        commission_percentage: settings.commission_percentage ?? '',
        min_payout_amount:     settings.min_payout_amount ?? '',
        payout_method:         settings.payout_method ?? 'manual',
        referral_bonus:        settings.referral_bonus ?? '',
    })

    function submit(e) {
        e.preventDefault()
        put('/admin/referrals/settings')
    }

    return (
        <AdminLayout title="Pengaturan Referral">
            <Head title="Pengaturan Referral" />

            <div className="max-w-lg">
                <div className="bg-white rounded-2xl border border-gray-100 p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Persentase Komisi (%)
                            </label>
                            <input
                                type="number" min={0} max={100} step={0.01}
                                value={data.commission_percentage}
                                onChange={e => setData('commission_percentage', e.target.value)}
                                className="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <p className="mt-1 text-xs text-gray-400">Persentase dari nilai pembayaran yang diberikan ke referrer</p>
                            {errors.commission_percentage && <p className="mt-1 text-xs text-red-600">{errors.commission_percentage}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Minimum Payout (IDR)
                            </label>
                            <input
                                type="number" min={0}
                                value={data.min_payout_amount}
                                onChange={e => setData('min_payout_amount', e.target.value)}
                                className="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <p className="mt-1 text-xs text-gray-400">Saldo minimum sebelum user bisa request payout</p>
                            {errors.min_payout_amount && <p className="mt-1 text-xs text-red-600">{errors.min_payout_amount}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Bonus Referred User (IDR)
                            </label>
                            <input
                                type="number" min={0}
                                value={data.referral_bonus}
                                onChange={e => setData('referral_bonus', e.target.value)}
                                className="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <p className="mt-1 text-xs text-gray-400">Bonus satu kali untuk user yang diundang (0 = tidak ada bonus)</p>
                            {errors.referral_bonus && <p className="mt-1 text-xs text-red-600">{errors.referral_bonus}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Metode Credit Komisi
                            </label>
                            <select
                                value={data.payout_method}
                                onChange={e => setData('payout_method', e.target.value)}
                                className="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
                            >
                                <option value="manual">Manual — admin credit secara manual</option>
                                <option value="auto">Auto — langsung di-credit saat pembayaran verified</option>
                            </select>
                            {errors.payout_method && <p className="mt-1 text-xs text-red-600">{errors.payout_method}</p>}
                        </div>

                        <div className="flex items-center gap-3 pt-1">
                            <button
                                type="submit"
                                disabled={processing}
                                className="px-5 py-2.5 text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white rounded-xl transition-colors"
                            >
                                {processing ? 'Menyimpan...' : 'Simpan Pengaturan'}
                            </button>
                            {recentlySuccessful && (
                                <span className="text-sm text-green-600 font-medium">✓ Tersimpan</span>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    )
}

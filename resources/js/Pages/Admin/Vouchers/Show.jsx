import { Head, Link, router } from '@inertiajs/react'
import { formatDdMmYyyy } from '../../../lib/formatDate'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Pagination from '../../../Components/Pagination'

export default function VoucherShow({ voucher, redemptions }) {
    function toggle() {
        router.post(`/admin/vouchers/${voucher.id}/toggle`, {}, { preserveScroll: true })
    }

    const statusMap = {
        label: voucher.is_redeemed ? 'Sudah Digunakan' : voucher.is_active ? 'Tersedia' : 'Nonaktif',
        status: voucher.is_redeemed ? 'redeemed' : voucher.is_active ? 'available' : 'inactive',
    }

    return (
        <AdminLayout title={`Voucher ${voucher.code}`}>
            <Head title={`Voucher ${voucher.code}`} />

            <div className="mb-4">
                <Link href="/admin/vouchers" className="text-sm text-indigo-600 hover:underline">← Kembali ke Daftar Voucher</Link>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
                {/* Info Card */}
                <div className="md:col-span-1 bg-white rounded-2xl border border-gray-100 p-5 space-y-4">
                    <div className="flex items-start justify-between">
                        <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wide mb-1">Kode Voucher</p>
                            <p className="text-xl font-mono font-bold text-gray-900">{voucher.code}</p>
                        </div>
                        <Badge status={statusMap.status} label={statusMap.label} />
                    </div>

                    <div className="space-y-2 text-sm">
                        <Row label="Durasi">{voucher.duration_days} hari</Row>
                        <Row label="Plan">{voucher.plan?.name ?? 'Termurah'}</Row>
                        <Row label="Berlaku Dari">{formatDdMmYyyy(voucher.valid_from)}</Row>
                        <Row label="Berlaku Hingga">{formatDdMmYyyy(voucher.valid_until)}</Row>
                        <Row label="Catatan">{voucher.notes ?? '–'}</Row>
                        <Row label="Dibuat">{voucher.created_at}</Row>
                    </div>

                    {!voucher.is_redeemed && (
                        <button
                            onClick={toggle}
                            className={`w-full py-2 text-sm font-medium rounded-xl border transition-colors ${
                                voucher.is_active
                                    ? 'border-red-200 text-red-600 hover:bg-red-50'
                                    : 'border-green-200 text-green-700 hover:bg-green-50'
                            }`}
                        >
                            {voucher.is_active ? 'Nonaktifkan Voucher' : 'Aktifkan Voucher'}
                        </button>
                    )}
                </div>

                {/* Redemptions Table */}
                <div className="md:col-span-2 bg-white rounded-2xl border border-gray-100 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-50">
                        <h2 className="text-sm font-semibold text-gray-800">
                            Riwayat Pemakaian
                            <span className="ml-2 text-xs font-normal text-gray-400">({redemptions.meta?.total ?? 0} total)</span>
                        </h2>
                    </div>

                    {redemptions.data?.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-gray-400">Belum ada yang menggunakan voucher ini</div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                                <tr>
                                    <th className="px-4 py-3 text-left">User</th>
                                    <th className="px-4 py-3 text-left">Digunakan</th>
                                    <th className="px-4 py-3 text-left">Subscription</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {redemptions.data.map(r => (
                                    <tr key={r.id}>
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-gray-800">{r.user?.name ?? '–'}</p>
                                            <p className="text-xs text-gray-400">{r.user?.email}</p>
                                        </td>
                                        <td className="px-4 py-3 text-gray-500">{r.redeemed_at ? new Date(r.redeemed_at).toLocaleDateString('id') : '–'}</td>
                                        <td className="px-4 py-3">
                                            {r.subscription ? (
                                                <span>
                                                    <Badge status={r.subscription.status} label={r.subscription.status} />
                                                    <span className="ml-2 text-xs text-gray-400">s/d {r.subscription.end_date}</span>
                                                </span>
                                            ) : '–'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="px-4 pb-4">
                        <Pagination links={redemptions.links} meta={redemptions.meta} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    )
}

function Row({ label, children }) {
    return (
        <div className="flex justify-between gap-2">
            <span className="text-gray-400 shrink-0">{label}</span>
            <span className="text-gray-700 text-right">{children}</span>
        </div>
    )
}

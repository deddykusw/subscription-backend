import { Head, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Modal from '../../../Components/Modal'
import Pagination from '../../../Components/Pagination'

const TABS = [
    { key: 'pending',   label: 'Pending' },
    { key: 'completed', label: 'Completed' },
    { key: 'failed',    label: 'Failed' },
    { key: 'all',       label: 'Semua' },
]

const METHOD_LABEL = { bank_transfer: 'Bank Transfer', paypal: 'PayPal', gopay: 'GoPay', ovo: 'OVO' }

export default function Payouts({ payouts, filters, counts }) {
    const [selected, setSelected] = useState(null)
    const [action, setAction]     = useState(null) // 'approve' | 'reject'
    const form = useForm({ success: true, notes: '' })

    function openApprove(p) { setSelected(p); setAction('approve'); form.setData({ success: true, notes: '' }) }
    function openReject(p)  { setSelected(p); setAction('reject');  form.setData({ success: false, notes: '' }) }
    function closeModal()   { setSelected(null); setAction(null); form.reset() }

    function submit(e) {
        e.preventDefault()
        form.post(`/admin/referrals/payouts/${selected.id}/process`, { onSuccess: closeModal })
    }

    return (
        <AdminLayout title="Payout Referral">
            <Head title="Payout" />

            <div className="flex gap-1 mb-5 bg-gray-100 rounded-xl p-1 w-fit">
                {TABS.map(t => (
                    <button key={t.key}
                        onClick={() => router.get('/admin/referrals/payouts', { status: t.key }, { preserveState: true })}
                        className={`px-4 py-1.5 text-sm rounded-lg font-medium transition-colors ${
                            filters.status === t.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                        }`}>
                        {t.label}
                        {counts[t.key] !== undefined && (
                            <span className="ml-1.5 text-xs bg-gray-200 text-gray-600 rounded-full px-1.5 py-0.5">{counts[t.key]}</span>
                        )}
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th className="px-4 py-3 text-left">User</th>
                            <th className="px-4 py-3 text-left">Jumlah</th>
                            <th className="px-4 py-3 text-left">Metode</th>
                            <th className="px-4 py-3 text-left">Detail</th>
                            <th className="px-4 py-3 text-left">Status</th>
                            <th className="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {payouts.data?.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">Tidak ada payout</td></tr>
                        )}
                        {payouts.data?.map(p => {
                            const isPending = p.status === 'pending' || p.status === 'processing'
                            return (
                                <tr key={p.id} className="hover:bg-gray-50/50">
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-gray-800">{p.user?.name ?? '–'}</p>
                                        <p className="text-xs text-gray-400">{p.user?.email}</p>
                                    </td>
                                    <td className="px-4 py-3 font-semibold text-gray-800">
                                        {Number(p.amount).toLocaleString('id', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">{METHOD_LABEL[p.payout_method] ?? p.payout_method}</td>
                                    <td className="px-4 py-3 text-gray-500 text-xs">
                                        {p.payout_details?.bank_name && <span>{p.payout_details.bank_name} · {p.payout_details.account_number}</span>}
                                        {p.payout_details?.paypal_email && <span>{p.payout_details.paypal_email}</span>}
                                        {p.payout_details?.phone_number && <span>{p.payout_details.phone_number}</span>}
                                    </td>
                                    <td className="px-4 py-3"><Badge status={p.status} label={p.status} /></td>
                                    <td className="px-4 py-3 text-right">
                                        {isPending && (
                                            <div className="flex items-center justify-end gap-2">
                                                <button onClick={() => openApprove(p)}
                                                    className="text-xs px-2.5 py-1 rounded-lg border border-green-200 hover:bg-green-50 text-green-700">
                                                    Selesaikan
                                                </button>
                                                <button onClick={() => openReject(p)}
                                                    className="text-xs px-2.5 py-1 rounded-lg border border-red-200 hover:bg-red-50 text-red-600">
                                                    Gagalkan
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            )
                        })}
                    </tbody>
                </table>
            </div>
            <Pagination links={payouts.links} meta={payouts.meta} />

            <Modal show={!!selected} title={action === 'approve' ? 'Selesaikan Payout' : 'Gagalkan Payout'} onClose={closeModal}>
                {selected && (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="bg-gray-50 rounded-xl p-4 text-sm space-y-2">
                            <Row label="User">{selected.user?.name}</Row>
                            <Row label="Jumlah">{Number(selected.amount).toLocaleString('id')}</Row>
                            <Row label="Metode">{METHOD_LABEL[selected.payout_method] ?? selected.payout_method}</Row>
                            {selected.payout_details?.bank_name && <Row label="Bank">{selected.payout_details.bank_name} · {selected.payout_details.account_number} · {selected.payout_details.account_name}</Row>}
                            {selected.payout_details?.paypal_email && <Row label="PayPal">{selected.payout_details.paypal_email}</Row>}
                            {selected.payout_details?.phone_number && <Row label="No. HP">{selected.payout_details.phone_number}</Row>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Catatan{action === 'reject' ? ' *' : ' (opsional)'}
                            </label>
                            <input value={form.data.notes} onChange={e => form.setData('notes', e.target.value)}
                                placeholder={action === 'approve' ? 'cth: Ditransfer via BCA tgl 8 Mei' : 'Alasan kegagalan'}
                                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                            {form.errors.notes && <p className="mt-1 text-xs text-red-600">{form.errors.notes}</p>}
                        </div>

                        <div className="flex justify-end gap-2 pt-1">
                            <button type="button" onClick={closeModal} className="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Batal</button>
                            <button type="submit" disabled={form.processing}
                                className={`px-4 py-2 text-sm font-medium text-white rounded-xl disabled:opacity-60 ${action === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}`}>
                                {form.processing ? 'Memproses...' : action === 'approve' ? 'Selesaikan' : 'Gagalkan'}
                            </button>
                        </div>
                    </form>
                )}
            </Modal>
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

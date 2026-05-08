import { Head, Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Modal from '../../../Components/Modal'
import Pagination from '../../../Components/Pagination'

const STATUS_TABS = [
    { key: 'pending',  label: 'Pending' },
    { key: 'verified', label: 'Verified' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'all',      label: 'Semua' },
]

export default function PaymentsIndex({ orders, filters, counts }) {
    const [selected, setSelected] = useState(null)
    const [action, setAction]     = useState(null) // 'approve' | 'reject'

    const form = useForm({ is_verified: true, notes: '' })

    function openApprove(order) { setSelected(order); setAction('approve'); form.setData({ is_verified: true, notes: '' }) }
    function openReject(order)  { setSelected(order); setAction('reject');  form.setData({ is_verified: false, notes: '' }) }
    function closeModal()       { setSelected(null); setAction(null); form.reset() }

    function submit(e) {
        e.preventDefault()
        form.post(`/admin/payments/${selected.id}/verify`, { onSuccess: closeModal })
    }

    function setTab(key) {
        router.get('/admin/payments', { status: key }, { preserveState: true })
    }

    return (
        <AdminLayout title="Pembayaran">
            <Head title="Pembayaran" />

            {/* Tabs */}
            <div className="flex gap-1 mb-5 bg-gray-100 rounded-xl p-1 w-fit">
                {STATUS_TABS.map(t => (
                    <button
                        key={t.key}
                        onClick={() => setTab(t.key)}
                        className={`px-4 py-1.5 text-sm rounded-lg font-medium transition-colors ${
                            filters.status === t.key
                                ? 'bg-white text-gray-900 shadow-sm'
                                : 'text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {t.label}
                        {counts[t.key] !== undefined && (
                            <span className="ml-1.5 text-xs bg-gray-200 text-gray-600 rounded-full px-1.5 py-0.5">
                                {counts[t.key]}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* Table */}
            <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th className="px-4 py-3 text-left">User</th>
                            <th className="px-4 py-3 text-left">Plan</th>
                            <th className="px-4 py-3 text-left">Jumlah</th>
                            <th className="px-4 py-3 text-left">Status</th>
                            <th className="px-4 py-3 text-left">Tanggal</th>
                            <th className="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {orders.data?.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">Tidak ada data</td></tr>
                        )}
                        {orders.data?.map(order => (
                            <tr key={order.id} className="hover:bg-gray-50/50">
                                <td className="px-4 py-3">
                                    <p className="font-medium text-gray-800">{order.user?.name ?? '–'}</p>
                                    <p className="text-xs text-gray-400">{order.user?.email}</p>
                                </td>
                                <td className="px-4 py-3 text-gray-600">{order.plan?.name ?? '–'}</td>
                                <td className="px-4 py-3 font-medium text-gray-800">
                                    {Number(order.amount).toLocaleString('id', { style: 'currency', currency: order.currency ?? 'IDR', maximumFractionDigits: 0 })}
                                </td>
                                <td className="px-4 py-3"><Badge status={order.status} label={order.status} /></td>
                                <td className="px-4 py-3 text-gray-500">{order.created_at ? new Date(order.created_at).toLocaleDateString('id') : '–'}</td>
                                <td className="px-4 py-3 text-right">
                                    {order.status === 'pending' ? (
                                        <div className="flex items-center justify-end gap-2">
                                            {order.proof_screenshot_url && (
                                                <a href={order.proof_screenshot_url} target="_blank" rel="noreferrer"
                                                    className="text-xs px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-50 text-gray-600">
                                                    Bukti
                                                </a>
                                            )}
                                            <button onClick={() => openApprove(order)}
                                                className="text-xs px-2.5 py-1 rounded-lg border border-green-200 hover:bg-green-50 text-green-700">
                                                Setujui
                                            </button>
                                            <button onClick={() => openReject(order)}
                                                className="text-xs px-2.5 py-1 rounded-lg border border-red-200 hover:bg-red-50 text-red-600">
                                                Tolak
                                            </button>
                                        </div>
                                    ) : (
                                        <span className="text-xs text-gray-400">
                                            {order.verified_at ? new Date(order.verified_at).toLocaleDateString('id') : '–'}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={orders.links} meta={orders.meta} />

            {/* Confirm Modal */}
            <Modal
                show={!!selected}
                title={action === 'approve' ? 'Setujui Pembayaran' : 'Tolak Pembayaran'}
                onClose={closeModal}
            >
                {selected && (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="bg-gray-50 rounded-xl p-4 text-sm space-y-2">
                            <Row label="User">{selected.user?.name} <span className="text-gray-400">({selected.user?.email})</span></Row>
                            <Row label="Plan">{selected.plan?.name}</Row>
                            <Row label="Jumlah">{Number(selected.amount).toLocaleString('id')}</Row>
                            {selected.transaction_id && <Row label="Tx ID">{selected.transaction_id}</Row>}
                        </div>

                        {action === 'reject' && (
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Alasan Penolakan *</label>
                                <textarea
                                    value={form.data.notes}
                                    onChange={e => form.setData('notes', e.target.value)}
                                    rows={3}
                                    className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                                    placeholder="Jelaskan alasan penolakan kepada user..."
                                />
                                {form.errors.notes && <p className="mt-1 text-xs text-red-600">{form.errors.notes}</p>}
                            </div>
                        )}

                        <div className="flex justify-end gap-2 pt-1">
                            <button type="button" onClick={closeModal}
                                className="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">
                                Batal
                            </button>
                            <button type="submit" disabled={form.processing}
                                className={`px-4 py-2 text-sm font-medium text-white rounded-xl disabled:opacity-60 ${
                                    action === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'
                                }`}>
                                {form.processing ? 'Memproses...' : action === 'approve' ? 'Ya, Setujui' : 'Ya, Tolak'}
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
            <span className="text-gray-400">{label}</span>
            <span className="text-gray-700">{children}</span>
        </div>
    )
}

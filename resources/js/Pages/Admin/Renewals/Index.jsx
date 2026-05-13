import { Head, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Modal from '../../../Components/Modal'
import Pagination from '../../../Components/Pagination'

const STATUS_TABS = [
    { key: 'awaiting_review', label: 'Menunggu review' },
    { key: 'pending_payment', label: 'Belum upload bukti' },
    { key: 'verified', label: 'Disetujui' },
    { key: 'rejected', label: 'Ditolak' },
    { key: 'all', label: 'Semua' },
]

export default function RenewalsIndex({ checkouts, filters, counts }) {
    const [selected, setSelected] = useState(null)
    const [action, setAction] = useState(null)

    const form = useForm({ is_verified: true, notes: '' })

    function openApprove(row) {
        setSelected(row)
        setAction('approve')
        form.setData({ is_verified: true, notes: '' })
    }

    function openReject(row) {
        setSelected(row)
        setAction('reject')
        form.setData({ is_verified: false, notes: '' })
    }

    function closeModal() {
        setSelected(null)
        setAction(null)
        form.reset()
    }

    function submit(e) {
        e.preventDefault()
        if (!selected) return
        form.post(`/admin/renewals/${selected.id}/verify`, { onSuccess: closeModal })
    }

    function setTab(key) {
        router.get('/admin/renewals', { status: key }, { preserveState: true })
    }

    return (
        <AdminLayout title="Perpanjangan (Renewal)">
            <Head title="Renewal" />

            <p className="text-sm text-gray-500 mb-4">
                Data checkout dari aplikasi mobile (token presensi). Bukti gambar disimpan privat — gunakan tombol
                <strong className="font-medium"> Bukti </strong>
                untuk membuka file.
            </p>

            <div className="flex flex-wrap gap-1 mb-5 bg-gray-100 rounded-xl p-1 w-fit">
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

            <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th className="px-4 py-3 text-left">Checkout</th>
                            <th className="px-4 py-3 text-left">User</th>
                            <th className="px-4 py-3 text-left">Periode</th>
                            <th className="px-4 py-3 text-left">Plan</th>
                            <th className="px-4 py-3 text-left">Jumlah</th>
                            <th className="px-4 py-3 text-left">Status</th>
                            <th className="px-4 py-3 text-left">Unggah bukti</th>
                            <th className="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {checkouts.data?.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-8 text-center text-gray-400">
                                    Tidak ada data
                                </td>
                            </tr>
                        )}
                        {checkouts.data?.map(row => (
                            <tr key={row.id} className="hover:bg-gray-50/50">
                                <td className="px-4 py-3 font-mono text-xs text-gray-600 max-w-[140px] truncate" title={row.id}>
                                    {row.id}
                                </td>
                                <td className="px-4 py-3">
                                    <p className="font-medium text-gray-800">{row.user?.name ?? '–'}</p>
                                    <p className="text-xs text-gray-400">{row.user?.email}</p>
                                </td>
                                <td className="px-4 py-3 text-gray-600">{row.period}</td>
                                <td className="px-4 py-3 text-gray-600">{row.plan?.name ?? '–'}</td>
                                <td className="px-4 py-3 font-medium text-gray-800">
                                    {Number(row.amount).toLocaleString('id', {
                                        style: 'currency',
                                        currency: row.currency ?? 'IDR',
                                        maximumFractionDigits: 0,
                                    })}
                                </td>
                                <td className="px-4 py-3">
                                    <Badge status={row.status} label={row.status_label ?? row.status} />
                                </td>
                                <td className="px-4 py-3 text-gray-500">
                                    {row.payment_proof_submitted_at
                                        ? new Date(row.payment_proof_submitted_at).toLocaleString('id')
                                        : '–'}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {row.status === 'awaiting_review' ? (
                                        <div className="flex items-center justify-end gap-2 flex-wrap">
                                            {row.has_proof && (
                                                <a
                                                    href={`/admin/renewals/${row.id}/proof`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-xs px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-50 text-gray-600"
                                                >
                                                    Bukti
                                                </a>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => openApprove(row)}
                                                className="text-xs px-2.5 py-1 rounded-lg border border-green-200 hover:bg-green-50 text-green-700"
                                            >
                                                Setujui
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => openReject(row)}
                                                className="text-xs px-2.5 py-1 rounded-lg border border-red-200 hover:bg-red-50 text-red-600"
                                            >
                                                Tolak
                                            </button>
                                        </div>
                                    ) : (
                                        <span className="text-xs text-gray-400">
                                            {row.reviewed_at ? new Date(row.reviewed_at).toLocaleDateString('id') : '–'}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={checkouts.links} meta={checkouts.meta} />

            <Modal show={!!selected} title={action === 'approve' ? 'Setujui perpanjangan' : 'Tolak perpanjangan'} onClose={closeModal}>
                {selected && (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="bg-gray-50 rounded-xl p-4 text-sm space-y-2">
                            <Row label="Checkout ID">{selected.id}</Row>
                            <Row label="User">
                                {selected.user?.name} <span className="text-gray-400">({selected.user?.email})</span>
                            </Row>
                            <Row label="Periode">{selected.period}</Row>
                            <Row label="Plan">{selected.plan?.name}</Row>
                            <Row label="Jumlah">{Number(selected.amount).toLocaleString('id')}</Row>
                        </div>

                        {action === 'reject' && (
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Alasan penolakan *</label>
                                <textarea
                                    value={form.data.notes}
                                    onChange={e => form.setData('notes', e.target.value)}
                                    rows={3}
                                    className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                                    placeholder="Alasan untuk user (opsional di email nanti)…"
                                />
                                {form.errors.notes && <p className="mt-1 text-xs text-red-600">{form.errors.notes}</p>}
                            </div>
                        )}

                        {form.errors.checkout && (
                            <p className="text-sm text-red-600">{form.errors.checkout}</p>
                        )}

                        <div className="flex justify-end gap-2 pt-1">
                            <button
                                type="button"
                                onClick={closeModal}
                                className="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className={`px-4 py-2 text-sm font-medium text-white rounded-xl disabled:opacity-60 ${
                                    action === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'
                                }`}
                            >
                                {form.processing ? 'Memproses…' : action === 'approve' ? 'Ya, setujui' : 'Ya, tolak'}
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
            <span className="text-gray-700 text-right">{children}</span>
        </div>
    )
}

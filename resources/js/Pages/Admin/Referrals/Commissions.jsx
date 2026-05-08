import { Head, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Modal from '../../../Components/Modal'
import Pagination from '../../../Components/Pagination'

const TABS = [
    { key: 'pending',   label: 'Pending' },
    { key: 'credited',  label: 'Credited' },
    { key: 'paid_out',  label: 'Paid Out' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'all',       label: 'Semua' },
]

export default function Commissions({ commissions, filters, counts }) {
    const [selected, setSelected]     = useState(null)
    const [modalType, setModalType]   = useState(null) // 'cancel'
    const cancelForm = useForm({ reason: '' })

    function openCancel(c) { setSelected(c); setModalType('cancel'); cancelForm.reset() }
    function closeModal()  { setSelected(null); setModalType(null) }

    function credit(id) {
        if (!confirm('Credit komisi ini ke saldo referrer?')) return
        router.post(`/admin/referrals/commissions/${id}/credit`, {}, { preserveScroll: true })
    }

    function submitCancel(e) {
        e.preventDefault()
        cancelForm.post(`/admin/referrals/commissions/${selected.id}/cancel`, {
            onSuccess: closeModal,
        })
    }

    return (
        <AdminLayout title="Komisi Referral">
            <Head title="Komisi" />

            <div className="flex gap-1 mb-5 bg-gray-100 rounded-xl p-1 w-fit">
                {TABS.map(t => (
                    <button key={t.key}
                        onClick={() => router.get('/admin/referrals/commissions', { status: t.key }, { preserveState: true })}
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
                            <th className="px-4 py-3 text-left">Referrer</th>
                            <th className="px-4 py-3 text-left">Referred</th>
                            <th className="px-4 py-3 text-left">Komisi</th>
                            <th className="px-4 py-3 text-left">Status</th>
                            <th className="px-4 py-3 text-left">Tanggal</th>
                            <th className="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {commissions.data?.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">Tidak ada komisi</td></tr>
                        )}
                        {commissions.data?.map(c => (
                            <tr key={c.id} className="hover:bg-gray-50/50">
                                <td className="px-4 py-3">
                                    <p className="font-medium text-gray-800">{c.referrer?.name ?? '–'}</p>
                                    <p className="text-xs text-gray-400">{c.referrer?.email}</p>
                                </td>
                                <td className="px-4 py-3">
                                    <p className="text-gray-700">{c.referred?.name ?? '–'}</p>
                                    <p className="text-xs text-gray-400">{c.referred?.email}</p>
                                </td>
                                <td className="px-4 py-3 font-semibold text-gray-800">
                                    {Number(c.amount).toLocaleString('id', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}
                                    <p className="text-xs font-normal text-gray-400">{c.commission_percentage}%</p>
                                </td>
                                <td className="px-4 py-3"><Badge status={c.status} label={c.status} /></td>
                                <td className="px-4 py-3 text-gray-500">{c.created_at ? new Date(c.created_at).toLocaleDateString('id') : '–'}</td>
                                <td className="px-4 py-3 text-right">
                                    {c.status === 'pending' && (
                                        <div className="flex items-center justify-end gap-2">
                                            <button onClick={() => credit(c.id)}
                                                className="text-xs px-2.5 py-1 rounded-lg border border-green-200 hover:bg-green-50 text-green-700">
                                                Credit
                                            </button>
                                            <button onClick={() => openCancel(c)}
                                                className="text-xs px-2.5 py-1 rounded-lg border border-red-200 hover:bg-red-50 text-red-600">
                                                Batalkan
                                            </button>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination links={commissions.links} meta={commissions.meta} />

            <Modal show={modalType === 'cancel'} title="Batalkan Komisi" onClose={closeModal}>
                {selected && (
                    <form onSubmit={submitCancel} className="space-y-4">
                        <p className="text-sm text-gray-600">
                            Batalkan komisi sebesar <strong>{Number(selected.amount).toLocaleString('id')}</strong> untuk{' '}
                            <strong>{selected.referrer?.name}</strong>?
                        </p>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Alasan *</label>
                            <textarea
                                value={cancelForm.data.reason}
                                onChange={e => cancelForm.setData('reason', e.target.value)}
                                rows={3}
                                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                            />
                            {cancelForm.errors.reason && <p className="mt-1 text-xs text-red-600">{cancelForm.errors.reason}</p>}
                        </div>
                        <div className="flex justify-end gap-2 pt-1">
                            <button type="button" onClick={closeModal} className="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Batal</button>
                            <button type="submit" disabled={cancelForm.processing} className="px-4 py-2 text-sm font-medium bg-red-600 hover:bg-red-700 disabled:opacity-60 text-white rounded-xl">
                                {cancelForm.processing ? 'Memproses...' : 'Batalkan Komisi'}
                            </button>
                        </div>
                    </form>
                )}
            </Modal>
        </AdminLayout>
    )
}

import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Modal from '../../../Components/Modal'
import ConfirmDeleteUserModal from '../../../Components/ConfirmDeleteUserModal'
import Pagination from '../../../Components/Pagination'

export default function UserShow({ user, currentSub, subscriptions, orders, redemptions, plans }) {
    const { auth, flash } = usePage().props
    const [showActivate, setShowActivate] = useState(false)
    const [showDeleteUser, setShowDeleteUser] = useState(false)
    const [deleteDeleting, setDeleteDeleting] = useState(false)
    const deleteMounted = useRef(true)
    useEffect(() => {
        deleteMounted.current = true
        return () => { deleteMounted.current = false }
    }, [])

    const activateForm = useForm({ plan_id: plans[0]?.id ?? '' })

    function toggleAdmin() {
        if (!confirm(`${user.is_admin ? 'Cabut hak admin' : 'Jadikan admin'} untuk ${user.name}?`)) return
        router.post(`/admin/users/${user.id}/toggle-admin`, {}, { preserveScroll: true })
    }

    function confirmDeleteUser() {
        setDeleteDeleting(true)
        router.delete(`/admin/users/${user.id}`, {
            onFinish: () => {
                if (!deleteMounted.current) return
                setDeleteDeleting(false)
                setShowDeleteUser(false)
            },
        })
    }

    function submitActivate(e) {
        e.preventDefault()
        activateForm.post(`/admin/users/${user.id}/activate`, {
            onSuccess: () => setShowActivate(false),
        })
    }

    const subStatusMap = {
        active:   { status: 'active',   label: 'Aktif' },
        trial:    { status: 'trial',    label: 'Trial' },
        expired:  { status: 'expired',  label: 'Expired' },
        cancelled:{ status: 'cancelled',label: 'Cancelled' },
    }

    return (
        <AdminLayout title={`User: ${user.name}`}>
            <Head title={`User: ${user.name}`} />

            {flash?.error && (
                <div role="alert" className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <Link href="/admin/users" className="text-sm text-indigo-600 hover:underline">← Kembali ke Daftar User</Link>
                    {!user.is_admin && (
                        <Link
                            href={`/admin/messages/${user.id}`}
                            className="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            💬 Buka percakapan pesan
                        </Link>
                    )}
                </div>
                {auth?.user?.id !== user.id && (
                    <button
                        type="button"
                        onClick={() => setShowDeleteUser(true)}
                        className="text-sm font-medium text-red-600 hover:text-red-800"
                    >
                        Hapus user
                    </button>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">

                {/* ── Kolom kiri: profil + subscription ──────────────────── */}
                <div className="space-y-4">

                    {/* Profil */}
                    <div className="bg-white rounded-2xl border border-gray-100 p-5">
                        <div className="flex items-start justify-between mb-4">
                            <div>
                                <h2 className="font-semibold text-gray-900">{user.name}</h2>
                                <p className="text-sm text-gray-500">{user.email}</p>
                            </div>
                            {user.is_admin && <Badge status="credited" label="Admin" />}
                        </div>

                        <div className="space-y-2 text-sm">
                            <Row label="Username">{user.username ?? '–'}</Row>
                            <Row label="External ID"><span className="font-mono text-xs">{user.external_user_id ?? '–'}</span></Row>
                            <Row label="Bergabung">{user.created_at ? new Date(user.created_at).toLocaleDateString('id', { day:'numeric', month:'long', year:'numeric' }) : '–'}</Row>
                        </div>

                        <button
                            onClick={toggleAdmin}
                            className={`mt-4 w-full py-2 text-sm font-medium rounded-xl border transition-colors ${
                                user.is_admin
                                    ? 'border-red-200 text-red-600 hover:bg-red-50'
                                    : 'border-indigo-200 text-indigo-600 hover:bg-indigo-50'
                            }`}>
                            {user.is_admin ? 'Cabut Hak Admin' : 'Jadikan Admin'}
                        </button>
                    </div>

                    {/* Subscription */}
                    <div className="bg-white rounded-2xl border border-gray-100 p-5">
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-sm font-semibold text-gray-800">Subscription</h3>
                            <button onClick={() => setShowActivate(true)}
                                className="text-xs px-2.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg">
                                + Aktifkan
                            </button>
                        </div>

                        {currentSub ? (
                            <div className="space-y-2 text-sm">
                                <div className="flex items-center gap-2">
                                    <Badge status={currentSub.status} label={subStatusMap[currentSub.status]?.label ?? currentSub.status} />
                                </div>
                                <Row label="Plan">{currentSub.plan ?? '–'}</Row>
                                <Row label="Berakhir">{currentSub.end_date}</Row>
                            </div>
                        ) : (
                            <p className="text-sm text-gray-400">Tidak ada subscription aktif</p>
                        )}
                    </div>

                    {/* Voucher Redemptions */}
                    <div className="bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 className="text-sm font-semibold text-gray-800 mb-3">Voucher Digunakan</h3>
                        {redemptions.length === 0 ? (
                            <p className="text-sm text-gray-400">Belum ada</p>
                        ) : (
                            <div className="space-y-2">
                                {redemptions.map(r => (
                                    <div key={r.id} className="flex items-center justify-between text-sm">
                                        <span className="font-mono text-xs text-gray-700">{r.voucher?.code ?? '–'}</span>
                                        <span className="text-xs text-gray-400">{r.voucher?.duration_days}h · {r.redeemed_at ? new Date(r.redeemed_at).toLocaleDateString('id') : ''}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* ── Kolom kanan: riwayat ──────────────────────────────── */}
                <div className="lg:col-span-2 space-y-5">

                    {/* Subscription History */}
                    <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="px-5 py-4 border-b border-gray-50">
                            <h3 className="text-sm font-semibold text-gray-800">Riwayat Subscription</h3>
                        </div>
                        {subscriptions.data?.length === 0 ? (
                            <p className="px-5 py-6 text-sm text-gray-400">Belum ada riwayat</p>
                        ) : (
                            <>
                                <table className="w-full text-sm">
                                    <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                                        <tr>
                                            <th className="px-4 py-3 text-left">Plan</th>
                                            <th className="px-4 py-3 text-left">Status</th>
                                            <th className="px-4 py-3 text-left">Mulai</th>
                                            <th className="px-4 py-3 text-left">Berakhir</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-50">
                                        {subscriptions.data.map(s => (
                                            <tr key={s.id}>
                                                <td className="px-4 py-3 text-gray-700">{s.plan?.name ?? '–'}</td>
                                                <td className="px-4 py-3">
                                                    <Badge status={s.status} label={subStatusMap[s.status]?.label ?? s.status} />
                                                </td>
                                                <td className="px-4 py-3 text-gray-500">{s.start_date ? new Date(s.start_date).toLocaleDateString('id') : '–'}</td>
                                                <td className="px-4 py-3 text-gray-500">{s.end_date ? new Date(s.end_date).toLocaleDateString('id') : '–'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <div className="px-4 pb-3">
                                    <Pagination links={subscriptions.links} meta={subscriptions.meta} />
                                </div>
                            </>
                        )}
                    </div>

                    {/* Payment Orders */}
                    <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="px-5 py-4 border-b border-gray-50">
                            <h3 className="text-sm font-semibold text-gray-800">Riwayat Pembayaran <span className="text-xs font-normal text-gray-400">(10 terbaru)</span></h3>
                        </div>
                        {orders.length === 0 ? (
                            <p className="px-5 py-6 text-sm text-gray-400">Belum ada pembayaran</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                                    <tr>
                                        <th className="px-4 py-3 text-left">Plan</th>
                                        <th className="px-4 py-3 text-left">Jumlah</th>
                                        <th className="px-4 py-3 text-left">Status</th>
                                        <th className="px-4 py-3 text-left">Tanggal</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {orders.map(o => (
                                        <tr key={o.id}>
                                            <td className="px-4 py-3 text-gray-700">{o.plan?.name ?? '–'}</td>
                                            <td className="px-4 py-3 font-medium text-gray-800">
                                                {Number(o.amount).toLocaleString('id', { style: 'currency', currency: o.currency ?? 'IDR', maximumFractionDigits: 0 })}
                                            </td>
                                            <td className="px-4 py-3"><Badge status={o.status} label={o.status} /></td>
                                            <td className="px-4 py-3 text-gray-500">{o.created_at ? new Date(o.created_at).toLocaleDateString('id') : '–'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            {/* Modal: Activate Subscription */}
            <Modal show={showActivate} title={`Aktifkan Subscription — ${user.name}`} onClose={() => setShowActivate(false)}>
                <form onSubmit={submitActivate} className="space-y-4">
                    <p className="text-sm text-gray-600">
                        Subscription lama akan dibatalkan dan diganti dengan yang baru.
                    </p>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Plan</label>
                        <select
                            value={activateForm.data.plan_id}
                            onChange={e => activateForm.setData('plan_id', e.target.value)}
                            className="w-full px-3 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
                        >
                            {plans.map(p => (
                                <option key={p.id} value={p.id}>
                                    {p.name} — {Number(p.price).toLocaleString('id', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}
                                </option>
                            ))}
                        </select>
                        {activateForm.errors.plan_id && <p className="mt-1 text-xs text-red-600">{activateForm.errors.plan_id}</p>}
                    </div>
                    <div className="flex justify-end gap-2 pt-1">
                        <button type="button" onClick={() => setShowActivate(false)}
                            className="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Batal</button>
                        <button type="submit" disabled={activateForm.processing}
                            className="px-4 py-2 text-sm font-medium bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white rounded-xl">
                            {activateForm.processing ? 'Mengaktifkan...' : 'Aktifkan Subscription'}
                        </button>
                    </div>
                </form>
            </Modal>

            <ConfirmDeleteUserModal
                show={showDeleteUser}
                user={showDeleteUser ? { id: user.id, name: user.name, email: user.email } : null}
                onClose={() => !deleteDeleting && setShowDeleteUser(false)}
                onConfirm={confirmDeleteUser}
                deleting={deleteDeleting}
            />
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

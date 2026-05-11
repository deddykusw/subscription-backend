import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { formatDdMmYyyy } from '../../../lib/formatDate'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Modal from '../../../Components/Modal'
import Pagination from '../../../Components/Pagination'

function voucherStatus(v) {
    if (!v.is_active) return { status: 'inactive', label: 'Nonaktif' }
    if (v.is_redeemed)  return { status: 'redeemed', label: 'Sudah Digunakan' }
    return { status: 'available', label: 'Tersedia' }
}

export default function VouchersIndex({ vouchers, filters }) {
    const { flash } = usePage().props
    const [showBulk, setShowBulk]     = useState(false)
    const [showCreate, setShowCreate] = useState(false)
    const [showCodes, setShowCodes]   = useState(false)
    const [search, setSearch]         = useState(filters?.search ?? '')

    // Show generated codes modal after bulk generate
    useEffect(() => {
        if (flash?.codes?.length) setShowCodes(true)
    }, [flash?.codes])

    const bulk = useForm({
        quantity: 10, duration_days: 30, plan_id: '', valid_from: '', valid_until: '', notes: '',
    })
    const create = useForm({
        code: '', duration_days: 30, plan_id: '', valid_from: '', valid_until: '', notes: '',
    })

    function submitBulk(e) {
        e.preventDefault()
        bulk.post('/admin/vouchers/bulk-generate', {
            onSuccess: () => { setShowBulk(false); bulk.reset() },
        })
    }

    function submitCreate(e) {
        e.preventDefault()
        create.post('/admin/vouchers', {
            onSuccess: () => { setShowCreate(false); create.reset() },
        })
    }

    function toggle(id) {
        router.post(`/admin/vouchers/${id}/toggle`, {}, { preserveScroll: true })
    }

    function destroy(id, code) {
        if (!confirm(`Hapus voucher ${code}?`)) return
        router.delete(`/admin/vouchers/${id}`, { preserveScroll: true })
    }

    function doSearch(e) {
        e.preventDefault()
        router.get('/admin/vouchers', { search, is_active: filters?.is_active }, { preserveState: true })
    }

    function copyAll() {
        navigator.clipboard.writeText((flash?.codes ?? []).join('\n'))
    }

    return (
        <AdminLayout title="Voucher">
            <Head title="Voucher" />

            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-3 mb-5">
                <form onSubmit={doSearch} className="flex gap-2">
                    <input
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder="Cari kode..."
                        className="px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <button type="submit" className="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-sm rounded-xl">Cari</button>
                </form>
                <div className="flex gap-2">
                    <Link href="/admin/vouchers" className="px-3 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Semua</Link>
                    <Link href="/admin/vouchers?is_active=1" className="px-3 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Aktif</Link>
                    <button onClick={() => setShowCreate(true)} className="px-4 py-2 text-sm bg-white border border-gray-200 rounded-xl hover:bg-gray-50 font-medium">+ Buat</button>
                    <button onClick={() => setShowBulk(true)} className="px-4 py-2 text-sm bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-medium">⚡ Bulk Generate</button>
                </div>
            </div>

            {/* Table */}
            <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th className="px-4 py-3 text-left">Kode</th>
                            <th className="px-4 py-3 text-left">Durasi</th>
                            <th className="px-4 py-3 text-left">Status</th>
                            <th className="px-4 py-3 text-left">Berlaku Hingga</th>
                            <th className="px-4 py-3 text-left">Catatan</th>
                            <th className="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {vouchers.data?.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">Belum ada voucher</td></tr>
                        )}
                        {vouchers.data?.map(v => {
                            const { status, label } = voucherStatus(v)
                            return (
                                <tr key={v.id} className="hover:bg-gray-50/50">
                                    <td className="px-4 py-3 font-mono font-medium text-gray-900">
                                        <Link href={`/admin/vouchers/${v.id}`} className="hover:text-indigo-600">{v.code}</Link>
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">{v.duration_days} hari</td>
                                    <td className="px-4 py-3"><Badge status={status} label={label} /></td>
                                    <td className="px-4 py-3 text-gray-500">{formatDdMmYyyy(v.valid_until)}</td>
                                    <td className="px-4 py-3 text-gray-400 max-w-xs truncate">{v.notes ?? '–'}</td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            <button
                                                onClick={() => toggle(v.id)}
                                                className="text-xs px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-50 text-gray-600"
                                            >
                                                {v.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                            </button>
                                            {!v.is_redeemed && (
                                                <button
                                                    onClick={() => destroy(v.id, v.code)}
                                                    className="text-xs px-2.5 py-1 rounded-lg border border-red-100 hover:bg-red-50 text-red-600"
                                                >
                                                    Hapus
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            )
                        })}
                    </tbody>
                </table>
            </div>
            <Pagination links={vouchers.links} meta={vouchers.meta} />

            {/* Modal: Bulk Generate */}
            <Modal show={showBulk} title="Bulk Generate Voucher" onClose={() => setShowBulk(false)}>
                <form onSubmit={submitBulk} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Jumlah Kode" error={bulk.errors.quantity}>
                            <input type="number" min={1} max={1000} value={bulk.data.quantity}
                                onChange={e => bulk.setData('quantity', e.target.value)}
                                className={input()} />
                        </Field>
                        <Field label="Durasi (hari)" error={bulk.errors.duration_days}>
                            <input type="number" min={1} value={bulk.data.duration_days}
                                onChange={e => bulk.setData('duration_days', e.target.value)}
                                className={input()} />
                        </Field>
                        <Field label="Berlaku Dari" error={bulk.errors.valid_from}>
                            <input type="date" value={bulk.data.valid_from}
                                onChange={e => bulk.setData('valid_from', e.target.value)}
                                className={input()} />
                        </Field>
                        <Field label="Berlaku Hingga" error={bulk.errors.valid_until}>
                            <input type="date" value={bulk.data.valid_until}
                                onChange={e => bulk.setData('valid_until', e.target.value)}
                                className={input()} />
                        </Field>
                    </div>
                    <Field label="Catatan Internal" error={bulk.errors.notes}>
                        <input value={bulk.data.notes} onChange={e => bulk.setData('notes', e.target.value)}
                            placeholder="cth: Batch Tokopedia Mei 2026" className={input()} />
                    </Field>
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={() => setShowBulk(false)} className={btnSecondary()}>Batal</button>
                        <button type="submit" disabled={bulk.processing} className={btnPrimary()}>
                            {bulk.processing ? 'Generate...' : `Generate ${bulk.data.quantity} Kode`}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Create Single */}
            <Modal show={showCreate} title="Buat Voucher" onClose={() => setShowCreate(false)}>
                <form onSubmit={submitCreate} className="space-y-4">
                    <Field label="Kode (kosongkan untuk auto-generate)" error={create.errors.code}>
                        <input value={create.data.code} onChange={e => create.setData('code', e.target.value)}
                            placeholder="cth: PROMO2026" className={`${input()} uppercase`} />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Durasi (hari)" error={create.errors.duration_days}>
                            <input type="number" min={1} value={create.data.duration_days}
                                onChange={e => create.setData('duration_days', e.target.value)}
                                className={input()} />
                        </Field>
                        <Field label="Berlaku Hingga" error={create.errors.valid_until}>
                            <input type="date" value={create.data.valid_until}
                                onChange={e => create.setData('valid_until', e.target.value)}
                                className={input()} />
                        </Field>
                    </div>
                    <Field label="Catatan Internal" error={create.errors.notes}>
                        <input value={create.data.notes} onChange={e => create.setData('notes', e.target.value)}
                            className={input()} />
                    </Field>
                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={() => setShowCreate(false)} className={btnSecondary()}>Batal</button>
                        <button type="submit" disabled={create.processing} className={btnPrimary()}>
                            {create.processing ? 'Menyimpan...' : 'Buat Voucher'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Generated Codes */}
            <Modal show={showCodes} title={`${flash?.codes?.length ?? 0} Kode Berhasil Di-generate`} onClose={() => setShowCodes(false)} size="lg">
                <div className="mb-3 flex justify-end">
                    <button onClick={copyAll} className="text-sm px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg">📋 Salin Semua</button>
                </div>
                <div className="bg-gray-50 rounded-xl p-4 max-h-80 overflow-y-auto font-mono text-sm space-y-1">
                    {(flash?.codes ?? []).map((code, i) => (
                        <div key={i} className="text-gray-700">{code}</div>
                    ))}
                </div>
                <p className="mt-3 text-xs text-gray-400">Semua kode sudah tersimpan di database. Salin untuk dijual di marketplace.</p>
            </Modal>
        </AdminLayout>
    )
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    )
}
const input = () => 'w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500'
const btnPrimary = () => 'px-4 py-2 text-sm font-medium bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white rounded-xl'
const btnSecondary = () => 'px-4 py-2 text-sm font-medium border border-gray-200 hover:bg-gray-50 rounded-xl'

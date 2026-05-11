import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Pagination from '../../../Components/Pagination'

const MAX_USERS = 100

export default function PushCreate({ users, filters, firebaseConfigured }) {
    const { flash } = usePage().props
    const [search, setSearch] = useState(filters?.search ?? '')
    const [selected, setSelected] = useState(() => new Set())

    const form = useForm({
        user_ids: [],
        title: '',
        body: '',
        data_json: '',
    })

    const pageIds = useMemo(() => (users.data ?? []).map(u => u.id), [users.data])

    function toggleUser(id) {
        setSelected(prev => {
            const next = new Set(prev)
            if (next.has(id)) {
                next.delete(id)
            } else if (next.size < MAX_USERS) {
                next.add(id)
            }
            return next
        })
    }

    function togglePage() {
        const allOnPageSelected = pageIds.length > 0 && pageIds.every(id => selected.has(id))
        setSelected(prev => {
            const next = new Set(prev)
            if (allOnPageSelected) {
                pageIds.forEach(id => next.delete(id))
            } else {
                for (const id of pageIds) {
                    if (next.size >= MAX_USERS) break
                    next.add(id)
                }
            }
            return next
        })
    }

    function clearSelection() {
        setSelected(new Set())
    }

    function submit(e) {
        e.preventDefault()
        form.setData('user_ids', Array.from(selected))
        form.post('/admin/push', { preserveScroll: true })
    }

    function doSearch(ev) {
        ev.preventDefault()
        router.get('/admin/push', { search }, { preserveState: true })
    }

    const allPageSelected = pageIds.length > 0 && pageIds.every(id => selected.has(id))

    return (
        <AdminLayout title="Push notification">
            <Head title="Push notification" />

            {flash?.error && (
                <div role="alert" className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900">Kirim push ke user</h2>
                    <p className="text-sm text-gray-500 mt-0.5">
                        Pilih satu atau lebih user yang sudah mendaftarkan token FCM dari aplikasi mobile (maks. {MAX_USERS} user per pengiriman).
                    </p>
                </div>
                <Link href="/admin" className="text-sm text-indigo-600 hover:underline">← Dashboard</Link>
            </div>

            {!firebaseConfigured && (
                <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <strong>Firebase belum dikonfigurasi.</strong> Set environment variable{' '}
                    <code className="rounded bg-amber-100/80 px-1">FIREBASE_CREDENTIALS</code>{' '}
                    ke path file service account JSON agar pengiriman push dapat dijalankan.
                </div>
            )}

            <div className="space-y-6">
                <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                    <div className="px-4 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                        <form onSubmit={doSearch} className="flex gap-2">
                            <input
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Cari nama, email, external ID…"
                                className="px-3 py-2 text-sm border border-gray-200 rounded-xl w-64 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <button type="submit" className="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-sm rounded-xl">Cari</button>
                        </form>
                        <div className="flex items-center gap-3 text-sm">
                            <span className="text-gray-600">
                                Terpilih: <strong className="text-gray-900">{selected.size}</strong> / {MAX_USERS}
                            </span>
                            {selected.size > 0 && (
                                <button type="button" onClick={clearSelection} className="text-indigo-600 hover:underline">
                                    Kosongkan
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                                <tr>
                                    <th className="px-4 py-3 w-10">
                                        <input
                                            type="checkbox"
                                            checked={allPageSelected}
                                            onChange={togglePage}
                                            aria-label="Pilih semua di halaman ini"
                                        />
                                    </th>
                                    <th className="px-4 py-3 text-left">User</th>
                                    <th className="px-4 py-3 text-left">Perangkat FCM</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {(users.data ?? []).length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="px-4 py-10 text-center text-gray-400">
                                            Tidak ada user dengan token FCM. User harus membuka aplikasi mobile setelah login agar token terkirim ke server.
                                        </td>
                                    </tr>
                                )}
                                {(users.data ?? []).map(user => (
                                    <tr key={user.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                checked={selected.has(user.id)}
                                                onChange={() => toggleUser(user.id)}
                                                disabled={!selected.has(user.id) && selected.size >= MAX_USERS}
                                                aria-label={`Pilih ${user.name}`}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-gray-900">{user.name}</p>
                                            <p className="text-xs text-gray-400">{user.email}</p>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">{user.fcm_devices_count ?? 0}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={users.links} meta={users.meta} />
                </div>

                <form onSubmit={submit} className="bg-white rounded-2xl border border-gray-100 p-5 space-y-4 max-w-2xl">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Judul</label>
                        <input
                            value={form.data.title}
                            onChange={e => form.setData('title', e.target.value)}
                            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            maxLength={120}
                            required
                        />
                        {form.errors.title && <p className="mt-1 text-xs text-red-600">{form.errors.title}</p>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Isi pesan</label>
                        <textarea
                            value={form.data.body}
                            onChange={e => form.setData('body', e.target.value)}
                            rows={4}
                            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            maxLength={500}
                            required
                        />
                        {form.errors.body && <p className="mt-1 text-xs text-red-600">{form.errors.body}</p>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Data tambahan (JSON opsional)</label>
                        <textarea
                            value={form.data.data_json}
                            onChange={e => form.setData('data_json', e.target.value)}
                            rows={3}
                            placeholder='{"screen":"inbox","id":"123"}'
                            className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-xs"
                        />
                        <p className="mt-1 text-xs text-gray-400">Objek flat; nilai non-string akan di-stringify. Kosongkan jika tidak perlu.</p>
                        {form.errors.data_json && <p className="mt-1 text-xs text-red-600">{form.errors.data_json}</p>}
                    </div>

                    <div className="flex items-center gap-3 pt-2">
                        <button
                            type="submit"
                            disabled={form.processing || selected.size === 0 || !firebaseConfigured}
                            className="px-5 py-2.5 text-sm font-semibold rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white"
                        >
                            {form.processing ? 'Mengirim…' : 'Kirim push'}
                        </button>
                        {selected.size === 0 && (
                            <span className="text-xs text-gray-400">Pilih minimal satu user di tabel.</span>
                        )}
                    </div>
                </form>
            </div>
        </AdminLayout>
    )
}

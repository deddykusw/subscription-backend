import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Pagination from '../../../Components/Pagination'

const SUB_FILTERS = [
    { key: '',        label: 'Semua' },
    { key: 'active',  label: 'Active' },
    { key: 'trial',   label: 'Trial' },
    { key: 'expired', label: 'Expired' },
]

function subStatus(currentSub) {
    if (!currentSub) return { status: 'expired', label: 'Tidak Aktif' }
    if (currentSub.status === 'active') return { status: 'active', label: 'Aktif' }
    if (currentSub.status === 'trial')  return { status: 'trial',  label: 'Trial' }
    return { status: 'expired', label: 'Expired' }
}

export default function UsersIndex({ users, filters, counts }) {
    const [search, setSearch] = useState(filters?.search ?? '')

    function doSearch(e) {
        e.preventDefault()
        router.get('/admin/users', { ...filters, search }, { preserveState: true })
    }

    function setFilter(key, value) {
        router.get('/admin/users', { ...filters, search, [key]: value || undefined }, { preserveState: true })
    }

    return (
        <AdminLayout title="Manajemen User">
            <Head title="Users" />

            {/* Stats */}
            <div className="grid grid-cols-3 gap-4 mb-5">
                <div className="bg-white rounded-2xl border border-gray-100 p-4">
                    <p className="text-xs text-gray-400 uppercase tracking-wide">Total User</p>
                    <p className="text-2xl font-bold text-gray-900 mt-1">{counts.total}</p>
                </div>
                <div className="bg-white rounded-2xl border border-gray-100 p-4">
                    <p className="text-xs text-gray-400 uppercase tracking-wide">Subscription Aktif</p>
                    <p className="text-2xl font-bold text-green-600 mt-1">{counts.active}</p>
                </div>
                <div className="bg-white rounded-2xl border border-gray-100 p-4">
                    <p className="text-xs text-gray-400 uppercase tracking-wide">Admin</p>
                    <p className="text-2xl font-bold text-indigo-600 mt-1">{counts.admin}</p>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap items-center gap-3 mb-4">
                <form onSubmit={doSearch} className="flex gap-2">
                    <input
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        placeholder="Cari nama, email, ID..."
                        className="px-3 py-2 text-sm border border-gray-200 rounded-xl w-64 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <button type="submit" className="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-sm rounded-xl">Cari</button>
                </form>

                {/* Subscription filter */}
                <div className="flex gap-1 bg-gray-100 rounded-xl p-1">
                    {SUB_FILTERS.map(f => (
                        <button key={f.key}
                            onClick={() => setFilter('subscription', f.key)}
                            className={`px-3 py-1.5 text-sm rounded-lg font-medium transition-colors ${
                                (filters?.subscription ?? '') === f.key
                                    ? 'bg-white text-gray-900 shadow-sm'
                                    : 'text-gray-500 hover:text-gray-700'
                            }`}>
                            {f.label}
                        </button>
                    ))}
                </div>

                {/* Admin filter */}
                <button
                    onClick={() => setFilter('is_admin', filters?.is_admin ? undefined : '1')}
                    className={`px-3 py-1.5 text-sm rounded-xl border font-medium transition-colors ${
                        filters?.is_admin
                            ? 'bg-indigo-600 text-white border-indigo-600'
                            : 'border-gray-200 text-gray-600 hover:bg-gray-50'
                    }`}>
                    Admin Saja
                </button>

                {(filters?.search || filters?.is_admin || filters?.subscription) && (
                    <button onClick={() => router.get('/admin/users')}
                        className="px-3 py-1.5 text-sm text-gray-400 hover:text-gray-600">
                        × Reset
                    </button>
                )}
            </div>

            {/* Table */}
            <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th className="px-4 py-3 text-left">User</th>
                            <th className="px-4 py-3 text-left">External ID</th>
                            <th className="px-4 py-3 text-left">Subscription</th>
                            <th className="px-4 py-3 text-left">Role</th>
                            <th className="px-4 py-3 text-left">Bergabung</th>
                            <th className="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {users.data?.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">Tidak ada user ditemukan</td></tr>
                        )}
                        {users.data?.map(user => {
                            const { status, label } = subStatus(user.current_subscription)
                            return (
                                <tr key={user.id} className="hover:bg-gray-50/50">
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-gray-900">{user.name}</p>
                                        <p className="text-xs text-gray-400">{user.email}</p>
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 font-mono text-xs">{user.external_user_id ?? '–'}</td>
                                    <td className="px-4 py-3">
                                        <Badge status={status} label={label} />
                                        {user.current_subscription && (
                                            <p className="text-xs text-gray-400 mt-0.5">
                                                {user.current_subscription.plan} · s/d {user.current_subscription.end_date}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {user.is_admin
                                            ? <Badge status="credited" label="Admin" />
                                            : <span className="text-xs text-gray-400">User</span>
                                        }
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 text-xs">
                                        {user.created_at ? new Date(user.created_at).toLocaleDateString('id') : '–'}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link href={`/admin/users/${user.id}`}
                                            className="text-xs px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-50 text-indigo-600 hover:border-indigo-200">
                                            Detail →
                                        </Link>
                                    </td>
                                </tr>
                            )
                        })}
                    </tbody>
                </table>
            </div>
            <Pagination links={users.links} meta={users.meta} />
        </AdminLayout>
    )
}

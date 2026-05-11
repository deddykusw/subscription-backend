import { Head, Link } from '@inertiajs/react'
import AdminLayout from '../../../Layouts/AdminLayout'
import Badge from '../../../Components/Badge'
import Pagination from '../../../Components/Pagination'

function formatTime(iso) {
    if (!iso) return '–'
    try {
        return new Date(iso).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' })
    } catch {
        return iso
    }
}

export default function MessagesIndex({ threads }) {
    const rows = threads?.data ?? []

    return (
        <AdminLayout title="Pesan dari User">
            <Head title="Pesan" />

            <p className="text-sm text-gray-500 mb-4">
                Hanya percakapan <strong>user ↔ admin</strong>. Klik baris untuk membaca dan membalas.
            </p>

            <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <table className="min-w-full text-sm">
                    <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3 font-medium">User</th>
                            <th className="px-4 py-3 font-medium">Pesan terakhir</th>
                            <th className="px-4 py-3 font-medium w-28">Belum dibaca</th>
                            <th className="px-4 py-3 font-medium w-24"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={4} className="px-4 py-10 text-center text-gray-400">
                                    Belum ada pesan dari user.
                                </td>
                            </tr>
                        )}
                        {rows.map(row => (
                            <tr key={row.user_id} className="hover:bg-gray-50/80">
                                <td className="px-4 py-3">
                                    {row.user ? (
                                        <>
                                            <div className="font-medium text-gray-900">{row.user.name}</div>
                                            <div className="text-xs text-gray-500 truncate max-w-xs">{row.user.email}</div>
                                            {row.user.username && (
                                                <div className="text-xs text-gray-400">@{row.user.username}</div>
                                            )}
                                        </>
                                    ) : (
                                        <span className="text-gray-400">User #{row.user_id}</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-gray-600 whitespace-nowrap">
                                    {formatTime(row.last_message_at)}
                                </td>
                                <td className="px-4 py-3">
                                    {row.unread_for_admin > 0 ? (
                                        <Badge status="pending" label={`${row.unread_for_admin} baru`} />
                                    ) : (
                                        <span className="text-gray-400">—</span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    <Link
                                        href={`/admin/messages/${row.user_id}`}
                                        className="text-indigo-600 hover:text-indigo-800 font-medium"
                                    >
                                        Buka →
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Pagination links={threads.links} meta={threads.meta} />
        </AdminLayout>
    )
}

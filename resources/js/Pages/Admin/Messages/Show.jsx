import { Head, Link, useForm } from '@inertiajs/react'
import { useEffect, useRef } from 'react'
import AdminLayout from '../../../Layouts/AdminLayout'

function formatTime(iso) {
    if (!iso) return ''
    try {
        return new Date(iso).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' })
    } catch {
        return iso
    }
}

export default function MessagesShow({ threadUser, messages }) {
    const bottomRef = useRef(null)
    const form = useForm({ message: '' })

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
    }, [messages])

    function submit(e) {
        e.preventDefault()
        form.post(`/admin/messages/${threadUser.id}/reply`, {
            preserveScroll: true,
            onSuccess: () => form.reset('message'),
        })
    }

    return (
        <AdminLayout title={`Percakapan — ${threadUser.name}`}>
            <Head title={`Pesan: ${threadUser.name}`} />

            <div className="mb-4 flex items-center gap-3">
                <Link href="/admin/messages" className="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                    ← Kembali ke daftar
                </Link>
            </div>

            <div className="bg-white rounded-2xl border border-gray-100 p-4 mb-4">
                <h2 className="text-lg font-semibold text-gray-900">{threadUser.name}</h2>
                <p className="text-sm text-gray-500">{threadUser.email}</p>
                {threadUser.username && (
                    <p className="text-xs text-gray-400 mt-1">@{threadUser.username}</p>
                )}
            </div>

            <div className="bg-gray-100 rounded-2xl border border-gray-200 p-4 min-h-[320px] max-h-[55vh] overflow-y-auto space-y-3 mb-4">
                {(messages ?? []).length === 0 && (
                    <p className="text-center text-gray-400 text-sm py-8">Belum ada pesan. Kirim balasan di bawah.</p>
                )}
                {(messages ?? []).map(m => (
                    <div
                        key={m.id}
                        className={`flex ${m.sender_is_admin ? 'justify-end' : 'justify-start'}`}
                    >
                        <div
                            className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-sm shadow-sm ${
                                m.sender_is_admin
                                    ? 'bg-indigo-600 text-white rounded-br-md'
                                    : 'bg-white text-gray-800 border border-gray-200 rounded-bl-md'
                            }`}
                        >
                            {m.sender_is_admin && m.admin && (
                                <div className="text-[10px] uppercase tracking-wide opacity-80 mb-1">
                                    Admin · {m.admin.name}
                                </div>
                            )}
                            {!m.sender_is_admin && (
                                <div className="text-[10px] uppercase tracking-wide text-gray-400 mb-1">User</div>
                            )}
                            <div className="whitespace-pre-wrap break-words">{m.body}</div>
                            <div
                                className={`text-[10px] mt-1.5 ${
                                    m.sender_is_admin ? 'text-indigo-200' : 'text-gray-400'
                                }`}
                            >
                                {formatTime(m.created_at)}
                            </div>
                        </div>
                    </div>
                ))}
                <div ref={bottomRef} />
            </div>

            <form onSubmit={submit} className="bg-white rounded-2xl border border-gray-100 p-4">
                <label className="block text-sm font-medium text-gray-700 mb-2">Balasan</label>
                <textarea
                    value={form.data.message}
                    onChange={e => form.setData('message', e.target.value)}
                    rows={4}
                    placeholder="Tulis balasan untuk user..."
                    className="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {form.errors.message && (
                    <p className="mt-1 text-xs text-red-600">{form.errors.message}</p>
                )}
                <div className="mt-3 flex justify-end gap-2">
                    <button
                        type="submit"
                        disabled={form.processing || !form.data.message.trim()}
                        className="px-4 py-2 text-sm font-medium bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-xl"
                    >
                        {form.processing ? 'Mengirim...' : 'Kirim balasan'}
                    </button>
                </div>
            </form>
        </AdminLayout>
    )
}

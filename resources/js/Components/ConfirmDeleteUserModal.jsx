import { useEffect } from 'react'

function initials(name) {
    if (!name?.trim()) return '?'
    const parts = name.trim().split(/\s+/)
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    }
    return name.slice(0, 2).toUpperCase()
}

const IMPACT_ITEMS = [
    'Subscription & riwayat pembayaran',
    'Pesan dan percakapan dengan admin',
    'Profil presensi & token terkait',
    'Referral, komisi, payout & redemption voucher',
]

/**
 * @param {{ show: boolean, user: { id: number, name: string, email?: string } | null, onClose: () => void, onConfirm: () => void, deleting?: boolean }} props
 */
export default function ConfirmDeleteUserModal({ show, user, onClose, onConfirm, deleting = false }) {
    useEffect(() => {
        function onKey(e) {
            if (e.key === 'Escape' && show && !deleting) onClose()
        }
        if (show) document.addEventListener('keydown', onKey)
        return () => document.removeEventListener('keydown', onKey)
    }, [show, deleting, onClose])

    if (!show || !user) return null

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6">
            <button
                type="button"
                className={`absolute inset-0 bg-slate-900/50 backdrop-blur-md transition-opacity ${deleting ? 'pointer-events-none' : ''}`}
                aria-label="Tutup dialog"
                onClick={onClose}
                disabled={deleting}
            />
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-user-title"
                className="relative w-full max-w-md transition-all duration-200 ease-out"
            >
                <div className="overflow-hidden rounded-3xl bg-white shadow-2xl shadow-rose-900/10 ring-1 ring-slate-200/80">
                    <div className="h-1.5 bg-gradient-to-r from-rose-500 via-red-500 to-amber-400" />

                    <div className="p-6 sm:p-7">
                        <div className="flex gap-4">
                            <div
                                className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-red-600 text-lg font-bold text-white shadow-lg shadow-rose-500/30"
                                aria-hidden
                            >
                                {initials(user.name)}
                            </div>
                            <div className="min-w-0 pt-0.5">
                                <h2 id="delete-user-title" className="text-lg font-semibold tracking-tight text-slate-900">
                                    Hapus user secara permanen?
                                </h2>
                                <p className="mt-1.5 text-sm leading-relaxed text-slate-500">
                                    Akun dan seluruh jejak data di sistem akan dihapus. Tindakan ini{' '}
                                    <span className="font-medium text-slate-700">tidak dapat dikembalikan</span>.
                                </p>
                            </div>
                        </div>

                        <div className="mt-5 rounded-2xl border border-slate-100 bg-slate-50/80 px-4 py-3">
                            <p className="text-xs font-medium uppercase tracking-wider text-slate-400">Target</p>
                            <p className="mt-1 truncate font-semibold text-slate-900">{user.name}</p>
                            {user.email && (
                                <p className="truncate text-sm text-slate-500">{user.email}</p>
                            )}
                        </div>

                        <p className="mt-5 text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Yang ikut terhapus
                        </p>
                        <ul className="mt-2 space-y-2">
                            {IMPACT_ITEMS.map(label => (
                                <li
                                    key={label}
                                    className="flex items-start gap-3 rounded-xl border border-slate-100 bg-white px-3 py-2.5 text-sm text-slate-600 shadow-sm"
                                >
                                    <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-xs font-semibold text-emerald-600" aria-hidden>
                                        ✓
                                    </span>
                                    <span className="leading-snug">{label}</span>
                                </li>
                            ))}
                        </ul>

                        <div className="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end sm:gap-3">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={deleting}
                                className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-50"
                            >
                                Batal
                            </button>
                            <button
                                type="button"
                                onClick={onConfirm}
                                disabled={deleting}
                                className="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-rose-600 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-rose-500/25 transition hover:from-rose-500 hover:to-red-500 disabled:opacity-60"
                            >
                                {deleting ? (
                                    <>
                                        <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                                        Menghapus…
                                    </>
                                ) : (
                                    <>
                                        <span aria-hidden>✕</span>
                                        Ya, hapus permanen
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}

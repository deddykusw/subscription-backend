import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'

const NAV = [
    { label: 'Dashboard',   href: '/admin',                       icon: '⊞' },
    { label: 'Users',       href: '/admin/users',                 icon: '👥' },
    { label: 'Push',        href: '/admin/push',                  icon: '🔔' },
    { label: 'Pesan',       href: '/admin/messages',              icon: '💬' },
    { label: 'Voucher',     href: '/admin/vouchers',              icon: '🎟' },
    { label: 'Pembayaran',  href: '/admin/payments',              icon: '💳' },
    { label: 'Komisi',      href: '/admin/referrals/commissions', icon: '💰' },
    { label: 'Payout',      href: '/admin/referrals/payouts',     icon: '📤' },
    { label: 'Pengaturan',  href: '/admin/referrals/settings',    icon: '⚙' },
]

export default function AdminLayout({ title, children }) {
    const { auth, flash } = usePage().props
    const [toast, setToast] = useState(null)
    const currentPath = window.location.pathname

    useEffect(() => {
        if (flash?.success || flash?.error) {
            setToast({ type: flash.success ? 'success' : 'error', message: flash.success || flash.error })
            const t = setTimeout(() => setToast(null), 4000)
            return () => clearTimeout(t)
        }
    }, [flash])

    function logout(e) {
        e.preventDefault()
        router.post('/admin/logout')
    }

    return (
        <div className="min-h-screen flex bg-gray-50">
            {/* Sidebar */}
            <aside className="w-56 shrink-0 bg-white border-r border-gray-200 flex flex-col">
                <div className="h-14 flex items-center px-5 border-b border-gray-200">
                    <span className="font-bold text-indigo-600 text-lg tracking-tight">SIKAP Admin</span>
                </div>

                <nav className="flex-1 py-4 space-y-0.5 px-2">
                    {NAV.map(item => {
                        const active = currentPath === item.href || (item.href !== '/admin' && currentPath.startsWith(item.href))
                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                                    active
                                        ? 'bg-indigo-50 text-indigo-700'
                                        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                                }`}
                            >
                                <span className="text-base">{item.icon}</span>
                                {item.label}
                            </Link>
                        )
                    })}
                </nav>

                <div className="p-3 border-t border-gray-200">
                    <div className="text-xs text-gray-500 truncate px-1 mb-1">{auth?.user?.email}</div>
                    <button
                        onClick={logout}
                        className="w-full text-left px-3 py-2 rounded-lg text-sm text-red-600 hover:bg-red-50 transition-colors"
                    >
                        Logout
                    </button>
                </div>
            </aside>

            {/* Main */}
            <div className="flex-1 flex flex-col min-w-0">
                <header className="h-14 bg-white border-b border-gray-200 flex items-center px-6">
                    <h1 className="text-sm font-semibold text-gray-800">{title}</h1>
                </header>

                <main className="flex-1 p-6 overflow-auto">
                    {children}
                </main>
            </div>

            {/* Toast */}
            {toast && (
                <div className={`fixed bottom-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-sm font-medium text-white transition-all ${
                    toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'
                }`}>
                    <span>{toast.type === 'success' ? '✓' : '✕'}</span>
                    {toast.message}
                </div>
            )}
        </div>
    )
}

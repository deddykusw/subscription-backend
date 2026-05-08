import { Head, Link } from '@inertiajs/react'
import AdminLayout from '../../Layouts/AdminLayout'

function StatCard({ label, value, sub, href, color = 'indigo' }) {
    const colors = {
        indigo: 'bg-indigo-50 text-indigo-700',
        green:  'bg-green-50 text-green-700',
        yellow: 'bg-yellow-50 text-yellow-700',
        purple: 'bg-purple-50 text-purple-700',
        blue:   'bg-blue-50 text-blue-700',
        red:    'bg-red-50 text-red-700',
    }
    const card = (
        <div className={`rounded-2xl p-5 ${colors[color]} flex flex-col gap-1`}>
            <p className="text-xs font-medium opacity-70 uppercase tracking-wide">{label}</p>
            <p className="text-3xl font-bold">{value}</p>
            {sub && <p className="text-xs opacity-60">{sub}</p>}
        </div>
    )
    return href ? <Link href={href}>{card}</Link> : card
}

export default function Dashboard({ stats }) {
    return (
        <AdminLayout title="Dashboard">
            <Head title="Dashboard" />

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <StatCard label="Total User"        value={stats.total_users}          color="indigo" />
                <StatCard label="Subscription Aktif" value={stats.active_subscriptions} color="green"  href="/admin/payments" />
                <StatCard label="Trial Aktif"       value={stats.trial_subscriptions}  color="blue"   />
                <StatCard label="Pembayaran Pending" value={stats.pending_payments}     color="yellow" href="/admin/payments" />
                <StatCard label="Total Voucher"     value={stats.total_vouchers}       color="purple" href="/admin/vouchers"
                    sub={`${stats.redeemed_vouchers} sudah digunakan`} />
                <StatCard label="Voucher Tersedia"  value={stats.total_vouchers - stats.redeemed_vouchers} color="green" href="/admin/vouchers" />
                <StatCard label="Komisi Pending"    value={stats.pending_commissions}  color="yellow" href="/admin/referrals/commissions" />
                <StatCard label="Payout Pending"    value={stats.pending_payouts}      color="red"    href="/admin/referrals/payouts" />
            </div>

            <div className="mt-8 grid grid-cols-1 md:grid-cols-3 gap-4">
                <Link href="/admin/vouchers?is_active=1"
                    className="flex items-center gap-3 p-4 bg-white rounded-2xl border border-gray-100 hover:border-indigo-200 hover:shadow-sm transition-all">
                    <span className="text-2xl">🎟</span>
                    <div>
                        <p className="text-sm font-semibold text-gray-800">Kelola Voucher</p>
                        <p className="text-xs text-gray-500">Generate & manage kode voucher</p>
                    </div>
                </Link>
                <Link href="/admin/payments?status=pending"
                    className="flex items-center gap-3 p-4 bg-white rounded-2xl border border-gray-100 hover:border-indigo-200 hover:shadow-sm transition-all">
                    <span className="text-2xl">💳</span>
                    <div>
                        <p className="text-sm font-semibold text-gray-800">Verifikasi Pembayaran</p>
                        <p className="text-xs text-gray-500">Review bukti transfer user</p>
                    </div>
                </Link>
                <Link href="/admin/referrals/payouts?status=pending"
                    className="flex items-center gap-3 p-4 bg-white rounded-2xl border border-gray-100 hover:border-indigo-200 hover:shadow-sm transition-all">
                    <span className="text-2xl">📤</span>
                    <div>
                        <p className="text-sm font-semibold text-gray-800">Proses Payout</p>
                        <p className="text-xs text-gray-500">Cairkan komisi referral</p>
                    </div>
                </Link>
            </div>
        </AdminLayout>
    )
}

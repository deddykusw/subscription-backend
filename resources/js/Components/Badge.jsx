const VARIANTS = {
    // subscription / generic
    active:     'bg-green-100 text-green-800',
    trial:      'bg-blue-100 text-blue-800',
    expired:    'bg-gray-100 text-gray-600',
    cancelled:  'bg-red-100 text-red-700',
    // payment
    pending:    'bg-yellow-100 text-yellow-800',
    verified:   'bg-green-100 text-green-800',
    rejected:   'bg-red-100 text-red-700',
    // commission
    credited:   'bg-indigo-100 text-indigo-800',
    paid_out:   'bg-purple-100 text-purple-800',
    // payout
    processing: 'bg-blue-100 text-blue-800',
    completed:  'bg-green-100 text-green-800',
    failed:     'bg-red-100 text-red-700',
    // voucher
    redeemed:   'bg-gray-100 text-gray-500',
    available:  'bg-emerald-100 text-emerald-800',
    inactive:   'bg-gray-100 text-gray-500',
}

export default function Badge({ status, label }) {
    const cls = VARIANTS[status] ?? 'bg-gray-100 text-gray-600'
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${cls}`}>
            {label ?? status}
        </span>
    )
}

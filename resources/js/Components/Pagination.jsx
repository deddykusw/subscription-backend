import { Link } from '@inertiajs/react'

export default function Pagination({ links, meta }) {
    if (!links || links.length <= 3) return null

    return (
        <div className="flex items-center justify-between mt-4">
            <p className="text-sm text-gray-500">
                {meta?.from ?? '–'}–{meta?.to ?? '–'} dari {meta?.total ?? '–'} data
            </p>
            <div className="flex gap-1">
                {links.map((link, i) => (
                    <Link
                        key={i}
                        href={link.url ?? '#'}
                        preserveScroll
                        className={`px-3 py-1.5 text-sm rounded-lg border transition-colors ${
                            link.active
                                ? 'bg-indigo-600 border-indigo-600 text-white'
                                : link.url
                                ? 'border-gray-200 text-gray-600 hover:bg-gray-50'
                                : 'border-gray-100 text-gray-300 cursor-default pointer-events-none'
                        }`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>
        </div>
    )
}

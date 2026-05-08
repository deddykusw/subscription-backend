import { useEffect } from 'react'

export default function Modal({ show, title, onClose, children, size = 'md' }) {
    useEffect(() => {
        function handler(e) { if (e.key === 'Escape') onClose() }
        if (show) document.addEventListener('keydown', handler)
        return () => document.removeEventListener('keydown', handler)
    }, [show, onClose])

    if (!show) return null

    const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="fixed inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose} />
            <div className={`relative bg-white rounded-2xl shadow-2xl w-full ${widths[size]} max-h-[90vh] flex flex-col`}>
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h2 className="text-base font-semibold text-gray-900">{title}</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                </div>
                <div className="overflow-y-auto px-6 py-4 flex-1">
                    {children}
                </div>
            </div>
        </div>
    )
}

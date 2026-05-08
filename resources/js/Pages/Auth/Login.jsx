import { Head, useForm } from '@inertiajs/react'

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    })

    function submit(e) {
        e.preventDefault()
        post('/admin/login')
    }

    return (
        <>
            <Head title="Login Admin" />
            <div className="min-h-screen flex items-center justify-center bg-gray-50">
                <div className="w-full max-w-sm">
                    <div className="text-center mb-8">
                        <h1 className="text-2xl font-bold text-indigo-600">SIKAP Admin</h1>
                        <p className="text-sm text-gray-500 mt-1">Masuk ke dashboard administrator</p>
                    </div>

                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData('email', e.target.value)}
                                    className="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    placeholder="admin@example.com"
                                    autoFocus
                                />
                                {errors.email && <p className="mt-1.5 text-xs text-red-600">{errors.email}</p>}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                                <input
                                    type="password"
                                    value={data.password}
                                    onChange={e => setData('password', e.target.value)}
                                    className="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    placeholder="••••••••"
                                />
                                {errors.password && <p className="mt-1.5 text-xs text-red-600">{errors.password}</p>}
                            </div>

                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={e => setData('remember', e.target.checked)}
                                    className="rounded border-gray-300 text-indigo-600"
                                />
                                <span className="text-sm text-gray-600">Ingat saya</span>
                            </label>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-semibold rounded-xl transition-colors"
                            >
                                {processing ? 'Masuk...' : 'Masuk'}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </>
    )
}

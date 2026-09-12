import { Head, Link } from '@inertiajs/react'

export default function Welcome() {
    return (
        <>
            <Head title="LexIA" />
            <div className="flex min-h-full flex-col items-center justify-center px-6 py-20 text-center">
                <h1 className="font-serif text-5xl font-semibold text-brand-700">LexIA</h1>
                <p className="mt-4 max-w-md text-ink-500">
                    Inteligência aplicada à prática jurídica.
                </p>
                <div className="mt-8 flex gap-3">
                    <Link href="/login" className="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Entrar
                    </Link>
                    <Link href="/cadastro" className="rounded-md bg-white px-4 py-2 text-sm font-medium text-ink-800 ring-1 ring-ink-200 hover:bg-ink-50">
                        Criar conta
                    </Link>
                </div>
            </div>
        </>
    )
}

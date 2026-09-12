import { Head, usePage } from '@inertiajs/react'
import { AppLayout } from '@/layouts/app-layout'
import type { PageProps } from '@/types'

export default function Dashboard() {
    const { auth } = usePage<PageProps>().props
    const user = auth.user

    return (
        <AppLayout title="Painel">
            <Head title="Painel" />
            <div className="rounded-lg bg-white p-6 ring-1 ring-ink-200">
                <p className="text-ink-600">
                    Bem-vindo, <strong className="text-ink-900">{user?.name}</strong>.
                </p>
                <p className="mt-1 text-sm text-ink-400">
                    {user?.account.type_label} · {user?.role_label}
                </p>
                <p className="mt-6 text-sm text-ink-500">
                    O módulo de Jurisprudência aparecerá aqui.
                </p>
            </div>
        </AppLayout>
    )
}

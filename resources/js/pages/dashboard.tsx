import { Head, usePage } from '@inertiajs/react'
import { AppLayout } from '@/layouts/app-layout'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import type { PageProps } from '@/types'

export default function Dashboard() {
    const { auth } = usePage<PageProps>().props
    const user = auth.user

    return (
        <AppLayout title="Painel">
            <Head title="Painel" />

            <Card className="max-w-2xl">
                <CardHeader>
                    <CardTitle className="font-serif text-xl">Bem-vindo, {user?.name}.</CardTitle>
                    <CardDescription>
                        {user?.account.type_label} · {user?.role_label}
                    </CardDescription>
                </CardHeader>
                <CardContent className="text-sm text-muted-foreground">
                    O módulo de Jurisprudência aparecerá aqui.
                </CardContent>
            </Card>
        </AppLayout>
    )
}

import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AuthLayout } from '@/layouts/auth-layout'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'

export default function VerifyEmail({ status }: { status?: string }) {
    const form = useForm({})

    const resend = (event: FormEvent) => {
        event.preventDefault()
        form.post('/email/verification-notification')
    }

    return (
        <AuthLayout
            title="Confirme seu e-mail"
            description="Enviamos um link de confirmação para o seu e-mail. Clique nele para liberar o acesso."
        >
            <Head title="Confirme seu e-mail" />

            {status === 'verification-link-sent' && (
                <Alert variant="success" role="status" className="mb-4">
                    Um novo link foi enviado.
                </Alert>
            )}

            <form onSubmit={resend} className="grid gap-3">
                <Button type="submit" disabled={form.processing} className="w-full">
                    Reenviar link
                </Button>
                <Button asChild variant="ghost" className="w-full">
                    <Link href="/logout" method="post" as="button">
                        Sair
                    </Link>
                </Button>
            </form>
        </AuthLayout>
    )
}

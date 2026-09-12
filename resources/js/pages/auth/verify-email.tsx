import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { Button } from '@/components/ui/button'

export default function VerifyEmail({ status }: { status?: string }) {
    const form = useForm({})

    const resend = (event: FormEvent) => {
        event.preventDefault()
        form.post('/email/verification-notification')
    }

    return (
        <div className="flex min-h-full items-center justify-center px-6 py-16">
            <Head title="Confirme seu e-mail" />
            <div className="w-full max-w-md rounded-lg bg-white p-6 text-center ring-1 ring-ink-200">
                <h1 className="font-serif text-2xl font-semibold text-brand-700">Confirme seu e-mail</h1>
                <p className="mt-3 text-sm text-ink-500">
                    Enviamos um link de confirmação para o seu e-mail. Clique nele para liberar o acesso.
                </p>

                {status === 'verification-link-sent' && (
                    <div role="status" className="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                        Um novo link foi enviado.
                    </div>
                )}

                <form onSubmit={resend} className="mt-5 flex items-center justify-center gap-3">
                    <Button type="submit" disabled={form.processing}>Reenviar link</Button>
                    <Link href="/logout" method="post" as="button" className="text-sm text-ink-500 hover:text-red-600">
                        Sair
                    </Link>
                </form>
            </div>
        </div>
    )
}

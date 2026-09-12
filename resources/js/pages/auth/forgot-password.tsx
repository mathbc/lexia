import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Field, Input } from '@/components/ui/field'

export default function ForgotPassword({ status }: { status?: string }) {
    const form = useForm({ email: '' })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/forgot-password')
    }

    return (
        <div className="flex min-h-full items-center justify-center px-6 py-16">
            <Head title="Recuperar senha" />
            <div className="w-full max-w-sm">
                <h1 className="text-center font-serif text-2xl font-semibold text-brand-700">Recuperar senha</h1>
                <p className="mt-2 mb-6 text-center text-sm text-ink-500">
                    Enviaremos um link para você definir uma nova senha.
                </p>

                {status && (
                    <div role="status" className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                        {status}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 ring-1 ring-ink-200">
                    <Field label="E-mail" error={form.errors.email} required>
                        <Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus required />
                    </Field>
                    <Button type="submit" disabled={form.processing} className="w-full">
                        {form.processing ? 'Enviando…' : 'Enviar link'}
                    </Button>
                    <Link href="/login" className="block text-center text-xs text-ink-500 hover:text-brand-700">
                        Voltar para o login
                    </Link>
                </form>
            </div>
        </div>
    )
}

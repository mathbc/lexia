import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AuthLayout } from '@/layouts/auth-layout'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Field, Input } from '@/components/ui/field'

export default function ForgotPassword({ status }: { status?: string }) {
    const form = useForm({ email: '' })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/forgot-password')
    }

    return (
        <AuthLayout
            title="Recuperar senha"
            description="Enviaremos um link para você definir uma nova senha."
            footer={
                <Link href="/login" className="font-medium text-foreground underline-offset-4 hover:underline">
                    Voltar para o login
                </Link>
            }
        >
            <Head title="Recuperar senha" />

            {status && (
                <Alert variant="success" role="status" className="mb-4">
                    {status}
                </Alert>
            )}

            <form onSubmit={submit} className="grid gap-4">
                <Field label="E-mail" error={form.errors.email} required>
                    <Input
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        autoFocus
                        required
                    />
                </Field>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {form.processing ? 'Enviando…' : 'Enviar link'}
                </Button>
            </form>
        </AuthLayout>
    )
}

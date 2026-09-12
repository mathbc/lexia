import { Head, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AuthLayout } from '@/layouts/auth-layout'
import { Button } from '@/components/ui/button'
import { Field, Input } from '@/components/ui/field'

export default function ResetPassword({ email, token }: { email: string; token: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })
    }

    return (
        <AuthLayout title="Nova senha" description="Escolha uma senha para voltar a acessar.">
            <Head title="Definir nova senha" />

            <form onSubmit={submit} className="grid gap-4">
                <Field label="E-mail" error={form.errors.email} required>
                    <Input
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        required
                    />
                </Field>
                <Field label="Nova senha" error={form.errors.password} required>
                    <Input
                        type="password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        autoComplete="new-password"
                        required
                    />
                </Field>
                <Field label="Confirme a nova senha" error={form.errors.password_confirmation} required>
                    <Input
                        type="password"
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                        autoComplete="new-password"
                        required
                    />
                </Field>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {form.processing ? 'Salvando…' : 'Salvar senha'}
                </Button>
            </form>
        </AuthLayout>
    )
}

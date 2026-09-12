import { Head, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AuthLayout } from '@/layouts/auth-layout'
import { Button } from '@/components/ui/button'
import { Field, Input } from '@/components/ui/field'

export default function ConfirmPassword() {
    const form = useForm({ password: '' })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/user/confirm-password', { onFinish: () => form.reset() })
    }

    return (
        <AuthLayout
            title="Confirme sua senha"
            description="Esta é uma área protegida. Confirme sua senha para continuar."
        >
            <Head title="Confirme sua senha" />

            <form onSubmit={submit} className="grid gap-4">
                <Field label="Senha" error={form.errors.password} required>
                    <Input
                        type="password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        autoFocus
                        required
                    />
                </Field>

                <Button type="submit" disabled={form.processing} className="w-full">
                    Confirmar
                </Button>
            </form>
        </AuthLayout>
    )
}

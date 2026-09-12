import { Head, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Field, Input } from '@/components/ui/field'

export default function ConfirmPassword() {
    const form = useForm({ password: '' })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/user/confirm-password', { onFinish: () => form.reset() })
    }

    return (
        <div className="flex min-h-full items-center justify-center px-6 py-16">
            <Head title="Confirme sua senha" />
            <form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-lg bg-white p-6 ring-1 ring-ink-200">
                <h1 className="font-serif text-xl font-semibold text-brand-700">Confirme sua senha</h1>
                <p className="text-sm text-ink-500">Esta é uma área protegida. Confirme sua senha para continuar.</p>

                <Field label="Senha" error={form.errors.password} required>
                    <Input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoFocus required />
                </Field>

                <Button type="submit" disabled={form.processing} className="w-full">Confirmar</Button>
            </form>
        </div>
    )
}

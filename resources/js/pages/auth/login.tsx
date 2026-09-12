import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Field, Input } from '@/components/ui/field'

export default function Login({ status }: { status?: string }) {
    const form = useForm({ email: '', password: '', remember: false })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/login', { onFinish: () => form.reset('password') })
    }

    return (
        <div className="flex min-h-full items-center justify-center px-6 py-16">
            <Head title="Entrar" />
            <div className="w-full max-w-sm">
                <h1 className="text-center font-serif text-3xl font-semibold text-brand-700">LexIA</h1>
                <p className="mt-2 mb-8 text-center text-sm text-ink-500">Acesse sua conta</p>

                {status && (
                    <div role="status" className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                        {status}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 ring-1 ring-ink-200">
                    <Field label="E-mail" error={form.errors.email} required>
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            autoComplete="username"
                            autoFocus
                            required
                        />
                    </Field>

                    <Field label="Senha" error={form.errors.password} required>
                        <Input
                            type="password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            autoComplete="current-password"
                            required
                        />
                    </Field>

                    <label className="flex items-center gap-2 text-sm text-ink-600">
                        <input
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(e) => form.setData('remember', e.target.checked)}
                            className="rounded border-ink-300 text-brand-600 focus:ring-brand-500"
                        />
                        Manter conectado
                    </label>

                    <Button type="submit" disabled={form.processing} className="w-full">
                        {form.processing ? 'Entrando…' : 'Entrar'}
                    </Button>

                    <div className="flex justify-between text-xs text-ink-500">
                        <Link href="/forgot-password" className="hover:text-brand-700">
                            Esqueci minha senha
                        </Link>
                        <Link href="/cadastro" className="hover:text-brand-700">
                            Criar conta
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    )
}

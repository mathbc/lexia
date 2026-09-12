import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AuthLayout } from '@/layouts/auth-layout'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, Input } from '@/components/ui/field'
import { Label } from '@/components/ui/label'

export default function Login({ status }: { status?: string }) {
    const form = useForm({ email: '', password: '', remember: false })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/login', { onFinish: () => form.reset('password') })
    }

    return (
        <AuthLayout
            title="Acesse sua conta"
            description="Entre com o e-mail cadastrado."
            footer={
                <>
                    Não tem conta?{' '}
                    <Link href="/cadastro" className="font-medium text-foreground underline-offset-4 hover:underline">
                        Criar conta
                    </Link>
                </>
            }
        >
            <Head title="Entrar" />

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

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="remember"
                        checked={form.data.remember}
                        onCheckedChange={(checked) => form.setData('remember', checked === true)}
                    />
                    <Label htmlFor="remember" className="text-sm font-normal">
                        Manter conectado
                    </Label>
                </div>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {form.processing ? 'Entrando…' : 'Entrar'}
                </Button>

                <Link
                    href="/forgot-password"
                    className="text-center text-xs text-muted-foreground underline-offset-4 hover:underline"
                >
                    Esqueci minha senha
                </Link>
            </form>
        </AuthLayout>
    )
}

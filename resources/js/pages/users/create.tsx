import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { UserFormFields, type UserFormValues } from '@/components/user-form-fields'
import type { Option } from '@/types'

export default function UserCreate({ roles, types, states }: { roles: Option[]; types: Option[]; states: Option[] }) {
    const form = useForm<UserFormValues>({
        name: '',
        email: '',
        type: '',
        role: '',
        oab_number: '',
        oab_state: '',
        birth_date: '',
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/usuarios')
    }

    return (
        <AppLayout title="Novo usuário">
            <Head title="Novo usuário" />
            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <section className="rounded-lg bg-white p-6 ring-1 ring-ink-200">
                    <UserFormFields
                        values={form.data}
                        errors={form.errors}
                        onChange={(key, value) => form.setData((current) => ({ ...current, [key]: value }))}
                        types={types}
                        states={states}
                        roles={roles}
                        canChangeRole
                    />
                    <p className="mt-4 text-xs text-ink-400">
                        O usuário receberá um convite por e-mail para definir a própria senha.
                    </p>
                </section>

                <div className="flex justify-end gap-3">
                    <Link href="/usuarios">
                        <Button type="button" variant="secondary">Cancelar</Button>
                    </Link>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Enviando…' : 'Enviar convite'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    )
}

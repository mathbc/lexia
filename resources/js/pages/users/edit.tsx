import { Head, Link, router, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { UserFormFields, type UserFormValues } from '@/components/user-form-fields'
import type { Option, UserRow } from '@/types'

interface Props {
    user: UserRow & { birth_date: string | null }
    canChangeRole: boolean
    roles: Option[]
    types: Option[]
    states: Option[]
}

export default function UserEdit({ user, canChangeRole, roles, types, states }: Props) {
    const form = useForm<UserFormValues>({
        name: user.name,
        email: user.email,
        type: user.type,
        role: user.role,
        oab_number: user.oab_number ?? '',
        oab_state: user.oab_state ?? '',
        birth_date: user.birth_date?.slice(0, 10) ?? '',
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.put(`/usuarios/${user.id}`)
    }

    return (
        <AppLayout title={user.name}>
            <Head title={`Editar ${user.name}`} />
            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <section className="rounded-lg bg-white p-6 ring-1 ring-ink-200">
                    <UserFormFields
                        values={form.data}
                        errors={form.errors}
                        onChange={(key, value) => form.setData((current) => ({ ...current, [key]: value }))}
                        types={types}
                        states={states}
                        roles={roles}
                        canChangeRole={canChangeRole}
                    />
                </section>

                <div className="flex items-center justify-between gap-3">
                    <Button
                        type="button"
                        variant={user.enabled ? 'danger' : 'secondary'}
                        onClick={() => router.patch(`/usuarios/${user.id}/status`)}
                    >
                        {user.enabled ? 'Desabilitar acesso' : 'Habilitar acesso'}
                    </Button>

                    <div className="flex gap-3">
                        <Link href="/usuarios">
                            <Button type="button" variant="secondary">Cancelar</Button>
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Salvando…' : 'Salvar'}
                        </Button>
                    </div>
                </div>
            </form>
        </AppLayout>
    )
}

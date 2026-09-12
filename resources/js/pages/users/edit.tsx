import { Head, Link, router, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { UserFormFields, type UserFormValues } from '@/components/user-form-fields'
import type { Option, UserRow } from '@/types'

interface Props {
    account: { id: string; name: string }
    user: UserRow & { birth_date: string | null }
    canChangeRole: boolean
    roles: Option[]
    types: Option[]
    states: Option[]
}

export default function UserEdit({ account, user, canChangeRole, roles, types, states }: Props) {
    const form = useForm<UserFormValues>({
        name: user.name,
        email: user.email,
        type: user.type,
        role: user.role,
        oab_number: user.oab_number ?? '',
        oab_state: user.oab_state ?? '',
        birth_date: user.birth_date?.slice(0, 10) ?? '',
    })

    const base = `/contas/${account.id}/usuarios`

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.put(`${base}/${user.id}`)
    }

    return (
        <AppLayout title={user.name} subtitle={account.name}>
            <Head title={`Editar ${user.name}`} />
            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <Card>
                    <CardContent>
                        <UserFormFields
                            values={form.data}
                            errors={form.errors}
                            onChange={(key, value) => form.setData((current) => ({ ...current, [key]: value }))}
                            types={types}
                            states={states}
                            roles={roles}
                            canChangeRole={canChangeRole}
                        />
                    </CardContent>
                </Card>

                <div className="flex items-center justify-between gap-3 pb-4">
                    <Button
                        type="button"
                        variant={user.enabled ? 'destructive' : 'outline'}
                        onClick={() => router.patch(`${base}/${user.id}/status`)}
                    >
                        {user.enabled ? 'Desabilitar acesso' : 'Habilitar acesso'}
                    </Button>

                    <div className="flex gap-3">
                        <Button asChild variant="outline">
                            <Link href={base}>Cancelar</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Salvando…' : 'Salvar'}
                        </Button>
                    </div>
                </div>
            </form>
        </AppLayout>
    )
}

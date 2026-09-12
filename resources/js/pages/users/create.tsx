import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { UserFormFields, type UserFormValues } from '@/components/user-form-fields'
import type { Option } from '@/types'

interface Props {
    account: { id: string; name: string }
    roles: Option[]
    types: Option[]
    states: Option[]
}

export default function UserCreate({ account, roles, types, states }: Props) {
    const form = useForm<UserFormValues>({
        name: '',
        email: '',
        type: '',
        role: '',
        oab_number: '',
        oab_state: '',
        birth_date: '',
    })

    const base = `/contas/${account.id}/usuarios`

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post(base)
    }

    return (
        <AppLayout title="Novo usuário" subtitle={account.name}>
            <Head title="Novo usuário" />
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
                            canChangeRole
                        />
                        <p className="mt-4 text-xs text-muted-foreground">
                            O usuário receberá um convite por e-mail para definir a própria senha.
                        </p>
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-3 pb-4">
                    <Button asChild variant="outline">
                        <Link href={base}>Cancelar</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Enviando…' : 'Enviar convite'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    )
}

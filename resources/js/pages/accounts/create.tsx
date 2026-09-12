import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { AccountFormFields, type AccountFormValues } from '@/components/account-form-fields'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import type { Option } from '@/types'

interface OwnerValues {
    owner_name: string
    owner_email: string
    owner_type: string
    owner_birth_date: string
}

type Values = AccountFormValues & OwnerValues

interface Props {
    accountTypes: Option[]
    states: Option[]
    userTypes: Option[]
}

export default function AccountCreate({ accountTypes, states, userTypes }: Props) {
    const form = useForm<Values>({
        name: '',
        legal_name: '',
        type: '',
        federal_id: '',
        oab_number: '',
        oab_state: '',
        email: '',
        phone: '',
        postal_code: '',
        street: '',
        number: '',
        complement: '',
        district: '',
        city: '',
        state: '',
        owner_name: '',
        owner_email: '',
        owner_type: '',
        owner_birth_date: '',
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/contas')
    }

    return (
        <AppLayout title="Nova conta">
            <Head title="Nova conta" />

            <form onSubmit={submit} className="max-w-3xl space-y-8">
                <AccountFormFields
                    values={form.data}
                    errors={form.errors}
                    set={(patch) => form.setData((current) => ({ ...current, ...patch }))}
                    accountTypes={accountTypes}
                    states={states}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Responsável</CardTitle>
                        <CardDescription>O primeiro usuário da conta, criado como Admin da Conta.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Nome" error={form.errors.owner_name} required>
                                <Input
                                    value={form.data.owner_name}
                                    onChange={(e) => form.setData('owner_name', e.target.value)}
                                />
                            </Field>

                            <Field label="E-mail" error={form.errors.owner_email} required>
                                <Input
                                    type="email"
                                    value={form.data.owner_email}
                                    onChange={(e) => form.setData('owner_email', e.target.value)}
                                />
                            </Field>

                            <Field label="Tipo" error={form.errors.owner_type} required>
                                <Select
                                    value={form.data.owner_type}
                                    onValueChange={(value) => form.setData('owner_type', value)}
                                    options={userTypes}
                                    placeholder="Selecione"
                                />
                            </Field>

                            <Field label="Data de nascimento" error={form.errors.owner_birth_date}>
                                <Input
                                    type="date"
                                    value={form.data.owner_birth_date}
                                    onChange={(e) => form.setData('owner_birth_date', e.target.value)}
                                />
                            </Field>
                        </div>

                        <p className="mt-4 text-xs text-muted-foreground">
                            O responsável receberá um convite por e-mail para definir a própria senha.
                        </p>
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-3 pb-4">
                    <Button asChild variant="outline">
                        <Link href="/contas">Cancelar</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Criando…' : 'Criar conta'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    )
}

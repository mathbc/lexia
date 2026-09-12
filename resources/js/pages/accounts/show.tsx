import { Head, router, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { AccountTabs } from '@/components/account-tabs'
import { AccountFormFields, type AccountFormValues } from '@/components/account-form-fields'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { accountIdentifier } from '@/lib/format'
import type { Account, AccountAbilities, Option } from '@/types'

interface Props {
    account: Account
    can: AccountAbilities
    accountTypes: Option[]
    states: Option[]
}

export default function AccountShow({ account, can, accountTypes, states }: Props) {
    const form = useForm<AccountFormValues>({
        name: account.name,
        legal_name: account.legal_name ?? '',
        type: account.type,
        federal_id: account.federal_id ?? '',
        oab_number: account.oab_number ?? '',
        oab_state: account.oab_state ?? '',
        email: account.email,
        phone: account.phone,
        postal_code: account.postal_code,
        street: account.street,
        number: account.number,
        complement: account.complement ?? '',
        district: account.district,
        city: account.city,
        state: account.state,
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.put(`/contas/${account.id}`)
    }

    const identifier = accountIdentifier(account)

    return (
        <AppLayout
            title={account.legal_name ?? account.name}
            subtitle={
                <span className="flex items-center gap-2">
                    {identifier && <span className="tabular">{identifier}</span>}
                    <Badge variant={account.active ? 'success' : 'muted'}>
                        {account.active ? 'Ativa' : 'Inativa'}
                    </Badge>
                </span>
            }
            tabs={<AccountTabs accountId={account.id} can={can} current="general" />}
        >
            <Head title={account.name} />

            <form onSubmit={submit} className="max-w-3xl space-y-8">
                <AccountFormFields
                    values={form.data}
                    errors={form.errors}
                    set={(patch) => form.setData((current) => ({ ...current, ...patch }))}
                    accountTypes={accountTypes}
                    states={states}
                    disabled={!can.update}
                />

                {(can.update || can.toggle_status) && (
                    <div className="flex flex-wrap items-center justify-between gap-3 pb-4">
                        {can.toggle_status ? (
                            <Button
                                type="button"
                                variant={account.active ? 'destructive' : 'outline'}
                                onClick={() => router.patch(`/contas/${account.id}/status`)}
                            >
                                {account.active ? 'Desativar conta' : 'Reativar conta'}
                            </Button>
                        ) : (
                            <span />
                        )}

                        {can.update && (
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Salvando…' : 'Salvar alterações'}
                            </Button>
                        )}
                    </div>
                )}
            </form>
        </AppLayout>
    )
}

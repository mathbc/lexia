import { Head, Link, router, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { CustomerFormFields, type CustomerFormValues } from '@/components/customer-form-fields'
import { Button } from '@/components/ui/button'
import { customerIdentifier } from '@/lib/format'
import type { Customer, CustomerAbilities, Option } from '@/types'

interface Props {
    customer: Customer
    can: CustomerAbilities
    customerTypes: Option[]
    states: Option[]
}

export default function CustomerShow({ customer, can, customerTypes, states }: Props) {
    const form = useForm<CustomerFormValues>({
        name: customer.name,
        legal_name: customer.legal_name ?? '',
        type: customer.type,
        cpf: customer.cpf ?? '',
        cnpj: customer.cnpj ?? '',
        email: customer.email,
        phone: customer.phone,
        postal_code: customer.postal_code,
        street: customer.street,
        number: customer.number,
        complement: customer.complement ?? '',
        district: customer.district,
        city: customer.city,
        state: customer.state,
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.put(`/clientes/${customer.id}`)
    }

    const remove = () => {
        if (window.confirm(`Excluir ${customer.name}? A ação não pode ser desfeita.`)) {
            router.delete(`/clientes/${customer.id}`)
        }
    }

    const identifier = customerIdentifier(customer)

    return (
        <AppLayout
            title={customer.legal_name ?? customer.name}
            subtitle={identifier && <span className="tabular">{identifier}</span>}
        >
            <Head title={customer.name} />

            <form onSubmit={submit} className="max-w-3xl space-y-8">
                <CustomerFormFields
                    values={form.data}
                    errors={form.errors}
                    set={(patch) => form.setData((current) => ({ ...current, ...patch }))}
                    customerTypes={customerTypes}
                    states={states}
                    disabled={!can.update}
                />

                <div className="flex flex-wrap items-center justify-between gap-3 pb-4">
                    {can.delete ? (
                        <Button type="button" variant="destructive" onClick={remove}>
                            Excluir cliente
                        </Button>
                    ) : (
                        <span />
                    )}

                    <div className="flex gap-3">
                        <Button asChild variant="outline">
                            <Link href="/clientes">Voltar</Link>
                        </Button>
                        {can.update && (
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Salvando…' : 'Salvar alterações'}
                            </Button>
                        )}
                    </div>
                </div>
            </form>
        </AppLayout>
    )
}

import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { CustomerFormFields, type CustomerFormValues } from '@/components/customer-form-fields'
import { Button } from '@/components/ui/button'
import type { Option } from '@/types'

interface Props {
    customerTypes: Option[]
    maritalStatuses: Option[]
    states: Option[]
}

export default function CustomerCreate({ customerTypes, maritalStatuses, states }: Props) {
    const form = useForm<CustomerFormValues>({
        name: '',
        legal_name: '',
        type: '',
        cpf: '',
        cnpj: '',
        marital_status: '',
        occupation: '',
        birth_date: '',
        email: '',
        phone: '',
        postal_code: '',
        street: '',
        number: '',
        complement: '',
        district: '',
        city: '',
        state: '',
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/clientes')
    }

    return (
        <AppLayout title="Novo cliente">
            <Head title="Novo cliente" />

            <form onSubmit={submit} className="max-w-3xl space-y-8">
                <CustomerFormFields
                    values={form.data}
                    errors={form.errors}
                    set={(patch) => form.setData((current) => ({ ...current, ...patch }))}
                    customerTypes={customerTypes}
                    maritalStatuses={maritalStatuses}
                    states={states}
                />

                <div className="flex justify-end gap-3 pb-4">
                    <Button asChild variant="outline">
                        <Link href="/clientes">Cancelar</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Salvando…' : 'Cadastrar cliente'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    )
}

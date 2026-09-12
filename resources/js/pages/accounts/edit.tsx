import { Head, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Field, Input, Select } from '@/components/ui/field'
import { digits, formatCnpj, formatPhone, formatPostalCode, isValidCnpj } from '@/lib/format'
import type { Option } from '@/types'

interface Account {
    id: string
    name: string
    legal_name: string | null
    type: string
    federal_id: string | null
    oab_number: string | null
    oab_state: string | null
    email: string
    phone: string
    postal_code: string
    street: string
    number: string
    complement: string | null
    district: string
    city: string
    state: string
    active: boolean
}

export default function AccountEdit({
    account,
    canUpdate,
    accountTypes,
    states,
}: {
    account: Account
    canUpdate: boolean
    accountTypes: Option[]
    states: Option[]
}) {
    const form = useForm({
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

    const isFirm = form.data.type === 'law_firm'

    // Client-side only for immediate feedback; the Cnpj rule on the server
    // remains the source of truth.
    const cnpjLooksWrong = isFirm && form.data.federal_id.length > 0 && !isValidCnpj(form.data.federal_id)

    const lookupPostalCode = async (value: string) => {
        const raw = digits(value)
        if (raw.length !== 8) return

        try {
            const response = await fetch(`https://viacep.com.br/ws/${raw}/json/`)
            const data = await response.json()
            if (data.erro) return

            form.setData((current) => ({
                ...current,
                street: data.logradouro || current.street,
                district: data.bairro || current.district,
                city: data.localidade || current.city,
                state: data.uf || current.state,
            }))
        } catch {
            // A CEP lookup failure must never block the form; the fields stay
            // editable by hand.
        }
    }

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.put(`/conta/${account.id}`)
    }

    return (
        <AppLayout title="Minha conta">
            <Head title="Minha conta" />

            <form onSubmit={submit} className="max-w-3xl space-y-8">
                <section className="rounded-lg bg-white p-6 ring-1 ring-ink-200">
                    <h2 className="mb-4 text-lg font-semibold">Identificação</h2>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Tipo de conta" error={form.errors.type} required>
                            <Select
                                value={form.data.type}
                                onChange={(e) => form.setData('type', e.target.value)}
                                options={accountTypes}
                                disabled={!canUpdate}
                            />
                        </Field>

                        <Field label="Nome" error={form.errors.name} required>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                disabled={!canUpdate}
                            />
                        </Field>

                        {isFirm ? (
                            <>
                                <Field label="Razão social" error={form.errors.legal_name} required>
                                    <Input
                                        value={form.data.legal_name}
                                        onChange={(e) => form.setData('legal_name', e.target.value)}
                                        disabled={!canUpdate}
                                    />
                                </Field>

                                <Field
                                    label="CNPJ"
                                    required
                                    error={form.errors.federal_id ?? (cnpjLooksWrong ? 'CNPJ inválido.' : undefined)}
                                >
                                    <Input
                                        value={formatCnpj(form.data.federal_id)}
                                        onChange={(e) => form.setData('federal_id', digits(e.target.value))}
                                        inputMode="numeric"
                                        placeholder="00.000.000/0000-00"
                                        disabled={!canUpdate}
                                    />
                                </Field>
                            </>
                        ) : (
                            <>
                                <Field label="Número da OAB" error={form.errors.oab_number} required>
                                    <Input
                                        value={form.data.oab_number}
                                        onChange={(e) => form.setData('oab_number', e.target.value.toUpperCase())}
                                        placeholder="123456"
                                        disabled={!canUpdate}
                                    />
                                </Field>

                                <Field label="Seccional" error={form.errors.oab_state} required>
                                    <Select
                                        value={form.data.oab_state}
                                        onChange={(e) => form.setData('oab_state', e.target.value)}
                                        options={states}
                                        placeholder="Selecione"
                                        disabled={!canUpdate}
                                    />
                                </Field>
                            </>
                        )}
                    </div>
                </section>

                <section className="rounded-lg bg-white p-6 ring-1 ring-ink-200">
                    <h2 className="mb-4 text-lg font-semibold">Contato</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="E-mail" error={form.errors.email} required>
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                disabled={!canUpdate}
                            />
                        </Field>
                        <Field label="Telefone" error={form.errors.phone} required>
                            <Input
                                value={formatPhone(form.data.phone)}
                                onChange={(e) => form.setData('phone', digits(e.target.value))}
                                inputMode="tel"
                                placeholder="(11) 90000-0000"
                                disabled={!canUpdate}
                            />
                        </Field>
                    </div>
                </section>

                <section className="rounded-lg bg-white p-6 ring-1 ring-ink-200">
                    <h2 className="mb-4 text-lg font-semibold">Endereço</h2>
                    <div className="grid gap-4 sm:grid-cols-6">
                        <div className="sm:col-span-2">
                            <Field label="CEP" error={form.errors.postal_code} hint="Preenche o endereço" required>
                                <Input
                                    value={formatPostalCode(form.data.postal_code)}
                                    onChange={(e) => form.setData('postal_code', digits(e.target.value))}
                                    onBlur={(e) => lookupPostalCode(e.target.value)}
                                    inputMode="numeric"
                                    placeholder="00000-000"
                                    disabled={!canUpdate}
                                />
                            </Field>
                        </div>
                        <div className="sm:col-span-3">
                            <Field label="Logradouro" error={form.errors.street} required>
                                <Input value={form.data.street} onChange={(e) => form.setData('street', e.target.value)} disabled={!canUpdate} />
                            </Field>
                        </div>
                        <Field label="Número" error={form.errors.number} required>
                            <Input value={form.data.number} onChange={(e) => form.setData('number', e.target.value)} disabled={!canUpdate} />
                        </Field>

                        <div className="sm:col-span-2">
                            <Field label="Complemento" error={form.errors.complement}>
                                <Input value={form.data.complement} onChange={(e) => form.setData('complement', e.target.value)} disabled={!canUpdate} />
                            </Field>
                        </div>
                        <div className="sm:col-span-2">
                            <Field label="Bairro" error={form.errors.district} required>
                                <Input value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} disabled={!canUpdate} />
                            </Field>
                        </div>
                        <Field label="Cidade" error={form.errors.city} required>
                            <Input value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} disabled={!canUpdate} />
                        </Field>
                        <Field label="UF" error={form.errors.state} required>
                            <Select value={form.data.state} onChange={(e) => form.setData('state', e.target.value)} options={states} disabled={!canUpdate} />
                        </Field>
                    </div>
                </section>

                {canUpdate && (
                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Salvando…' : 'Salvar alterações'}
                        </Button>
                    </div>
                )}
            </form>
        </AppLayout>
    )
}

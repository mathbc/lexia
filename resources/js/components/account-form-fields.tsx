import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { digits, formatCnpj, formatPhone, formatPostalCode, isValidCnpj } from '@/lib/format'
import type { Option } from '@/types'

export interface AccountFormValues {
    name: string
    legal_name: string
    type: string
    federal_id: string
    oab_number: string
    oab_state: string
    email: string
    phone: string
    postal_code: string
    street: string
    number: string
    complement: string
    district: string
    city: string
    state: string
}

type Errors = Partial<Record<keyof AccountFormValues, string>>

interface Props {
    values: AccountFormValues
    errors: Errors
    /** Patches one or more fields; the CEP lookup fills four at once. */
    set: (patch: Partial<AccountFormValues>) => void
    accountTypes: Option[]
    states: Option[]
    disabled?: boolean
}

/**
 * Shared between creating an account and editing one, so the two screens
 * cannot drift.
 */
export function AccountFormFields({ values, errors, set, accountTypes, states, disabled = false }: Props) {
    const isFirm = values.type === 'law_firm'

    // Client-side only for immediate feedback; the Cnpj rule on the server
    // remains the source of truth.
    const cnpjLooksWrong = isFirm && values.federal_id.length > 0 && !isValidCnpj(values.federal_id)

    const lookupPostalCode = async (value: string) => {
        const raw = digits(value)
        if (raw.length !== 8) return

        try {
            const response = await fetch(`https://viacep.com.br/ws/${raw}/json/`)
            const data = await response.json()
            if (data.erro) return

            set({
                street: data.logradouro || values.street,
                district: data.bairro || values.district,
                city: data.localidade || values.city,
                state: data.uf || values.state,
            })
        } catch {
            // A CEP lookup failure must never block the form; the fields stay
            // editable by hand.
        }
    }

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle>Identificação</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <Field label="Tipo de conta" error={errors.type} required>
                        <Select
                            value={values.type}
                            onValueChange={(value) => set({ type: value })}
                            options={accountTypes}
                            placeholder="Selecione"
                            disabled={disabled}
                        />
                    </Field>

                    <Field label="Nome" error={errors.name} required>
                        <Input value={values.name} onChange={(e) => set({ name: e.target.value })} disabled={disabled} />
                    </Field>

                    {isFirm ? (
                        <>
                            <Field label="Razão social" error={errors.legal_name} required>
                                <Input
                                    value={values.legal_name}
                                    onChange={(e) => set({ legal_name: e.target.value })}
                                    disabled={disabled}
                                />
                            </Field>

                            <Field
                                label="CNPJ"
                                required
                                error={errors.federal_id ?? (cnpjLooksWrong ? 'CNPJ inválido.' : undefined)}
                            >
                                <Input
                                    value={formatCnpj(values.federal_id)}
                                    onChange={(e) => set({ federal_id: digits(e.target.value) })}
                                    inputMode="numeric"
                                    placeholder="00.000.000/0000-00"
                                    disabled={disabled}
                                />
                            </Field>
                        </>
                    ) : (
                        <>
                            <Field label="Número da OAB" error={errors.oab_number} required>
                                <Input
                                    value={values.oab_number}
                                    onChange={(e) => set({ oab_number: e.target.value.toUpperCase() })}
                                    placeholder="123456"
                                    disabled={disabled}
                                />
                            </Field>

                            <Field label="Seccional" error={errors.oab_state} required>
                                <Select
                                    value={values.oab_state}
                                    onValueChange={(value) => set({ oab_state: value })}
                                    options={states}
                                    placeholder="Selecione"
                                    disabled={disabled}
                                />
                            </Field>
                        </>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Contato</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <Field label="E-mail" error={errors.email} required>
                        <Input
                            type="email"
                            value={values.email}
                            onChange={(e) => set({ email: e.target.value })}
                            disabled={disabled}
                        />
                    </Field>
                    <Field label="Telefone" error={errors.phone} required>
                        <Input
                            value={formatPhone(values.phone)}
                            onChange={(e) => set({ phone: digits(e.target.value) })}
                            inputMode="tel"
                            placeholder="(11) 90000-0000"
                            disabled={disabled}
                        />
                    </Field>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Endereço</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-6">
                    <Field label="CEP" error={errors.postal_code} hint="Preenche o endereço" required className="sm:col-span-2">
                        <Input
                            value={formatPostalCode(values.postal_code)}
                            onChange={(e) => set({ postal_code: digits(e.target.value) })}
                            onBlur={(e) => lookupPostalCode(e.target.value)}
                            inputMode="numeric"
                            placeholder="00000-000"
                            disabled={disabled}
                        />
                    </Field>

                    <Field label="Logradouro" error={errors.street} required className="sm:col-span-3">
                        <Input value={values.street} onChange={(e) => set({ street: e.target.value })} disabled={disabled} />
                    </Field>

                    <Field label="Número" error={errors.number} required>
                        <Input value={values.number} onChange={(e) => set({ number: e.target.value })} disabled={disabled} />
                    </Field>

                    <Field label="Complemento" error={errors.complement} className="sm:col-span-2">
                        <Input
                            value={values.complement}
                            onChange={(e) => set({ complement: e.target.value })}
                            disabled={disabled}
                        />
                    </Field>

                    <Field label="Bairro" error={errors.district} required className="sm:col-span-2">
                        <Input value={values.district} onChange={(e) => set({ district: e.target.value })} disabled={disabled} />
                    </Field>

                    <Field label="Cidade" error={errors.city} required>
                        <Input value={values.city} onChange={(e) => set({ city: e.target.value })} disabled={disabled} />
                    </Field>

                    <Field label="UF" error={errors.state} required>
                        <Select
                            value={values.state}
                            onValueChange={(value) => set({ state: value })}
                            options={states}
                            placeholder="Selecione"
                            disabled={disabled}
                        />
                    </Field>
                </CardContent>
            </Card>
        </>
    )
}

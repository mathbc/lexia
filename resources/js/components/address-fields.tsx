import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { digits, formatPostalCode } from '@/lib/format'
import { lookupPostalCode } from '@/lib/postal-code'
import type { Option } from '@/types'

export interface AddressFormValues {
    postal_code: string
    street: string
    number: string
    complement: string
    district: string
    city: string
    state: string
}

interface Props {
    values: AddressFormValues
    errors: Partial<Record<keyof AddressFormValues, string>>
    /** Aplica um patch; a consulta de CEP preenche quatro campos de uma vez. */
    set: (patch: Partial<AddressFormValues>) => void
    states: Option[]
    disabled?: boolean
}

/**
 * O endereço postal, igual em todo cadastro que tem um.
 *
 * A forma é fixada pelos Correios, então conta e cliente desenham o mesmo
 * cartão em vez de manterem duas cópias que divergem.
 */
export function AddressFields({ values, errors, set, states, disabled = false }: Props) {
    const fillFromPostalCode = async (value: string) => {
        const patch = await lookupPostalCode(value)

        if (patch) {
            set(patch)
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Endereço</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-6">
                <Field label="CEP" error={errors.postal_code} hint="Preenche o endereço" required className="sm:col-span-2">
                    <Input
                        value={formatPostalCode(values.postal_code)}
                        onChange={(e) => set({ postal_code: digits(e.target.value) })}
                        onBlur={(e) => fillFromPostalCode(e.target.value)}
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
    )
}

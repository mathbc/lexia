import { AddressFields, type AddressFormValues } from '@/components/address-fields'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { digits, formatCnpj, formatCpf, formatPhone, isValidCnpj, isValidCpf } from '@/lib/format'
import type { Option } from '@/types'

export interface CustomerFormValues extends AddressFormValues {
    name: string
    legal_name: string
    type: string
    cpf: string
    cnpj: string
    email: string
    phone: string
}

type Errors = Partial<Record<keyof CustomerFormValues, string>>

interface Props {
    values: CustomerFormValues
    errors: Errors
    /** Aplica um patch; a consulta de CEP preenche quatro campos de uma vez. */
    set: (patch: Partial<CustomerFormValues>) => void
    customerTypes: Option[]
    states: Option[]
    disabled?: boolean
}

/**
 * Compartilhado entre cadastrar um cliente e editá-lo, para que as duas telas
 * não divirjam.
 *
 * O tipo decide qual documento aparece: pessoa física traz CPF, pessoa
 * jurídica traz razão social e CNPJ. É a mesma regra que o `required_if` do
 * servidor aplica — aqui ela só evita pedir o que não vale.
 */
export function CustomerFormFields({ values, errors, set, customerTypes, states, disabled = false }: Props) {
    const isCompany = values.type === 'company'

    // Só para resposta imediata; as regras Cpf e Cnpj no servidor continuam
    // sendo a fonte da verdade.
    const cpfLooksWrong = !isCompany && values.cpf.length > 0 && !isValidCpf(values.cpf)
    const cnpjLooksWrong = isCompany && values.cnpj.length > 0 && !isValidCnpj(values.cnpj)

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle>Identificação</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <Field label="Tipo de cliente" error={errors.type} required>
                        <Select
                            value={values.type}
                            onValueChange={(value) => set({ type: value })}
                            options={customerTypes}
                            placeholder="Selecione"
                            disabled={disabled}
                        />
                    </Field>

                    <Field label="Nome" error={errors.name} hint={isCompany ? 'Nome fantasia' : undefined} required>
                        <Input value={values.name} onChange={(e) => set({ name: e.target.value })} disabled={disabled} />
                    </Field>

                    {isCompany ? (
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
                                error={errors.cnpj ?? (cnpjLooksWrong ? 'CNPJ inválido.' : undefined)}
                            >
                                <Input
                                    value={formatCnpj(values.cnpj)}
                                    onChange={(e) => set({ cnpj: digits(e.target.value) })}
                                    inputMode="numeric"
                                    placeholder="00.000.000/0000-00"
                                    disabled={disabled}
                                />
                            </Field>
                        </>
                    ) : (
                        <Field label="CPF" required error={errors.cpf ?? (cpfLooksWrong ? 'CPF inválido.' : undefined)}>
                            <Input
                                value={formatCpf(values.cpf)}
                                onChange={(e) => set({ cpf: digits(e.target.value) })}
                                inputMode="numeric"
                                placeholder="000.000.000-00"
                                disabled={disabled}
                            />
                        </Field>
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

            <AddressFields values={values} errors={errors} set={set} states={states} disabled={disabled} />
        </>
    )
}

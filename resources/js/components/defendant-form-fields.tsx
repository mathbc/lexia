import { AddressFields, type AddressFormValues } from '@/components/address-fields'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Textarea } from '@/components/ui/field'
import { digits, formatDocument, formatPhone, isValidCnpj, isValidCpf } from '@/lib/format'
import type { Option } from '@/types'

export interface DefendantFormValues {
    defendant_name: string
    defendant_document: string
    defendant_email: string
    defendant_phone: string
    defendant_postal_code: string
    defendant_street: string
    defendant_number: string
    defendant_complement: string
    defendant_district: string
    defendant_city: string
    defendant_state: string
    defendant_notes: string
}

type Errors = Partial<Record<keyof DefendantFormValues, string>>

/**
 * O `AddressFields` fala `postal_code`; aqui tudo é `defendant_postal_code`.
 * As três funções abaixo são a ponte entre os dois nomes — o que custa menos
 * do que manter um segundo cartão de endereço, com a consulta de CEP
 * duplicada, só porque as chaves têm outro prefixo.
 */
const addressOf = (values: DefendantFormValues): AddressFormValues => ({
    postal_code: values.defendant_postal_code,
    street: values.defendant_street,
    number: values.defendant_number,
    complement: values.defendant_complement,
    district: values.defendant_district,
    city: values.defendant_city,
    state: values.defendant_state,
})

const addressErrorsOf = (errors: Errors): Partial<Record<keyof AddressFormValues, string>> => ({
    postal_code: errors.defendant_postal_code,
    street: errors.defendant_street,
    number: errors.defendant_number,
    complement: errors.defendant_complement,
    district: errors.defendant_district,
    city: errors.defendant_city,
    state: errors.defendant_state,
})

/**
 * O caminho de volta. As chaves ausentes são descartadas em vez de viajarem
 * como `undefined`: a consulta de CEP devolve quatro campos, e os outros três
 * seriam apagados — o controle ficaria sem valor e o React o trataria como não
 * controlado.
 */
const defendantPatchOf = (patch: Partial<AddressFormValues>): Partial<DefendantFormValues> =>
    Object.fromEntries(
        Object.entries(patch)
            .filter(([, value]) => value !== undefined)
            .map(([key, value]) => [`defendant_${key}`, value]),
    )

interface Props {
    values: DefendantFormValues
    errors: Errors
    /** Aplica um patch; a consulta de CEP preenche quatro campos de uma vez. */
    set: (patch: Partial<DefendantFormValues>) => void
    states: Option[]
    disabled?: boolean
}

/**
 * Quem está do outro lado da peça.
 *
 * Nenhum campo é obrigatório, e isso é a regra e não uma folga: o cliente se
 * cadastra depois de conhecido, o réu costuma ser o contrário — um nome e uma
 * placa, uma empresa sem endereço ainda. O formulário aceita o que o advogado
 * tem, e as informações complementares guardam o resto.
 *
 * O documento é um campo só. Ao contrário do cliente, o réu é descrito e não
 * cadastrado: não há unicidade a garantir por tipo, então não se pergunta se é
 * pessoa física ou jurídica — a máscara se decide pela quantidade de dígitos.
 */
export function DefendantFormFields({ values, errors, set, states, disabled = false }: Props) {
    const document = values.defendant_document

    // Só reclama de documento completo: onze dígitos que não fecham como CPF,
    // catorze que não fecham como CNPJ. Enquanto está sendo digitado, calado.
    const documentLooksWrong =
        (document.length === 11 && !isValidCpf(document)) || (document.length === 14 && !isValidCnpj(document))

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle>Identificação do réu</CardTitle>
                    <CardDescription>
                        Informe o que já se sabe. O que faltar pode ser complementado depois.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <Field label="Nome ou razão social" error={errors.defendant_name}>
                        <Input
                            value={values.defendant_name}
                            onChange={(e) => set({ defendant_name: e.target.value })}
                            disabled={disabled}
                        />
                    </Field>

                    <Field
                        label="CPF ou CNPJ"
                        hint="A máscara se ajusta ao documento digitado"
                        error={errors.defendant_document ?? (documentLooksWrong ? 'Documento inválido.' : undefined)}
                    >
                        <Input
                            value={formatDocument(document)}
                            onChange={(e) => set({ defendant_document: digits(e.target.value).slice(0, 14) })}
                            inputMode="numeric"
                            placeholder="000.000.000-00"
                            disabled={disabled}
                        />
                    </Field>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Contato do réu</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <Field label="E-mail" error={errors.defendant_email}>
                        <Input
                            type="email"
                            value={values.defendant_email}
                            onChange={(e) => set({ defendant_email: e.target.value })}
                            disabled={disabled}
                        />
                    </Field>

                    <Field label="Telefone" error={errors.defendant_phone}>
                        <Input
                            value={formatPhone(values.defendant_phone)}
                            onChange={(e) => set({ defendant_phone: digits(e.target.value) })}
                            inputMode="tel"
                            placeholder="(11) 90000-0000"
                            disabled={disabled}
                        />
                    </Field>
                </CardContent>
            </Card>

            <AddressFields
                title="Endereço do réu"
                required={false}
                values={addressOf(values)}
                errors={addressErrorsOf(errors)}
                set={(patch) => set(defendantPatchOf(patch))}
                states={states}
                disabled={disabled}
            />

            <Card>
                <CardHeader>
                    <CardTitle>Informações complementares</CardTitle>
                    <CardDescription>
                        Tudo o que ajuda a localizar ou identificar o réu e não cabe nos campos acima.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Field label="Observações" error={errors.defendant_notes}>
                        <Textarea
                            value={values.defendant_notes}
                            onChange={(e) => set({ defendant_notes: e.target.value })}
                            rows={6}
                            placeholder="Placa do veículo, local de trabalho, redes sociais, horários em que é encontrado…"
                            disabled={disabled}
                        />
                    </Field>
                </CardContent>
            </Card>
        </>
    )
}

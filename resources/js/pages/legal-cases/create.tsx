import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { CustomerCreateDialog } from '@/components/customer-create-dialog'
import { DefendantFormFields, type DefendantFormValues } from '@/components/defendant-form-fields'
import { LegalCaseSteps, type LegalCaseStep } from '@/components/legal-case-steps'
import { PracticeAreaPicker } from '@/components/practice-area-picker'
import { ProceduralClassPicker } from '@/components/procedural-class-picker'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Select } from '@/components/ui/field'
import type { Option, ProceduralClassOption } from '@/types'

const STEPS: LegalCaseStep[] = [
    { label: 'Dados básicos', description: 'Cliente, área de atuação e classe processual' },
    { label: 'Dados do réu', description: 'Quem é a parte contrária e como localizá-la' },
    { label: 'Preenchimento da peça', description: 'Fatos, fundamentos e pedidos' },
    { label: 'Revisão forense', description: 'Conferência final antes do protocolo' },
]

/** Nada do réu é obrigatório — ver `DefendantFormFields`. */
const EMPTY_DEFENDANT: DefendantFormValues = {
    defendant_name: '',
    defendant_document: '',
    defendant_email: '',
    defendant_phone: '',
    defendant_postal_code: '',
    defendant_street: '',
    defendant_number: '',
    defendant_complement: '',
    defendant_district: '',
    defendant_city: '',
    defendant_state: '',
    defendant_notes: '',
}

interface Props {
    customers: Option[]
    practiceAreas: Option[]
    proceduralClasses: ProceduralClassOption[]
    /** A área da query string: é o servidor que guarda essa escolha. */
    selectedArea: string
    branches: Option[]
    degrees: Option[]
    /** Para o cadastro de cliente que acontece aqui mesmo, sem trocar de tela. */
    customerTypes: Option[]
    states: Option[]
    can: { create_customer: boolean }
}

export default function LegalCaseCreate({
    customers,
    practiceAreas,
    proceduralClasses,
    selectedArea,
    branches,
    degrees,
    customerTypes,
    states,
    can,
}: Props) {
    const [step, setStep] = useState(0)
    const [customerId, setCustomerId] = useState('')
    const [proceduralClassId, setProceduralClassId] = useState('')

    // Ainda não há persistência: o réu mora em estado local até existir uma
    // Action que o receba. Fica fora do `useForm` de propósito — não há para
    // onde enviar, e um formulário sem destino só esconderia isso.
    const [defendant, setDefendant] = useState<DefendantFormValues>(EMPTY_DEFENDANT)

    /**
     * A área vive na URL, não em estado local: é ela que diz ao servidor quais
     * classes mandar. `reload` já preserva estado e rolagem, então o cliente
     * escolhido e o passo atual sobrevivem à troca; `replace` evita encher o
     * histórico a cada área visitada.
     */
    const selectArea = (slug: string) => {
        setProceduralClassId('')
        router.reload({
            only: ['proceduralClasses', 'selectedArea'],
            data: { area: slug },
            replace: true,
        })
    }

    const complete = customerId !== '' && selectedArea !== '' && proceduralClassId !== ''

    return (
        <AppLayout title="Nova peça">
            <Head title="Nova peça" />

            <div className="grid gap-6 pb-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <LegalCaseSteps
                    steps={STEPS}
                    current={step}
                    // Enquanto a etapa 1 não fecha, as outras ficam inertes.
                    reachable={complete ? STEPS.length - 1 : 0}
                    onSelect={setStep}
                />

                <div className="min-w-0 space-y-6">
                    {step === 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Dados básicos</CardTitle>
                                <CardDescription>
                                    Para quem é a peça e sob que enquadramento ela será redigida.
                                </CardDescription>
                            </CardHeader>

                            <CardContent className="space-y-8">
                                <Field
                                    label="Cliente"
                                    required
                                    className="max-w-md"
                                    hint={
                                        customers.length === 0
                                            ? 'Nenhum cliente cadastrado ainda.'
                                            : undefined
                                    }
                                    action={
                                        can.create_customer && (
                                            <CustomerCreateDialog
                                                customerTypes={customerTypes}
                                                states={states}
                                                onCreated={(customer) => setCustomerId(customer.value)}
                                            />
                                        )
                                    }
                                >
                                    <Select
                                        value={customerId}
                                        onValueChange={setCustomerId}
                                        options={customers}
                                        placeholder="Selecione o cliente"
                                    />
                                </Field>

                                <fieldset className="space-y-3">
                                    <legend className="text-sm font-medium">
                                        Área de atuação
                                        <span aria-hidden className="ml-0.5 text-destructive">
                                            *
                                        </span>
                                    </legend>
                                    <PracticeAreaPicker
                                        areas={practiceAreas}
                                        value={selectedArea}
                                        onSelect={selectArea}
                                    />
                                </fieldset>

                                <fieldset className="space-y-3">
                                    <legend className="text-sm font-medium">
                                        Classe processual
                                        <span aria-hidden className="ml-0.5 text-destructive">
                                            *
                                        </span>
                                    </legend>

                                    {selectedArea === '' ? (
                                        <p className="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                                            Escolha uma área de atuação para ver as classes disponíveis.
                                        </p>
                                    ) : (
                                        // A key remonta o seletor a cada área:
                                        // um filtro de instância que sobrasse da
                                        // área anterior esvaziaria a lista sem
                                        // explicação.
                                        <ProceduralClassPicker
                                            key={selectedArea}
                                            classes={proceduralClasses}
                                            branches={branches}
                                            degrees={degrees}
                                            value={proceduralClassId}
                                            onSelect={setProceduralClassId}
                                        />
                                    )}
                                </fieldset>
                            </CardContent>
                        </Card>
                    )}

                    {step === 1 && (
                        <DefendantFormFields
                            values={defendant}
                            // Sem servidor ainda, não há erro para mostrar: a
                            // validação do réu nasce junto com a Action.
                            errors={{}}
                            set={(patch) => setDefendant((current) => ({ ...current, ...patch }))}
                            states={states}
                        />
                    )}

                    {step > 1 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{STEPS[step]?.label}</CardTitle>
                                <CardDescription>{STEPS[step]?.description}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <p className="rounded-lg border border-dashed px-4 py-12 text-center text-sm text-muted-foreground">
                                    Esta etapa ainda não foi implementada.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Cancelar só no primeiro passo, onde ainda não se andou
                        nada; dali em diante o par é Voltar/Continuar. O
                        Continuar some no último passo porque não há para onde
                        ir enquanto a peça não for salva. */}
                    <div className="flex justify-end gap-3">
                        {step === 0 ? (
                            <Button asChild variant="outline">
                                <Link href="/pecas">Cancelar</Link>
                            </Button>
                        ) : (
                            <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                                Voltar
                            </Button>
                        )}

                        {step < STEPS.length - 1 && (
                            <Button type="button" disabled={!complete} onClick={() => setStep(step + 1)}>
                                Continuar
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}

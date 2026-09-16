import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { LegalCaseSteps, type LegalCaseStep } from '@/components/legal-case-steps'
import { PracticeAreaPicker } from '@/components/practice-area-picker'
import { ProceduralClassPicker } from '@/components/procedural-class-picker'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Select } from '@/components/ui/field'
import type { Option, ProceduralClassOption } from '@/types'

const STEPS: LegalCaseStep[] = [
    { label: 'Dados básicos', description: 'Cliente, área de atuação e classe processual' },
    { label: 'Preenchimento da peça', description: 'Partes, fatos, fundamentos e pedidos' },
    { label: 'Revisão forense', description: 'Conferência final antes do protocolo' },
]

interface Props {
    customers: Option[]
    practiceAreas: Option[]
    proceduralClasses: ProceduralClassOption[]
    /** A área da query string: é o servidor que guarda essa escolha. */
    selectedArea: string
    branches: Option[]
    degrees: Option[]
}

export default function LegalCaseCreate({
    customers,
    practiceAreas,
    proceduralClasses,
    selectedArea,
    branches,
    degrees,
}: Props) {
    const [step, setStep] = useState(0)
    const [customerId, setCustomerId] = useState('')
    const [proceduralClassId, setProceduralClassId] = useState('')

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
                                <Field label="Cliente" required className="max-w-md">
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

                    {step > 0 && (
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

                    <div className="flex justify-end gap-3">
                        {step === 0 ? (
                            <>
                                <Button asChild variant="outline">
                                    <Link href="/pecas">Cancelar</Link>
                                </Button>
                                <Button type="button" disabled={!complete} onClick={() => setStep(1)}>
                                    Continuar
                                </Button>
                            </>
                        ) : (
                            <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                                Voltar
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}

import { Head, Link, router, useForm } from "@inertiajs/react";
import { useState } from "react";
import { AppLayout } from "@/layouts/app-layout";
import { CustomerCreateDialog } from "@/components/customer-create-dialog";
import {
    DefendantFormFields,
    type DefendantFormValues,
} from "@/components/defendant-form-fields";
import { DocumentUploadFields } from "@/components/document-upload-fields";
import {
    FactsFormFields,
    type FactsFormValues,
} from "@/components/facts-form-fields";
import { LegalCaseSteps, type StepItem } from "@/components/legal-case-steps";
import { PracticeAreaPicker } from "@/components/practice-area-picker";
import { ProceduralClassPicker } from "@/components/procedural-class-picker";
import { RequirementFormFields } from "@/components/requirement-form-fields";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Field, Input, Select } from "@/components/ui/field";
import { toDocumentDrafts, type DocumentDraft } from "@/lib/documents";
import { readHandoff } from "@/lib/legal-case-handoff";
import { newRequirement, type RequirementDraft } from "@/lib/requirements";
import type {
    LegalCaseDraft,
    LegalCaseStepValue,
    Option,
    ProceduralClassOption,
} from "@/types";

/**
 * A ordem em que o assistente é preenchido. Espelha `LegalCaseStep::cases()`,
 * e é ela que traduz o valor do enum no índice com que a trilha trabalha.
 */
const STEP_ORDER: LegalCaseStepValue[] = [
    "basics",
    "defendant",
    "facts",
    "requirements",
    "documents",
    "review",
];

/**
 * A descrição de cada etapa fica aqui, e o rótulo não: o rótulo é vocabulário
 * do domínio e vem do enum, a descrição é cópia de tela e não tem contraparte
 * no servidor.
 */
const STEP_DESCRIPTIONS: Record<LegalCaseStepValue, string> = {
    basics: "Cliente, endereçamento, área de atuação e classe processual",
    defendant: "Quem é a parte contrária e como localizá-la",
    facts: "O relato que sustenta os fundamentos e os pedidos, e a urgência, se houver",
    requirements:
        "O que se pede ao juízo, e quanto vale cada pedido que tem cifra",
    documents: "Os anexos que instruem a peça",
    review: "Conferência final antes do protocolo",
};

/** Nada do réu é obrigatório — ver `DefendantFormFields`. */
const EMPTY_DEFENDANT: DefendantFormValues = {
    defendant_name: "",
    defendant_document: "",
    defendant_email: "",
    defendant_phone: "",
    defendant_postal_code: "",
    defendant_street: "",
    defendant_number: "",
    defendant_complement: "",
    defendant_district: "",
    defendant_city: "",
    defendant_state: "",
    defendant_notes: "",
};

/** A peça não pede tutela até o advogado dizer que pede — ver `FactsFormFields`. */
const EMPTY_FACTS: FactsFormValues = {
    facts: "",
    injunctive_relief: false,
    injunctive_relief_description: "",
};

interface Props {
    /** A peça em edição, ou null em `/pecas/nova`. */
    legalCase: LegalCaseDraft | null;
    /** Onde abrir: o `?etapa`, senão a marca d'água, senão a etapa 1. */
    initialStep: LegalCaseStepValue;
    /** `LegalCaseStep::options()` — os rótulos em português vêm do enum. */
    steps: Option[];
    customers: Option[];
    practiceAreas: Option[];
    proceduralClasses: ProceduralClassOption[];
    /** A área da query string: é o servidor que guarda essa escolha. */
    selectedArea: string;
    branches: Option[];
    degrees: Option[];
    /** Para o cadastro de cliente que acontece aqui mesmo, sem trocar de tela. */
    customerTypes: Option[];
    states: Option[];
    can: { create_customer: boolean };
}

/**
 * O assistente de montagem da peça — abrindo uma nova e reabrindo uma salva.
 *
 * Cada "Continuar" salva a etapa corrente e o servidor redireciona para a URL
 * de edição com a próxima em `?etapa`. Por isso a peça nasce no fim da etapa 1
 * e o trabalho passa a sobreviver ao fechamento da aba.
 *
 * São quatro `useForm`, um por etapa que persiste, e não um formulário só com
 * vinte e cinco campos: cada componente de campos já expõe exatamente
 * `{ values, errors, set }` da sua própria forma, então encaixam sem adaptação,
 * e o `processing` desabilita só o botão da etapa que está salvando.
 *
 * Uma consequência a saber: o `useForm` do Inertia espelha o bag único de erros
 * da página, então os quatro enxergam todos os erros. É inofensivo aqui porque
 * nenhuma etapa compartilha nome de campo com outra.
 *
 * Os documentos são a exceção que continua em estado local: o rascunho carrega
 * o próprio `File`, que não sobrevive a um reload, e não há onde guardá-lo
 * ainda. O "Continuar" deles não salva nada — só avança a etapa, que é
 * informação verdadeira sobre a peça.
 *
 * Uma peça nova pode chegar aqui preenchida: quem vem do preenchimento
 * inteligente traz o cliente, a classe e o relato numa entrega guardada pelo
 * browser, e a área na própria URL — ver `@/lib/legal-case-handoff`. Nada disso
 * está salvo, e é o "Continuar" da primeira etapa que grava tudo de uma vez,
 * relato incluído. O advogado vê o enquadramento antes de aceitá-lo, que é o
 * ponto de devolvê-lo ao assistente em vez de abrir a minuta direto.
 */
export default function LegalCaseForm({
    legalCase,
    initialStep,
    steps,
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
    const [step, setStep] = useState(() =>
        Math.max(STEP_ORDER.indexOf(initialStep), 0),
    );

    /**
     * A entrega do preenchimento inteligente, lida uma vez na montagem.
     *
     * Trocar de área é um `reload` parcial, que não remonta a tela — então o
     * rascunho não é relido a cada área visitada, e o que o advogado escolher
     * à mão daqui em diante prevalece. Com uma peça salva na mão não há o que
     * aproveitar, e a leitura descarta: foi a criação que transformou aquela
     * entrega em linha no banco.
     */
    const [handoff] = useState(() => readHandoff(legalCase ? "" : selectedArea));

    const basics = useForm({
        customer_id: legalCase?.customer_id ?? handoff?.customer_id ?? "",
        practice_area: selectedArea,
        procedural_class_id:
            legalCase?.procedural_class_id ?? handoff?.procedural_class_id ?? "",
        court_addressing: legalCase?.court_addressing ?? "",
    });

    const defendant = useForm<DefendantFormValues>({
        ...EMPTY_DEFENDANT,
        ...(legalCase?.defendant as Partial<DefendantFormValues> | undefined),
    });

    const facts = useForm<FactsFormValues>(
        legalCase?.facts ?? { ...EMPTY_FACTS, facts: handoff?.facts ?? "" },
    );

    // Os pedidos começam vazios numa peça nova: a lista é do caso, e uma peça
    // pré-preenchida com pedidos que ninguém escolheu é pior do que uma em
    // branco. Os botões de pedido frequente são a resposta a isso.
    const requirements = useForm<{ requirements: RequirementDraft[] }>({
        requirements: legalCase?.requirements ?? [],
    });

    const [documents, setDocuments] = useState<DocumentDraft[]>([]);

    const trail: StepItem[] = steps.map((option) => ({
        label: option.label,
        description:
            STEP_DESCRIPTIONS[option.value as LegalCaseStepValue] ?? "",
    }));

    /**
     * A área vive na URL, não em estado local: é ela que diz ao servidor quais
     * classes mandar. `reload` já preserva estado e rolagem, e `replace` evita
     * encher o histórico a cada área visitada.
     */
    const selectArea = (slug: string) => {
        basics.setData((current) => ({
            ...current,
            practice_area: slug,
            procedural_class_id: "",
        }));

        router.reload({
            only: ["proceduralClasses", "selectedArea"],
            data: { area: slug },
            replace: true,
        });
    };

    const complete =
        basics.data.customer_id !== "" &&
        selectedArea !== "" &&
        basics.data.procedural_class_id !== "";

    /**
     * Até onde a trilha abre: a marca d'água que o servidor guardou. Numa peça
     * nova nada foi salvo ainda, então só a etapa 1 existe.
     */
    const reachable = legalCase
        ? Math.max(STEP_ORDER.indexOf(legalCase.current_step), 0)
        : 0;

    const id = legalCase?.id;
    const saving =
        basics.processing ||
        defendant.processing ||
        facts.processing ||
        requirements.processing;

    /**
     * O que acontece quando a etapa é salva: abre a que o servidor mandou abrir.
     *
     * Quem decide a próxima etapa continua sendo o redirecionamento — o
     * `?etapa` —, mas o valor não chega aqui sozinho. `useForm` navega com
     * `preserveState`, e com ele o adapter do Inertia mantém a mesma `key` no
     * componente da página: props novas, mesma instância, nenhum `useState`
     * reinicializado. Sem isto o "Continuar" salvaria e ficaria parado, que é
     * exatamente o que o `initialStep` da montagem continua dizendo.
     */
    const openSavedStep = {
        onSuccess: (page: { props: Record<string, unknown> }) => {
            const next = STEP_ORDER.indexOf(
                page.props.initialStep as LegalCaseStepValue,
            );

            // Uma etapa fora da trilha não existe hoje — o servidor redireciona
            // com valores do enum —, mas cair no `-1` recuaria para a primeira,
            // que seria pior do que não andar.
            if (next >= 0) {
                setStep(next);
            }
        },
    };

    const submit = () => {
        if (step === 0) {
            if (id) {
                return basics.put(`/pecas/${id}/dados-basicos`, openSavedStep);
            }

            // O relato viaja junto da criação, e só dela: até aqui ele vive no
            // navegador, e é o primeiro reload que o perderia — a etapa de
            // fatos abriria em branco depois de o advogado já a ter escrito lá
            // atrás, no preenchimento inteligente. Numa peça montada à mão a
            // caixa está vazia e o servidor grava null.
            basics.transform((data) => ({ ...data, facts: facts.data.facts }));

            return basics.post("/pecas", openSavedStep);
        }

        if (step === 1) {
            return defendant.put(`/pecas/${id}/reu`, openSavedStep);
        }

        if (step === 2) {
            return facts.put(`/pecas/${id}/fatos`, openSavedStep);
        }

        if (step === 3) {
            return requirements.put(`/pecas/${id}/pedidos`, openSavedStep);
        }

        // Documentos e revisão não persistem nada: só dizem até onde a peça
        // chegou.
        return router.patch(
            `/pecas/${id}/etapa`,
            { step: STEP_ORDER[step + 1] ?? "review" },
            openSavedStep,
        );
    };

    const title = legalCase ? "Editar peça" : "Nova peça";

    return (
        <AppLayout title={title}>
            <Head title={title} />

            <div className="grid gap-6 pb-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <LegalCaseSteps
                    steps={trail}
                    current={step}
                    // Enquanto a etapa 1 não é salva, as outras ficam inertes.
                    reachable={reachable}
                    onSelect={setStep}
                />

                <div className="min-w-0 space-y-6">
                    {step === 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Dados básicos</CardTitle>
                                <CardDescription>
                                    Para quem é a peça, a quem ela é dirigida e
                                    sob que enquadramento será redigida.
                                </CardDescription>
                            </CardHeader>

                            <CardContent className="space-y-8">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Cliente"
                                        required
                                        error={basics.errors.customer_id}
                                        hint={
                                            customers.length === 0
                                                ? "Nenhum cliente cadastrado ainda."
                                                : undefined
                                        }
                                        action={
                                            can.create_customer && (
                                                <CustomerCreateDialog
                                                    customerTypes={
                                                        customerTypes
                                                    }
                                                    states={states}
                                                    onCreated={(customer) =>
                                                        basics.setData(
                                                            "customer_id",
                                                            customer.value,
                                                        )
                                                    }
                                                />
                                            )
                                        }
                                    >
                                        <Select
                                            value={basics.data.customer_id}
                                            onValueChange={(value) =>
                                                basics.setData(
                                                    "customer_id",
                                                    value,
                                                )
                                            }
                                            options={customers}
                                            placeholder="Selecione o cliente"
                                        />
                                    </Field>

                                    <Field
                                        label="Endereçamento"
                                        error={basics.errors.court_addressing}
                                        hint="A quem a peça é dirigida. Pode ficar em branco por ora."
                                    >
                                        <Input
                                            value={basics.data.court_addressing}
                                            onChange={(e) =>
                                                basics.setData(
                                                    "court_addressing",
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Ao Juízo da 3ª Vara Cível da Comarca de Florianópolis/SC"
                                        />
                                    </Field>
                                </div>

                                <fieldset className="space-y-3">
                                    <legend className="text-sm font-medium">
                                        Área de atuação
                                        <span
                                            aria-hidden
                                            className="ml-0.5 text-destructive"
                                        >
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
                                        <span
                                            aria-hidden
                                            className="ml-0.5 text-destructive"
                                        >
                                            *
                                        </span>
                                    </legend>

                                    {selectedArea === "" ? (
                                        <p className="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                                            Escolha uma área de atuação para ver
                                            as classes disponíveis.
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
                                            value={
                                                basics.data.procedural_class_id
                                            }
                                            onSelect={(value) =>
                                                basics.setData(
                                                    "procedural_class_id",
                                                    value,
                                                )
                                            }
                                        />
                                    )}

                                    {basics.errors.procedural_class_id && (
                                        <p className="text-sm text-destructive">
                                            {basics.errors.procedural_class_id}
                                        </p>
                                    )}
                                </fieldset>
                            </CardContent>
                        </Card>
                    )}

                    {step === 1 && (
                        <DefendantFormFields
                            values={defendant.data}
                            errors={defendant.errors}
                            set={(patch) =>
                                defendant.setData((current) => ({
                                    ...current,
                                    ...patch,
                                }))
                            }
                            states={states}
                            disabled={defendant.processing}
                        />
                    )}

                    {step === 2 && (
                        <FactsFormFields
                            values={facts.data}
                            errors={facts.errors}
                            set={(patch) =>
                                facts.setData((current) => ({
                                    ...current,
                                    ...patch,
                                }))
                            }
                            disabled={facts.processing}
                        />
                    )}

                    {step === 3 && (
                        <RequirementFormFields
                            requirements={requirements.data.requirements}
                            disabled={requirements.processing}
                            onAdd={(description) =>
                                requirements.setData((current) => ({
                                    requirements: [
                                        ...current.requirements,
                                        newRequirement(description),
                                    ],
                                }))
                            }
                            onChange={(requirementId, patch) =>
                                requirements.setData((current) => ({
                                    requirements: current.requirements.map(
                                        (requirement) =>
                                            requirement.id === requirementId
                                                ? { ...requirement, ...patch }
                                                : requirement,
                                    ),
                                }))
                            }
                            onRemove={(requirementId) =>
                                requirements.setData((current) => ({
                                    requirements: current.requirements.filter(
                                        (requirement) =>
                                            requirement.id !== requirementId,
                                    ),
                                }))
                            }
                        />
                    )}

                    {step === 4 && (
                        <DocumentUploadFields
                            documents={documents}
                            onAdd={(files) =>
                                setDocuments((current) => [
                                    ...current,
                                    ...toDocumentDrafts(files, current),
                                ])
                            }
                            onDescribe={(documentId, description) =>
                                setDocuments((current) =>
                                    current.map((document) =>
                                        document.id === documentId
                                            ? { ...document, description }
                                            : document,
                                    ),
                                )
                            }
                            onRemove={(documentId) =>
                                setDocuments((current) =>
                                    current.filter(
                                        (document) =>
                                            document.id !== documentId,
                                    ),
                                )
                            }
                        />
                    )}

                    {step > 4 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{trail[step]?.label}</CardTitle>
                                <CardDescription>
                                    {trail[step]?.description}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <p className="rounded-lg border border-dashed px-4 py-12 text-center text-sm text-muted-foreground">
                                    Esta etapa ainda não foi implementada.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Cancelar só no primeiro passo, onde ainda não se andou
                        nada; dali em diante o par é Voltar/Continuar. Voltar é
                        estado local: o que ficou para trás já está salvo. */}
                    <div className="flex justify-end gap-3">
                        {step === 0 ? (
                            <Button asChild variant="outline">
                                <Link href="/pecas">Cancelar</Link>
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                            >
                                Voltar
                            </Button>
                        )}

                        {step < STEP_ORDER.length - 1 && (
                            <Button
                                type="button"
                                disabled={!complete || saving}
                                onClick={submit}
                            >
                                {saving ? "Salvando…" : "Continuar"}
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

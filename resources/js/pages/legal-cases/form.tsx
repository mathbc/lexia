import { Head, Link, router, useForm } from "@inertiajs/react";
import { Mic, Sparkles } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { AppLayout } from "@/layouts/app-layout";
import { AnalysisDialog } from "@/components/analysis-dialog";
import {
    ADDRESSING_GRID,
    CourtAddressingFields,
} from "@/components/court-addressing-fields";
import { CourtDecisionFields } from "@/components/court-decision-fields";
import { CustomerCreateDialog } from "@/components/customer-create-dialog";
import {
    DefendantFormFields,
    type DefendantFormValues,
} from "@/components/defendant-form-fields";
import { DocumentUploadFields } from "@/components/document-upload-fields";
import { ForensicReviewFields } from "@/components/forensic-review-fields";
import { InjunctiveReliefFields } from "@/components/injunctive-relief-fields";
import { LegalCaseSteps, type StepItem } from "@/components/legal-case-steps";
import { LegalCaseTabs } from "@/components/legal-case-tabs";
import { JudicialSystemAccess } from "@/components/judicial-system-access";
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
import { Field, Select, Textarea } from "@/components/ui/field";
import {
    toCourtDecisionDrafts,
    toCourtDecisionPayload,
    type CourtDecisionDraft,
} from "@/lib/court-decisions";
import { toDocumentDrafts, type DocumentDraft } from "@/lib/documents";
import { formatDocument } from "@/lib/format";
import {
    toForensicReviewPayload,
    toThesisDrafts,
    withDecisions,
} from "@/lib/forensic-review";
import { readHandoff } from "@/lib/legal-case-handoff";
import {
    toLegalThemeDrafts,
    toLegalThemePayload,
    type LegalThemeDraft,
} from "@/lib/legal-themes";
import {
    newRequirement,
    toRequirementDrafts,
    type RequirementDraft,
} from "@/lib/requirements";
import type {
    CustomerOption,
    DefendantSuggestion,
    ForensicReviewTab,
    JudicialSystemOption,
    LegalCaseDraft,
    LegalCaseStepValue,
    Option,
    ProceduralClassOption,
    ResearchedCourtDecision,
    ResearchedPrecedent,
    ResearchedThesis,
} from "@/types";

/**
 * A ordem em que o assistente é preenchido. Espelha `LegalCaseStep::cases()`,
 * e é ela que traduz o valor do enum no índice com que a trilha trabalha.
 *
 * O índice só vale para a trilha: o resto da tela pergunta pela etapa pelo
 * valor (`currentStep === "review"`), para que pôr ou tirar uma etapa seja uma
 * linha aqui e outra no enum, e nenhuma conta de índice pelo arquivo.
 */
const STEP_ORDER: LegalCaseStepValue[] = [
    "basics",
    "defendant",
    "requirements",
    "documents",
    "review",
    "court-decisions",
];

/**
 * A descrição de cada etapa fica aqui, e o rótulo não: o rótulo é vocabulário
 * do domínio e vem do enum, a descrição é cópia de tela e não tem contraparte
 * no servidor.
 */
const STEP_DESCRIPTIONS: Record<LegalCaseStepValue, string> = {
    basics: "Cliente, endereçamento, o relato dos fatos, o enquadramento e a urgência, se houver",
    defendant: "Quem é a parte contrária e como localizá-la",
    requirements:
        "O que se pede ao juízo, e quanto vale cada pedido que tem cifra",
    documents: "Os anexos que instruem a peça",
    review: "As teses que a peça sustenta e os julgados que as fundamentam",
    "court-decisions": "O que os tribunais já decidiram em casos como este",
};

/**
 * O que a pesquisa de teses está fazendo enquanto a etapa 5 espera.
 *
 * Estas frases moravam no preenchimento inteligente, porque era lá que a
 * pesquisa rodava. Vieram junto com ela: hoje a etapa 5 é quem abre os portais
 * oficiais, e é a única espera do assistente que sai da máquina.
 *
 * Como em `ANALYSIS_STEPS`, são frases sobre o trabalho e não sobre o
 * andamento — a chamada é uma só e o servidor não relata por onde anda.
 */
const RESEARCH_STEPS = [
    "Lendo o enquadramento e os pedidos já registrados.",
    "Formulando a questão jurídica que o caso levanta.",
    "Pesquisando no Planalto, no STJ e no STF as teses que cabem aqui.",
    "Abrindo as páginas oficiais e conferindo cada súmula e cada tema.",
    "Distinguindo súmula, tema repetitivo e acórdão isolado.",
    "Descartando o que não se confirmou em fonte oficial.",
    "Transcrevendo as teses, a fundamentação e os julgados que as sustentam.",
    "Em paralelo, consultando o catálogo de temas do STJ pela proximidade com o relato.",
] as const;

/**
 * A seleção de temas sozinha — quando só a aba de temas está sendo pesquisada.
 *
 * Bem mais curta do que a pesquisa de teses: nenhum portal é aberto. A busca
 * vetorial traz os temas mais próximos do relato e um agente lê cada um.
 */
const THEME_STEPS = [
    "Comparando o relato com os temas repetitivos do STJ.",
    "Separando os temas mais próximos da questão que os fatos levantam.",
    "Lendo a questão submetida a julgamento e a tese firmada de cada um.",
    "Ficando só com os temas que de fato se aplicam ao caso.",
] as const;

/**
 * O que a pesquisa de jurisprudência está fazendo enquanto a etapa 6 espera.
 *
 * A segunda espera que sai da máquina, e a segunda mais longa: dois agentes em
 * série e, depois deles, a leitura do registro de cada julgado confirmado. As
 * frases descrevem esse trabalho — inclusive a última, que é a que explica por
 * que a ementa da tela é a do tribunal e não a do modelo.
 */
const COURT_DECISION_STEPS = [
    "Lendo a área de atuação e a classe processual da peça.",
    "Formulando a questão que os tribunais já responderam.",
    "Procurando no LexML os acórdãos que decidiram uma questão como esta.",
    "Conferindo se cada julgado tem registro próprio no catálogo.",
    "Descartando o que não aponta para um registro do LexML.",
    "Abrindo cada registro e transcrevendo a ementa que o tribunal publicou.",
] as const;

/**
 * O que acontece ao concluir o assistente, para a espera dizer alguma coisa.
 *
 * Concluir é a única etapa que custa uma inferência sem pesquisar: grava as
 * teses e os julgados que sobreviveram à leitura do advogado, registra a peça e
 * manda o agente redigir a minuta inteira. Ver `FinalizeLegalCase`.
 */
const FINALISING_STEPS = [
    "Gravando as teses, os temas, os precedentes e a jurisprudência…",
    "Registrando a peça…",
    "Redigindo a qualificação das partes…",
    "Escrevendo a narrativa dos fatos…",
    "Ordenando os fundamentos e numerando os pedidos…",
] as const;

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

/**
 * A sugestão do agente do réu, nos termos dos campos.
 *
 * O servidor diz "o relato não diz" com `null`, e aqui isso vira a string
 * vazia: um controle sem valor deixa de ser controlado no React, e a etapa do
 * réu é feita de doze inputs. É a única tradução do caminho, e ela acontece na
 * ponta que conhece os campos — ver `@/lib/legal-case-handoff`.
 */
const suggestedDefendant = (
    suggestion: DefendantSuggestion | null | undefined,
): Partial<DefendantFormValues> => {
    if (!suggestion) {
        return {};
    }

    const keys = Object.keys(EMPTY_DEFENDANT) as (keyof DefendantFormValues)[];

    // Percorrer as chaves conhecidas, e não as que vieram: uma entrega de outra
    // versão do formato não enfia campo nenhum no formulário.
    return Object.fromEntries(
        keys.map((key) => [key, suggestion[key] ?? ""]),
    ) as Partial<DefendantFormValues>;
};

interface Props {
    /** A peça em edição, ou null em `/pecas/nova`. */
    legalCase: LegalCaseDraft | null;
    /** Onde abrir: o `?etapa`, senão a marca d'água, senão a etapa 1. */
    initialStep: LegalCaseStepValue;
    /** `LegalCaseStep::options()` — os rótulos em português vêm do enum. */
    steps: Option[];
    customers: CustomerOption[];
    practiceAreas: Option[];
    proceduralClasses: ProceduralClassOption[];
    /** A área da query string: é o servidor que guarda essa escolha. */
    selectedArea: string;
    /** `LegalCaseOptions::judicialSystems()`, com os tribunais de cada um. */
    judicialSystems: JudicialSystemOption[];
    branches: Option[];
    degrees: Option[];
    /** `LegalThesisType::options()` e `LegalPrecedentType::options()`: o
        português dos rótulos da revisão forense vem do enum. */
    thesisTypes: Option[];
    precedentTypes: Option[];
    /** `LegalThesisOrigin::options()` e `LegalBasisType::options()`: o selo de
        cada tese e os tipos da tabela do cadastro manual. */
    thesisOrigins: Option[];
    legalBasisTypes: Option[];
    /** Para o cadastro de cliente que acontece aqui mesmo, sem trocar de tela. */
    customerTypes: Option[];
    maritalStatuses: Option[];
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
 * São três `useForm`, um por etapa que persiste, e não um formulário só com
 * vinte e cinco campos: cada componente de campos já expõe exatamente
 * `{ values, errors, set }` da sua própria forma, então encaixam sem adaptação,
 * e o `processing` desabilita só o botão da etapa que está salvando.
 *
 * Uma consequência a saber: o `useForm` do Inertia espelha o bag único de erros
 * da página, então os três enxergam todos os erros. É inofensivo aqui porque
 * nenhuma etapa compartilha nome de campo com outra.
 *
 * Os documentos, a revisão forense e a jurisprudência continuam em estado
 * local, e pelo mesmo motivo: o "Continuar" delas não grava nada. O rascunho de
 * um documento carrega o próprio `File`, que não sobrevive a um reload; a
 * decisão de manter ou tirar uma tese ou um julgado só vira gravação no
 * "Concluir". As duas do meio avançam a etapa e nada mais, que é informação
 * verdadeira sobre a peça — e a sexta, sendo a última, é quem carrega o botão
 * que fecha tudo.
 *
 * Uma peça nova pode chegar aqui preenchida: quem vem do preenchimento
 * inteligente traz o cliente, a classe, o relato, os dados do réu e os pedidos
 * numa entrega guardada pelo browser, e a área na própria URL — ver
 * `@/lib/legal-case-handoff`. Nada disso está salvo, e cada etapa grava o que é
 * dela quando o advogado clica em "Continuar": a primeira grava o enquadramento
 * e o relato, a segunda grava o réu se ele for aceito, a terceira grava os
 * pedidos que sobreviverem à revisão. O advogado vê as sugestões antes de
 * aceitá-las, que é o ponto de devolvê-las ao assistente em vez de abrir a
 * minuta direto.
 *
 * As etapas 5 e 6 são as duas que abrem **pesquisando**, e são a mesma tela
 * duas vezes: a revisão forense procura as teses nos portais oficiais, a
 * análise de jurisprudência procura os julgados no LexML, as duas disparam ao
 * abrir e **uma vez só** — o marcador é a coluna de relato da rodada e nunca a
 * lista estar vazia —, as duas chegam com tudo marcado e as duas pedem que o
 * advogado **tire** o que não serve. Ver `ForensicReviewFields` e
 * `CourtDecisionFields`, e os dois efeitos mais abaixo.
 *
 * A etapa 6, sendo a última, é também quem carrega o "Concluir e gerar minuta",
 * e é por isso que ele leva as duas decisões de uma vez.
 */
export default function LegalCaseForm({
    legalCase,
    initialStep,
    steps,
    customers,
    practiceAreas,
    proceduralClasses,
    selectedArea,
    judicialSystems,
    branches,
    degrees,
    thesisTypes,
    precedentTypes,
    thesisOrigins,
    legalBasisTypes,
    customerTypes,
    maritalStatuses,
    states,
    can,
}: Props) {
    const [step, setStep] = useState(() =>
        Math.max(STEP_ORDER.indexOf(initialStep), 0),
    );

    const currentStep = STEP_ORDER[step];

    // O documento ao lado do nome, separado por um traço: é o que distingue
    // dois clientes homônimos na lista. A máscara sai do comprimento, e o
    // servidor já mandou o CNPJ para a pessoa jurídica e o CPF para a física.
    const customerOptions = useMemo(
        () =>
            customers.map((customer) => ({
                value: customer.value,
                label: customer.document
                    ? `${customer.label} — ${formatDocument(customer.document)}`
                    : customer.label,
            })),
        [customers],
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
    const [handoff] = useState(() =>
        readHandoff(legalCase ? "" : selectedArea),
    );

    // O relato é desta etapa, e é o campo dela que a entrega traz escrito em
    // vez de escolhido. A tutela vem **sugerida**: quando a IA a recomenda, a
    // caixa abre marcada e o texto escrito, com o selo que diz de onde veio —
    // mas pedir urgência continua sendo decisão do advogado, que desmarca, e a
    // peça só pede depois que ele salvar. A peça salva manda na entrega, como
    // em todo o resto.
    const suggestedRelief = handoff?.injunctive_relief ?? null;

    // O endereçamento vem sugerido pela mesma regra: a frase e o sistema abrem
    // escritos, com o selo, e a peça salva manda na entrega.
    const suggestedAddressing = handoff?.court_addressing ?? null;

    const basics = useForm({
        customer_id: legalCase?.customer_id ?? handoff?.customer_id ?? "",
        practice_area: selectedArea,
        procedural_class_id:
            legalCase?.procedural_class_id ??
            handoff?.procedural_class_id ??
            "",
        judicial_system_id:
            legalCase?.judicial_system_id ??
            suggestedAddressing?.judicial_system?.id ??
            "",
        court_addressing:
            legalCase?.court_addressing ??
            suggestedAddressing?.court_addressing ??
            "",
        court_addressing_suggestion:
            legalCase?.court_addressing_suggestion ?? suggestedAddressing,
        facts: legalCase?.facts ?? handoff?.facts ?? "",
        injunctive_relief:
            legalCase?.injunctive_relief ??
            suggestedRelief?.recommended ??
            false,
        injunctive_relief_description:
            legalCase?.injunctive_relief_description ??
            (suggestedRelief?.recommended
                ? (suggestedRelief.description ?? "")
                : ""),
        injunctive_relief_suggestion:
            legalCase?.injunctive_relief_suggestion ?? suggestedRelief,
    });

    // A sugestão vem antes da peça salva de propósito: a entrega só existe numa
    // peça nova, mas a ordem deixa dito quem manda se um dia existirem as duas.
    const defendant = useForm<DefendantFormValues>({
        ...EMPTY_DEFENDANT,
        ...suggestedDefendant(handoff?.defendant),
        ...(legalCase?.defendant as Partial<DefendantFormValues> | undefined),
    });

    // A peça montada à mão continua começando com a lista vazia, e é de
    // propósito: pedido que ninguém escolheu é pior do que nenhum, e os botões
    // de pedido frequente são a resposta para quem quer começar depressa. O que
    // muda com o preenchimento inteligente é a origem — estes saíram do relato
    // do próprio cliente, e por isso são do caso, o que texto de praxe não
    // seria. Cada linha chega com a chave cunhada aqui e o valor já mascarado.
    const requirements = useForm<{ requirements: RequirementDraft[] }>({
        requirements:
            legalCase?.requirements ??
            toRequirementDrafts(handoff?.requirements),
    });

    const [documents, setDocuments] = useState<DocumentDraft[]>([]);

    /**
     * A revisão forense: as teses vêm do banco, e só a decisão é local.
     *
     * **O que está gravado manda.** Uma peça já pesquisada volta com as teses
     * do banco, com os ids reais; uma peça nova começa sem nenhuma e as recebe
     * da pesquisa que a própria etapa 5 dispara ao abrir. E a tese cadastrada ou
     * editada à mão também é linha antes de aparecer aqui.
     *
     * Por isso as teses são **derivadas** das props a cada render, e o estado
     * guarda só o `keep` de cada uma, num mapa por id. Já foi o contrário — a
     * lista inteira em estado, reconstruída por um efeito quando a assinatura
     * de ids mudava —, e a edição à mão quebrou o arranjo: ela troca o texto
     * sem trocar id nenhum, então a assinatura não a via, e reconstruir por
     * qualquer mudança remarcaria o que o advogado acabou de desmarcar. Com a
     * decisão à parte, as duas coisas deixam de competir: a pesquisa refeita
     * traz ids novos, que o mapa não conhece e que chegam marcados, e a tese
     * editada chega com o texto novo e a decisão que já tinha.
     *
     * O mapa só vira gravação no "Concluir", e o "Continuar" o atravessa
     * intacto porque a visita preserva o estado da página.
     */
    const [thesisDecisions, setThesisDecisions] = useState<
        Record<string, boolean>
    >({});

    const theses = useMemo(
        () =>
            withDecisions(
                toThesisDrafts(
                    legalCase
                        ? {
                              theses: legalCase.theses,
                              precedents: legalCase.precedents,
                          }
                        : undefined,
                ),
                thesisDecisions,
            ),
        [legalCase?.theses, legalCase?.precedents, thesisDecisions],
    );

    /**
     * A jurisprudência da etapa 6, em estado local.
     *
     * Com uma simplificação: aqui a origem é uma só. Não há `handoff` — o
     * preenchimento inteligente nunca pesquisou jurisprudência —, então o que
     * chega vem sempre do banco, gravado pela pesquisa que a etapa dispara. O
     * que vive aqui é só a **decisão**: o `keep` de cada julgado, que vira
     * gravação no "Concluir e gerar minuta".
     */
    const [courtDecisions, setCourtDecisions] = useState<CourtDecisionDraft[]>(
        () => toCourtDecisionDrafts(legalCase?.court_decisions),
    );

    /**
     * A pesquisa da etapa 6 grava e o Inertia re-renderiza com props novas sem
     * remontar a página, de modo que o `useState` acima continuaria mostrando a
     * lista vazia que existia quando a etapa abriu.
     *
     * A dependência é a assinatura dos ids e não o array: depender da
     * referência faria qualquer re-render remarcar tudo, desfazendo em silêncio
     * o que o advogado acabou de desmarcar. As teses já não usam este arranjo —
     * lá a edição à mão muda o texto sem mudar id —, mas aqui nada se edita, e
     * a assinatura basta.
     */
    const courtDecisionSignature = (legalCase?.court_decisions ?? [])
        .map((decision) => decision.id)
        .join(",");

    useEffect(() => {
        if (!legalCase) {
            return;
        }

        setCourtDecisions(toCourtDecisionDrafts(legalCase.court_decisions));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [courtDecisionSignature]);

    /**
     * Os temas do STJ, a segunda aba da etapa 5 — o mesmo arranjo, pela mesma
     * razão: a seleção grava, o Inertia re-renderiza sem remontar, e a
     * assinatura de ids é o que impede um re-render de remarcar o que o
     * advogado desmarcou.
     */
    const [legalThemes, setLegalThemes] = useState<LegalThemeDraft[]>(() =>
        toLegalThemeDrafts(legalCase?.themes),
    );

    const themeSignature = (legalCase?.themes ?? [])
        .map((theme) => theme.id)
        .join(",");

    useEffect(() => {
        if (!legalCase) {
            return;
        }

        setLegalThemes(toLegalThemeDrafts(legalCase.themes));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [themeSignature]);

    /**
     * A conclusão é um `useForm` como as três etapas que gravam, e não um
     * `router.post` solto: é ele que dá o `processing` que tranca o botão, e é
     * ele que aceita as três listas sem que cada tese precise de uma assinatura
     * de índice para satisfazer o tipo de payload do Inertia.
     */
    const review = useForm<{
        theses: ResearchedThesis[];
        precedents: ResearchedPrecedent[];
        themes: { legal_theme_id: string; reason: string | null }[];
        court_decisions: ResearchedCourtDecision[];
    }>({ theses: [], precedents: [], themes: [], court_decisions: [] });

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

    // O sistema e o endereçamento ficam fora do `complete`: são opcionais.
    const selectedSystem = judicialSystems.find(
        (system) => system.value === basics.data.judicial_system_id,
    );

    /**
     * Até onde a trilha abre: a marca d'água que o servidor guardou. Numa peça
     * nova nada foi salvo ainda, então só a etapa 1 existe.
     */
    const reachable = legalCase
        ? Math.max(STEP_ORDER.indexOf(legalCase.current_step), 0)
        : 0;

    const id = legalCase?.id;
    const saving =
        basics.processing || defendant.processing || requirements.processing;

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
        if (currentStep === "basics") {
            return id
                ? basics.put(`/pecas/${id}/dados-basicos`, openSavedStep)
                : basics.post("/pecas", openSavedStep);
        }

        if (currentStep === "defendant") {
            return defendant.put(`/pecas/${id}/reu`, openSavedStep);
        }

        if (currentStep === "requirements") {
            return requirements.put(`/pecas/${id}/pedidos`, openSavedStep);
        }

        // As duas etapas cujo "Continuar" não grava nada — os documentos, cujos
        // `File` ainda não têm onde ser salvos, e a revisão forense, cujas
        // teses já são linhas e cujo `keep` só vira gravação no "Concluir" —
        // dizem a única coisa verdadeira que têm a dizer: a peça chegou até
        // aqui. E é esse `patch` que destrava a etapa 6, sem o qual a pesquisa
        // de jurisprudência não teria marca d'água que a autorizasse.
        //
        // `preserveState` é o que separa este `router.patch` do que ele era.
        // Sem ele a visita remonta a página, e o mapa de decisões das teses
        // volta vazio: a tese que o advogado acabou de desmarcar na etapa 5
        // reapareceria marcada, e o "Concluir" da etapa 6 gravaria de volta o
        // que ele tinha acabado de tirar. Um `useForm` já preservaria sozinho
        // — aqui não há formulário nenhum para preservar.
        return router.patch(
            `/pecas/${id}/etapa`,
            { step: STEP_ORDER[step + 1] ?? STEP_ORDER[STEP_ORDER.length - 1] },
            { ...openSavedStep, preserveState: true },
        );
    };

    /**
     * O fim do assistente.
     *
     * Um gesto só, e três efeitos: grava o que sobreviveu à leitura do advogado
     * nas etapas 5 e 6, tira a peça do rascunho e manda o agente redigir a
     * minuta. O servidor redireciona para a aba do documento, e por isso aqui
     * não há `openSavedStep` — não há próxima etapa para abrir.
     *
     * A espera é de verdade: é uma inferência de minutos, e a falha dela não
     * desfaz a gravação — ver `FinalizeLegalCase`. Se o agente cair, a peça
     * chega registrada na aba da minuta, que oferece tentar de novo.
     */
    const finalise = () => {
        // As três decisões vivem em estado local, então o payload é montado na
        // hora do envio, pelo `transform`. O que não estiver nestas listas é
        // apagado pelo diff do servidor: é assim que desmarcar vira remoção.
        review.transform(() => ({
            ...toForensicReviewPayload(theses),
            themes: toLegalThemePayload(legalThemes),
            court_decisions: toCourtDecisionPayload(courtDecisions),
        }));

        review.post(`/pecas/${id}/concluir`);
    };

    /**
     * As duas pesquisas do assistente, que preenchem as etapas 5 e 6.
     *
     * `router.post` solto e não um `useForm`: não há payload nenhum — a peça
     * está na URL e tudo o que os agentes leem já está no banco. O `researching`
     * é local porque é ele que abre o diálogo, e o `preserveState` impede que a
     * volta remonte a página e o apague sozinho.
     *
     * Um estado por etapa, e não um compartilhado: as duas rodadas falham por
     * motivos diferentes — o STJ fora do ar, o LexML fora do ar — e uma falha na
     * etapa 5 não pode impedir a etapa 6 de tentar, nem o contrário.
     *
     * Na etapa 5 o estado é **por aba**, pelo mesmo motivo um nível abaixo: as
     * teses e os temas correm juntos no servidor, mas cada metade tem marcador
     * próprio e falha por si — a pesquisa de teses pode cair com o STJ enquanto
     * a de temas volta.
     */
    const [researching, setResearching] = useState<ForensicReviewTab[]>([]);
    const [researchFailed, setResearchFailed] = useState<ForensicReviewTab[]>(
        [],
    );

    const [researchingDecisions, setResearchingDecisions] = useState(false);
    const [decisionResearchFailed, setDecisionResearchFailed] = useState(false);

    /**
     * Pede as abas dadas — as duas ao abrir uma peça nova, uma só no botão de
     * cada aba ou quando só uma nunca foi pesquisada.
     *
     * A falha de uma metade volta como erro de validação sob a chave da aba
     * (`errors.theses`, `errors.themes`), e é isso que o `onError` lê: a metade
     * que voltou já está gravada e chega nas props. O `onHttpException` cobre o
     * que não é de uma aba só — a peça sem fatos, um 500 qualquer — e marca as
     * duas, senão o efeito de abertura tentaria de novo sem parar.
     */
    const research = (tabs: ForensicReviewTab[]) => {
        if (!id || researching.length > 0 || tabs.length === 0) {
            return;
        }

        const fail = (failed: ForensicReviewTab[]) =>
            setResearchFailed((current) => [
                ...current.filter((tab) => !failed.includes(tab)),
                ...failed,
            ]);

        setResearching(tabs);
        setResearchFailed((current) =>
            current.filter((tab) => !tabs.includes(tab)),
        );

        router.post(
            `/pecas/${id}/revisao-forense/pesquisar`,
            { tabs },
            {
                preserveScroll: true,
                // Sem `onSuccess` que mexa na etapa: o servidor redireciona
                // para `?etapa=review`, que é onde já estamos. Quem redesenha
                // são os efeitos das teses e dos temas, com as props novas.
                onError: (errors) => fail(tabs.filter((tab) => tab in errors)),
                onHttpException: () => fail(tabs),
                onNetworkError: () => fail(tabs),
                onFinish: () => setResearching([]),
            },
        );
    };

    /** A mesma coisa uma etapa adiante — ver `ResearchLegalCaseJurisprudence`. */
    const researchCourtDecisions = () => {
        if (!id || researchingDecisions) {
            return;
        }

        setResearchingDecisions(true);
        setDecisionResearchFailed(false);

        router.post(
            `/pecas/${id}/jurisprudencia/pesquisar`,
            {},
            {
                preserveScroll: true,
                onError: () => setDecisionResearchFailed(true),
                onFinish: () => setResearchingDecisions(false),
            },
        );
    };

    /**
     * Uma vez por aba, ao abrir a etapa, e nunca mais sozinha.
     *
     * O gatilho é o marcador de cada aba ser nulo — `legalCase.research` para as
     * teses, `legalCase.theme_research` para os temas —, que é "nunca se
     * pesquisou", e **não** a lista estar vazia. O efeito pede só as abas que
     * faltam: uma peça pesquisada antes de a aba de temas existir pede só os
     * temas, e as teses que o advogado já curou não são tocadas.
     *
     * A diferença entre marcador e lista é a que decide: uma pesquisa
     * que abriu os portais e nada confirmou é uma resposta legítima e cara que
     * grava zero teses, então um gatilho pela lista vazia dispararia de novo a
     * cada visita à etapa, a cada troca de aba e a cada reload — gastando cota
     * do Gemini toda vez e, pior, substituindo em silêncio o que o advogado já
     * tivesse curado, porque `SaveLegalCaseForensicReview` reconcilia por diff.
     *
     * Com o marcador no banco, voltar à etapa não pesquisa: o que já foi
     * encontrado está gravado e é isso que a tela mostra. Uma segunda rodada é
     * o botão "Pesquisar novamente", que é um gesto do advogado.
     *
     * `researchFailed` é o que impede o laço depois de um erro: a falha não
     * grava marcador nenhum, então sem ele a etapa tentaria de novo a cada
     * render. Uma aba que falhou fica de fora até o botão dela ser apertado.
     */
    useEffect(() => {
        if (currentStep !== "review" || !id || researching.length > 0) {
            return;
        }

        const missing: ForensicReviewTab[] = [];

        if (legalCase?.research == null) {
            missing.push("theses");
        }

        if (legalCase?.theme_research == null) {
            missing.push("themes");
        }

        research(missing.filter((tab) => !researchFailed.includes(tab)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        step,
        id,
        legalCase?.research,
        legalCase?.theme_research,
        researchFailed.join(","),
    ]);

    /**
     * O mesmo disparo na etapa 6, com o marcador que é dela.
     *
     * `court_decision_research` e não `court_decisions.length`, pelo motivo que
     * o efeito acima explica por extenso — e aqui ele é ainda mais visível: um
     * relato sobre o qual os tribunais nada decidiram grava zero julgados, e
     * conferir a lista faria esta peça pagar a pesquisa em toda visita.
     *
     * As duas nunca correm juntas: são etapas diferentes e só uma está na tela.
     */
    useEffect(() => {
        if (
            currentStep === "court-decisions" &&
            id &&
            legalCase?.court_decision_research == null &&
            !researchingDecisions &&
            !decisionResearchFailed
        ) {
            researchCourtDecisions();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [step, id, legalCase?.court_decision_research, decisionResearchFailed]);

    const title = legalCase ? "Editar peça" : "Nova peça";

    return (
        <AppLayout
            title={title}
            tabs={
                id ? (
                    <LegalCaseTabs
                        legalCaseId={id}
                        // A aba da minuta só existe depois de a peça ser
                        // registrada: antes disso não há documento nenhum.
                        available={legalCase !== null && !legalCase.is_draft}
                        current="form"
                    />
                ) : undefined
            }
            tabsActions={
                // O select ao vivo, e não o que foi salvo: escolher o sistema
                // já mostra a porta, e a UF do foro vem da sugestão que está
                // no formulário agora.
                selectedSystem && (
                    <JudicialSystemAccess
                        name={selectedSystem.label}
                        links={selectedSystem.links}
                        forumState={
                            basics.data.court_addressing_suggestion?.state
                        }
                    />
                )
            }
        >
            <Head title={title} />

            <div className="grid gap-6 pb-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <div className="space-y-3">
                    <LegalCaseSteps
                        steps={trail}
                        current={step}
                        // Enquanto a etapa 1 não é salva, as outras ficam
                        // inertes — e o aviso abaixo diz por quê.
                        reachable={reachable}
                        onSelect={setStep}
                    />

                    {/* Sem isto a trilha cinza parece defeito, e nunca tanto
                        quanto depois do preenchimento inteligente: os campos
                        chegam preenchidos e nada clica. O que falta é a peça
                        existir — nada foi gravado ainda, e é o "Continuar"
                        desta etapa que a cria. Tudo o que vem depois precisa
                        dela: a etapa 5 pesquisa teses e as grava, e não há
                        onde gravá-las sem uma chave primária. */}
                    {!id && (
                        <p className="text-xs text-muted-foreground">
                            As demais etapas abrem depois de você salvar os
                            dados básicos: é o "Continuar" desta etapa que cria
                            a peça.
                        </p>
                    )}
                </div>

                <div className="min-w-0 space-y-6">
                    {currentStep === "basics" && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Dados básicos e fatos</CardTitle>
                                <CardDescription>
                                    Para quem é a peça, a quem ela é dirigida, o
                                    que aconteceu e sob que enquadramento será
                                    redigida.
                                </CardDescription>
                            </CardHeader>

                            <CardContent className="space-y-8">
                                {/* Na grade do endereçamento, que vem logo
                                    abaixo: o cliente fica com a largura dele,
                                    e a coluna do sistema, vazia aqui. */}
                                <div className={ADDRESSING_GRID}>
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
                                                    maritalStatuses={
                                                        maritalStatuses
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
                                            options={customerOptions}
                                            placeholder="Selecione o cliente"
                                        />
                                    </Field>
                                </div>

                                {/* Logo abaixo do cliente, e não depois da
                                    classe: a consulta à IA lê os fatos, a área
                                    e a classe que vêm mais abaixo, e o botão
                                    só acorda com eles preenchidos. O sistema
                                    judicial mora dentro deste bloco, ao lado
                                    do endereçamento: são o par que a mesma
                                    consulta escreve. */}
                                <CourtAddressingFields
                                    judicial_system_id={
                                        basics.data.judicial_system_id
                                    }
                                    court_addressing={
                                        basics.data.court_addressing
                                    }
                                    court_addressing_suggestion={
                                        basics.data.court_addressing_suggestion
                                    }
                                    facts={basics.data.facts}
                                    practiceArea={selectedArea}
                                    proceduralClassId={
                                        basics.data.procedural_class_id
                                    }
                                    customerId={basics.data.customer_id}
                                    defendant={{
                                        defendant_name:
                                            defendant.data.defendant_name,
                                        defendant_document:
                                            defendant.data.defendant_document,
                                        defendant_city:
                                            defendant.data.defendant_city,
                                        defendant_state:
                                            defendant.data.defendant_state,
                                    }}
                                    error={basics.errors.court_addressing}
                                    systems={judicialSystems}
                                    systemError={
                                        basics.errors.judicial_system_id
                                    }
                                    onChange={(patch) =>
                                        basics.setData((data) => ({
                                            ...data,
                                            ...patch,
                                        }))
                                    }
                                />

                                {/* Uma caixa de texto e nada mais: os fatos
                                    vêm na ordem em que aconteceram, e qualquer
                                    estrutura imposta aqui seria uma aposta
                                    sobre uma peça que ainda não existe. O
                                    microfone é a porta do ditado, que ainda não
                                    existe — desabilitado de propósito, porque
                                    um botão que aceita o clique e não faz nada
                                    é pior do que um que assume não estar
                                    pronto. */}
                                <Field
                                    label="Fatos"
                                    error={basics.errors.facts}
                                    hint="O ditado por voz entra numa próxima versão."
                                    action={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled
                                            aria-label="Ditar os fatos (em breve)"
                                        >
                                            <Mic />
                                            Ditar
                                        </Button>
                                    }
                                >
                                    <Textarea
                                        value={basics.data.facts}
                                        onChange={(e) =>
                                            basics.setData(
                                                "facts",
                                                e.target.value,
                                            )
                                        }
                                        rows={12}
                                        placeholder="Relate o caso como o cliente o contou: quando começou, o que foi feito, o que foi cobrado, o que se tentou resolver antes de procurar a Justiça…"
                                    />
                                </Field>

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

                                <InjunctiveReliefFields
                                    injunctive_relief={
                                        basics.data.injunctive_relief
                                    }
                                    injunctive_relief_description={
                                        basics.data
                                            .injunctive_relief_description
                                    }
                                    injunctive_relief_suggestion={
                                        basics.data.injunctive_relief_suggestion
                                    }
                                    facts={basics.data.facts}
                                    practiceArea={selectedArea}
                                    proceduralClassId={
                                        basics.data.procedural_class_id
                                    }
                                    error={
                                        basics.errors
                                            .injunctive_relief_description
                                    }
                                    onChange={(patch) =>
                                        basics.setData((data) => ({
                                            ...data,
                                            ...patch,
                                        }))
                                    }
                                />
                            </CardContent>
                        </Card>
                    )}

                    {currentStep === "defendant" && (
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

                    {currentStep === "requirements" && (
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

                    {currentStep === "documents" && (
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

                    {currentStep === "review" && legalCase && (
                        <ForensicReviewFields
                            legalCaseId={legalCase.id}
                            research={legalCase?.research ?? null}
                            themeResearch={legalCase?.theme_research ?? null}
                            researching={researching}
                            failed={researchFailed}
                            onResearch={(tab) => research([tab])}
                            theses={theses}
                            onToggle={(thesisId, keep) =>
                                setThesisDecisions((current) => ({
                                    ...current,
                                    [thesisId]: keep,
                                }))
                            }
                            themes={legalThemes}
                            onToggleTheme={(themeId, keep) =>
                                setLegalThemes((current) =>
                                    current.map((draft) =>
                                        draft.id === themeId
                                            ? { ...draft, keep }
                                            : draft,
                                    ),
                                )
                            }
                            thesisTypes={thesisTypes}
                            precedentTypes={precedentTypes}
                            thesisOrigins={thesisOrigins}
                            legalBasisTypes={legalBasisTypes}
                        />
                    )}

                    {currentStep === "court-decisions" && (
                        <CourtDecisionFields
                            research={
                                legalCase?.court_decision_research ?? null
                            }
                            researching={researchingDecisions}
                            failed={decisionResearchFailed}
                            onResearch={researchCourtDecisions}
                            decisions={courtDecisions}
                            onToggle={(decisionId, keep) =>
                                setCourtDecisions((current) =>
                                    current.map((draft) =>
                                        draft.id === decisionId
                                            ? { ...draft, keep }
                                            : draft,
                                    ),
                                )
                            }
                        />
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

                        {step < STEP_ORDER.length - 1 ? (
                            <Button
                                type="button"
                                disabled={!complete || saving}
                                onClick={submit}
                            >
                                {saving ? "Salvando…" : "Continuar"}
                            </Button>
                        ) : (
                            /* A última etapa é onde a peça acaba, e o rótulo
                               diz as duas coisas que vão acontecer. As teses
                               que ele grava são as da etapa 5, que seguem em
                               estado local até aqui — ver `finalise`. */
                            <Button
                                type="button"
                                disabled={!complete || review.processing}
                                onClick={finalise}
                            >
                                <Sparkles />
                                {review.processing
                                    ? "Concluindo…"
                                    : "Concluir e gerar minuta"}
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            {/* A pesquisa é a única espera do assistente que sai da máquina:
                o agente abre os portais oficiais antes de responder. Diálogo
                modal pela mesma razão do preenchimento inteligente — sair da
                tela joga fora a inferência inteira sem avisar.

                O título e a dica dizem a demora de saída, e isso é deliberado:
                é de longe a espera mais longa do projeto — dois agentes em
                série, um deles abrindo página por página —, e uma tela que
                prometesse pouco faria o advogado desistir no meio, jogando
                fora minutos de inferência. Quem espera sabendo que vai
                demorar espera; quem espera achando que travou, recarrega.

                As frases circulam mais devagar aqui do que no preenchimento
                inteligente: numa espera de minutos, um texto que troca a cada
                quatro segundos passa de sinal de vida a agitação. */}
            <AnalysisDialog
                open={researching.includes("theses")}
                title="Pesquisando as teses — leva alguns minutos"
                hint="É a etapa mais demorada da peça, e a espera é normal: o agente consulta o Planalto, o STJ e o STF e lê cada página antes de responder, o que costuma levar alguns minutos. Os temas do STJ são selecionados ao mesmo tempo. Mantenha esta aba aberta e não recarregue a página — ao terminar, tudo fica gravado na peça e a pesquisa não se repete."
                messages={RESEARCH_STEPS}
                interval={6500}
            />

            {/* Só os temas: sem portal nenhum, é a espera curta da etapa — a
                de uma peça pesquisada antes de a aba existir, ou a do botão
                da aba. */}
            <AnalysisDialog
                open={
                    researching.includes("themes") &&
                    !researching.includes("theses")
                }
                title="Selecionando os temas do STJ"
                hint="O agente compara o relato com o catálogo de temas repetitivos do STJ e fica com os que se aplicam ao caso. Mantenha esta aba aberta — ao terminar, os temas ficam gravados na peça."
                messages={THEME_STEPS}
            />

            {/* A segunda espera que sai da máquina, e a mesma escolha de
                diálogo modal: sair da tela joga fora a inferência inteira sem
                avisar. O texto diz o que esta faz de diferente — ela lê o
                registro de cada julgado depois de achá-lo, e é essa leitura que
                torna a ementa da tela a do tribunal. */}
            <AnalysisDialog
                open={researchingDecisions}
                title="Pesquisando a jurisprudência — leva alguns minutos"
                hint="O agente procura no LexML os acórdãos que decidiram uma questão como a desta peça e abre o registro de cada um para transcrever a ementa que o tribunal publicou. Mantenha esta aba aberta e não recarregue a página — ao terminar, os julgados ficam gravados na peça e a pesquisa não se repete."
                messages={COURT_DECISION_STEPS}
                interval={6500}
            />

            <AnalysisDialog
                open={review.processing}
                title="Concluindo a peça"
                hint="A redação da minuta pode levar alguns minutos. Mantenha esta aba aberta: ao terminar, o documento abre na aba Minuta."
                messages={FINALISING_STEPS}
            />
        </AppLayout>
    );
}

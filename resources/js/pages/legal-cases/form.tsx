import { Head, Link, router, useForm } from "@inertiajs/react";
import { Sparkles } from "lucide-react";
import { useEffect, useState } from "react";
import { AppLayout } from "@/layouts/app-layout";
import { AnalysisDialog } from "@/components/analysis-dialog";
import { CourtDecisionFields } from "@/components/court-decision-fields";
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
import { ForensicReviewFields } from "@/components/forensic-review-fields";
import { LegalCaseSteps, type StepItem } from "@/components/legal-case-steps";
import { LegalCaseTabs } from "@/components/legal-case-tabs";
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
import {
    toCourtDecisionDrafts,
    toCourtDecisionPayload,
    type CourtDecisionDraft,
} from "@/lib/court-decisions";
import { toDocumentDrafts, type DocumentDraft } from "@/lib/documents";
import {
    toForensicReviewPayload,
    toThesisDrafts,
    type ThesisDraft,
} from "@/lib/forensic-review";
import { readHandoff } from "@/lib/legal-case-handoff";
import {
    newRequirement,
    toRequirementDrafts,
    type RequirementDraft,
} from "@/lib/requirements";
import type {
    DefendantSuggestion,
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
 */
const STEP_ORDER: LegalCaseStepValue[] = [
    "basics",
    "defendant",
    "facts",
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
    basics: "Cliente, endereçamento, área de atuação e classe processual",
    defendant: "Quem é a parte contrária e como localizá-la",
    facts: "O relato que sustenta os fundamentos e os pedidos, e a urgência, se houver",
    requirements:
        "O que se pede ao juízo, e quanto vale cada pedido que tem cifra",
    documents: "Os anexos que instruem a peça",
    review: "As teses que a peça sustenta e os julgados que as fundamentam",
    "court-decisions": "O que os tribunais já decidiram em casos como este",
};

/**
 * O que a pesquisa de teses está fazendo enquanto a etapa 6 espera.
 *
 * Estas frases moravam no preenchimento inteligente, porque era lá que a
 * pesquisa rodava. Vieram junto com ela: hoje a etapa 6 é quem abre os portais
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
] as const;

/**
 * O que a pesquisa de jurisprudência está fazendo enquanto a etapa 7 espera.
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
    "Gravando as teses, os precedentes e a jurisprudência…",
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
    /** `LegalThesisType::options()` e `LegalPrecedentType::options()`: o
        português dos rótulos da revisão forense vem do enum. */
    thesisTypes: Option[];
    precedentTypes: Option[];
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
 * São quatro `useForm`, um por etapa que persiste, e não um formulário só com
 * vinte e cinco campos: cada componente de campos já expõe exatamente
 * `{ values, errors, set }` da sua própria forma, então encaixam sem adaptação,
 * e o `processing` desabilita só o botão da etapa que está salvando.
 *
 * Uma consequência a saber: o `useForm` do Inertia espelha o bag único de erros
 * da página, então os quatro enxergam todos os erros. É inofensivo aqui porque
 * nenhuma etapa compartilha nome de campo com outra.
 *
 * Os documentos, a revisão forense e a jurisprudência continuam em estado
 * local, e pelo mesmo motivo: o "Continuar" delas não grava nada. O rascunho de
 * um documento carrega o próprio `File`, que não sobrevive a um reload; a
 * decisão de manter ou tirar uma tese ou um julgado só vira gravação no
 * "Concluir". As duas do meio avançam a etapa e nada mais, que é informação
 * verdadeira sobre a peça — e a sétima, sendo a última, é quem carrega o botão
 * que fecha tudo.
 *
 * Uma peça nova pode chegar aqui preenchida: quem vem do preenchimento
 * inteligente traz o cliente, a classe, o relato, os dados do réu, os pedidos e
 * a revisão forense numa entrega guardada pelo browser, e a área na própria URL
 * — ver `@/lib/legal-case-handoff`. Nada disso está salvo, e cada etapa grava o
 * que é dela quando o advogado clica em "Continuar": a primeira grava o
 * enquadramento junto com o relato, a segunda grava o réu se ele for aceito, a
 * quarta grava os pedidos que sobreviverem à revisão. O advogado vê as
 * sugestões antes de aceitá-las, que é o ponto de devolvê-las ao assistente em
 * vez de abrir a minuta direto.
 *
 * As etapas 6 e 7 são as duas que abrem **pesquisando**, e são a mesma tela
 * duas vezes: a revisão forense procura as teses nos portais oficiais, a
 * análise de jurisprudência procura os julgados no LexML, as duas disparam ao
 * abrir e **uma vez só** — o marcador é a coluna de relato da rodada e nunca a
 * lista estar vazia —, as duas chegam com tudo marcado e as duas pedem que o
 * advogado **tire** o que não serve. Ver `ForensicReviewFields` e
 * `CourtDecisionFields`, e os dois efeitos mais abaixo.
 *
 * A etapa 7, sendo a última, é também quem carrega o "Concluir e gerar minuta",
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
    branches,
    degrees,
    thesisTypes,
    precedentTypes,
    customerTypes,
    maritalStatuses,
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
    const [handoff] = useState(() =>
        readHandoff(legalCase ? "" : selectedArea),
    );

    const basics = useForm({
        customer_id: legalCase?.customer_id ?? handoff?.customer_id ?? "",
        practice_area: selectedArea,
        procedural_class_id:
            legalCase?.procedural_class_id ??
            handoff?.procedural_class_id ??
            "",
        court_addressing: legalCase?.court_addressing ?? "",
    });

    // A sugestão vem antes da peça salva de propósito: a entrega só existe numa
    // peça nova, mas a ordem deixa dito quem manda se um dia existirem as duas.
    const defendant = useForm<DefendantFormValues>({
        ...EMPTY_DEFENDANT,
        ...suggestedDefendant(handoff?.defendant),
        ...(legalCase?.defendant as Partial<DefendantFormValues> | undefined),
    });

    const facts = useForm<FactsFormValues>(
        legalCase?.facts ?? { ...EMPTY_FACTS, facts: handoff?.facts ?? "" },
    );

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
     * A revisão forense, em estado local — mas não mais sem rede embaixo.
     *
     * Ela tem duas origens, e a ordem entre elas é a regra: **o que está gravado
     * manda**. Uma peça já concluída volta com as teses do banco, com os ids
     * reais, e é isso que a etapa 6 mostra ao reabrir; uma peça nova as recebe da
     * pesquisa que `ClassifyLegalCase` fez, pelo `sessionStorage`, e essas só
     * viram linha quando o advogado clicar em Concluir.
     *
     * Era aqui que ficava a única etapa em que recarregar a página custava
     * trabalho já feito. Não é mais: `LegalCaseFormProps::draft()` projeta as
     * duas listas, e `toThesisDrafts` as lê sem saber de onde vieram.
     */
    const [theses, setTheses] = useState<ThesisDraft[]>(() =>
        toThesisDrafts(
            legalCase
                ? {
                      theses: legalCase.theses,
                      precedents: legalCase.precedents,
                  }
                : undefined,
        ),
    );

    /**
     * As teses vinham do `handoff` e agora vêm do banco, então elas mudam
     * **depois** da montagem: a pesquisa da etapa 6 grava e o Inertia
     * re-renderiza com props novas, sem remontar a página (`preserveState`).
     * Sem este efeito o `useState` acima continuaria mostrando a lista vazia
     * que existia quando a etapa abriu.
     *
     * A dependência é uma **assinatura de ids**, e não os arrays das props. A
     * diferença não é estilo: um `keep` desmarcado vive só em estado local até
     * o "Concluir", e depender das referências faria qualquer re-render com
     * props novas remarcar tudo, desfazendo em silêncio o que o advogado
     * acabou de decidir. Com a assinatura, o efeito só dispara quando o
     * conjunto de teses realmente muda — que é o que a pesquisa faz.
     */
    const thesisSignature = [
        ...(legalCase?.theses ?? []).map((thesis) => thesis.id),
        ...(legalCase?.precedents ?? []).map((precedent) => precedent.id),
    ].join(",");

    useEffect(() => {
        if (!legalCase) {
            return;
        }

        setTheses(
            toThesisDrafts({
                theses: legalCase.theses,
                precedents: legalCase.precedents,
            }),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [thesisSignature]);

    /**
     * A jurisprudência da etapa 7, em estado local pelo mesmo arranjo das teses.
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
     * O mesmo efeito das teses, e pelo mesmo motivo: a pesquisa da etapa 7
     * grava e o Inertia re-renderiza com props novas sem remontar a página, de
     * modo que o `useState` acima continuaria mostrando a lista vazia que
     * existia quando a etapa abriu.
     *
     * A dependência é a assinatura dos ids e não o array: depender da
     * referência faria qualquer re-render remarcar tudo, desfazendo em silêncio
     * o que o advogado acabou de desmarcar.
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
     * A conclusão é um `useForm` como as quatro etapas que gravam, e não um
     * `router.post` solto: é ele que dá o `processing` que tranca o botão, e é
     * ele que aceita as três listas sem que cada tese precise de uma assinatura
     * de índice para satisfazer o tipo de payload do Inertia.
     */
    const review = useForm<{
        theses: ResearchedThesis[];
        precedents: ResearchedPrecedent[];
        court_decisions: ResearchedCourtDecision[];
    }>({ theses: [], precedents: [], court_decisions: [] });

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

        // As duas etapas cujo "Continuar" não grava nada — os documentos, cujos
        // `File` ainda não têm onde ser salvos, e a revisão forense, cujas
        // teses já são linhas e cujo `keep` só vira gravação no "Concluir" —
        // dizem a única coisa verdadeira que têm a dizer: a peça chegou até
        // aqui. E é esse `patch` que destrava a etapa 7, sem o qual a pesquisa
        // de jurisprudência não teria marca d'água que a autorizasse.
        //
        // `preserveState` é o que separa este `router.patch` do que ele era.
        // Sem ele a visita remonta a página, e o `useState` das teses volta a
        // ler as props: a tese que o advogado acabou de desmarcar na etapa 6
        // reapareceria marcada, e o "Concluir" da etapa 7 gravaria de volta o
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
     * nas etapas 6 e 7, tira a peça do rascunho e manda o agente redigir a
     * minuta. O servidor redireciona para a aba do documento, e por isso aqui
     * não há `openSavedStep` — não há próxima etapa para abrir.
     *
     * A espera é de verdade: é uma inferência de minutos, e a falha dela não
     * desfaz a gravação — ver `FinalizeLegalCase`. Se o agente cair, a peça
     * chega registrada na aba da minuta, que oferece tentar de novo.
     */
    const finalise = () => {
        // As duas decisões vivem em estado local, então o payload é montado na
        // hora do envio — `transform` é o mesmo mecanismo que leva o relato
        // junto da criação na etapa 1. O que não estiver nestas listas é
        // apagado pelo diff do servidor: é assim que desmarcar vira remoção.
        review.transform(() => ({
            ...toForensicReviewPayload(theses),
            court_decisions: toCourtDecisionPayload(courtDecisions),
        }));

        review.post(`/pecas/${id}/concluir`);
    };

    /**
     * As duas pesquisas do assistente, que preenchem as etapas 6 e 7.
     *
     * `router.post` solto e não um `useForm`: não há payload nenhum — a peça
     * está na URL e tudo o que os agentes leem já está no banco. O `researching`
     * é local porque é ele que abre o diálogo, e o `preserveState` impede que a
     * volta remonte a página e o apague sozinho.
     *
     * Um estado por etapa, e não um compartilhado: as duas rodadas falham por
     * motivos diferentes — o STJ fora do ar, o LexML fora do ar — e uma falha na
     * etapa 6 não pode impedir a etapa 7 de tentar, nem o contrário.
     */
    const [researching, setResearching] = useState(false);
    const [researchFailed, setResearchFailed] = useState(false);

    const [researchingDecisions, setResearchingDecisions] = useState(false);
    const [decisionResearchFailed, setDecisionResearchFailed] = useState(false);

    const research = () => {
        if (!id || researching) {
            return;
        }

        setResearching(true);
        setResearchFailed(false);

        router.post(
            `/pecas/${id}/revisao-forense/pesquisar`,
            {},
            {
                preserveScroll: true,
                // Sem `onSuccess` que mexa na etapa: o servidor redireciona
                // para `?etapa=review`, que é onde já estamos. Quem redesenha
                // é o efeito das teses, com as props novas.
                onError: () => setResearchFailed(true),
                onFinish: () => setResearching(false),
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
     * Uma vez, ao abrir a etapa, e nunca mais sozinha.
     *
     * O gatilho é `legalCase.research === null`, que é "nunca se pesquisou", e
     * **não** `theses.length === 0`. A diferença é a que decide: uma pesquisa
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
     * render.
     */
    useEffect(() => {
        if (
            step === 5 &&
            id &&
            legalCase?.research == null &&
            !researching &&
            !researchFailed
        ) {
            research();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [step, id, legalCase?.research, researchFailed]);

    /**
     * O mesmo disparo na etapa 7, com o marcador que é dela.
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
            step === 6 &&
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
                        dela: a etapa 6 pesquisa teses e as grava, e não há
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

                    {step === 5 && (
                        <ForensicReviewFields
                            research={legalCase?.research ?? null}
                            researching={researching}
                            failed={researchFailed}
                            onResearch={research}
                            theses={theses}
                            onToggle={(thesisId, keep) =>
                                setTheses((current) =>
                                    current.map((draft) =>
                                        draft.id === thesisId
                                            ? { ...draft, keep }
                                            : draft,
                                    ),
                                )
                            }
                            thesisTypes={thesisTypes}
                            precedentTypes={precedentTypes}
                        />
                    )}

                    {step === 6 && (
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
                               que ele grava são as da etapa 6, que seguem em
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
                open={researching}
                title="Pesquisando as teses — leva alguns minutos"
                hint="É a etapa mais demorada da peça, e a espera é normal: o agente consulta o Planalto, o STJ e o STF e lê cada página antes de responder, o que costuma levar alguns minutos. Mantenha esta aba aberta e não recarregue a página — ao terminar, as teses ficam gravadas na peça e a pesquisa não se repete."
                messages={RESEARCH_STEPS}
                interval={6500}
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

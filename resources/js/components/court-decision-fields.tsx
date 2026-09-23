import {
    CalendarDays,
    ChevronDown,
    ExternalLink,
    Gavel,
    Landmark,
    MapPin,
    RefreshCw,
    ShieldAlert,
} from "lucide-react";
import { useId, useLayoutEffect, useRef, useState } from "react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import {
    decidedAtLabel,
    keptCourtDecisions,
    type CourtDecisionDraft,
} from "@/lib/court-decisions";
// O rótulo de uma fonte é o domínio dela, e a regra não muda de etapa para
// etapa: mora onde foi escrita primeiro, em vez de existir em duas cópias.
import { sourceLabel } from "@/lib/forensic-review";
import { cn } from "@/lib/utils";
import type { CourtDecisionFindings } from "@/types";

interface Props {
    /**
     * O relato da pesquisa já gravada, ou nulo quando nunca se pesquisou.
     *
     * O nulo é o estado transitório: a etapa dispara a pesquisa ao abrir, então
     * ele dura o tempo do diálogo. Presente com tudo vazio é o outro caso — uma
     * rodada que abriu o LexML e nada confirmou —, e essa não se repete.
     */
    research: CourtDecisionFindings | null;
    /** O agente está respondendo agora: a etapa mostra a espera. */
    researching: boolean;
    /** A rodada falhou — portal fora do ar, cota esgotada — e nada foi gravado. */
    failed: boolean;
    /** Refaz a pesquisa, descartando a anterior. Um gesto do advogado, nunca automático. */
    onResearch: () => void;
    /** Os julgados com a decisão do advogado ao lado — ver `@/lib/court-decisions`. */
    decisions: CourtDecisionDraft[];
    onToggle: (id: string, keep: boolean) => void;
}

/**
 * A sétima etapa: o que os tribunais já decidiram em casos como este.
 *
 * A irmã de `ForensicReviewFields`, um passo adiante, e desenhada na mesma
 * forma de propósito: a etapa abre **preenchida por uma pesquisa** e não por um
 * relato, todo achado chega marcado, e a decisão que a tela pede é **tirar**.
 * Um advogado que aprendeu a etapa 6 não precisa aprender esta.
 *
 * O disparo é **uma vez por peça**, e quem decide é `research` ser nulo, nunca a
 * lista de julgados estar vazia: uma rodada que nada confirma é uma resposta
 * cara e legítima que grava zero linhas, e repeti-la a cada visita gastaria cota
 * e apagaria o que o advogado já curou — a gravação reconcilia por diff. Uma
 * segunda rodada é o botão "Pesquisar novamente".
 *
 * O que ela **não** repete é o conteúdo, e a diferença é a que separa as duas
 * tabelas. A etapa 6 pesquisa **teses** — o que a peça argumenta, com a súmula
 * ou o tema que a fundamenta — e cada precedente vem com uma aderência e uma
 * frase sobre o que ele faz por este caso. Aqui não há nem uma coisa nem outra:
 * um julgado é o **documento**, transcrito do registro do LexML, e o que ele
 * vale para esta peça é leitura do advogado. Por isso cada linha mostra a
 * ementa — recolhida, com o "Ver mais" que a abre — e o link para a página de
 * onde ela foi lida, e nenhum número ao lado.
 *
 * Três blocos fecham a tela, como na etapa 6 e pelos mesmos motivos. **As
 * fontes** dizem quais registros foram de fato abertos. **O pendente** é o que
 * ficou em aberto. **As citações sem registro** são as que a guarda removeu, e
 * existem porque uma etapa vazia tem duas causas opostas — não se achou nada,
 * ou se achou e não se confirmou — que produzem a mesma lista vazia.
 */
export function CourtDecisionFields({
    research,
    researching,
    failed,
    onResearch,
    decisions,
    onToggle,
}: Props) {
    const kept = keptCourtDecisions(decisions).length;

    return (
        <Card>
            <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
                <div className="space-y-1.5">
                    <CardTitle>Análise de Jurisprudência</CardTitle>
                    <CardDescription>
                        {decisions.length === 0
                            ? "O que os tribunais já decidiram em casos como este."
                            : `${decisions.length} ${
                                  decisions.length === 1
                                      ? "julgado encontrado"
                                      : "julgados encontrados"
                              } · ${kept} ${
                                  kept === 1
                                      ? "mantido na peça"
                                      : "mantidos na peça"
                              }`}
                    </CardDescription>
                </div>

                {/* Depois de uma rodada ter acontecido, ou de uma ter falhado:
                    a primeira falha deixa `research` nulo, e o alerta abaixo
                    manda usar este botão. Antes disso a etapa já está
                    pesquisando sozinha, e um botão ali convidaria a uma segunda
                    chamada simultânea. */}
                {(research !== null || failed) && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={researching}
                        onClick={onResearch}
                    >
                        <RefreshCw />
                        Pesquisar novamente
                    </Button>
                )}
            </CardHeader>

            <CardContent className="space-y-6">
                {researching ? (
                    // O diálogo modal de `form.tsx` é quem conta o que está
                    // acontecendo; aqui basta não afirmar que não há nada — e
                    // repetir a demora, porque é o que esta caixa mostra se o
                    // diálogo já tiver se fechado e a rodada for a do botão.
                    <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-balance text-muted-foreground">
                        Pesquisando a jurisprudência no LexML… Leva alguns
                        minutos: o agente lê o registro de cada julgado antes de
                        transcrever a ementa.
                    </p>
                ) : failed ? (
                    // A falha não gravou marcador nenhum, então a etapa não
                    // tenta de novo sozinha — e é preciso dizer isso, senão a
                    // tela parece uma pesquisa que não achou nada.
                    <Alert variant="destructive">
                        <ShieldAlert />
                        <AlertTitle>
                            A pesquisa não pôde ser concluída
                        </AlertTitle>
                        <AlertDescription>
                            Nada foi gravado. A pesquisa depende do portal do
                            LexML: ele fora do ar basta para derrubá-la. Use
                            "Pesquisar novamente" para tentar outra vez, ou
                            conclua sem jurisprudência — a minuta é redigida
                            normalmente, com as teses da revisão forense.
                        </AlertDescription>
                    </Alert>
                ) : research === null ? (
                    // Estado de partida numa peça que nunca pesquisou e cuja
                    // etapa ainda não disparou — um piscar, na prática.
                    <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                        Ainda não há pesquisa de jurisprudência nesta peça.
                    </p>
                ) : (
                    <>
                        {research.legal_question && (
                            <div className="rounded-lg bg-muted/50 p-4">
                                <p className="text-xs font-medium">
                                    Questão pesquisada
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {research.legal_question}
                                </p>
                            </div>
                        )}

                        {decisions.length === 0 ? (
                            <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                                A pesquisa não confirmou nenhum julgado no
                                registro do LexML. O que ficou em aberto está
                                abaixo.
                            </p>
                        ) : (
                            <ol className="space-y-4">
                                {decisions.map((draft, index) => (
                                    <CourtDecisionItem
                                        key={draft.id}
                                        draft={draft}
                                        position={index + 1}
                                        onToggle={onToggle}
                                    />
                                ))}
                            </ol>
                        )}

                        <Findings research={research} />
                    </>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Um julgado, com a caixa que decide se ele fica.
 *
 * A caixa fica fora do corpo e não é apagada com ele: desmarcar um julgado
 * esmaece o que ele diz, e não o controle que o traz de volta — o mesmo arranjo
 * de `ThesisItem`.
 *
 * A ementa chega recolhida, com o "Ver mais" ao lado. Ela é a única coisa aqui
 * que decide se o julgado serve, mas é também o texto mais longo da tela, e
 * uma lista de dez julgados publicada por inteiro vira uma etapa que se
 * percorre por rolagem em vez de leitura. O corte é só visual — o texto está
 * todo no DOM, o que recolhe é um `line-clamp` — e por isso nada some da busca
 * da página nem do que a gravação envia.
 */
function CourtDecisionItem({
    draft,
    position,
    onToggle,
}: {
    draft: CourtDecisionDraft;
    position: number;
    onToggle: (id: string, keep: boolean) => void;
}) {
    const id = useId();
    const { decision } = draft;
    const decidedAt = decidedAtLabel(decision.decided_at);

    return (
        <li className="rounded-lg border p-4">
            <div className="flex items-start gap-3">
                <Checkbox
                    id={id}
                    checked={draft.keep}
                    onCheckedChange={(checked) =>
                        onToggle(draft.id, checked === true)
                    }
                    className="mt-1"
                />

                <div
                    className={cn(
                        "min-w-0 flex-1 space-y-4",
                        !draft.keep && "opacity-50",
                    )}
                >
                    <div className="space-y-2">
                        <Label htmlFor={id} className="text-base">
                            {position}. {decision.title}
                        </Label>

                        {/* Autoridade, localidade e data são o cabeçalho do
                            registro, e é por eles que um advogado reconhece o
                            julgado antes de ler a ementa. Cada um some quando o
                            registro não o trouxe. */}
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                            {decision.authority && (
                                <span className="inline-flex items-center gap-1.5">
                                    <Landmark className="size-3.5" />
                                    {decision.authority}
                                </span>
                            )}
                            {decision.locality && (
                                <span className="inline-flex items-center gap-1.5">
                                    <MapPin className="size-3.5" />
                                    {decision.locality}
                                </span>
                            )}
                            {decidedAt && (
                                <span className="inline-flex items-center gap-1.5">
                                    <CalendarDays className="size-3.5" />
                                    {decidedAt}
                                </span>
                            )}
                        </div>
                    </div>

                    <DecisionSummary text={decision.summary} />

                    {decision.subject && (
                        <div>
                            <p className="text-xs font-medium">Assuntos</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {decision.subject}
                            </p>
                        </div>
                    )}

                    {decision.source_url && (
                        // Aba nova: a etapa não está salva, e levar o advogado
                        // para fora perderia o que ele acabou de decidir nas
                        // caixas acima.
                        <a
                            href={decision.source_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                        >
                            Ler o registro no {sourceLabel(decision.source_url)}
                            <ExternalLink className="size-3.5" />
                        </a>
                    )}
                </div>
            </div>
        </li>
    );
}

/**
 * Quantas linhas da ementa ficam à vista antes do "Ver mais".
 *
 * A classe é escrita por extenso porque o Tailwind 4 varre o código à procura
 * dela: `line-clamp-${n}` não compila para utilitário nenhum.
 */
const SUMMARY_CLAMP = "line-clamp-6";

/**
 * A ementa recolhida, com o botão que a abre.
 *
 * **O botão só existe quando há o que revelar**, e isso não se decide pelo
 * tamanho da string: a ementa vem com as quebras de linha do registro, então
 * duzentos caracteres podem ocupar dez linhas e mil podem ocupar três. Quem
 * sabe é o layout, e por isso a decisão é uma medição — refeita quando a coluna
 * muda de largura, já que a mesma ementa transborda no celular e cabe no
 * desktop.
 *
 * A medição só roda **recolhido**: aberta, a caixa cresce até o texto e
 * `scrollHeight` volta a igualar `clientHeight`, o que apagaria o "Ver menos"
 * no primeiro clique.
 */
function DecisionSummary({ text }: { text: string }) {
    const id = useId();
    const [expanded, setExpanded] = useState(false);
    const [clipped, setClipped] = useState(false);
    const ref = useRef<HTMLParagraphElement>(null);

    useLayoutEffect(() => {
        const node = ref.current;

        if (!node || expanded) {
            return;
        }

        const measure = () =>
            setClipped(node.scrollHeight > node.clientHeight + 1);

        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(node);

        return () => observer.disconnect();
    }, [expanded, text]);

    return (
        <div>
            <p className="text-xs font-medium">Ementa</p>
            <p
                ref={ref}
                id={id}
                className={cn(
                    "mt-1 text-sm whitespace-pre-line text-muted-foreground",
                    !expanded && SUMMARY_CLAMP,
                )}
            >
                {text}
            </p>

            {(clipped || expanded) && (
                <Button
                    type="button"
                    variant="link"
                    size="sm"
                    aria-expanded={expanded}
                    aria-controls={id}
                    className="mt-1 h-auto px-0 text-muted-foreground hover:text-foreground"
                    onClick={() => setExpanded((open) => !open)}
                >
                    {expanded ? "Ver menos" : "Ver mais"}
                    <ChevronDown
                        className={cn(
                            "transition-transform",
                            expanded && "rotate-180",
                        )}
                    />
                </Button>
            )}
        </div>
    );
}

/**
 * O relato da pesquisa: onde ela esteve, o que não resolveu e o que recusou.
 *
 * Não descreve a peça, descreve a rodada — por isso fica depois dos julgados e
 * separado deles. As três listas somem quando estão vazias.
 */
function Findings({ research }: { research: CourtDecisionFindings }) {
    const hasFindings =
        research.pending.length > 0 ||
        research.unverified_citations.length > 0 ||
        research.sources.length > 0;

    if (!hasFindings) {
        return null;
    }

    return (
        <>
            <Separator />

            <div className="space-y-4">
                {research.pending.length > 0 && (
                    <Alert>
                        <Gavel />
                        <AlertTitle>O que ficou em aberto</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc space-y-1 pl-4">
                                {research.pending.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {research.unverified_citations.length > 0 && (
                    <Alert variant="destructive">
                        <ShieldAlert />
                        <AlertTitle>
                            Julgados removidos por falta de registro
                        </AlertTitle>
                        <AlertDescription className="space-y-2">
                            <p>
                                A pesquisa os trouxe, mas nenhum registro do
                                LexML os confirmou. Não cite nenhum deles sem
                                conferir na fonte.
                            </p>
                            <ul className="list-disc space-y-1 pl-4">
                                {research.unverified_citations.map(
                                    (citation) => (
                                        <li key={citation}>{citation}</li>
                                    ),
                                )}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {research.sources.length > 0 && (
                    <div>
                        <p className="text-xs font-medium">
                            Registros consultados
                        </p>
                        {/* O endereço inteiro, e não o domínio como na etapa 6:
                            aqui todas as fontes são o mesmo portal, e
                            "lexml.gov.br" repetido cinco vezes não distinguiria
                            um registro do outro. O que distingue é a URN, que
                            está no fim da URL — daí o `truncate` em vez de um
                            corte no meio. */}
                        <ul className="mt-2 space-y-1">
                            {research.sources.map((source) => (
                                <li key={source}>
                                    <a
                                        href={source}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex max-w-full items-center gap-1.5 text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                    >
                                        <span className="truncate">
                                            {source}
                                        </span>
                                        <ExternalLink className="size-3.5 shrink-0" />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </>
    );
}

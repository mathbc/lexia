import { ExternalLink, Scale, ShieldAlert } from 'lucide-react'
import { useId } from 'react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { adherenceLabel, keptTheses, sourceLabel, type ThesisDraft } from '@/lib/forensic-review'
import { cn } from '@/lib/utils'
import type { LegalResearch, Option, ResearchedPrecedent } from '@/types'

interface Props {
    /** A pesquisa inteira, ou nulo quando não há uma nesta sessão. */
    research: LegalResearch | null
    /** As teses com a decisão do advogado ao lado — ver `@/lib/forensic-review`. */
    theses: ThesisDraft[]
    onToggle: (id: string, keep: boolean) => void
    /** `LegalThesisType::options()`: o português dos rótulos vem do enum. */
    thesisTypes: Option[]
    /** `LegalPrecedentType::options()`, pelo mesmo motivo. */
    precedentTypes: Option[]
}

/**
 * A sexta etapa: as teses que a peça vai sustentar e os julgados que as
 * sustentam.
 *
 * É a única etapa do assistente que abre **preenchida por uma pesquisa**, e não
 * por um relato: `ClassifyLegalCase` a pede como última etapa, e o que chega
 * aqui já passou pela guarda de `LegalResearchData` — toda citação que sobrou
 * foi lida num portal oficial, e a que não foi está dita em separado, embaixo.
 *
 * Por isso a decisão que a tela pede é **tirar**, não escolher. Toda tese chega
 * marcada; a caixa ao lado do título é o que a desvincula da peça. Uma tese
 * desmarcada não some — ela continua na lista, apagada, porque o advogado que
 * mudar de ideia não tem como pedir a pesquisa de novo sem esperar minutos por
 * ela.
 *
 * Os precedentes ficam **debaixo da tese que fundamentam**, e não numa lista
 * própria: o servidor manda as duas listas achatadas porque é essa a forma que
 * a gravação aceita, e ler uma tese sem os julgados dela embaixo não é ler
 * nada. Quem torna a aninhar é `@/lib/forensic-review`.
 *
 * Três blocos fecham a tela e nenhum deles é decoração. **As fontes** dizem
 * quais portais foram de fato abertos — é o que separa uma pesquisa de um
 * modelo recitando de memória. **O pendente** é o que ficou em aberto, e
 * costuma ser trabalho de verdade: um documento que falta, uma divergência que
 * não se resolveu. **As citações sem fonte oficial** são as que a guarda
 * removeu, e existem porque uma tese sem fundamentação tem duas causas opostas
 * — não se achou nada, ou se achou e não se confirmou — que produzem
 * exatamente a mesma lista vazia.
 *
 * Nada aqui é enviado. A lista vive no rascunho até existir a Action que grava
 * a revisão forense, e é o mesmo arranjo dos documentos: a tela está pronta
 * antes da gravação, de propósito. A consequência é que recarregar a página
 * perde o que a pesquisa custou — o único ponto do assistente em que isso
 * acontece, e a razão pela qual a tela em branco não afirma que a pesquisa
 * falhou.
 */
export function ForensicReviewFields({
    research,
    theses,
    onToggle,
    thesisTypes,
    precedentTypes,
}: Props) {
    const kept = keptTheses(theses).length

    return (
        <Card>
            <CardHeader>
                <CardTitle>Revisão forense</CardTitle>
                <CardDescription>
                    {theses.length === 0
                        ? 'As teses que a peça sustenta e os julgados que as fundamentam.'
                        : `${theses.length} ${theses.length === 1 ? 'tese encontrada' : 'teses encontradas'} · ${kept} ${
                              kept === 1 ? 'mantida na peça' : 'mantidas na peça'
                          }`}
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-6">
                {research === null ? (
                    // Três situações caem aqui e a tela não consegue distingui-las:
                    // a peça montada à mão, que nunca pediu pesquisa; a pesquisa
                    // que falhou durante o preenchimento inteligente; e a aba que
                    // foi recarregada, que descarta a entrega. Afirmar uma das três
                    // seria inventar — a frase diz o que vale para todas.
                    <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                        Não há pesquisa de teses nesta sessão. Ela é feita pelo preenchimento
                        inteligente, junto com o enquadramento, e vive apenas enquanto esta aba
                        estiver aberta — recarregar a página a descarta.
                    </p>
                ) : (
                    <>
                        {research.legal_question && (
                            <div className="rounded-lg bg-muted/50 p-4">
                                <p className="text-xs font-medium">Questão pesquisada</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {research.legal_question}
                                </p>
                            </div>
                        )}

                        {theses.length === 0 ? (
                            <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                                A pesquisa não confirmou nenhuma tese em fonte oficial. O que ficou
                                em aberto está abaixo.
                            </p>
                        ) : (
                            <ol className="space-y-4">
                                {theses.map((draft, index) => (
                                    <ThesisItem
                                        key={draft.id}
                                        draft={draft}
                                        position={index + 1}
                                        onToggle={onToggle}
                                        thesisTypes={thesisTypes}
                                        precedentTypes={precedentTypes}
                                    />
                                ))}
                            </ol>
                        )}

                        <Findings research={research} />
                    </>
                )}
            </CardContent>
        </Card>
    )
}

/**
 * Uma tese, com a caixa que decide se ela fica.
 *
 * A caixa fica fora do corpo e não é apagada com ele: desmarcar uma tese
 * esmaece o que ela diz, e não o controle que a traz de volta.
 */
function ThesisItem({
    draft,
    position,
    onToggle,
    thesisTypes,
    precedentTypes,
}: {
    draft: ThesisDraft
    position: number
    onToggle: (id: string, keep: boolean) => void
    thesisTypes: Option[]
    precedentTypes: Option[]
}) {
    const id = useId()
    const { thesis } = draft
    const type = labelOf(thesisTypes, thesis.type)

    return (
        <li className="rounded-lg border p-4">
            <div className="flex items-start gap-3">
                <Checkbox
                    id={id}
                    checked={draft.keep}
                    onCheckedChange={(checked) => onToggle(draft.id, checked === true)}
                    className="mt-1"
                />

                <div className={cn('min-w-0 flex-1 space-y-4', !draft.keep && 'opacity-50')}>
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <Label htmlFor={id} className="text-base">
                                {position}. {thesis.name}
                            </Label>
                            {type && <Badge variant="secondary">{type}</Badge>}
                        </div>

                        <p className="text-sm text-muted-foreground">{thesis.description}</p>
                    </div>

                    {thesis.impact && (
                        <div>
                            <p className="text-xs font-medium">O que o cliente ganha</p>
                            <p className="mt-1 text-sm text-muted-foreground">{thesis.impact}</p>
                        </div>
                    )}

                    {thesis.legal_bases.length > 0 && (
                        <div>
                            <p className="text-xs font-medium">Fundamentação</p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {thesis.legal_bases.map((basis) => (
                                    <Badge key={basis.reference} variant="outline">
                                        {basis.reference}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}

                    <div>
                        <p className="text-xs font-medium">
                            Precedentes
                            {draft.precedents.length > 0 && ` (${draft.precedents.length})`}
                        </p>

                        {draft.precedents.length === 0 ? (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Nenhum julgado confirmado para esta tese.
                            </p>
                        ) : (
                            <ul className="mt-2 space-y-2">
                                {draft.precedents.map((precedent, index) => (
                                    <PrecedentItem
                                        key={`${draft.id}-${index}`}
                                        precedent={precedent}
                                        precedentTypes={precedentTypes}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </li>
    )
}

/**
 * Um julgado: o que ele diz, o que ele faz por este caso, e de onde foi lido.
 *
 * A aderência é o quanto o precedente se ajusta ao caso, medida pelo agente.
 * Ausente quer dizer não medida, e não zero — a mesma distinção que o valor de
 * um pedido faz —, e por isso o selo some em vez de mostrar "0%".
 */
function PrecedentItem({
    precedent,
    precedentTypes,
}: {
    precedent: ResearchedPrecedent
    precedentTypes: Option[]
}) {
    const type = labelOf(precedentTypes, precedent.type)
    const adherence = adherenceLabel(precedent.adherence)

    return (
        <li className="space-y-2 rounded-md border bg-muted/30 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Scale className="size-4 text-muted-foreground" />
                <p className="text-sm font-medium">{precedent.name}</p>
                {type && <Badge variant="secondary">{type}</Badge>}
                {adherence && <Badge variant="muted">{adherence} de aderência</Badge>}
            </div>

            <p className="text-sm text-muted-foreground">{precedent.description}</p>

            {precedent.grounding && (
                <p className="text-sm">
                    <span className="font-medium">Por que serve: </span>
                    <span className="text-muted-foreground">{precedent.grounding}</span>
                </p>
            )}

            {precedent.citation && (
                <p className="text-xs text-muted-foreground">{precedent.citation}</p>
            )}
        </li>
    )
}

/**
 * O relato da pesquisa: onde ela esteve, o que não resolveu e o que recusou.
 *
 * Não descreve a peça, descreve a rodada — por isso fica depois das teses e
 * separado delas. As três listas somem quando estão vazias, que é o caso
 * comum de duas delas.
 */
function Findings({ research }: { research: LegalResearch }) {
    const hasFindings =
        research.pending.length > 0 ||
        research.unverified_citations.length > 0 ||
        research.sources.length > 0

    if (!hasFindings) {
        return null
    }

    return (
        <>
            <Separator />

            <div className="space-y-4">
                {research.pending.length > 0 && (
                    <Alert>
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
                        <AlertTitle>Citações removidas por falta de fonte oficial</AlertTitle>
                        <AlertDescription className="space-y-2">
                            <p>
                                A pesquisa as trouxe, mas nenhuma página oficial as confirmou. Não
                                use nenhuma delas sem conferir na fonte.
                            </p>
                            <ul className="list-disc space-y-1 pl-4">
                                {research.unverified_citations.map((citation) => (
                                    <li key={citation}>{citation}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {research.sources.length > 0 && (
                    <div>
                        <p className="text-xs font-medium">Fontes consultadas</p>
                        <ul className="mt-2 space-y-1">
                            {research.sources.map((source) => (
                                <li key={source}>
                                    {/* Aba nova: a etapa não está salva, e levar
                                        o advogado para fora perderia o que ele
                                        acabou de decidir nas caixas acima. */}
                                    <a
                                        href={source}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                    >
                                        {sourceLabel(source)}
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </>
    )
}

/**
 * O rótulo em português de um valor de enum, vindo das opções do servidor.
 *
 * Um valor que as opções não conhecem devolve nulo e o selo some: é o que
 * acontece com um rascunho gravado antes de o enum ganhar um caso, e é melhor
 * do que desenhar o valor cru — "principal_merits" não é português nenhum.
 */
const labelOf = (options: Option[], value: string | null): string | null =>
    options.find((option) => option.value === value)?.label ?? null

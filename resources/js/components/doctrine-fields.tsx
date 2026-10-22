import {
    BookOpen,
    ExternalLink,
    Library,
    RefreshCw,
    Search,
    ShieldAlert,
    UserRound,
} from "lucide-react";
import { useId } from "react";
import { DecisionSummary } from "@/components/court-decision-fields";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import {
    keptDoctrines,
    lexmlDoctrineSearchUrl,
    type DoctrineDraft,
} from "@/lib/doctrines";
import { cn } from "@/lib/utils";
import type { DoctrineFindings } from "@/types";

interface Props {
    /**
     * O relato da pesquisa já gravada, ou nulo quando ela nunca rodou.
     *
     * O nulo é transitório, como nas outras abas: a etapa dispara a pesquisa ao
     * abrir. Presente com a lista vazia é uma rodada que abriu o acervo e nada
     * confirmou — e essa não se repete sozinha.
     */
    research: DoctrineFindings | null;
    /** A pesquisa está rodando agora. */
    researching: boolean;
    /** A rodada falhou — cota, LexML fora do ar — e nada foi gravado. */
    failed: boolean;
    /** Refaz só a pesquisa de doutrina, sem tocar nas teses nem nos temas. */
    onResearch: () => void;
    doctrines: DoctrineDraft[];
    onToggle: (id: string, keep: boolean) => void;
}

/**
 * A terceira aba da revisão forense: a doutrina em que a peça pode se apoiar.
 *
 * Doutrina é o que juristas escreveram sobre a norma e o instituto — fundamento
 * **complementar**, que não é lei nem julgado. Quem preenche a aba é a pesquisa
 * no acervo da Rede Virtual de Bibliotecas, pelo LexML: um agente condensa o
 * caso em poucos termos curtos, acha as obras, e o sistema lê o registro de cada
 * uma. Autor, título, ano e publicação são do catálogo; a única frase escrita
 * por um modelo é a "Relação com o caso", e a aba a rotula assim. Nenhuma linha
 * diz o que o autor defende — ninguém leu a obra.
 *
 * Roda em paralelo com as teses e os temas, com marcador próprio, e falha e se
 * repete sozinha. A decisão é **tirar**, como nas outras abas: tudo chega
 * marcado, e o "Concluir e gerar minuta" apaga o que ficou desmarcado. A minuta
 * ainda não cita doutrina, e a aba diz isso.
 */
export function DoctrinesPanel({
    research,
    researching,
    failed,
    onResearch,
    doctrines,
    onToggle,
}: Props) {
    const kept = keptDoctrines(doctrines).length;

    return (
        <div className="space-y-6">
            <div className="flex items-start justify-between gap-4">
                <p className="text-sm text-muted-foreground">
                    {doctrines.length === 0
                        ? "Livros e artigos do acervo do LexML que tratam da questão de direito do caso."
                        : `${doctrines.length} ${doctrines.length === 1 ? "obra encontrada" : "obras encontradas"} · ${kept} ${
                              kept === 1
                                  ? "mantida na peça"
                                  : "mantidas na peça"
                          }`}
                </p>

                {/* Só depois de uma rodada, ou de uma falha: antes disso a
                    etapa já está pesquisando sozinha. */}
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
            </div>

            {researching ? (
                <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-balance text-muted-foreground">
                    Pesquisando a doutrina no acervo do LexML… O agente condensa
                    o caso em termos de busca, procura as obras na Rede Virtual
                    de Bibliotecas e lê o registro de cada uma.
                </p>
            ) : failed ? (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertTitle>
                        A pesquisa de doutrina não pôde ser concluída
                    </AlertTitle>
                    <AlertDescription>
                        Nada foi gravado nesta aba, e as teses e os temas não
                        foram tocados. Use "Pesquisar novamente" para tentar
                        outra vez, ou siga sem doutrina: a peça pode ser
                        concluída assim.
                    </AlertDescription>
                </Alert>
            ) : research === null ? (
                <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                    Ainda não há pesquisa de doutrina nesta peça.
                </p>
            ) : (
                <>
                    {doctrines.length === 0 ? (
                        <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-balance text-muted-foreground">
                            Nenhuma obra do acervo se confirmou para esta peça.
                            Use "Pesquisar novamente", ou abra a busca do LexML
                            com os termos abaixo.
                        </p>
                    ) : (
                        <>
                            <ol className="space-y-4">
                                {doctrines.map((draft, index) => (
                                    <DoctrineItem
                                        key={draft.id}
                                        draft={draft}
                                        position={index + 1}
                                        onToggle={onToggle}
                                    />
                                ))}
                            </ol>

                            <p className="text-xs text-muted-foreground">
                                A minuta ainda não cita doutrina: as obras
                                mantidas ficam registradas na peça para consulta.
                            </p>
                        </>
                    )}

                    <SearchedTerms terms={research.search_terms ?? []} />
                    <Findings research={research} />
                </>
            )}
        </div>
    );
}

/**
 * Uma obra, com a caixa que decide se ela fica.
 *
 * A caixa fica fora do corpo e não é esmaecida com ele, como nos julgados e nos
 * temas: desmarcar apaga o que a obra diz, e não o controle que a traz de volta.
 */
function DoctrineItem({
    draft,
    position,
    onToggle,
}: {
    draft: DoctrineDraft;
    position: number;
    onToggle: (id: string, keep: boolean) => void;
}) {
    const id = useId();
    const { doctrine } = draft;

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
                        <div className="flex flex-wrap items-center gap-2">
                            <Label htmlFor={id} className="text-base">
                                {position}. {doctrine.title}
                            </Label>
                            {doctrine.kind && (
                                <Badge variant="secondary">{doctrine.kind}</Badge>
                            )}
                            {doctrine.published_in && (
                                <Badge variant="muted">
                                    {doctrine.published_in}
                                </Badge>
                            )}
                        </div>

                        {/* Autor e publicação são a referência: é por eles que
                            a obra se cita, e é como o catálogo os escreve. */}
                        <div className="flex flex-col gap-1.5 text-xs text-muted-foreground">
                            <span className="inline-flex items-center gap-1.5">
                                <UserRound className="size-3.5 shrink-0" />
                                {doctrine.author}
                            </span>
                            {doctrine.publication && (
                                <span className="inline-flex items-start gap-1.5">
                                    <BookOpen className="mt-0.5 size-3.5 shrink-0" />
                                    {doctrine.publication}
                                </span>
                            )}
                        </div>
                    </div>

                    {doctrine.relevance && (
                        <div className="rounded-lg bg-muted/50 p-3">
                            <p className="text-xs font-medium">
                                Relação com o caso
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {doctrine.relevance}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Nota da IA sobre o assunto da obra — não é
                                citação do autor.
                            </p>
                        </div>
                    )}

                    {doctrine.summary && (
                        <DecisionSummary
                            text={doctrine.summary}
                            label="Descrição no catálogo"
                        />
                    )}

                    {doctrine.subject && (
                        <div>
                            <p className="text-xs font-medium">Assuntos</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {doctrine.subject}
                            </p>
                        </div>
                    )}

                    {doctrine.source_url && (
                        // Aba nova: as caixas desta etapa ainda não foram
                        // gravadas, e sair da tela perderia a decisão.
                        <a
                            href={doctrine.source_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                        >
                            Abrir o registro no LexML
                            <ExternalLink className="size-3.5" />
                        </a>
                    )}
                </div>
            </div>
        </li>
    );
}

/**
 * Os termos em que o agente condensou o caso, cada um levando à busca de
 * doutrina do próprio LexML.
 *
 * É o que deixa o advogado ver com que palavras o acervo foi consultado — e
 * continuar a busca de onde o agente parou, no navegador dele, que passa pela
 * verificação de segurança que o servidor não passa.
 */
function SearchedTerms({ terms }: { terms: string[] }) {
    if (terms.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <p className="text-xs font-medium">Termos pesquisados</p>
            <ul className="flex flex-wrap gap-2">
                {terms.map((term) => (
                    <li key={term}>
                        <a
                            href={lexmlDoctrineSearchUrl(term)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                        >
                            <Search className="size-3.5" />
                            {term}
                            <ExternalLink className="size-3.5" />
                        </a>
                    </li>
                ))}
            </ul>
            <p className="text-xs text-muted-foreground">
                Cada termo abre a busca de doutrina do LexML.
            </p>
        </div>
    );
}

/**
 * O relato da rodada: o que ficou em aberto, o que caiu e o que foi aberto.
 * As três listas somem quando estão vazias.
 */
function Findings({ research }: { research: DoctrineFindings }) {
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
                        <Library />
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
                            Obras removidas por falta de registro ou de autor
                        </AlertTitle>
                        <AlertDescription className="space-y-2">
                            <p>
                                A pesquisa as trouxe, mas nenhum registro do
                                acervo as confirmou com autor. Não cite nenhuma
                                delas sem conferir na fonte.
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

import { Landmark, RefreshCw, ShieldAlert } from "lucide-react";
import { useId } from "react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { keptLegalThemes, type LegalThemeDraft } from "@/lib/legal-themes";
import { cn } from "@/lib/utils";
import type { LegalThemeFindings } from "@/types";

interface Props {
    /**
     * O relato da seleção já gravada, ou nulo quando ela nunca rodou.
     *
     * O nulo é transitório, como nas teses: a etapa dispara a seleção ao abrir.
     * Presente com a lista vazia é a outra afirmação — a seleção rodou e nenhum
     * tema se aplicava —, e essa não se repete sozinha.
     */
    research: LegalThemeFindings | null;
    /** A seleção está rodando agora. */
    researching: boolean;
    /** A rodada falhou — o Ollama fora do ar, o catálogo vazio — e nada foi gravado. */
    failed: boolean;
    /** Refaz só a seleção de temas, sem tocar nas teses. */
    onResearch: () => void;
    themes: LegalThemeDraft[];
    onToggle: (id: string, keep: boolean) => void;
}

/**
 * A segunda aba da revisão forense: os temas do STJ em que a peça se apoia.
 *
 * Quem preenche é o RAG sobre o catálogo — a busca vetorial traz os temas mais
 * próximos do relato e `LegalThemeSelectionAgent` fica com os que se aplicam,
 * cada um com a razão ao lado. Roda em paralelo com a pesquisa de teses e tem
 * marcador próprio, então esta aba pode ter falhado enquanto a outra voltou, e
 * o "Pesquisar novamente" daqui refaz só ela.
 *
 * A decisão é **tirar**, como nas teses: todo tema chega marcado, e desmarcar o
 * esmaece sem tirá-lo da lista. O desvínculo acontece no "Concluir e gerar
 * minuta", na etapa 7, pelo `sync()` de `SaveLegalCaseThemes`.
 */
export function LegalThemesPanel({
    research,
    researching,
    failed,
    onResearch,
    themes,
    onToggle,
}: Props) {
    const kept = keptLegalThemes(themes).length;

    return (
        <div className="space-y-6">
            <div className="flex items-start justify-between gap-4">
                <p className="text-sm text-muted-foreground">
                    {themes.length === 0
                        ? "Os temas repetitivos e demais precedentes qualificados do STJ que se aplicam ao caso."
                        : `${themes.length} ${themes.length === 1 ? "tema selecionado" : "temas selecionados"} · ${kept} ${
                              kept === 1
                                  ? "mantido na peça"
                                  : "mantidos na peça"
                          }`}
                </p>

                {/* Só depois de uma rodada, ou de uma falha: antes disso a
                    etapa já está selecionando sozinha. */}
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
                    Consultando o catálogo de temas do STJ… O agente lê os temas
                    mais próximos do relato e fica com os que se aplicam.
                </p>
            ) : failed ? (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertTitle>
                        A seleção de temas não pôde ser concluída
                    </AlertTitle>
                    <AlertDescription>
                        Nada foi gravado nesta aba, e as teses não foram
                        tocadas. Use "Pesquisar novamente" para tentar outra
                        vez, ou siga sem temas: a peça pode ser concluída assim.
                    </AlertDescription>
                </Alert>
            ) : research === null ? (
                <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                    Ainda não há seleção de temas nesta peça.
                </p>
            ) : (
                <>
                    {themes.length === 0 ? (
                        <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-balance text-muted-foreground">
                            Nenhum tema do STJ se aplica a este relato. A
                            maioria dos casos não toca precedente qualificado
                            nenhum.
                        </p>
                    ) : (
                        <ol className="space-y-4">
                            {themes.map((draft) => (
                                <LegalThemeItem
                                    key={draft.id}
                                    draft={draft}
                                    onToggle={onToggle}
                                />
                            ))}
                        </ol>
                    )}

                    {/* O quanto se olhou, para que "nenhum se aplica" não se
                        leia como "ninguém procurou". */}
                    <p className="text-xs text-muted-foreground">
                        {research.considered}{" "}
                        {research.considered === 1
                            ? "tema do catálogo consultado"
                            : "temas do catálogo consultados"}{" "}
                        pela proximidade com o relato.
                    </p>
                </>
            )}
        </div>
    );
}

/**
 * Um tema, com a caixa que decide se ele fica.
 *
 * A caixa fica fora do corpo e não é esmaecida com ele, como no `ThesisItem`:
 * desmarcar apaga o que o tema diz, e não o controle que o traz de volta.
 */
function LegalThemeItem({
    draft,
    onToggle,
}: {
    draft: LegalThemeDraft;
    onToggle: (id: string, keep: boolean) => void;
}) {
    const id = useId();
    const { theme } = draft;

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
                    <div className="flex flex-wrap items-center gap-2">
                        <Label htmlFor={id} className="text-base">
                            {theme.heading}
                        </Label>
                        {theme.judging_body && (
                            <Badge variant="secondary">
                                {theme.judging_body}
                            </Badge>
                        )}
                        <Badge variant="muted">{theme.status}</Badge>
                    </div>

                    {theme.reason && (
                        <div className="rounded-lg bg-muted/50 p-3">
                            <p className="text-xs font-medium">
                                Por que se aplica
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {theme.reason}
                            </p>
                        </div>
                    )}

                    <div>
                        <p className="text-xs font-medium">
                            Questão submetida a julgamento
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {theme.question}
                        </p>
                    </div>

                    <div>
                        <p className="text-xs font-medium">Tese firmada</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {theme.settled_thesis ??
                                "Ainda não firmada. Um tema afetado pode suspender os processos sobre a mesma questão."}
                        </p>
                    </div>

                    {theme.general_repercussions.length > 0 && (
                        <div>
                            <p className="text-xs font-medium">
                                Repercussão geral no STF
                            </p>
                            <ul className="mt-2 space-y-2">
                                {theme.general_repercussions.map(
                                    (repercussion) => (
                                        <li
                                            key={repercussion.number}
                                            className="flex items-start gap-2 text-sm text-muted-foreground"
                                        >
                                            <Landmark className="mt-0.5 size-4 shrink-0" />
                                            <span>
                                                <span className="font-medium text-foreground">
                                                    Tema {repercussion.number}
                                                </span>{" "}
                                                — {repercussion.description}
                                            </span>
                                        </li>
                                    ),
                                )}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </li>
    );
}

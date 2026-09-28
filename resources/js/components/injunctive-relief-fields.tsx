import {
    CircleAlert,
    FileText,
    LoaderCircle,
    RefreshCw,
    ShieldAlert,
    Sparkles,
} from "lucide-react";
import { useId, useState } from "react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, Textarea } from "@/components/ui/field";
import { Label } from "@/components/ui/label";
import { postJson } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import type { InjunctiveReliefSuggestion } from "@/types";

/** Os três campos da etapa 1 que esta parte da tela escreve. */
export interface InjunctiveReliefValues {
    injunctive_relief: boolean;
    injunctive_relief_description: string;
    injunctive_relief_suggestion: InjunctiveReliefSuggestion | null;
}

interface Props extends InjunctiveReliefValues {
    /** O que a consulta leva ao agente — o relato e o par CNJ da própria etapa. */
    facts: string;
    practiceArea: string;
    proceduralClassId: string;
    error?: string;
    onChange: (patch: Partial<InjunctiveReliefValues>) => void;
}

/**
 * A tutela de urgência da etapa 1: a decisão, o texto, e o que a IA disse.
 *
 * **A tutela é decisão, não dedução**, e isso não mudou com o agente. Quem
 * responde é a caixa, e a descrição só aparece depois dela. Desmarcar apaga o
 * texto de propósito: uma descrição guardada sob um pedido que não existe é
 * dado que ninguém consegue interpretar depois. O que o agente faz é
 * **sugerir** a decisão — no preenchimento inteligente a etapa abre com a caixa
 * marcada e o texto escrito quando ele recomenda —, e o advogado aceita,
 * edita ou desmarca.
 *
 * Por isso a origem fica à vista enquanto o texto for dela. O envelope da
 * sugestão viaja com o formulário e é gravado junto, e é a comparação do texto
 * na caixa com o que foi sugerido que diz se o selo é "Sugestão da IA" ou
 * "Sugestão da IA · editada". Um texto escrito à mão não leva selo nenhum.
 *
 * O botão muda com o estado, e a regra é a pedida pelo produto:
 *
 * - **"Gerar novamente"** quando há uma sugestão que recomenda a tutela;
 * - **"Consultar IA"** em todo o resto — a peça montada à mão, o agente que não
 *   viu urgência e o que falhou no preenchimento inteligente.
 *
 * Os dois chamam a mesma rota com o que a etapa tem na tela, porque a peça pode
 * ainda não existir. Uma resposta que recomenda substitui o texto — e pergunta
 * antes, quando havia texto do advogado a perder —; uma que não recomenda não
 * toca a caixa nem o texto, só registra o que a IA disse. Consultar de novo
 * nunca desfaz o que o advogado decidiu.
 */
export function InjunctiveReliefFields({
    injunctive_relief: relief,
    injunctive_relief_description: description,
    injunctive_relief_suggestion: suggestion,
    facts,
    practiceArea,
    proceduralClassId,
    error,
    onChange,
}: Props) {
    const reliefId = useId();
    const [consulting, setConsulting] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);

    const recommended = suggestion?.recommended === true;
    const ready =
        facts.trim() !== "" && practiceArea !== "" && proceduralClassId !== "";

    // Só há o que perder quando a caixa tem texto que não é o da sugestão.
    const written = relief && description.trim() !== "";
    const edited =
        written && description.trim() !== (suggestion?.description ?? "").trim();

    const consult = async () => {
        setConfirming(false);
        setConsulting(true);
        setFailure(null);

        try {
            const answer = await postJson<InjunctiveReliefSuggestion>(
                "/pecas/tutela-de-urgencia/sugerir",
                {
                    facts,
                    practice_area: practiceArea,
                    procedural_class_id: proceduralClassId,
                },
            );

            onChange(
                answer.recommended
                    ? {
                          injunctive_relief: true,
                          injunctive_relief_description: answer.description ?? "",
                          injunctive_relief_suggestion: answer,
                      }
                    : { injunctive_relief_suggestion: answer },
            );
        } catch (reason) {
            setFailure(
                reason instanceof Error
                    ? reason.message
                    : "Não foi possível analisar a urgência agora.",
            );
        } finally {
            setConsulting(false);
        }
    };

    // Uma resposta que recomenda escreve por cima da caixa, então o texto que
    // o advogado escreveu ou editou pede confirmação antes de ir embora.
    const ask = () => (edited ? setConfirming(true) : void consult());

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                {/* A caixa fica fora do `Field` porque o rótulo vem à direita
                    do controle, e não acima dele. */}
                <div className="flex items-start gap-3">
                    <Checkbox
                        id={reliefId}
                        checked={relief}
                        onCheckedChange={(checked) =>
                            onChange({
                                injunctive_relief: checked === true,
                                injunctive_relief_description:
                                    checked === true ? description : "",
                            })
                        }
                        className="mt-0.5"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor={reliefId} className="font-normal">
                            A peça tem pedido de tutela de urgência
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            Marque quando houver perigo de dano ou risco ao
                            resultado útil do processo.
                        </p>
                    </div>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={!ready || consulting}
                    onClick={ask}
                    title={
                        ready
                            ? undefined
                            : "Escreva os fatos e escolha a área e a classe para consultar a IA."
                    }
                >
                    {consulting ? (
                        <LoaderCircle className="animate-spin" />
                    ) : recommended ? (
                        <RefreshCw />
                    ) : (
                        <Sparkles />
                    )}
                    {consulting
                        ? "Analisando a urgência…"
                        : recommended
                          ? "Gerar novamente"
                          : "Consultar IA"}
                </Button>
            </div>

            {failure !== null && (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertDescription>{failure}</AlertDescription>
                </Alert>
            )}

            {suggestion !== null && (
                <SuggestionNote
                    suggestion={suggestion}
                    relief={relief}
                    edited={edited}
                />
            )}

            {relief && (
                <Field
                    label="Descrição do pedido de tutela"
                    error={error}
                    hint="O que se pede em caráter de urgência e por que não pode esperar."
                >
                    <Textarea
                        value={description}
                        onChange={(e) =>
                            onChange({
                                injunctive_relief_description: e.target.value,
                            })
                        }
                        rows={10}
                        disabled={consulting}
                        placeholder="Descreva a providência pedida desde já — a suspensão da cobrança, a retirada do nome do cadastro, a entrega do bem — e o dano que a demora causaria…"
                    />
                </Field>
            )}

            <AlertDialog open={confirming} onOpenChange={setConfirming}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Substituir a descrição da tutela?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            A IA analisa o relato de novo e, se recomendar a
                            tutela, escreve uma descrição nova no lugar da que
                            está na caixa. Se não recomendar, nada muda.
                        </AlertDialogDescription>
                    </AlertDialogHeader>

                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertDescription>
                            O texto que você escreveu ou editou será perdido.
                        </AlertDescription>
                    </Alert>

                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void consult()}>
                            <Sparkles />
                            Consultar IA
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

/**
 * O que a IA disse, ao lado da caixa — a origem do texto e a razão dele.
 *
 * Quando ela recomenda, o selo diz se o texto ainda é o dela; a espécie, por
 * que a medida foi pedida; e os documentos, o que o juiz vai procurar. Quando
 * ela não recomenda, a nota diz qual requisito faltou, e a decisão continua
 * sendo a da caixa.
 */
function SuggestionNote({
    suggestion,
    relief,
    edited,
}: {
    suggestion: InjunctiveReliefSuggestion;
    relief: boolean;
    edited: boolean;
}) {
    if (!suggestion.recommended) {
        return (
            <div className="space-y-2 rounded-lg bg-muted/50 p-3">
                <Badge variant="outline">
                    <Sparkles />
                    Análise da IA
                </Badge>
                <p className="text-sm font-medium">
                    A IA não viu urgência neste relato.
                </p>
                {suggestion.justification !== "" && (
                    <p className="text-sm text-muted-foreground">
                        {suggestion.justification}
                    </p>
                )}
            </div>
        );
    }

    const status = !relief
        ? "Sugestão da IA · desmarcada"
        : edited
          ? "Sugestão da IA · editada"
          : "Sugestão da IA";

    return (
        <div className="space-y-3 rounded-lg bg-muted/50 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline">
                    <Sparkles />
                    {status}
                </Badge>
                {suggestion.kind_label !== null && (
                    <Badge variant="muted">
                        Tutela {suggestion.kind_label.toLowerCase()}
                    </Badge>
                )}
            </div>

            {suggestion.justification !== "" && (
                <p className="text-sm text-muted-foreground">
                    {suggestion.justification}
                </p>
            )}

            {suggestion.evidence.length > 0 && (
                <div className="space-y-1">
                    <p className="text-xs font-medium">
                        Para sustentar o pedido, junte:
                    </p>
                    <ul className="space-y-1 text-sm text-muted-foreground">
                        {suggestion.evidence.map((item) => (
                            <li key={item} className="flex items-start gap-2">
                                <FileText className="mt-0.5 size-3.5 shrink-0" />
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Prosa não se corta: a cifra que o relato não escreve é
                relatada, e a frase em volta dela continua de pé para o
                advogado corrigir. */}
            {suggestion.unsupported_amounts.length > 0 && (
                <Alert variant="destructive">
                    <CircleAlert />
                    <AlertDescription>
                        A sugestão cita{" "}
                        {suggestion.unsupported_amounts
                            .map((amount) => `R$ ${formatCurrency(amount)}`)
                            .join(", ")}
                        , que o relato não traz. Confira antes de salvar.
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

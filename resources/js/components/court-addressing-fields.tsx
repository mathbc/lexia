import {
    CircleAlert,
    Landmark,
    LoaderCircle,
    RefreshCw,
    ShieldAlert,
    Sparkles,
} from "lucide-react";
import { useState } from "react";
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
import { Field, Input } from "@/components/ui/field";
import { postJson } from "@/lib/api";
import type { CourtAddressingSuggestion } from "@/types";

/** Os três campos da etapa 1 que esta parte da tela escreve. */
export interface CourtAddressingValues {
    judicial_system_id: string;
    court_addressing: string;
    court_addressing_suggestion: CourtAddressingSuggestion | null;
}

/** Do réu, só o que decide a competência — o resto não vai à consulta. */
export interface CourtAddressingDefendant {
    defendant_name: string;
    defendant_document: string;
    defendant_city: string;
    defendant_state: string;
}

interface Props extends CourtAddressingValues {
    /** O que a consulta leva aos agentes — o que a etapa tem na tela. */
    facts: string;
    practiceArea: string;
    proceduralClassId: string;
    customerId: string;
    defendant: CourtAddressingDefendant;
    error?: string;
    onChange: (patch: Partial<CourtAddressingValues>) => void;
}

/**
 * O endereçamento e o sistema judicial da etapa 1, e o que a IA disse deles.
 *
 * Os dois campos continuam sendo do advogado, e opcionais: a peça é escrita em
 * passadas, e quem ainda não sabe o foro deixa em branco. O que o agente faz é
 * **sugerir** — decide o foro competente pela classe, pela área, pelos fatos e
 * pelas cidades das partes, e o servidor compõe a frase na forma neutra
 * ("Excelentíssimo(a) Senhor(a) Juiz(a) …") e lê o sistema do mapa do tribunal.
 *
 * O select do sistema **não** mora aqui: ele fica ao lado do cliente, no topo
 * da etapa, e este bloco logo abaixo dos dois. A consulta escreve nos dois
 * campos mesmo assim — por isso o sistema entra nos valores e sai no
 * `onChange` —, e é a nota daqui que diz qual sistema a IA apontou e por quê.
 *
 * A consulta lê o cliente, o relato, a área e a classe, e os três últimos
 * ficam **abaixo** deste bloco: o botão só acorda quando eles estão
 * preenchidos, e a descrição diz isso. O réu vem da etapa 2 — o que o
 * preenchimento inteligente extraiu, ou o que já foi salvo —, e numa peça
 * montada à mão ele vai vazio: o agente lê o relato.
 *
 * A origem fica à vista enquanto o texto for dela, pela mesma regra da tutela:
 * o envelope viaja com o formulário, é gravado junto, e é a comparação do texto
 * na caixa com a frase sugerida que decide entre "Sugestão da IA" e "· editada".
 * O sistema tem a sua própria marca, "· alterado", quando o select deixou de
 * ser o sugerido.
 *
 * Uma resposta sem frase — o foro que não é juízo de primeiro grau — não toca
 * no texto, e uma sem sistema não toca no select: consultar de novo nunca
 * apaga o que o advogado escolheu.
 */
export function CourtAddressingFields({
    judicial_system_id: systemId,
    court_addressing: addressing,
    court_addressing_suggestion: suggestion,
    facts,
    practiceArea,
    proceduralClassId,
    customerId,
    defendant,
    error,
    onChange,
}: Props) {
    const [consulting, setConsulting] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);

    const suggested = (suggestion?.court_addressing ?? null) !== null;
    const ready =
        facts.trim() !== "" &&
        practiceArea !== "" &&
        proceduralClassId !== "" &&
        customerId !== "";

    // Só há o que perder quando a caixa tem texto que não é o da sugestão.
    const edited =
        addressing.trim() !== "" &&
        addressing.trim() !== (suggestion?.court_addressing ?? "").trim();

    const consult = async () => {
        setConfirming(false);
        setConsulting(true);
        setFailure(null);

        try {
            const answer = await postJson<CourtAddressingSuggestion>(
                "/pecas/enderecamento/sugerir",
                {
                    facts,
                    practice_area: practiceArea,
                    procedural_class_id: proceduralClassId,
                    customer_id: customerId,
                    ...defendant,
                },
            );

            onChange({
                court_addressing: answer.court_addressing ?? addressing,
                judicial_system_id: answer.judicial_system?.id ?? systemId,
                court_addressing_suggestion: answer,
            });
        } catch (reason) {
            setFailure(
                reason instanceof Error
                    ? reason.message
                    : "Não foi possível sugerir o endereçamento agora.",
            );
        } finally {
            setConsulting(false);
        }
    };

    // A frase nova escreve por cima da caixa, então o texto que o advogado
    // escreveu ou editou pede confirmação antes de ir embora.
    const ask = () => (edited ? setConfirming(true) : void consult());

    return (
        <div className="space-y-4">
            {/* O botão vai no rótulo, como o "Novo cliente" do campo ao lado
                do sistema: a consulta é um atalho para este campo, e não uma
                seção própria da etapa. */}
            <Field
                label="Endereçamento"
                error={error}
                hint="A quem a peça é dirigida. A IA sugere o endereçamento e o sistema judicial depois que os fatos, a área e a classe estiverem preenchidos."
                action={
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!ready || consulting}
                        onClick={ask}
                        title={
                            ready
                                ? undefined
                                : "Escolha o cliente, escreva os fatos e escolha a área e a classe para consultar a IA."
                        }
                    >
                        {consulting ? (
                            <LoaderCircle className="animate-spin" />
                        ) : suggested ? (
                            <RefreshCw />
                        ) : (
                            <Sparkles />
                        )}
                        {consulting
                            ? "Analisando a competência…"
                            : suggested
                              ? "Gerar novamente"
                              : "Consultar IA"}
                    </Button>
                }
            >
                <Input
                    value={addressing}
                    onChange={(e) =>
                        onChange({ court_addressing: e.target.value })
                    }
                    disabled={consulting}
                    placeholder="Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de Florianópolis/SC"
                />
            </Field>

            {failure !== null && (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertDescription>{failure}</AlertDescription>
                </Alert>
            )}

            {suggestion !== null && (
                <SuggestionNote
                    suggestion={suggestion}
                    edited={edited}
                    systemChanged={
                        suggestion.judicial_system !== null &&
                        suggestion.judicial_system.id !== systemId
                    }
                />
            )}

            <AlertDialog open={confirming} onOpenChange={setConfirming}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Substituir o endereçamento?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            A IA analisa a competência de novo e escreve um
                            endereçamento no lugar do que está na caixa. Se ela
                            apontar um sistema judicial, ele também substitui o
                            escolhido.
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
 * O que a IA disse, abaixo do campo — o juízo, de onde veio a cidade, a base
 * legal, o sistema e o que conferir.
 *
 * Os avisos não são erro: são o que o advogado precisa confirmar antes de
 * confiar — a cidade que o relato não escreve, o tribunal que ainda migra de
 * sistema, a justiça que o mapa não cobre. Por isso o alerta neutro, e não o
 * vermelho.
 */
function SuggestionNote({
    suggestion,
    edited,
    systemChanged,
}: {
    suggestion: CourtAddressingSuggestion;
    edited: boolean;
    systemChanged: boolean;
}) {
    if (suggestion.court_addressing === null) {
        return (
            <div className="space-y-2 rounded-lg bg-muted/50 p-3">
                <Badge variant="outline">
                    <Sparkles />
                    Análise da IA
                </Badge>
                <p className="text-sm font-medium">
                    A IA não sugeriu um endereçamento para esta peça.
                </p>
                {suggestion.justification !== "" && (
                    <p className="text-sm text-muted-foreground">
                        {suggestion.justification}
                    </p>
                )}
            </div>
        );
    }

    const system = suggestion.judicial_system;

    return (
        <div className="space-y-3 rounded-lg bg-muted/50 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline">
                    <Sparkles />
                    {edited ? "Sugestão da IA · editada" : "Sugestão da IA"}
                </Badge>
                {suggestion.division_label !== null && (
                    <Badge variant="muted">{suggestion.division_label}</Badge>
                )}
                {suggestion.branch_label !== null && (
                    <Badge variant="muted">{suggestion.branch_label}</Badge>
                )}
                {suggestion.forum_source_label !== null && (
                    <Badge variant="muted">
                        {suggestion.forum_source_label}
                    </Badge>
                )}
            </div>

            {suggestion.justification !== "" && (
                <p className="text-sm text-muted-foreground">
                    {suggestion.justification}
                </p>
            )}

            {suggestion.legal_basis !== null && (
                <p className="text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">
                        Base legal:
                    </span>{" "}
                    {suggestion.legal_basis}
                </p>
            )}

            {system !== null && (
                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Landmark className="mt-0.5 size-3.5 shrink-0" />
                    <p>
                        <span className="font-medium text-foreground">
                            {system.name} ({system.court})
                        </span>{" "}
                        {system.source === "map"
                            ? "— o sistema que o mapa registra para o tribunal."
                            : "— escolhido pela IA entre os sistemas do tribunal."}
                        {systemChanged && " · alterado"}
                        {suggestion.judicial_system_justification !== null &&
                            ` ${suggestion.judicial_system_justification}`}
                    </p>
                </div>
            )}

            {suggestion.warnings.length > 0 && (
                <Alert>
                    <CircleAlert />
                    <AlertDescription>
                        <ul className="space-y-1">
                            {suggestion.warnings.map((warning) => (
                                <li key={warning}>{warning}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

import { FileText, Gavel, Info, Scale, Tags } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";

/**
 * O que a busca lê para achar o que achar. São os três campos que a peça já
 * tem quando esta etapa abre — e é por isso que ela vem depois da revisão
 * forense, e não antes: nenhum deles existe na etapa 1.
 */
const INPUTS = [
    {
        icon: Tags,
        label: "Área de atuação",
        description: "O ramo do direito em que o caso se enquadra.",
    },
    {
        icon: Scale,
        label: "Classe processual",
        description: "A classe de ajuizamento escolhida no enquadramento.",
    },
    {
        icon: FileText,
        label: "Descrição dos fatos",
        description: "O relato que sustenta os fundamentos e os pedidos.",
    },
] as const;

/**
 * A sétima etapa: os julgados que os tribunais já proferiram em casos como
 * este.
 *
 * Por ora é **só a tela**. Não há agente por trás, não há coluna para gravar e
 * não há botão que dispare coisa alguma — o que existe é o lugar onde a análise
 * vai morar e a explicação do que ela fará quando existir, para que a etapa não
 * apareça na trilha como um passo em branco.
 *
 * Note que ela não repete a revisão forense. A etapa 6 pesquisa **teses** — o
 * que a peça argumenta, com a súmula ou o tema que a fundamenta — e chega pelo
 * `LegalThesisResearchAgent`. Esta pesquisa **decisões**: o acórdão de um caso
 * parecido, que se cita para mostrar como aquele tribunal já resolveu a questão.
 * No sistema a jurisprudência se chama `CourtDecision`, e é esse o nome que a
 * tabela, o model e o agente vão usar quando chegarem.
 *
 * O componente não recebe props de propósito: enquanto nada é buscado e nada é
 * gravado, qualquer dado que ele aceitasse seria enfeite — e um enfeite que o
 * dia da implementação teria de desfazer.
 */
export function CourtDecisionFields() {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Análise de Jurisprudência</CardTitle>
                <CardDescription>
                    O que os tribunais já decidiram em casos como este.
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-6">
                <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed px-4 py-10 text-center">
                    <Gavel className="size-6 text-muted-foreground" />

                    <p className="max-w-prose text-sm text-muted-foreground">
                        Nesta etapa o agente de IA buscará{" "}
                        <span className="font-medium text-foreground">
                            três jurisprudências
                        </span>{" "}
                        nos portais oficiais — os domínios{" "}
                        <span className="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">
                            .jus.br
                        </span>{" "}
                        e{" "}
                        <span className="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground">
                            .gov.br
                        </span>{" "}
                        —, lendo cada página antes de responder.
                    </p>

                    <p className="max-w-prose text-sm text-muted-foreground">
                        A busca parte do que a peça já registrou:
                    </p>
                </div>

                <ul className="grid gap-3 sm:grid-cols-3">
                    {INPUTS.map((input) => (
                        <li
                            key={input.label}
                            className="rounded-lg border bg-muted/30 p-4"
                        >
                            <div className="flex items-center gap-2">
                                <input.icon className="size-4 text-muted-foreground" />
                                <span className="text-sm font-medium text-foreground">
                                    {input.label}
                                </span>
                            </div>

                            <p className="mt-1.5 text-xs text-muted-foreground">
                                {input.description}
                            </p>
                        </li>
                    ))}
                </ul>

                {/* Dizer que nada roda ainda é obrigação da tela: uma etapa que
                    abre, não pesquisa e não mostra resultado nenhum é lida como
                    defeito, e o advogado fica recarregando à espera do que não
                    vem. */}
                <Alert>
                    <Info />
                    <AlertTitle>A busca ainda não está ligada</AlertTitle>
                    <AlertDescription>
                        Por ora esta etapa é apenas a tela: nenhuma consulta é
                        feita e nada é gravado. Siga para concluir a peça — a
                        minuta é redigida normalmente, com as teses da revisão
                        forense.
                    </AlertDescription>
                </Alert>
            </CardContent>
        </Card>
    );
}

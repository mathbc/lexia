import { Head, Link, router } from "@inertiajs/react";
import { LoaderCircle, Sparkles } from "lucide-react";
import { useState } from "react";
import { AppLayout } from "@/layouts/app-layout";
import { AnalysisDialog } from "@/components/analysis-dialog";
import { CustomerCreateDialog } from "@/components/customer-create-dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Field, Select, Textarea } from "@/components/ui/field";
import { postJson } from "@/lib/api";
import { stashHandoff } from "@/lib/legal-case-handoff";
import type { LegalCaseClassification, Option } from "@/types";

/**
 * O que as cinco etapas fazem, na ordem em que `ClassifyLegalCase` as chama —
 * ler os fatos, decidir a área, escolher entre as classes de ajuizamento
 * vinculadas a ela, procurar no mesmo relato quem é o réu, depois o que o
 * cliente está pedindo e, por fim, pesquisar nos portais oficiais as teses que
 * a peça pode sustentar.
 *
 * São frases sobre o trabalho, não sobre o andamento: a chamada é uma só e o
 * servidor não relata por onde anda, então nenhuma delas afirma que uma etapa
 * terminou.
 */
const ANALYSIS_STEPS = [
    "Lendo o relato e separando o que tem peso jurídico.",
    "Comparando os fatos com as áreas de atuação do catálogo.",
    "Reunindo as classes processuais de ajuizamento da área.",
    "Ordenando as classes candidatas pela proximidade com o caso.",
    "Pesando prazo, pressuposto e instrumento de cada candidata.",
    "Escrevendo a justificativa de cada uma das duas escolhas.",
    "Separando quem narra o caso de quem está do outro lado dele.",
    "Recolhendo o que o relato diz sobre o réu e onde encontrá-lo.",
    "Procurando no relato o que o cliente quer do juízo.",
    "Escrevendo cada pedido e conferindo os valores contra os fatos.",
    "Formulando a questão jurídica que o caso levanta.",
    "Pesquisando no Planalto, no STJ e no STF as teses que cabem aqui.",
    "Abrindo as páginas oficiais e conferindo cada súmula e cada tema.",
    "Descartando o que não se confirmou em fonte oficial.",
    "Transcrevendo as teses, a fundamentação e os julgados que as sustentam.",
] as const;

interface Props {
    customers: Option[];
    customerTypes: Option[];
    states: Option[];
    can: { create_customer: boolean };
}

/**
 * A porta curta para uma peça nova: o cliente e o relato, e mais nada.
 *
 * É a outra metade da escolha que o diálogo da listagem oferece. Onde o
 * assistente de seis etapas pede o enquadramento ao advogado — área, classe,
 * endereçamento —, a qualificação do réu, os pedidos e a revisão forense, aqui
 * os quatro são deduzidos dos fatos; o destino das duas é o mesmo formulário, e
 * a diferença é só quem preenche o quê.
 *
 * Por isso não há trilha de etapas nem `useForm`: nada é salvo desta tela. Ela
 * faz uma chamada só, `POST /pecas/classificar`, que não devolve tela nenhuma
 * — devolve o que os agentes leram do relato —, guarda o resultado junto com o
 * cliente e o relato e navega para `/pecas/nova`, onde o assistente abre com a
 * área e a classe escolhidas, os fatos já escritos e as etapas do réu, dos
 * pedidos e da revisão forense sugeridas. O
 * `?area=` é o que faz o servidor mandar as classes daquela área; o resto viaja
 * pelo `sessionStorage`, e `@/lib/legal-case-handoff` explica por quê.
 *
 * A espera é pelas cinco etapas, que são seis inferências em série — a pesquisa
 * de teses são duas —, e leva minutos, não o instante de um `submit`. Por isso
 * ela é um diálogo modal e não um punhado de campos desabilitados: congelar os
 * controles deste formulário deixaria de fora tudo o que está em volta dele — a
 * barra lateral, o cabeçalho, o menu do usuário —, e sair da tela joga fora a
 * inferência inteira sem que nada tenha avisado. O `AnalysisDialog` tranca a
 * página, conta o que está acontecendo e não se deixa fechar.
 *
 * A última etapa é a que mais pesa nessa conta, e é a única que sai da máquina:
 * a pesquisa abre os portais oficiais antes de responder. A espera cresceu com
 * ela, e é a partir daqui que trocar esta requisição por uma fila deixa de ser
 * um luxo — ver `ClassifyLegalCase`.
 */
export default function LegalCaseAssistedForm({
    customers,
    customerTypes,
    states,
    can,
}: Props) {
    const [customerId, setCustomerId] = useState("");
    const [facts, setFacts] = useState("");
    const [classifying, setClassifying] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const ready = customerId !== "" && facts.trim() !== "";

    const classify = async () => {
        setClassifying(true);
        setError(null);

        try {
            const classification = await postJson<LegalCaseClassification>(
                "/pecas/classificar",
                { facts },
            );

            const area = classification.practice_area.slug;

            stashHandoff({
                practice_area: area,
                customer_id: customerId,
                // A área sem classe de ajuizamento não existe no catálogo de
                // hoje, mas o payload admite o caso: sem classe, o assistente
                // abre a lista da área para o advogado escolher.
                procedural_class_id: classification.procedural_class?.id ?? "",
                facts,
                // Nulo aqui é a extração que falhou, e o enquadramento continua
                // valendo: a etapa do réu abre em branco, como sempre abriu.
                defendant: classification.defendant,
                // O mesmo vale para os pedidos, com uma distinção a mais: nulo
                // é a inferência que caiu, e a lista vazia é o relato que não
                // pede nada. A etapa abre igual nos dois casos.
                requirements: classification.requirements,
                // E a revisão forense, que é a única parte da entrega que nada
                // grava ainda: ela vive no `sessionStorage` até a aba fechar.
                // Nulo aqui é a pesquisa que falhou — um portal fora do ar
                // basta —, e não a que nada confirmou, que chega com as listas
                // vazias e o pendente escrito.
                research: classification.research,
            });

            // Sem desligar o estado de espera: a navegação já está em curso, e
            // o botão voltando a "Gerar peça" por um instante convidaria a um
            // segundo pedido tão demorado quanto o primeiro.
            router.visit(`/pecas/nova?area=${encodeURIComponent(area)}`);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : "Não foi possível enquadrar o caso agora.",
            );
            setClassifying(false);
        }
    };

    return (
        <AppLayout title="Nova peça" subtitle="Preenchimento inteligente">
            <Head title="Nova peça — preenchimento inteligente" />

            <div className="mx-auto w-full max-w-3xl space-y-6 pb-4">
                {error && (
                    <Alert variant="destructive">
                        <AlertTitle>A análise não foi concluída</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Cliente e fatos</CardTitle>
                        <CardDescription>
                            Diga para quem é a peça e conte o caso com o máximo
                            de detalhe. A área de atuação, a classe processual,
                            os dados do réu, os pedidos e as teses da revisão
                            forense são deduzidos daqui.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-6">
                        <Field
                            label="Cliente"
                            required
                            hint={
                                customers.length === 0
                                    ? "Nenhum cliente cadastrado ainda."
                                    : undefined
                            }
                            action={
                                can.create_customer && (
                                    <CustomerCreateDialog
                                        customerTypes={customerTypes}
                                        states={states}
                                        onCreated={(customer) =>
                                            setCustomerId(customer.value)
                                        }
                                    />
                                )
                            }
                        >
                            <Select
                                value={customerId}
                                onValueChange={setCustomerId}
                                options={customers}
                                placeholder="Selecione o cliente"
                            />
                        </Field>

                        {/* O mesmo placeholder da etapa de fatos: é o mesmo
                            pedido ao advogado, e duas redações para a mesma
                            coisa seriam dívida de cópia. */}
                        <Field
                            label="Fatos"
                            required
                            hint="Quanto mais completo o relato — datas, valores, nomes, o que já se tentou —, menos o modelo precisa supor."
                        >
                            <Textarea
                                value={facts}
                                onChange={(e) => setFacts(e.target.value)}
                                rows={18}
                                placeholder="Relate o caso como o cliente o contou: quando começou, o que foi feito, o que foi cobrado, o que se tentou resolver antes de procurar a Justiça…"
                            />
                        </Field>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="mr-auto text-sm text-muted-foreground">
                        A área de atuação, a classe processual, os dados do réu,
                        os pedidos e as teses serão sugeridos, e você poderá
                        revisá-los no assistente.
                    </p>

                    <Button variant="outline" asChild>
                        <Link href="/pecas">Cancelar</Link>
                    </Button>

                    <Button
                        type="button"
                        disabled={!ready || classifying}
                        onClick={classify}
                    >
                        {classifying ? (
                            <LoaderCircle className="animate-spin" />
                        ) : (
                            <Sparkles />
                        )}
                        {classifying ? "Analisando os fatos…" : "Gerar peça"}
                    </Button>
                </div>
            </div>

            {/* Fica montado durante a navegação para `/pecas/nova`: o diálogo
                some junto com a tela, e não antes dela. Desligá-lo ao receber
                a resposta devolveria o formulário por uma fração de segundo —
                clicável, e com um botão convidando ao mesmo pedido de novo. */}
            <AnalysisDialog
                open={classifying}
                title="Analisando o caso"
                hint="A análise pode levar vários minutos — a pesquisa de teses consulta os portais oficiais. Mantenha esta aba aberta: ao terminar, o assistente abre com o enquadramento, os dados do réu, os pedidos e a revisão forense preenchidos."
                messages={ANALYSIS_STEPS}
            />
        </AppLayout>
    );
}

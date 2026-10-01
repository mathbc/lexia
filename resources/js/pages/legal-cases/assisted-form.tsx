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
import { ApiError, postJson } from "@/lib/api";
import { stashHandoff } from "@/lib/legal-case-handoff";
import type { LegalCaseClassification, Option } from "@/types";

/**
 * O que as quatro etapas fazem — ler os fatos, decidir a área, escolher entre as
 * classes de ajuizamento vinculadas a ela e procurar no mesmo relato quem é o
 * réu e o que o cliente está pedindo.
 *
 * São frases sobre o trabalho, não sobre o andamento, e a distinção importa:
 * as quatro correm **em paralelo** no servidor, e a ordem em que estão escritas
 * aqui é a de quem lê, não a de quem executa. A chamada é uma só e o servidor
 * não relata por onde anda, então nenhuma delas afirma que uma etapa terminou.
 *
 * A primeira frase é a triagem, e é a única cuja posição é verdade: ela roda
 * sozinha e antes de todas, e um relato que ela recusa volta antes de as
 * outras começarem — com o motivo debaixo do campo Fatos.
 *
 * A pesquisa de teses **não está aqui**, e as frases dela saíram junto: ela
 * acontece ao abrir a etapa 5, sobre uma peça já gravada, com o diálogo próprio
 * de `ForensicReviewFields`. Prometê-la nesta tela era prometer uma espera que
 * não acontece mais aqui.
 */
const ANALYSIS_STEPS = [
    "Conferindo se o texto é o relato de um caso jurídico.",
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
] as const;

interface Props {
    customers: Option[];
    customerTypes: Option[];
    maritalStatuses: Option[];
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
 * Por isso não há trilha de etapas nem `useForm`. A tela faz uma chamada só,
 * `POST /pecas/classificar`, que não devolve tela nenhuma — devolve o que os
 * agentes leram do relato e, no mesmo gesto, **grava a peça como rascunho**
 * com isso tudo (ver `CreateAssistedLegalCase`). A navegação é para
 * `/pecas/{id}/editar`: o assistente abre na etapa 1 de uma peça que já
 * existe, com a área e a classe escolhidas, os fatos escritos e o réu e os
 * pedidos gravados atrás do "Continuar" de cada etapa. A espera de minutos
 * passa a sobreviver a uma aba fechada.
 *
 * Quando a resposta não traz peça — a seleção de classe falhou, e a coluna não
 * aceita peça sem classe, ou a gravação caiu —, vale o caminho antigo: o
 * resultado vai com o cliente e o relato pelo `sessionStorage` até
 * `/pecas/nova?area=`, e `@/lib/legal-case-handoff` explica por quê.
 *
 * A espera é pelas quatro etapas, em três tasks que correm em paralelo, e
 * nenhuma delas sai da máquina. Ainda assim é espera de inferência local e não
 * o instante de um `submit`, e é por isso que ela é um diálogo modal e não um
 * punhado de campos desabilitados: congelar os controles deste formulário
 * deixaria de fora tudo o que está em volta dele — a barra lateral, o
 * cabeçalho, o menu do usuário —, e sair da tela joga fora a inferência inteira
 * sem que nada tenha avisado. O `AnalysisDialog` tranca a página, conta o que
 * está acontecendo e não se deixa fechar.
 *
 * A revisão forense não está nesta conta e já esteve. Ela era a quinta etapa,
 * a única que saía da máquina e a mais lenta de todas, e dominava esta espera
 * sozinha — sem nada para mostrar depois, porque uma peça não salva não tem
 * onde guardar teses. Hoje ela roda ao abrir a etapa 5, sobre a peça já
 * gravada. Trocar esta requisição por uma fila continua sendo o próximo passo,
 * e agora por um motivo menor — ver `ClassifyLegalCase`.
 */
export default function LegalCaseAssistedForm({
    customers,
    customerTypes,
    maritalStatuses,
    states,
    can,
}: Props) {
    const [customerId, setCustomerId] = useState("");
    const [facts, setFacts] = useState("");
    const [classifying, setClassifying] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // A recusa que é sobre um campo vai para baixo dele: o relato que a
    // triagem não aceitou é corrigido ali, e um alerta no topo ficaria longe
    // do texto que precisa mudar.
    const [fieldErrors, setFieldErrors] = useState<{
        facts?: string;
        customer_id?: string;
    }>({});

    const ready = customerId !== "" && facts.trim() !== "";

    const classify = async () => {
        setClassifying(true);
        setError(null);
        setFieldErrors({});

        try {
            const classification = await postJson<LegalCaseClassification>(
                "/pecas/classificar",
                // O cliente só serve ao endereçamento: é o domicílio dele que
                // o foro do consumidor, do alimentando e do idoso aponta.
                { facts, customer_id: customerId },
            );

            // O caminho de sempre: a rota já gravou a peça como rascunho, e o
            // assistente abre nela, na etapa 1, com o réu e os pedidos atrás
            // do "Continuar" de cada etapa. Sem desligar o estado de espera,
            // pelo motivo escrito lá embaixo.
            if (classification.legal_case_id) {
                router.visit(`/pecas/${classification.legal_case_id}/editar`);

                return;
            }

            // A reserva: sem classe não há peça que a coluna aceite, e uma
            // gravação que falhou não devolveu id. O enquadramento continua
            // valendo e vai até `/pecas/nova` pela entrega, como ia antes.
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
                // A tutela é o terceiro elo do enquadramento, e o nulo é o
                // mesmo das extrações: a etapa falhou, e a etapa 1 oferece o
                // "Consultar IA" em vez de abrir com uma sugestão.
                injunctive_relief: classification.injunctive_relief,
                // O endereçamento vem depois do bloco e pelo mesmo caminho: o
                // nulo é a etapa que falhou, e a etapa 1 abre os dois campos em
                // branco com o "Consultar IA" à mão.
                court_addressing: classification.court_addressing,
            });

            // Sem desligar o estado de espera: a navegação já está em curso, e
            // o botão voltando a "Gerar peça" por um instante convidaria a um
            // segundo pedido tão demorado quanto o primeiro.
            router.visit(`/pecas/nova?area=${encodeURIComponent(area)}`);
        } catch (failure) {
            // O 422 da triagem e o do validador trazem o campo; o 503 do
            // agente que caiu, não, e continua no alerta do topo.
            const refused =
                failure instanceof ApiError
                    ? {
                          facts: failure.field("facts"),
                          customer_id: failure.field("customer_id"),
                      }
                    : {};

            if (refused.facts || refused.customer_id) {
                setFieldErrors(refused);
            } else {
                setError(
                    failure instanceof Error
                        ? failure.message
                        : "Não foi possível enquadrar o caso agora.",
                );
            }

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
                            os dados do réu e os pedidos são deduzidos daqui.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-6">
                        <Field
                            label="Cliente"
                            required
                            error={fieldErrors.customer_id}
                            hint={
                                customers.length === 0
                                    ? "Nenhum cliente cadastrado ainda."
                                    : undefined
                            }
                            action={
                                can.create_customer && (
                                    <CustomerCreateDialog
                                        customerTypes={customerTypes}
                                        maritalStatuses={maritalStatuses}
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
                                onValueChange={(value) => {
                                    setCustomerId(value);
                                    setFieldErrors(({ facts }) => ({ facts }));
                                }}
                                options={customers}
                                placeholder="Selecione o cliente"
                            />
                        </Field>

                        {/* O mesmo placeholder dos fatos da etapa 1: é o mesmo
                            pedido ao advogado, e duas redações para a mesma
                            coisa seriam dívida de cópia. */}
                        <Field
                            label="Fatos"
                            required
                            error={fieldErrors.facts}
                            hint="Quanto mais completo o relato — datas, valores, nomes, o que já se tentou —, menos o modelo precisa supor."
                        >
                            <Textarea
                                value={facts}
                                onChange={(e) => {
                                    setFacts(e.target.value);
                                    // A recusa era sobre o texto de antes.
                                    setFieldErrors(({ customer_id }) => ({
                                        customer_id,
                                    }));
                                }}
                                rows={18}
                                placeholder="Relate o caso como o cliente o contou: quando começou, o que foi feito, o que foi cobrado, o que se tentou resolver antes de procurar a Justiça…"
                            />
                        </Field>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="mr-auto text-sm text-muted-foreground">
                        A área de atuação, a classe processual, os dados do réu
                        e os pedidos serão sugeridos, e a peça fica salva como
                        rascunho para você revisá-los no assistente.
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
                hint="A análise pode levar alguns minutos. Mantenha esta aba aberta: ao terminar, a peça é salva como rascunho e o assistente abre com o enquadramento, os dados do réu e os pedidos preenchidos."
                messages={ANALYSIS_STEPS}
            />
        </AppLayout>
    );
}

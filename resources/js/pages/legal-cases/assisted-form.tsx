import { Head, Link, useForm } from "@inertiajs/react";
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
import { ANALYSIS_STEPS } from "@/lib/analysis-steps";
import type { AssistedFillRetry, Option } from "@/types";

interface Props {
    customers: Option[];
    customerTypes: Option[];
    maritalStatuses: Option[];
    states: Option[];
    can: { create_customer: boolean };
    /**
     * O preenchimento que voltou sem peça — recusado pela triagem ou falho —,
     * com o cliente, o relato e o motivo. Ver `InferenceRunProps::retry()`.
     */
    retry: AssistedFillRetry | null;
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
 * Por isso não há trilha de etapas, e o `useForm` tem dois campos. O envio,
 * `POST /pecas/classificar`, **não espera pelos agentes**: grava o pedido,
 * enfileira o trabalho na fila `inference` e redireciona na hora para a tela da
 * peça nova (`/pecas/nova?preenchimento={id}`), que espera travada atrás do
 * diálogo e consulta o andamento — ver `RunAssistedFill` e `useAssistedFill`.
 * Quando o worker termina, aquela tela abre `/pecas/{id}/editar`: o assistente
 * na etapa 1 de uma peça que já existe, com a área e a classe escolhidas, os
 * fatos escritos e o réu e os pedidos gravados atrás do "Continuar" de cada
 * etapa.
 *
 * Esperava aqui mesmo, e a requisição esperava junto: em produção o gateway a
 * cortava com 504 antes de a análise terminar, e a tela nunca saía do lugar.
 *
 * O que volta para cá é o preenchimento que não virou peça: a recusa da
 * triagem, que aparece debaixo do campo Fatos — o relato que ela não aceitou é
 * corrigido ali —, e a falha, no alerta do topo. Nos dois casos o formulário
 * volta como estava, com o cliente e o relato, por `retry`. Os erros de
 * validação (o relato curto demais, o cliente que não é da conta) nem chegam a
 * enfileirar e voltam pelo bag de erros do Inertia, como em qualquer
 * formulário.
 *
 * Quando o enquadramento termina sem peça — a seleção de classe falhou, e a
 * coluna não aceita peça sem classe, ou a gravação caiu —, vale o caminho
 * antigo: o resultado vai com o cliente e o relato pelo `sessionStorage` até
 * `/pecas/nova?area=`, e `@/lib/legal-case-handoff` explica por quê.
 */
export default function LegalCaseAssistedForm({
    customers,
    customerTypes,
    maritalStatuses,
    states,
    can,
    retry,
}: Props) {
    const form = useForm({
        customer_id: retry?.customer_id ?? "",
        facts: retry?.facts ?? "",
    });

    // O motivo do preenchimento que voltou, até o advogado mexer no que ele
    // descreve: a recusa da triagem era sobre o texto de antes.
    const [refusal, setRefusal] = useState(
        retry?.field === "facts" ? retry.message : null,
    );
    const failure = retry && retry.field === null ? retry.message : null;

    const ready = form.data.customer_id !== "" && form.data.facts.trim() !== "";

    const classify = () => {
        setRefusal(null);
        // Sem `onSuccess`: o servidor redireciona para a tela da peça nova, que
        // é onde a espera acontece.
        form.post("/pecas/classificar");
    };

    return (
        <AppLayout title="Nova peça" subtitle="Preenchimento inteligente">
            <Head title="Nova peça — preenchimento inteligente" />

            <div className="mx-auto w-full max-w-3xl space-y-6 pb-4">
                {failure && (
                    <Alert variant="destructive">
                        <AlertTitle>A análise não foi concluída</AlertTitle>
                        <AlertDescription>{failure}</AlertDescription>
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
                            error={form.errors.customer_id}
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
                                            form.setData(
                                                "customer_id",
                                                customer.value,
                                            )
                                        }
                                    />
                                )
                            }
                        >
                            <Select
                                value={form.data.customer_id}
                                onValueChange={(value) => {
                                    form.setData("customer_id", value);
                                    form.clearErrors("customer_id");
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
                            error={form.errors.facts ?? refusal ?? undefined}
                            hint="Quanto mais completo o relato — datas, valores, nomes, o que já se tentou —, menos o modelo precisa supor."
                        >
                            <Textarea
                                value={form.data.facts}
                                onChange={(e) => {
                                    form.setData("facts", e.target.value);
                                    // A recusa era sobre o texto de antes.
                                    form.clearErrors("facts");
                                    setRefusal(null);
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
                        disabled={!ready || form.processing}
                        onClick={classify}
                    >
                        {form.processing ? (
                            <LoaderCircle className="animate-spin" />
                        ) : (
                            <Sparkles />
                        )}
                        {form.processing ? "Enviando os fatos…" : "Gerar peça"}
                    </Button>
                </div>
            </div>

            {/* O envio só enfileira e redireciona, então este diálogo dura o
                instante do POST: a espera de verdade é a da tela da peça nova,
                que abre com o mesmo diálogo. Ele existe para que o formulário
                não volte clicável nesse meio-tempo, convidando a um segundo
                pedido. */}
            <AnalysisDialog
                open={form.processing}
                title="Analisando o caso"
                hint="A análise pode levar alguns minutos e continua no servidor mesmo se você sair desta tela. Ao terminar, a peça é salva como rascunho e o assistente abre com o enquadramento, os dados do réu e os pedidos preenchidos."
                messages={ANALYSIS_STEPS}
            />
        </AppLayout>
    );
}

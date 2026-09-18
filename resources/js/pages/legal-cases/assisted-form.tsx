import { Head, Link, router } from "@inertiajs/react";
import { LoaderCircle, Sparkles } from "lucide-react";
import { useState } from "react";
import { AppLayout } from "@/layouts/app-layout";
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
 * endereçamento —, aqui ele é deduzido dos fatos; o destino das duas é o mesmo
 * formulário, e a diferença é só quem preenche o quê.
 *
 * Por isso não há trilha de etapas nem `useForm`: nada é salvo desta tela. Ela
 * faz uma chamada só, `POST /pecas/classificar`, que não devolve tela nenhuma
 * — devolve o enquadramento —, guarda o resultado junto com o cliente e o
 * relato e navega para `/pecas/nova`, onde o assistente abre com a área e a
 * classe escolhidas e os fatos já escritos. O `?area=` é o que faz o servidor
 * mandar as classes daquela área; o resto viaja pelo `sessionStorage`, e
 * `@/lib/legal-case-handoff` explica por quê.
 *
 * O botão espera pelos dois agentes, que são duas inferências em série. Daí o
 * estado de espera ser explícito e os campos congelarem junto: a resposta
 * demora, e um formulário que continua aceitando digitação durante a espera
 * promete que o que for digitado conta.
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
                            de detalhe. A área de atuação, a classe processual e
                            o endereçamento são deduzidos daqui.
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
                                disabled={classifying}
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
                                disabled={classifying}
                            />
                        </Field>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="mr-auto text-sm text-muted-foreground">
                        {classifying
                            ? "Os agentes estão lendo o relato. A análise pode levar alguns minutos — mantenha esta aba aberta."
                            : "A área de atuação e a classe processual serão sugeridas, e você poderá revisá-las no assistente."}
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
        </AppLayout>
    );
}

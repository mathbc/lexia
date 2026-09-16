import { Head, Link } from "@inertiajs/react";
import { Sparkles } from "lucide-react";
import { useState } from "react";
import { AppLayout } from "@/layouts/app-layout";
import { CustomerCreateDialog } from "@/components/customer-create-dialog";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Field, Select, Textarea } from "@/components/ui/field";
import type { Option } from "@/types";

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
 * Por isso não há trilha de etapas nem `useForm`: nada é salvo desta tela. O
 * estado é local porque ele não sobrevive à navegação de propósito — quando a
 * geração existir, é ela que devolve o advogado ao assistente já preenchido,
 * e não um rascunho guardado aqui.
 *
 * O botão de gerar fica desabilitado enquanto os agentes não existem, e
 * desabilitado sempre — não "até preencher". A razão é a funcionalidade
 * ausente, e um botão que acende ao preencher prometeria que o preenchimento
 * adianta alguma coisa. É o mesmo acordo do "Ditar" na etapa de fatos.
 */
export default function LegalCaseAssistedForm({
    customers,
    customerTypes,
    states,
    can,
}: Props) {
    const [customerId, setCustomerId] = useState("");
    const [facts, setFacts] = useState("");

    return (
        <AppLayout title="Nova peça" subtitle="Preenchimento inteligente">
            <Head title="Nova peça — preenchimento inteligente" />

            <div className="mx-auto w-full max-w-3xl space-y-6 pb-4">
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
                        A geração assistida entra numa próxima versão.
                    </p>

                    <Button variant="outline" asChild>
                        <Link href="/pecas">Cancelar</Link>
                    </Button>

                    <Button
                        type="button"
                        disabled
                        aria-label="Gerar peça (em breve)"
                    >
                        <Sparkles />
                        Gerar peça
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}

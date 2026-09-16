import { Mic } from 'lucide-react'
import { useId } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, Textarea } from '@/components/ui/field'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'

export interface FactsFormValues {
    facts: string
    injunctive_relief: boolean
    injunctive_relief_description: string
}

type Errors = Partial<Record<keyof FactsFormValues, string>>

interface Props {
    values: FactsFormValues
    errors: Errors
    set: (patch: Partial<FactsFormValues>) => void
    disabled?: boolean
}

/**
 * O relato do que aconteceu e a urgência que ele justifica — os primeiros
 * campos da peça que o advogado escreve em vez de escolher.
 *
 * Os fatos são uma caixa de texto e nada mais: nem editor rico, nem seções
 * numeradas. Vêm na ordem em que aconteceram, e é essa narrativa que os
 * fundamentos e os pedidos vão citar depois; qualquer estrutura imposta aqui
 * seria uma aposta sobre uma peça que ainda não existe.
 *
 * A tutela de urgência é decisão, não dedução: quem responde é a caixa de
 * marcação, e a descrição só aparece depois dela. Desmarcar apaga o texto de
 * propósito — uma descrição guardada sob um pedido que não existe é dado que
 * ninguém consegue interpretar depois, e a marcação é gesto deliberado o
 * bastante para carregar essa consequência.
 *
 * O microfone ao lado do rótulo é a porta do ditado, que ainda não existe:
 * fica desabilitado de propósito, porque um botão que aceita o clique e não
 * faz nada é pior do que um que assume não estar pronto. Quando a transcrição
 * chegar, ela entrega texto puro e o destino é este mesmo `set` — nada mais no
 * formulário precisa saber de onde veio.
 */
export function FactsFormFields({ values, errors, set, disabled = false }: Props) {
    const reliefId = useId()

    return (
        <Card>
            <CardHeader>
                <CardTitle>Fatos e tutela de urgência</CardTitle>
                <CardDescription>
                    O que aconteceu, na ordem em que aconteceu, com datas, valores e nomes sempre
                    que houver — e, se o caso não puder esperar, o que se pede desde já.
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-6">
                <Field
                    label="Fatos"
                    error={errors.facts}
                    hint="O ditado por voz entra numa próxima versão."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled
                            aria-label="Ditar os fatos (em breve)"
                        >
                            <Mic />
                            Ditar
                        </Button>
                    }
                >
                    <Textarea
                        value={values.facts}
                        onChange={(e) => set({ facts: e.target.value })}
                        rows={16}
                        placeholder="Relate o caso como o cliente o contou: quando começou, o que foi feito, o que foi cobrado, o que se tentou resolver antes de procurar a Justiça…"
                        disabled={disabled}
                    />
                </Field>

                <Separator />

                {/* A caixa de marcação fica fora do `Field`: o rótulo vem à
                    direita do controle, e não acima dele. */}
                <div className="flex items-start gap-3">
                    <Checkbox
                        id={reliefId}
                        checked={values.injunctive_relief}
                        onCheckedChange={(checked) =>
                            set({
                                injunctive_relief: checked === true,
                                ...(checked === true ? {} : { injunctive_relief_description: '' }),
                            })
                        }
                        disabled={disabled}
                        className="mt-0.5"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor={reliefId} className="font-normal">
                            A peça tem pedido de tutela de urgência
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            Marque quando houver perigo de dano ou risco ao resultado útil do
                            processo.
                        </p>
                    </div>
                </div>

                {values.injunctive_relief && (
                    <Field
                        label="Descrição do pedido de tutela"
                        error={errors.injunctive_relief_description}
                        hint="O que se pede em caráter de urgência e por que não pode esperar."
                    >
                        <Textarea
                            value={values.injunctive_relief_description}
                            onChange={(e) => set({ injunctive_relief_description: e.target.value })}
                            rows={8}
                            placeholder="Descreva a providência pedida desde já — a suspensão da cobrança, a retirada do nome do cadastro, a entrega do bem — e o dano que a demora causaria…"
                            disabled={disabled}
                        />
                    </Field>
                )}
            </CardContent>
        </Card>
    )
}

import { Mic } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Textarea } from '@/components/ui/field'

interface Props {
    value: string
    error?: string
    onChange: (facts: string) => void
    disabled?: boolean
}

/**
 * O relato do que aconteceu — o primeiro campo da peça que o advogado escreve
 * em vez de escolher.
 *
 * É uma caixa de texto e nada mais: nem editor rico, nem seções numeradas. Os
 * fatos vêm na ordem em que aconteceram, e é essa narrativa que os fundamentos
 * e os pedidos vão citar depois; qualquer estrutura imposta aqui seria uma
 * aposta sobre uma peça que ainda não existe.
 *
 * O microfone ao lado do rótulo é a porta do ditado, que ainda não existe:
 * fica desabilitado de propósito, porque um botão que aceita o clique e não
 * faz nada é pior do que um que assume não estar pronto. Quando a transcrição
 * chegar, ela entrega texto puro e o destino é este mesmo `onChange` — nada
 * mais no formulário precisa saber de onde veio.
 */
export function FactsFormFields({ value, error, onChange, disabled = false }: Props) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Descrição dos fatos</CardTitle>
                <CardDescription>
                    O que aconteceu, na ordem em que aconteceu, com datas, valores e nomes sempre
                    que houver.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <Field
                    label="Fatos"
                    error={error}
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
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        rows={16}
                        placeholder="Relate o caso como o cliente o contou: quando começou, o que foi feito, o que foi cobrado, o que se tentou resolver antes de procurar a Justiça…"
                        disabled={disabled}
                    />
                </Field>
            </CardContent>
        </Card>
    )
}

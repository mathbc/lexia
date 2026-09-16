import { Plus, Trash2 } from 'lucide-react'
import { RowActions } from '@/components/row-actions'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Textarea } from '@/components/ui/field'
import { formatCurrency } from '@/lib/format'
import {
    claimedCents,
    SUGGESTED_REQUIREMENTS,
    writtenRequirements,
    type RequirementDraft,
} from '@/lib/requirements'

interface Props {
    requirements: RequirementDraft[]
    onAdd: (description?: string) => void
    onChange: (id: string, patch: Partial<RequirementDraft>) => void
    onRemove: (id: string) => void
    disabled?: boolean
}

/**
 * O que a peça pede ao juízo: a lista numerada que vem depois de "Ante o
 * exposto, requer:".
 *
 * Cada pedido é uma frase, não um campo de formulário — "a concessão da
 * gratuidade da justiça", "a citação do Réu para, querendo, contestar a ação"
 * —, e por isso é caixa de texto e não linha de tabela: a redação é o produto,
 * e o campo tem que caber nela. O valor vem ao lado porque só alguns pedidos
 * têm cifra; a gratuidade e a citação não valem nada em reais, e a coluna
 * aceita isso em vez de exigir um zero que seria lido como pedido de zero.
 *
 * Os botões de pedido frequente não são modelo de peça: escrevem a redação de
 * praxe no campo e saem da frente. Dois deles chegam pela metade — o que vem
 * depois de "para " é o caso, e a frase inacabada põe o cursor onde está o
 * trabalho. Ver `@/lib/requirements`.
 *
 * A numeração é a posição na lista, e é ela que o rótulo de cada campo repete:
 * a peça vai citar "o pedido 2", e a tela precisa concordar com ela.
 *
 * Nada é enviado. A lista mora no rascunho até existir a Action que salva a
 * peça inteira — é o mesmo arranjo do réu, dos fatos e dos documentos.
 */
export function RequirementFormFields({
    requirements,
    onAdd,
    onChange,
    onRemove,
    disabled = false,
}: Props) {
    const written = writtenRequirements(requirements)
    const claimed = claimedCents(requirements)

    return (
        <Card>
            <CardHeader>
                <CardTitle>Pedidos e requerimentos</CardTitle>
                <CardDescription>
                    {written.length === 0
                        ? 'O que se pede ao juízo, um pedido por item, na ordem em que serão numerados na peça.'
                        : `${written.length} ${written.length === 1 ? 'pedido' : 'pedidos'}${
                              claimed > 0 ? ` · R$ ${formatCurrency(String(claimed))} em valores` : ''
                          }`}
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-6">
                {/* A fila de pedidos frequentes fica acima da lista: é por onde
                    a maioria das peças começa, e o que ela escreve continua
                    editável como qualquer outro texto. */}
                <div className="space-y-3">
                    <p className="text-sm font-medium">Pedidos frequentes</p>

                    <div className="flex flex-wrap gap-2">
                        {SUGGESTED_REQUIREMENTS.map((suggestion) => (
                            <Button
                                key={suggestion.label}
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={disabled}
                                onClick={() => onAdd(suggestion.description)}
                            >
                                <Plus />
                                {suggestion.label}
                            </Button>
                        ))}
                    </div>
                </div>

                {requirements.length === 0 ? (
                    <p className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                        Nenhum pedido ainda. Comece por um dos frequentes acima ou escreva o seu.
                    </p>
                ) : (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">Ante o exposto, requer:</p>

                        <ol className="space-y-3">
                            {requirements.map((requirement, index) => (
                                <li key={requirement.id} className="rounded-lg border p-4">
                                    <div className="flex items-start gap-3">
                                        <div className="grid min-w-0 flex-1 gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                            <Field label={`Pedido ${index + 1}`}>
                                                <Textarea
                                                    value={requirement.description}
                                                    onChange={(event) =>
                                                        onChange(requirement.id, {
                                                            description: event.target.value,
                                                        })
                                                    }
                                                    rows={3}
                                                    placeholder="A condenação do Réu ao pagamento de…"
                                                    disabled={disabled}
                                                />
                                            </Field>

                                            <Field label="Valor (R$)" hint="Se o pedido tiver cifra.">
                                                <Input
                                                    value={requirement.amount}
                                                    onChange={(event) =>
                                                        onChange(requirement.id, {
                                                            amount: formatCurrency(event.target.value),
                                                        })
                                                    }
                                                    inputMode="numeric"
                                                    placeholder="0,00"
                                                    className="tabular"
                                                    disabled={disabled}
                                                />
                                            </Field>
                                        </div>

                                        {/* Sem `confirm`: o que se perde é o
                                            texto de um pedido, ainda não salvo,
                                            e a lista continua na tela. Quando
                                            remover apagar algo do servidor, a
                                            confirmação entra. */}
                                        <RowActions
                                            label={`Ações do pedido ${index + 1}`}
                                            actions={[
                                                {
                                                    label: 'Remover',
                                                    icon: Trash2,
                                                    destructive: true,
                                                    onSelect: () => onRemove(requirement.id),
                                                },
                                            ]}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>
                )}

                <Button type="button" variant="outline" disabled={disabled} onClick={() => onAdd()}>
                    <Plus />
                    Adicionar pedido
                </Button>
            </CardContent>
        </Card>
    )
}

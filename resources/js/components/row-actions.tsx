import { Link } from '@inertiajs/react'
import { MoreHorizontal } from 'lucide-react'
import { Fragment, type ComponentType, type MouseEvent } from 'react'
import { Button } from '@/components/ui/button'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete'

export interface RowAction {
    label: string
    /** Obrigatório: o menu é lido pelo par ícone + rótulo, como o menu lateral. */
    icon: ComponentType<{ className?: string }>
    /** Destino da ação. `get` vira link de verdade; os outros métodos, botão. */
    href?: string
    method?: Method
    /** Para o que não é navegação — abrir um diálogo, chamar o `router`. */
    onSelect?: () => void
    /** Vermelho, e sempre por último: reservado ao que destrói ou revoga. */
    destructive?: boolean
    /** Pergunta antes de executar. Use no que não dá para desfazer. */
    confirm?: string
    /** Abre um grupo: desenha um separador acima do item. */
    separated?: boolean
}

/**
 * Um item que o usuário não pode executar sai da lista em vez de aparecer
 * desabilitado — a mesma regra do menu lateral. `false`, `null` e `undefined`
 * são descartados, de modo que a chamada escreve `can.update && { ... }`.
 */
type RowActionItem = RowAction | false | null | undefined

/**
 * As ações de uma linha de tabela, atrás de um botão de três pontos.
 *
 * A coluna de ações guarda um botão só, independentemente de quantas ações a
 * linha tenha: é o que impede a tabela de crescer para o lado a cada nova
 * operação. Tabela nenhuma desenha `Editar`/`Excluir` soltos na linha.
 *
 * Nada aqui autoriza coisa alguma — a lista já chega filtrada pelo que o
 * servidor disse que o usuário pode fazer, e a Policy continua sendo a defesa
 * de verdade.
 */
export function RowActions({
    actions,
    label = 'Ações',
}: {
    actions: RowActionItem[]
    /** O nome do menu para leitor de tela: "Ações de Fulano". */
    label?: string
}) {
    const items = actions.filter((action): action is RowAction => Boolean(action))

    // Sem ação nenhuma não há botão: um menu vazio só promete o que não existe.
    if (items.length === 0) {
        return null
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-8 data-[state=open]:bg-accent">
                    <MoreHorizontal />
                    <span className="sr-only">{label}</span>
                </Button>
            </DropdownMenuTrigger>

            {/* Alinhado à direita porque a coluna de ações é a última. */}
            <DropdownMenuContent align="end" className="w-48">
                {items.map((action, index) => (
                    <Fragment key={action.label}>
                        {action.separated && index > 0 && <DropdownMenuSeparator />}
                        <ActionItem action={action} />
                    </Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    )
}

/**
 * A confirmação nativa é provisória: quando houver um `AlertDialog` no
 * projeto, é aqui — e só aqui — que ela é trocada.
 */
const allowed = (action: RowAction): boolean => !action.confirm || window.confirm(action.confirm)

function ActionItem({ action }: { action: RowAction }) {
    const Icon = action.icon
    const variant = action.destructive ? 'destructive' : 'default'

    if (action.href) {
        const method = action.method ?? 'get'

        return (
            <DropdownMenuItem variant={variant} asChild>
                {/* `as="a"` mantém ctrl+clique e "copiar endereço" no que é
                    navegação; o que muda estado precisa ser um botão. */}
                <Link
                    href={action.href}
                    method={method}
                    as={method === 'get' ? 'a' : 'button'}
                    className="w-full"
                    // A visita do Inertia verifica defaultPrevented, então
                    // barrar o clique aqui cancela também a navegação.
                    onClick={(event: MouseEvent) => {
                        if (!allowed(action)) {
                            event.preventDefault()
                        }
                    }}
                >
                    <Icon />
                    {action.label}
                </Link>
            </DropdownMenuItem>
        )
    }

    return (
        <DropdownMenuItem
            variant={variant}
            onSelect={(event) => {
                if (!allowed(action)) {
                    event.preventDefault()

                    return
                }

                action.onSelect?.()
            }}
        >
            <Icon />
            {action.label}
        </DropdownMenuItem>
    )
}

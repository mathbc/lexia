import { Link } from '@inertiajs/react'
import { ListChecks, Plus, Sparkles } from 'lucide-react'
import { useState, type ComponentType } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog'

interface ModeProps {
    href: string
    icon: ComponentType<{ className?: string }>
    title: string
    badge?: string
    description: string
    onNavigate: () => void
}

/**
 * Um caminho, não um valor: o cartão navega no clique.
 *
 * O seletor de classe processual usa `role="radio"` porque a classe é um dado
 * que será salvo — aqui o cartão é um destino, e um radiogroup pediria um
 * "Continuar" que não acrescenta nada e anunciaria "não marcada" num
 * `aria-checked` que nunca chega a ser verdadeiro. Sendo `Link`, o ctrl+clique
 * também volta a funcionar.
 */
function Mode({ href, icon: Icon, title, badge, description, onNavigate }: ModeProps) {
    return (
        <Link
            href={href}
            // Fechar antes de navegar: a listagem inteira é desmontada com o
            // diálogo aberto, e o bloqueio de rolagem do Radix deixaria o
            // `body` inclicável na tela de destino.
            onClick={onNavigate}
            className="flex flex-col gap-2 rounded-lg border bg-card p-4 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <span className="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground">
                {/* O tamanho é explícito: a regra que encolhe o ícone mora no
                    `Button`, e não alcança um `Link` cru. */}
                <Icon className="size-4" />
                {title}
                {badge && <Badge variant="outline">{badge}</Badge>}
            </span>

            <span className="text-xs leading-relaxed text-muted-foreground">{description}</span>
        </Link>
    )
}

/**
 * A escolha que abre uma peça nova.
 *
 * O botão da listagem deixou de navegar porque passaram a existir dois
 * caminhos até a mesma minuta, e qual deles se toma é uma decisão do advogado
 * sobre quanto ele quer informar — não um detalhe de tela que se resolva
 * adivinhando.
 *
 * O diálogo traz o próprio gatilho, como o cadastro de cliente faz: quem
 * chama escreve uma linha, e o `can.create` que já envolvia o botão continua
 * sendo o único portão do lado do cliente. A Policy segue sendo a defesa de
 * verdade — as duas rotas autorizam `create` na peça.
 */
export function LegalCaseModeDialog() {
    const [open, setOpen] = useState(false)

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Nova peça
                </Button>
            </DialogTrigger>

            {/* Sem rodapé e sem rolagem interna: não há o que cancelar além de
                fechar, e o conteúdo cabe. */}
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Como deseja montar a peça?</DialogTitle>
                    <DialogDescription>
                        Os dois caminhos chegam à mesma minuta. O que muda é quanto você informa e
                        quanto os agentes deduzem.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Mode
                        href="/pecas/nova/inteligente"
                        icon={Sparkles}
                        title="Preenchimento inteligente"
                        badge="Em breve"
                        description="Informe o cliente e relate os fatos com detalhe. A partir do relato, os agentes definem a área de atuação, a classe processual e o endereçamento, e ampliam a narrativa antes de redigir."
                        onNavigate={() => setOpen(false)}
                    />

                    <Mode
                        href="/pecas/nova"
                        icon={ListChecks}
                        title="Preenchimento manual"
                        description="Percorra as seis etapas do assistente: dados básicos, parte contrária, fatos, pedidos, documentos e revisão. Com o enquadramento definido por você, os agentes seguem na construção da minuta — refinando os fatos, localizando jurisprudência e redigindo os fundamentos."
                        onNavigate={() => setOpen(false)}
                    />
                </div>
            </DialogContent>
        </Dialog>
    )
}

import { ChevronDown, ExternalLink, Landmark } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import type { JudicialSystemLink } from '@/types'

/**
 * A saída da peça para o sistema em que ela será protocolada.
 *
 * O endereço é do **tribunal**, não do sistema: o eproc do TJSC e o do TJRS são
 * o mesmo programa em dois hosts, cada um do seu tribunal, e um "link do eproc"
 * não levaria a lugar nenhum em que se protocola. Por isso o botão precisa
 * saber qual tribunal, e quem responde é o foro — a UF em que a sugestão de
 * endereçamento o pôs. Sem ela, um sistema de um tribunal só (o Tucujuris) não
 * tem o que perguntar; os demais abrem a lista dos tribunais que o usam, em vez
 * de adivinhar um.
 *
 * A sigla fica visível no botão de propósito: se o advogado mudou a comarca
 * depois da sugestão, é ali que ele vê que o botão ainda aponta para a antiga.
 */
export function JudicialSystemAccess({
    name,
    links,
    forumState,
}: {
    name: string
    links: JudicialSystemLink[]
    /** A UF do foro, da sugestão de endereçamento; nula quando não houve. */
    forumState?: string | null
}) {
    if (links.length === 0) {
        return null
    }

    const link =
        links.find((candidate) => candidate.state === forumState) ??
        (links.length === 1 ? links[0] : undefined)

    if (link) {
        return (
            <Button variant="outline" asChild>
                <a
                    href={link.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={
                        link.note
                            ? `${name} no ${link.court}: ${link.note}. Confira se a comarca já o usa.`
                            : `Abrir o ${name} do ${link.court} em nova aba`
                    }
                >
                    <Landmark />
                    Acessar o {name}
                    <span className="text-muted-foreground">{link.court}</span>
                    <ExternalLink />
                </a>
            </Button>
        )
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" className="data-[state=open]:bg-accent">
                    <Landmark />
                    Acessar o {name}
                    <ChevronDown />
                </Button>
            </DropdownMenuTrigger>

            {/* Alinhado à direita porque o botão fica no fim da linha. */}
            <DropdownMenuContent align="end" className="w-72">
                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                    Em qual tribunal?
                </DropdownMenuLabel>
                {links.map((item) => (
                    <DropdownMenuItem key={item.state} asChild>
                        <a href={item.url} target="_blank" rel="noopener noreferrer">
                            <span className="font-medium">{item.court}</span>
                            {item.note && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {item.note}
                                </span>
                            )}
                            <ExternalLink className="ml-auto text-muted-foreground" />
                        </a>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    )
}

import { Link, usePage } from '@inertiajs/react'
import { LayoutDashboard, Menu, Scale } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet'
import { cn } from '@/lib/utils'
import type { AuthUser, PageProps } from '@/types'
import { LANDING_LINKS } from './navigation'

/**
 * A barra do topo da landing.
 *
 * É fixa e começa transparente sobre a entrada animada — só ganha fundo e
 * borda depois do primeiro rolar, para não cortar a balança ao meio. Abaixo de
 * `md` os links vão para uma gaveta (`Sheet`), a mesma do menu lateral do
 * painel.
 *
 * Os links são âncoras de uma página só, então são `<a>` de verdade e não
 * `<Link>` do Inertia — um visit aqui recarregaria a página inteira para
 * chegar a uma seção que já está na tela.
 */
export function LandingNav() {
    const { auth } = usePage<PageProps>().props
    const [scrolled, setScrolled] = useState(false)
    const [open, setOpen] = useState(false)

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8)

        onScroll()
        window.addEventListener('scroll', onScroll, { passive: true })

        return () => window.removeEventListener('scroll', onScroll)
    }, [])

    return (
        <header
            className={cn(
                'fixed inset-x-0 top-0 z-50 border-b transition-colors',
                scrolled ? 'border-border bg-background/80 backdrop-blur' : 'border-transparent',
            )}
        >
            <nav
                aria-label="Principal"
                className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-6"
            >
                <a href="#home" className="flex items-center gap-2 text-foreground">
                    <span className="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <Scale className="size-4" />
                    </span>
                    <span className="text-lg font-semibold">LexIA</span>
                </a>

                <ul className="hidden items-center gap-1 md:flex">
                    {LANDING_LINKS.map((link) => (
                        <li key={link.href}>
                            <a
                                href={link.href}
                                className="rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {link.label}
                            </a>
                        </li>
                    ))}
                </ul>

                <div className="hidden items-center gap-2 md:flex">
                    <PanelActions user={auth.user} />
                </div>

                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetTrigger asChild>
                        <Button type="button" variant="ghost" size="icon" className="md:hidden">
                            <Menu />
                            <span className="sr-only">Abrir o menu</span>
                        </Button>
                    </SheetTrigger>

                    <SheetContent side="right" className="gap-0">
                        <SheetHeader className="sr-only">
                            <SheetTitle>Menu</SheetTitle>
                            <SheetDescription>Navegação da página inicial</SheetDescription>
                        </SheetHeader>

                        <ul className="flex flex-col gap-1 px-4 pt-12">
                            {LANDING_LINKS.map((link) => (
                                <li key={link.href}>
                                    {/* Fechar a gaveta é o que deixa a âncora rolar
                                        até a seção: com ela aberta o corpo fica
                                        preso atrás do overlay. */}
                                    <a
                                        href={link.href}
                                        onClick={() => setOpen(false)}
                                        className="block rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                                    >
                                        {link.label}
                                    </a>
                                </li>
                            ))}
                        </ul>

                        <div className="mt-6 flex flex-col gap-2 border-t px-4 pt-6">
                            <PanelActions user={auth.user} className="w-full" />
                        </div>
                    </SheetContent>
                </Sheet>
            </nav>
        </header>
    )
}

/**
 * O acesso ao painel.
 *
 * Quem já tem sessão não precisa de um convite para entrar de novo: vê um
 * atalho para o painel no lugar do par entrar/criar conta.
 */
function PanelActions({ user, className }: { user: AuthUser | null; className?: string }) {
    if (user) {
        return (
            <Button asChild className={className}>
                <Link href="/painel">
                    <LayoutDashboard />
                    Ir para o painel
                </Link>
            </Button>
        )
    }

    return (
        <>
            <Button asChild variant="ghost" className={className}>
                <Link href="/login">Entrar</Link>
            </Button>
            <Button asChild className={className}>
                <Link href="/cadastro">Criar conta</Link>
            </Button>
        </>
    )
}

import { Link } from '@inertiajs/react'
import {
    BookUser,
    Building2,
    ChevronRight,
    ChevronsUpDown,
    FileText,
    FolderCog,
    LayoutDashboard,
    LogOut,
    Monitor,
    Moon,
    Scale,
    Sun,
    UserCog,
    Users,
} from 'lucide-react'
import type { ComponentType } from 'react'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
    useSidebar,
} from '@/components/ui/sidebar'
import { useAppearance, type Appearance } from '@/hooks/use-appearance'
import { initials } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AuthUser } from '@/types'

interface NavItem {
    label: string
    href: string
    icon: ComponentType<{ className?: string }>
    isActive: (path: string) => boolean
}

/**
 * Os cadastros da plataforma, agrupados sob um item só.
 *
 * Um item que o papel não alcança não aparece desabilitado: some. A rota já é
 * barrada no servidor, e um menu cheio de portas fechadas só ensina o que não
 * se pode fazer.
 */
function registrationsFor(user: AuthUser): NavItem[] {
    const usersHref = `/contas/${user.account.id}/usuarios`

    return [
        ...(user.is_platform_admin
            ? [{
                label: 'Contas',
                href: '/contas',
                icon: Building2,
                // A staff member inside a tenant is still browsing the registry.
                isActive: (path: string) => path.startsWith('/contas') && !path.startsWith(usersHref),
            }]
            : []),
        ...(user.role !== 'lawyer'
            ? [{
                label: 'Usuários',
                href: usersHref,
                icon: Users,
                isActive: (path: string) => path.startsWith(usersHref),
            }]
            : []),
        // Todo mundo da conta trabalha com os clientes dela.
        {
            label: 'Clientes',
            href: '/clientes',
            icon: BookUser,
            isActive: (path: string) => path.startsWith('/clientes'),
        },
    ]
}

const THEMES: { value: Appearance; label: string; icon: ComponentType<{ className?: string }> }[] = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Escuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
]

export function AppSidebar({ user, currentPath }: { user: AuthUser; currentPath: string }) {
    const { isMobile } = useSidebar()
    const { appearance, updateAppearance } = useAppearance()

    const registrations = registrationsFor(user)
    const inRegistrations = registrations.some((item) => item.isActive(currentPath))

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/painel">
                                <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                    <Scale className="size-4" />
                                </div>
                                <div className="grid flex-1 text-left leading-tight">
                                    <span className="truncate font-serif text-base font-semibold">LexIA</span>
                                    <span className="truncate text-xs text-muted-foreground" title={user.account.name}>
                                        {user.account.name}
                                    </span>
                                </div>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Navegação</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={currentPath.startsWith('/painel')}
                                    tooltip="Painel"
                                >
                                    <Link href="/painel">
                                        <LayoutDashboard />
                                        <span>Painel</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>

                            {/* Peça é o trabalho do produto, não um cadastro
                                de apoio: fica no primeiro nível, ao lado do
                                Painel. */}
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={currentPath.startsWith('/pecas')}
                                    tooltip="Peças Jurídicas"
                                >
                                    <Link href="/pecas">
                                        <FileText />
                                        <span>Peças Jurídicas</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>

                            {/* Os cadastros num menu só: um dropdown em vez de
                                um submenu que se abre, porque recolhido a
                                trilha de ícones não teria onde desdobrá-lo. */}
                            {registrations.length > 0 && (
                                <SidebarMenuItem>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <SidebarMenuButton isActive={inRegistrations} tooltip="Cadastros">
                                                <FolderCog />
                                                <span>Cadastros</span>
                                                <ChevronRight className="ml-auto" />
                                            </SidebarMenuButton>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            side={isMobile ? 'bottom' : 'right'}
                                            align="start"
                                            sideOffset={4}
                                            className="min-w-48"
                                        >
                                            {registrations.map((item) => (
                                                <DropdownMenuItem key={item.href} asChild>
                                                    <Link
                                                        href={item.href}
                                                        aria-current={item.isActive(currentPath) ? 'page' : undefined}
                                                        className={cn(
                                                            'w-full',
                                                            item.isActive(currentPath) &&
                                                                'bg-accent text-accent-foreground',
                                                        )}
                                                    >
                                                        <item.icon />
                                                        {item.label}
                                                    </Link>
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </SidebarMenuItem>
                            )}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <SidebarMenuButton
                                    size="lg"
                                    className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                                >
                                    <Avatar>
                                        <AvatarFallback>{initials(user.name)}</AvatarFallback>
                                    </Avatar>
                                    <div className="grid flex-1 text-left leading-tight">
                                        <span className="truncate text-sm font-medium">{user.name}</span>
                                        <span className="truncate text-xs text-muted-foreground">{user.role_label}</span>
                                    </div>
                                    <ChevronsUpDown className="ml-auto size-4" />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                                side={isMobile ? 'bottom' : 'right'}
                                align="end"
                                sideOffset={4}
                            >
                                <DropdownMenuLabel className="p-0 font-normal">
                                    <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                        <Avatar>
                                            <AvatarFallback>{initials(user.name)}</AvatarFallback>
                                        </Avatar>
                                        <div className="grid flex-1 leading-tight">
                                            <span className="truncate font-medium">{user.name}</span>
                                            <span className="truncate text-xs text-muted-foreground">{user.email}</span>
                                        </div>
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link href={`/contas/${user.account.id}`}>
                                        <UserCog />
                                        Minha conta
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuRadioGroup
                                    value={appearance}
                                    onValueChange={(value) => updateAppearance(value as Appearance)}
                                >
                                    {THEMES.map((theme) => (
                                        <DropdownMenuRadioItem key={theme.value} value={theme.value}>
                                            <theme.icon className="mr-1 size-4" />
                                            {theme.label}
                                        </DropdownMenuRadioItem>
                                    ))}
                                </DropdownMenuRadioGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem variant="destructive" asChild>
                                    <Link href="/logout" method="post" as="button" className="w-full">
                                        <LogOut />
                                        Sair
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>

            {/* A borda vira alça: arrastar/clicar recolhe o menu. */}
            <SidebarRail />
        </Sidebar>
    )
}

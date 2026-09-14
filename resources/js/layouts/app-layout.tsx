import { usePage } from '@inertiajs/react'
import { SlidersHorizontal, X } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { AppSidebar } from '@/components/app-sidebar'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import type { PageProps } from '@/types'
import { cn } from '@/lib/utils'

interface Props {
    title: string
    /** A line under the title: identifier, status, whatever names the record. */
    subtitle?: ReactNode
    actions?: ReactNode
    /** Rendered under the header, above the scrolling area. */
    tabs?: ReactNode
    /** Contents of the right-hand filter panel; the toggle only appears with it. */
    filters?: ReactNode
    /** How many filters are on, shown on the toggle so a hidden panel is never silent. */
    activeFilters?: number
    children: ReactNode
}

/** O menu recolhido é uma preferência, e o cookie que a guarda é lido aqui. */
const sidebarDefaultOpen = (): boolean => {
    if (typeof document === 'undefined') {
        return true
    }

    return document.cookie.includes('sidebar_state=true') || !document.cookie.includes('sidebar_state=')
}

export function AppLayout({ title, subtitle, actions, tabs, filters, activeFilters = 0, children }: Props) {
    const { auth, flash, url } = usePage<PageProps>().props as PageProps & { url?: string }
    const currentPath = typeof window === 'undefined' ? (url ?? '') : window.location.pathname
    const user = auth.user

    // The panel is component state, and every filter navigation runs with
    // preserveState, so it stays open while the results underneath change.
    const [filtersOpen, setFiltersOpen] = useState(false)

    return (
        // h-screen + overflow-hidden: the page itself never scrolls, so the
        // sidebar keeps its height and only the middle column moves.
        <SidebarProvider defaultOpen={sidebarDefaultOpen()} className="h-screen min-h-0 overflow-hidden">
            {user && <AppSidebar user={user} currentPath={currentPath} />}

            <SidebarInset className="flex min-w-0 flex-col overflow-hidden">
                <header className="shrink-0 border-b bg-card px-4 pt-4 lg:px-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-2">
                            <SidebarTrigger className="-ml-1" />
                            <Separator orientation="vertical" className="mr-1 !h-5" />
                            <div className="min-w-0">
                                <h1 className="truncate text-xl font-semibold text-foreground">{title}</h1>
                                {subtitle && <div className="mt-0.5 text-sm text-muted-foreground">{subtitle}</div>}
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            {actions}
                            {filters && (
                                <Button
                                    type="button"
                                    variant={activeFilters > 0 ? 'secondary' : 'outline'}
                                    onClick={() => setFiltersOpen((open) => !open)}
                                    aria-expanded={filtersOpen}
                                >
                                    <SlidersHorizontal />
                                    Filtros
                                    {activeFilters > 0 && (
                                        <Badge className="h-5 min-w-5 rounded-full px-1 tabular-nums">
                                            {activeFilters}
                                        </Badge>
                                    )}
                                </Button>
                            )}
                        </div>
                    </div>

                    {tabs ? <div className="mt-4 -mb-px pb-4">{tabs}</div> : <div className="h-4" />}
                </header>

                <div className="flex min-h-0 flex-1 overflow-hidden">
                    <div className="min-w-0 flex-1 overflow-y-auto px-4 py-6 lg:px-6">
                        {flash.success && (
                            <Alert variant="success" role="status" className="mb-5">
                                <AlertDescription>{flash.success}</AlertDescription>
                            </Alert>
                        )}
                        {flash.error && (
                            <Alert variant="destructive" className="mb-5">
                                <AlertDescription>{flash.error}</AlertDescription>
                            </Alert>
                        )}

                        {children}
                    </div>

                    {filters && filtersOpen && (
                        <>
                            {/* On a narrow screen the panel covers the content; the
                                scrim is what closes it again. */}
                            <button
                                type="button"
                                aria-label="Fechar filtros"
                                onClick={() => setFiltersOpen(false)}
                                className="fixed inset-0 z-30 bg-black/50 lg:hidden"
                            />
                            <aside
                                className={cn(
                                    'fixed inset-y-0 right-0 z-40 flex w-80 max-w-full flex-col border-l bg-card',
                                    'lg:static lg:z-auto lg:w-80',
                                )}
                            >
                                <div className="flex shrink-0 items-center justify-between border-b px-4 py-3">
                                    <h2 className="text-sm font-semibold">Filtros</h2>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => setFiltersOpen(false)}
                                    >
                                        <X />
                                        <span className="sr-only">Fechar filtros</span>
                                    </Button>
                                </div>
                                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-5">{filters}</div>
                            </aside>
                        </>
                    )}
                </div>
            </SidebarInset>
        </SidebarProvider>
    )
}

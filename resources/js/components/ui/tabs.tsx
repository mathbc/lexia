import { Link } from '@inertiajs/react'
import { cn } from '@/lib/utils'

export interface TabItem {
    label: string
    href: string
    active: boolean
}

/**
 * Navigation between the tabs of one record.
 *
 * Each tab is a real URL rather than local state, so a filtered user list or a
 * half-scrolled form survives a reload and can be linked to. O visual é o do
 * `Tabs` do shadcn — trilho em `muted`, aba ativa levantada em `background` —
 * mas o controle é um link, não o primitivo do Radix: quem guarda o estado é a
 * própria URL.
 */
export function Tabs({ items, className }: { items: TabItem[]; className?: string }) {
    return (
        <nav
            aria-label="Seções"
            className={cn(
                'inline-flex h-9 w-fit items-center justify-center rounded-lg bg-muted p-[3px] text-muted-foreground',
                className,
            )}
        >
            {items.map((item) => (
                <Link
                    key={item.href}
                    href={item.href}
                    aria-current={item.active ? 'page' : undefined}
                    data-state={item.active ? 'active' : 'inactive'}
                    className={cn(
                        "inline-flex h-[calc(100%-1px)] flex-1 items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
                        item.active
                            ? 'border-border bg-background text-foreground shadow-sm'
                            : 'hover:text-foreground',
                    )}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    )
}

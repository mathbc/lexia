import { router } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import type { Paginated } from '@/types'

/**
 * Laravel's paginator links. Shared so the two listings cannot drift.
 */
export function Pagination<T>({ page, label }: { page: Paginated<T>; label: string }) {
    if (page.last_page <= 1) {
        return null
    }

    return (
        <nav aria-label={label} className="mt-4 flex flex-wrap items-center gap-1">
            <p className="mr-auto text-sm text-muted-foreground">
                {page.from ?? 0}–{page.to ?? 0} de {page.total}
            </p>

            {page.links.map((link, index) => (
                <Button
                    key={index}
                    type="button"
                    size="sm"
                    variant={link.active ? 'default' : 'ghost'}
                    disabled={!link.url}
                    aria-current={link.active ? 'page' : undefined}
                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                    // A API devolve os rótulos com entidades HTML (&laquo;).
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    )
}

import { Link, usePage } from '@inertiajs/react'
import type { ReactNode } from 'react'
import type { PageProps } from '@/types'
import { cn } from '@/lib/cn'

interface NavItem {
    label: string
    href: string
    /** Roles allowed to see the entry; omitted means everyone. */
    roles?: string[]
}

const NAV: NavItem[] = [
    { label: 'Painel', href: '/painel' },
    { label: 'Usuários', href: '/usuarios', roles: ['account_admin', 'admin'] },
    // Jurisprudência lands here once the module exists.
]

export function AppLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const { auth, flash, url } = usePage<PageProps>().props as PageProps & { url?: string }
    const currentPath = typeof window === 'undefined' ? (url ?? '') : window.location.pathname
    const user = auth.user

    const visible = NAV.filter((item) => !item.roles || (user && item.roles.includes(user.role)))

    return (
        <div className="flex min-h-full">
            <aside className="hidden w-60 shrink-0 flex-col border-r border-ink-200 bg-white px-4 py-6 lg:flex">
                <Link href="/painel" className="px-2 font-serif text-2xl font-semibold text-brand-700">
                    LexIA
                </Link>

                {user && (
                    <p className="mt-1 truncate px-2 text-xs text-ink-400" title={user.account.name}>
                        {user.account.name}
                    </p>
                )}

                <nav className="mt-8 flex flex-col gap-1">
                    {visible.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={cn(
                                'rounded-md px-2 py-2 text-sm font-medium transition-colors',
                                currentPath.startsWith(item.href)
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-ink-600 hover:bg-ink-100',
                            )}
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>

                {user && (
                    <div className="mt-auto border-t border-ink-200 pt-4">
                        <Link href={`/conta/${user.account.id}`} className="block px-2 text-sm font-medium text-ink-700 hover:text-brand-700">
                            Minha conta
                        </Link>
                        <p className="mt-3 px-2 text-sm font-medium text-ink-800">{user.name}</p>
                        <p className="px-2 text-xs text-ink-400">{user.role_label}</p>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="mt-2 px-2 text-xs text-ink-500 hover:text-red-600"
                        >
                            Sair
                        </Link>
                    </div>
                )}
            </aside>

            <main className="flex-1 px-6 py-8 lg:px-10">
                <header className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    {actions}
                </header>

                {flash.success && (
                    <div role="status" className="mb-5 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-100">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div role="alert" className="mb-5 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-100">
                        {flash.error}
                    </div>
                )}

                {children}
            </main>
        </div>
    )
}

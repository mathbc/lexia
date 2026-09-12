import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input, Select } from '@/components/ui/field'
import type { Option, Paginated, PageProps, UserRow } from '@/types'

interface Props {
    users: Paginated<UserRow>
    filters: { search?: string; role?: string; enabled?: string }
    assignableRoles: Option[]
    types: Option[]
}

export default function UsersIndex({ users, filters, assignableRoles, types }: Props) {
    const { auth } = usePage<PageProps>().props
    const [search, setSearch] = useState(filters.search ?? '')

    // preserveState keeps focus and scroll; replace avoids stacking history
    // entries for every keystroke-driven filter change.
    const apply = (next: Record<string, string | undefined>) => {
        router.get('/usuarios', { ...filters, ...next }, { preserveState: true, replace: true })
    }

    const label = (options: Option[], value: string) =>
        options.find((option) => option.value === value)?.label ?? value

    return (
        <AppLayout
            title="Usuários"
            actions={
                <Link href="/usuarios/novo">
                    <Button>Novo usuário</Button>
                </Link>
            }
        >
            <Head title="Usuários" />

            <div className="mb-4 flex flex-wrap gap-3">
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        apply({ search })
                    }}
                    className="min-w-56 flex-1"
                >
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Buscar por nome, e-mail ou OAB"
                        aria-label="Buscar usuários"
                    />
                </form>

                <Select
                    value={filters.role ?? ''}
                    onChange={(e) => apply({ role: e.target.value || undefined })}
                    options={assignableRoles}
                    placeholder="Todos os perfis"
                    aria-label="Filtrar por perfil"
                    className="w-48"
                />

                <Select
                    value={filters.enabled ?? ''}
                    onChange={(e) => apply({ enabled: e.target.value || undefined })}
                    options={[
                        { value: '1', label: 'Habilitados' },
                        { value: '0', label: 'Desabilitados' },
                    ]}
                    placeholder="Todos os status"
                    aria-label="Filtrar por status"
                    className="w-44"
                />
            </div>

            <div className="overflow-x-auto rounded-lg bg-white ring-1 ring-ink-200">
                <table className="min-w-full divide-y divide-ink-200 text-sm">
                    <thead className="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-500">
                        <tr>
                            <th scope="col" className="px-4 py-3 font-medium">Nome</th>
                            <th scope="col" className="px-4 py-3 font-medium">Perfil</th>
                            <th scope="col" className="px-4 py-3 font-medium">Tipo</th>
                            <th scope="col" className="px-4 py-3 font-medium">OAB</th>
                            <th scope="col" className="px-4 py-3 font-medium">Status</th>
                            <th scope="col" className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-ink-100">
                        {users.data.map((user) => (
                            <tr key={user.id} className="hover:bg-ink-50">
                                <td className="px-4 py-3">
                                    <div className="font-medium text-ink-900">{user.name}</div>
                                    <div className="text-xs text-ink-400">{user.email}</div>
                                </td>
                                <td className="px-4 py-3">{label(assignableRoles, user.role)}</td>
                                <td className="px-4 py-3">{label(types, user.type)}</td>
                                <td className="tabular px-4 py-3 text-ink-500">
                                    {user.oab_number ? `${user.oab_state}/${user.oab_number}` : '—'}
                                </td>
                                <td className="px-4 py-3">
                                    <Badge tone={user.enabled ? 'success' : 'muted'}>
                                        {user.enabled ? 'Habilitado' : 'Desabilitado'}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {auth.user?.id !== user.id && (
                                        <Link
                                            href={`/usuarios/${user.id}/editar`}
                                            className="text-sm font-medium text-brand-600 hover:text-brand-700"
                                        >
                                            Editar
                                        </Link>
                                    )}
                                </td>
                            </tr>
                        ))}

                        {users.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-12 text-center text-ink-400">
                                    Nenhum usuário encontrado.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {users.last_page > 1 && (
                <nav aria-label="Paginação" className="mt-4 flex flex-wrap gap-1">
                    {users.links.map((link, index) => (
                        <button
                            key={index}
                            type="button"
                            disabled={!link.url}
                            aria-current={link.active ? 'page' : undefined}
                            onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                            className={
                                link.active
                                    ? 'rounded bg-brand-600 px-3 py-1.5 text-sm text-white'
                                    : 'rounded px-3 py-1.5 text-sm text-ink-600 hover:bg-ink-100 disabled:opacity-40'
                            }
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </AppLayout>
    )
}

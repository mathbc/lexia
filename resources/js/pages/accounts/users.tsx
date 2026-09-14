import { Head, Link, router, usePage } from '@inertiajs/react'
import { Pencil, Plus } from 'lucide-react'
import { useState } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { AccountTabs } from '@/components/account-tabs'
import { Pagination } from '@/components/pagination'
import { RowActions } from '@/components/row-actions'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { accountIdentifier } from '@/lib/format'
import type { Account, AccountAbilities, Option, Paginated, PageProps, UserRow } from '@/types'

interface Filters {
    search?: string
    role?: string
    type?: string
    enabled?: string
}

interface Props {
    account: Account
    can: AccountAbilities
    users: Paginated<UserRow>
    filters: Filters
    roles: Option[]
    types: Option[]
    canCreate: boolean
}

export default function AccountUsers({ account, can, users, filters, roles, types, canCreate }: Props) {
    const { auth } = usePage<PageProps>().props
    const [search, setSearch] = useState(filters.search ?? '')

    const base = `/contas/${account.id}/usuarios`

    // preserveState keeps focus, scroll and the open filter panel; replace
    // avoids stacking a history entry for every filter change.
    const apply = (next: Filters) => {
        router.get(base, { ...filters, ...next }, { preserveState: true, replace: true })
    }

    const clear = () => {
        setSearch('')
        router.get(base, {}, { preserveState: true, replace: true })
    }

    const label = (options: Option[], value: string) =>
        options.find((option) => option.value === value)?.label ?? value

    const activeFilters = [filters.search, filters.role, filters.type, filters.enabled].filter(Boolean).length

    const identifier = accountIdentifier(account)

    return (
        <AppLayout
            title={account.legal_name ?? account.name}
            subtitle={
                <span className="flex items-center gap-2">
                    {identifier && <span className="tabular">{identifier}</span>}
                    <Badge variant={account.active ? 'success' : 'muted'}>
                        {account.active ? 'Ativa' : 'Inativa'}
                    </Badge>
                </span>
            }
            tabs={<AccountTabs accountId={account.id} can={can} current="users" />}
            actions={
                canCreate && (
                    <Button asChild>
                        <Link href={`${base}/novo`}>
                            <Plus />
                            Novo usuário
                        </Link>
                    </Button>
                )
            }
            activeFilters={activeFilters}
            filters={
                <>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault()
                            apply({ search })
                        }}
                    >
                        <Field label="Busca" hint="Nome, e-mail ou OAB">
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar"
                                aria-label="Buscar usuários"
                            />
                        </Field>
                    </form>

                    <Field label="Perfil">
                        <Select
                            value={filters.role ?? ''}
                            onValueChange={(value) => apply({ role: value || undefined })}
                            options={roles}
                            placeholder="Todos os perfis"
                            clearable
                        />
                    </Field>

                    <Field label="Tipo">
                        <Select
                            value={filters.type ?? ''}
                            onValueChange={(value) => apply({ type: value || undefined })}
                            options={types}
                            placeholder="Todos os tipos"
                            clearable
                        />
                    </Field>

                    <Field label="Status">
                        <Select
                            value={filters.enabled ?? ''}
                            onValueChange={(value) => apply({ enabled: value || undefined })}
                            options={[
                                { value: '1', label: 'Habilitados' },
                                { value: '0', label: 'Desabilitados' },
                            ]}
                            placeholder="Todos os status"
                            clearable
                        />
                    </Field>

                    {activeFilters > 0 && (
                        <Button type="button" variant="outline" className="w-full" onClick={clear}>
                            Limpar filtros
                        </Button>
                    )}
                </>
            }
        >
            <Head title={`Usuários · ${account.name}`} />

            <Card className="gap-0 overflow-hidden py-0">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Nome</TableHead>
                            <TableHead>Perfil</TableHead>
                            <TableHead>Tipo</TableHead>
                            <TableHead>OAB</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Ações</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {users.data.map((user) => (
                            <TableRow key={user.id}>
                                <TableCell className="py-3 whitespace-normal">
                                    <div className="font-medium text-foreground">{user.name}</div>
                                    <div className="text-xs text-muted-foreground">{user.email}</div>
                                </TableCell>
                                <TableCell className="py-3">{label(roles, user.role)}</TableCell>
                                <TableCell className="py-3">{label(types, user.type)}</TableCell>
                                <TableCell className="tabular py-3 text-muted-foreground">
                                    {user.oab_number ? `${user.oab_state}/${user.oab_number}` : '—'}
                                </TableCell>
                                <TableCell className="py-3">
                                    <Badge variant={user.enabled ? 'success' : 'muted'}>
                                        {user.enabled ? 'Habilitado' : 'Desabilitado'}
                                    </Badge>
                                </TableCell>
                                <TableCell className="py-3 text-right">
                                    <RowActions
                                        label={`Ações de ${user.name}`}
                                        actions={[
                                            // Ninguém se gerencia a partir da
                                            // listagem: o próprio cadastro vive
                                            // no menu do usuário.
                                            auth.user?.id !== user.id && {
                                                label: 'Editar',
                                                icon: Pencil,
                                                href: `${base}/${user.id}/editar`,
                                            },
                                        ]}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}

                        {users.data.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={6} className="py-12 text-center text-muted-foreground">
                                    Nenhum usuário encontrado.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </Card>

            <Pagination page={users} label="Paginação de usuários" />
        </AppLayout>
    )
}

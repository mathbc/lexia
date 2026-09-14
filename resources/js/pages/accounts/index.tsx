import { Head, Link, router } from '@inertiajs/react'
import { Eye, Plus, Users } from 'lucide-react'
import { useState } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Pagination } from '@/components/pagination'
import { RowActions } from '@/components/row-actions'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { accountIdentifier } from '@/lib/format'
import type { AccountRow, Option, Paginated } from '@/types'

interface Filters {
    search?: string
    type?: string
    active?: string
}

interface Props {
    accounts: Paginated<AccountRow>
    filters: Filters
    accountTypes: Option[]
    canCreate: boolean
}

export default function AccountsIndex({ accounts, filters, accountTypes, canCreate }: Props) {
    const [search, setSearch] = useState(filters.search ?? '')

    const apply = (next: Filters) => {
        router.get('/contas', { ...filters, ...next }, { preserveState: true, replace: true })
    }

    const clear = () => {
        setSearch('')
        router.get('/contas', {}, { preserveState: true, replace: true })
    }

    const label = (value: string) =>
        accountTypes.find((option) => option.value === value)?.label ?? value

    const activeFilters = [filters.search, filters.type, filters.active].filter(Boolean).length

    return (
        <AppLayout
            title="Contas"
            actions={
                canCreate && (
                    <Button asChild>
                        <Link href="/contas/nova">
                            <Plus />
                            Nova conta
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
                        <Field label="Busca" hint="Nome, razão social, e-mail, CNPJ ou OAB">
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar"
                                aria-label="Buscar contas"
                            />
                        </Field>
                    </form>

                    <Field label="Tipo">
                        <Select
                            value={filters.type ?? ''}
                            onValueChange={(value) => apply({ type: value || undefined })}
                            options={accountTypes}
                            placeholder="Todos os tipos"
                            clearable
                        />
                    </Field>

                    <Field label="Status">
                        <Select
                            value={filters.active ?? ''}
                            onValueChange={(value) => apply({ active: value || undefined })}
                            options={[
                                { value: '1', label: 'Ativas' },
                                { value: '0', label: 'Inativas' },
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
            <Head title="Contas" />

            <Card className="gap-0 overflow-hidden py-0">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Conta</TableHead>
                            <TableHead>Tipo</TableHead>
                            <TableHead>Documento</TableHead>
                            <TableHead>Cidade</TableHead>
                            <TableHead>Usuários</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Ações</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {accounts.data.map((account) => (
                            <TableRow key={account.id}>
                                {/* O nome é a única coluna que pode quebrar:
                                    sem isso uma razão social longa empurra a
                                    tabela para fora do cartão. */}
                                <TableCell className="py-3 whitespace-normal">
                                    <div className="font-medium text-foreground">
                                        {account.legal_name ?? account.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">{account.email}</div>
                                </TableCell>
                                <TableCell className="py-3">{label(account.type)}</TableCell>
                                <TableCell className="tabular py-3 text-muted-foreground">
                                    {accountIdentifier(account) ?? '—'}
                                </TableCell>
                                <TableCell className="py-3 text-muted-foreground">
                                    {account.city}/{account.state}
                                </TableCell>
                                <TableCell className="tabular py-3 text-muted-foreground">
                                    {account.users_count}
                                </TableCell>
                                <TableCell className="py-3">
                                    <Badge variant={account.active ? 'success' : 'muted'}>
                                        {account.active ? 'Ativa' : 'Inativa'}
                                    </Badge>
                                </TableCell>
                                <TableCell className="py-3 text-right">
                                    <RowActions
                                        label={`Ações de ${account.name}`}
                                        actions={[
                                            {
                                                label: 'Abrir',
                                                icon: Eye,
                                                href: `/contas/${account.id}`,
                                            },
                                            {
                                                label: 'Usuários',
                                                icon: Users,
                                                href: `/contas/${account.id}/usuarios`,
                                            },
                                        ]}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}

                        {accounts.data.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={7} className="py-12 text-center text-muted-foreground">
                                    Nenhuma conta encontrada.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </Card>

            <Pagination page={accounts} label="Paginação de contas" />
        </AppLayout>
    )
}

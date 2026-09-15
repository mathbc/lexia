import { Head, Link, router } from '@inertiajs/react'
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Pagination } from '@/components/pagination'
import { RowActions } from '@/components/row-actions'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { customerIdentifier, formatPhone } from '@/lib/format'
import type { Customer, CustomerAbilities, Option, Paginated } from '@/types'

interface Filters {
    search?: string
    type?: string
}

interface Props {
    customers: Paginated<Customer>
    filters: Filters
    customerTypes: Option[]
    can: CustomerAbilities & { create: boolean }
}

export default function CustomersIndex({ customers, filters, customerTypes, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '')

    // preserveState mantém foco, rolagem e o painel de filtros aberto;
    // replace evita empilhar histórico a cada filtro.
    const apply = (next: Filters) => {
        router.get('/clientes', { ...filters, ...next }, { preserveState: true, replace: true })
    }

    const clear = () => {
        setSearch('')
        router.get('/clientes', {}, { preserveState: true, replace: true })
    }

    const label = (value: string) => customerTypes.find((option) => option.value === value)?.label ?? value

    const activeFilters = [filters.search, filters.type].filter(Boolean).length

    return (
        <AppLayout
            title="Clientes"
            actions={
                can.create && (
                    <Button asChild>
                        <Link href="/clientes/novo">
                            <Plus />
                            Novo cliente
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
                        <Field label="Busca" hint="Nome, razão social, e-mail, CPF ou CNPJ">
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar"
                                aria-label="Buscar clientes"
                            />
                        </Field>
                    </form>

                    <Field label="Tipo">
                        <Select
                            value={filters.type ?? ''}
                            onValueChange={(value) => apply({ type: value || undefined })}
                            options={customerTypes}
                            placeholder="Todos os tipos"
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
            <Head title="Clientes" />

            <Card className="gap-0 overflow-hidden py-0">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Cliente</TableHead>
                            <TableHead>Tipo</TableHead>
                            <TableHead>Documento</TableHead>
                            <TableHead>Telefone</TableHead>
                            <TableHead>Cidade</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Ações</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {customers.data.map((customer) => (
                            <TableRow key={customer.id}>
                                {/* O nome é a única coluna que pode quebrar:
                                    sem isso uma razão social longa empurra a
                                    tabela para fora do cartão. */}
                                <TableCell className="py-3 whitespace-normal">
                                    <div className="font-medium text-foreground">
                                        {customer.legal_name ?? customer.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">{customer.email}</div>
                                </TableCell>
                                <TableCell className="py-3">{label(customer.type)}</TableCell>
                                <TableCell className="tabular py-3 text-muted-foreground">
                                    {customerIdentifier(customer) ?? '—'}
                                </TableCell>
                                <TableCell className="tabular py-3 text-muted-foreground">
                                    {formatPhone(customer.phone)}
                                </TableCell>
                                <TableCell className="py-3 text-muted-foreground">
                                    {customer.city}/{customer.state}
                                </TableCell>
                                <TableCell className="py-3 text-right">
                                    <RowActions
                                        label={`Ações de ${customer.name}`}
                                        actions={[
                                            {
                                                label: can.update ? 'Abrir' : 'Visualizar',
                                                icon: can.update ? Pencil : Eye,
                                                href: `/clientes/${customer.id}`,
                                            },
                                            can.delete && {
                                                label: 'Excluir',
                                                icon: Trash2,
                                                href: `/clientes/${customer.id}`,
                                                method: 'delete',
                                                destructive: true,
                                                confirm: `Excluir ${customer.name}? A ação não pode ser desfeita.`,
                                                separated: true,
                                            },
                                        ]}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}

                        {customers.data.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={6} className="py-12 text-center text-muted-foreground">
                                    Nenhum cliente encontrado.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </Card>

            <Pagination page={customers} label="Paginação de clientes" />
        </AppLayout>
    )
}

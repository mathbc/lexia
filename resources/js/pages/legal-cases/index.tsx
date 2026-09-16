import { Head, Link, router } from '@inertiajs/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { Pagination } from '@/components/pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { formatDate } from '@/lib/format'
import type { LegalCaseAbilities, LegalCaseCard, Option, Paginated } from '@/types'

interface Filters {
    search?: string
    customer?: string
    practice_area?: string
}

interface Props {
    legalCases: Paginated<LegalCaseCard>
    filters: Filters
    customers: Option[]
    practiceAreas: Option[]
    can: LegalCaseAbilities
}

export default function LegalCasesIndex({ legalCases, filters, customers, practiceAreas, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '')

    // preserveState mantém foco, rolagem e o painel de filtros aberto;
    // replace evita empilhar histórico a cada filtro.
    const apply = (next: Filters) => {
        router.get('/pecas', { ...filters, ...next }, { preserveState: true, replace: true })
    }

    const clear = () => {
        setSearch('')
        router.get('/pecas', {}, { preserveState: true, replace: true })
    }

    const activeFilters = [filters.search, filters.customer, filters.practice_area].filter(Boolean).length

    return (
        <AppLayout
            title="Peças Jurídicas"
            actions={
                can.create && (
                    <Button asChild>
                        <Link href="/pecas/nova">
                            <Plus />
                            Nova peça
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
                        <Field label="Busca" hint="Cliente, classe processual ou código CNJ">
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar"
                                aria-label="Buscar peças"
                            />
                        </Field>
                    </form>

                    <Field label="Cliente">
                        <Select
                            value={filters.customer ?? ''}
                            onValueChange={(value) => apply({ customer: value || undefined })}
                            options={customers}
                            placeholder="Todos os clientes"
                            clearable
                        />
                    </Field>

                    <Field label="Área de atuação">
                        <Select
                            value={filters.practice_area ?? ''}
                            onValueChange={(value) => apply({ practice_area: value || undefined })}
                            options={practiceAreas}
                            placeholder="Todas as áreas"
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
            <Head title="Peças Jurídicas" />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {legalCases.data.map((legalCase) => (
                    <Card key={legalCase.id} className="gap-4 py-5">
                        <CardHeader className="px-5">
                            <CardTitle className="text-base">{legalCase.customer.display_name}</CardTitle>
                            <CardDescription>{legalCase.practice_area.label}</CardDescription>
                        </CardHeader>

                        <CardContent className="flex-1 px-5">
                            <p className="text-sm text-foreground">{legalCase.procedural_class.name}</p>
                            <p className="tabular mt-1 text-xs text-muted-foreground">
                                Classe CNJ {legalCase.procedural_class.code}
                                {legalCase.procedural_class.abbreviation &&
                                    ` · ${legalCase.procedural_class.abbreviation}`}
                            </p>
                        </CardContent>

                        <CardFooter className="px-5">
                            <Badge variant="muted">Criada em {formatDate(legalCase.created_at)}</Badge>
                        </CardFooter>
                    </Card>
                ))}
            </div>

            {legalCases.data.length === 0 && (
                <Card className="items-center py-12 text-center text-muted-foreground">
                    Nenhuma peça encontrada.
                </Card>
            )}

            <Pagination page={legalCases} label="Paginação de peças" />
        </AppLayout>
    )
}

import { Head, router, usePage } from '@inertiajs/react'
import { Building2, FileCheck, FilePen, Users } from 'lucide-react'
import { AppLayout } from '@/layouts/app-layout'
import { IndicatorCard } from '@/components/dashboard/indicator-card'
import { LegalCaseActivityChart, type MonthActivity } from '@/components/dashboard/legal-case-activity-chart'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Select } from '@/components/ui/field'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Option, PageProps } from '@/types'

/** Only the filters the server accepted: a refused value never comes back. */
interface Filters {
    account?: string
    year?: number
    user?: string
}

interface Props {
    /** Of the account in view — for LexIA staff, the chosen one or all of them. */
    indicators: { finalized: number; drafts: number; users: number }
    /** Null for anyone who is not LexIA staff: the numbers never leave the server. */
    platform: { accounts: number; legal_cases: number; users: number } | null
    /** `year` is the one in effect, which is the newest when none was chosen. */
    activity: { year: number | null; months: MonthActivity[] }
    /** Only the years that have pleadings, newest first. */
    years: number[]
    accounts: Option[]
    users: Option[]
    filters: Filters
    can: { view_accounts: boolean }
}

export default function Dashboard({ indicators, platform, activity, years, accounts, users, filters, can }: Props) {
    const { auth } = usePage<PageProps>().props
    const user = auth.user

    const apply = (next: Record<string, string | number | undefined>) => {
        router.get('/painel', { ...filters, ...next }, { preserveState: true, replace: true })
    }

    const clear = () => {
        router.get('/painel', {}, { preserveState: true, replace: true })
    }

    const activeFilters = [filters.account, filters.year, filters.user].filter(Boolean).length

    // What the cards count over, said only to LexIA staff: a customer has one
    // account, and naming it on every card would only repeat the sidebar.
    const scope = can.view_accounts
        ? (accounts.find((option) => option.value === filters.account)?.label ?? 'Todas as contas')
        : undefined

    const chosenUser = users.find((option) => option.value === filters.user)?.label
    const person = chosenUser ?? 'Todos os usuários'
    const yearTotal = activity.months.reduce((sum, entry) => sum + entry.finalized + entry.drafts, 0)
    const pickAccountFirst = can.view_accounts && !filters.account

    return (
        <AppLayout
            title="Painel"
            subtitle={user && `Bem-vindo, ${user.name}.`}
            activeFilters={activeFilters}
            filters={
                <>
                    {can.view_accounts && (
                        <Field label="Conta">
                            <Select
                                value={filters.account ?? ''}
                                // The user belongs to the account, so a new
                                // account drops it; the year stays, and the
                                // server moves it if the account lacks it.
                                onValueChange={(value) => apply({ account: value || undefined, user: undefined })}
                                options={accounts}
                                placeholder="Todas as contas"
                                clearable
                            />
                        </Field>
                    )}

                    <Field label="Ano" hint="Só os anos que têm peças cadastradas.">
                        <Select
                            value={activity.year ? String(activity.year) : ''}
                            onValueChange={(value) => apply({ year: value || undefined })}
                            options={years.map((year) => ({ value: String(year), label: String(year) }))}
                            placeholder="Nenhuma peça cadastrada"
                            disabled={years.length === 0}
                        />
                    </Field>

                    <Field
                        label="Usuário"
                        hint={
                            pickAccountFirst
                                ? 'Escolha uma conta para filtrar por usuário.'
                                : 'Filtra o gráfico; os cards seguem com a conta inteira.'
                        }
                    >
                        <Select
                            value={filters.user ?? ''}
                            onValueChange={(value) => apply({ user: value || undefined })}
                            options={users}
                            placeholder="Todos os usuários"
                            clearable
                            disabled={users.length === 0}
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
            <Head title="Painel" />

            {/* Container queries rather than breakpoints: the filter panel takes
                width from this column, not from the window. */}
            <div className="@container space-y-4">
                <div
                    className={cn(
                        'grid gap-4',
                        platform ? '@lg:grid-cols-2 @4xl:grid-cols-4' : '@2xl:grid-cols-3',
                    )}
                >
                    <IndicatorCard
                        label="Peças finalizadas"
                        value={indicators.finalized}
                        icon={FileCheck}
                        description={scope}
                    />
                    <IndicatorCard
                        label="Peças em rascunho"
                        value={indicators.drafts}
                        icon={FilePen}
                        description={scope}
                    />
                    <IndicatorCard label="Usuários" value={indicators.users} icon={Users} description={scope} />

                    {platform && (
                        <IndicatorCard
                            label="Contas cadastradas"
                            value={platform.accounts}
                            icon={Building2}
                            description="Todas as contas de clientes"
                        >
                            <dl className="grid grid-cols-2 gap-2">
                                <div>
                                    <dt>Peças</dt>
                                    <dd className="text-base font-medium text-foreground">
                                        {formatNumber(platform.legal_cases)}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Usuários</dt>
                                    <dd className="text-base font-medium text-foreground">
                                        {formatNumber(platform.users)}
                                    </dd>
                                </div>
                            </dl>
                        </IndicatorCard>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Movimentação mensal de peças</CardTitle>
                        <CardDescription>
                            {activity.year
                                ? [activity.year, scope, person].filter(Boolean).join(' · ')
                                : 'Peças abertas em cada mês, pela situação de hoje.'}
                        </CardDescription>
                        {activity.year && (
                            <CardAction className="text-sm text-muted-foreground">
                                {formatNumber(yearTotal)} {yearTotal === 1 ? 'peça' : 'peças'}
                            </CardAction>
                        )}
                    </CardHeader>

                    <CardContent>
                        {activity.year && yearTotal > 0 ? (
                            <LegalCaseActivityChart year={activity.year} months={activity.months} />
                        ) : (
                            <p className="py-16 text-center text-sm text-muted-foreground">
                                {activity.year
                                    ? `Nenhuma peça cadastrada${chosenUser ? ` por ${chosenUser}` : ''} em ${activity.year}.`
                                    : 'Nenhuma peça cadastrada ainda.'}
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    )
}

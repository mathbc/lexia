import { Bar, BarChart, BarStack, CartesianGrid, XAxis, YAxis } from 'recharts'
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart'
import { formatNumber } from '@/lib/format'

export interface MonthActivity {
    /** 1 a 12. */
    month: number
    finalized: number
    drafts: number
}

// Written out rather than asked of Intl, which abbreviates with a trailing dot.
const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']

const MONTH_NAMES = [
    'Janeiro',
    'Fevereiro',
    'Março',
    'Abril',
    'Maio',
    'Junho',
    'Julho',
    'Agosto',
    'Setembro',
    'Outubro',
    'Novembro',
    'Dezembro',
]

/**
 * Two greys from the chart ramp, far apart in lightness so the pair reads
 * without colour — the palette has none to give. The tokens already swap for
 * the dark theme, where the finished pleadings become the brightest mark.
 */
const config = {
    finalized: { label: 'Finalizadas', color: 'var(--chart-1)' },
    drafts: { label: 'Rascunhos', color: 'var(--chart-4)' },
} satisfies ChartConfig

interface LegalCaseActivityChartProps {
    year: number
    months: MonthActivity[]
}

/**
 * Pleadings opened in each month of the year, stacked by where they stand
 * today. Finished ones sit on the baseline, drafts on top; the stack, not each
 * segment, gets the rounded end.
 */
export function LegalCaseActivityChart({ year, months }: LegalCaseActivityChartProps) {
    const data = months.map((entry) => ({ ...entry, label: MONTHS[entry.month - 1] }))

    return (
        <>
            <ChartContainer config={config} className="aspect-auto h-72 w-full">
                <BarChart data={data} margin={{ top: 8, right: 8, left: -12 }}>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} />
                    <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={40} />
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                labelFormatter={(_, payload) =>
                                    `${MONTH_NAMES[(payload[0]?.payload?.month ?? 1) - 1]} de ${year}`
                                }
                            />
                        }
                    />
                    {/* Declaration order, not the default alphabetical one: the
                        legend reads bottom-up like the stack and the tooltip. */}
                    <ChartLegend content={<ChartLegendContent />} itemSorter={null} />
                    <BarStack stackId="legal-cases" radius={[4, 4, 0, 0]}>
                        {/* The card-coloured stroke is the 2px gap between segments. */}
                        <Bar dataKey="finalized" fill="var(--color-finalized)" stroke="var(--card)" strokeWidth={2} />
                        <Bar dataKey="drafts" fill="var(--color-drafts)" stroke="var(--card)" strokeWidth={2} />
                    </BarStack>
                </BarChart>
            </ChartContainer>

            {/* The same numbers for whoever cannot see the bars. */}
            <table className="sr-only">
                <caption>Peças cadastradas por mês em {year}</caption>
                <thead>
                    <tr>
                        <th scope="col">Mês</th>
                        <th scope="col">{config.finalized.label}</th>
                        <th scope="col">{config.drafts.label}</th>
                    </tr>
                </thead>
                <tbody>
                    {months.map((entry) => (
                        <tr key={entry.month}>
                            <th scope="row">{MONTH_NAMES[entry.month - 1]}</th>
                            <td>{formatNumber(entry.finalized)}</td>
                            <td>{formatNumber(entry.drafts)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    )
}

import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { formatNumber } from '@/lib/format'

interface IndicatorCardProps {
    label: string
    value: number
    icon: LucideIcon
    /** What the number counts over. One line: a long account name is cut. */
    description?: string
    /** Figures that break the headline down, under the description. */
    children?: ReactNode
}

/**
 * One headline number. The value keeps proportional figures on purpose: it
 * stands alone, and `tabular` is for columns that have to line up.
 */
export function IndicatorCard({ label, value, icon: Icon, description, children }: IndicatorCardProps) {
    return (
        <Card className="gap-4">
            <CardHeader>
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-3xl">{formatNumber(value)}</CardTitle>
                <CardAction className="text-muted-foreground">
                    <Icon className="size-4" aria-hidden />
                </CardAction>
            </CardHeader>

            {(description || children) && (
                <CardContent className="space-y-2 text-sm text-muted-foreground">
                    {description && (
                        <p className="truncate" title={description}>
                            {description}
                        </p>
                    )}
                    {children}
                </CardContent>
            )}
        </Card>
    )
}

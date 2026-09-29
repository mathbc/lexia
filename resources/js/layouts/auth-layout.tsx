import type { ReactNode } from 'react'
import { BrandLogo } from '@/components/landing/brand-logo'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

/**
 * A moldura das telas de acesso: marca, cartão centrado e um rodapé opcional.
 *
 * Login, recuperação e confirmação de senha são a mesma cena com textos
 * diferentes — deixá-las divergirem é como o cadastro passa a parecer outro
 * produto.
 */
export function AuthLayout({
    title,
    description,
    footer,
    children,
}: {
    title: string
    description?: ReactNode
    footer?: ReactNode
    children: ReactNode
}) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 px-6 py-12">
            <BrandLogo className="text-3xl text-foreground" />

            <Card className="w-full max-w-sm">
                <CardHeader className="text-center">
                    <CardTitle className="text-lg">{title}</CardTitle>
                    {description && <CardDescription>{description}</CardDescription>}
                </CardHeader>
                <CardContent>{children}</CardContent>
            </Card>

            {footer && <div className="text-center text-sm text-muted-foreground">{footer}</div>}
        </div>
    )
}

import { CircleCheckIcon, InfoIcon, LoaderCircleIcon, OctagonXIcon, TriangleAlertIcon } from 'lucide-react'
import type { CSSProperties } from 'react'
import { Toaster as Sonner, type ToasterProps } from 'sonner'

/**
 * O `sonner` do shadcn, sem o `next-themes` que o original traz.
 *
 * Não precisa de `theme`: as cores do toast são os tokens, e eles já trocam
 * sozinhos com a classe `.dark` na raiz. Cor, só no ícone — o verde do sucesso
 * e o vermelho do erro —, como no resto da paleta.
 */
function Toaster(props: ToasterProps) {
    return (
        <Sonner
            className="toaster group"
            icons={{
                success: <CircleCheckIcon className="size-4 text-success" />,
                info: <InfoIcon className="size-4" />,
                warning: <TriangleAlertIcon className="size-4" />,
                error: <OctagonXIcon className="size-4 text-destructive" />,
                loading: <LoaderCircleIcon className="size-4 animate-spin" />,
            }}
            toastOptions={{ classNames: { description: '!text-muted-foreground' } }}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                    '--border-radius': 'var(--radius)',
                } as CSSProperties
            }
            {...props}
        />
    )
}

export { Toaster }

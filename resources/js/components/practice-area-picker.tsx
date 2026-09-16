import { Button } from '@/components/ui/button'
import type { Option } from '@/types'

interface Props {
    areas: Option[]
    /** O slug da área escolhida, ou '' enquanto não há uma. */
    value: string
    onSelect: (slug: string) => void
    disabled?: boolean
}

/**
 * As 24 áreas de atuação como botões.
 *
 * Um grid em vez de um select porque a área é a primeira decisão da peça e o
 * conjunto é pequeno e estável — ver tudo de uma vez vale mais que economizar
 * altura. Sem primitivo novo do shadcn: `role="radio"` e `aria-checked` dão a
 * semântica de escolha única que o Button sozinho não tem.
 */
export function PracticeAreaPicker({ areas, value, onSelect, disabled }: Props) {
    return (
        <div
            role="radiogroup"
            aria-label="Área de atuação"
            className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
        >
            {areas.map((area) => {
                const selected = value === area.value

                return (
                    <Button
                        key={area.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        disabled={disabled}
                        variant={selected ? 'default' : 'outline'}
                        // h-auto e whitespace-normal porque "Direito
                        // Administrativo e Fazenda Pública" não cabe numa linha.
                        className="h-auto justify-start py-2.5 text-left leading-snug whitespace-normal"
                        onClick={() => onSelect(area.value)}
                    >
                        {area.label}
                    </Button>
                )
            })}
        </div>
    )
}

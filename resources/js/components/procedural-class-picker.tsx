import { useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Field, Input, Select } from '@/components/ui/field'
import { cn } from '@/lib/utils'
import type { JurisdictionTag, Option, ProceduralClassOption } from '@/types'

/** Quantas competências cabem num card antes de virarem "+N". */
const MAX_BADGES = 4

interface Props {
    classes: ProceduralClassOption[]
    branches: Option[]
    degrees: Option[]
    /** O id da classe escolhida, ou '' enquanto não há uma. */
    value: string
    onSelect: (id: string) => void
}

/**
 * Ramo e grau precisam ser satisfeitos pela MESMA competência: "Federal + 1º
 * grau" é `just_fed_1grau`, e não uma classe que tenha `just_fed_2grau` de um
 * lado e `just_es_1grau` do outro.
 */
const matchesInstance = (tags: JurisdictionTag[], branch: string, degree: string): boolean =>
    tags.some((tag) => (!branch || tag.branch === branch) && (!degree || tag.degree === degree))

const matchesSearch = (item: ProceduralClassOption, term: string): boolean => {
    const needle = term.trim().toLowerCase()

    if (needle === '') {
        return true
    }

    return item.name.toLowerCase().includes(needle) || String(item.code).includes(needle)
}

/**
 * O catálogo do CNJ reduzido à área escolhida, como um grid de cards.
 *
 * Uma área oferece de 2 a 141 classes, então a busca e o par ramo × grau não
 * são enfeite: são o que torna a lista utilizável. As específicas da área vêm
 * antes das genéricas — a ordem já chega pronta do servidor, aqui só se desenha
 * a divisão.
 */
export function ProceduralClassPicker({ classes, branches, degrees, value, onSelect }: Props) {
    const [search, setSearch] = useState('')
    const [branch, setBranch] = useState('')
    const [degree, setDegree] = useState('')

    const filtering = branch !== '' || degree !== ''

    const visible = useMemo(
        () =>
            classes.filter((item) => {
                if (!matchesSearch(item, search)) {
                    return false
                }

                // As 33 classes sem competência informada pelo CNJ somem sob
                // qualquer filtro de instância, e voltam quando ele é limpo.
                return filtering ? matchesInstance(item.jurisdictions, branch, degree) : true
            }),
        [classes, search, branch, degree, filtering],
    )

    const groups = [
        { scope: 'specific', label: 'Específicas da área', items: visible.filter((i) => i.scope === 'specific') },
        { scope: 'generic', label: 'Gerais (tronco cível)', items: visible.filter((i) => i.scope === 'generic') },
    ].filter((group) => group.items.length > 0)

    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label="Busca" hint="Nome ou código">
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Buscar classe"
                        aria-label="Buscar classe processual"
                    />
                </Field>

                <Field label="Ramo da justiça">
                    <Select
                        value={branch}
                        onValueChange={setBranch}
                        options={branches}
                        placeholder="Todos os ramos"
                        clearable
                    />
                </Field>

                <Field label="Grau">
                    <Select
                        value={degree}
                        onValueChange={setDegree}
                        options={degrees}
                        placeholder="Todos os graus"
                        clearable
                    />
                </Field>
            </div>

            <p className="tabular text-xs text-muted-foreground">
                {visible.length} de {classes.length} classes
            </p>

            {/* p-1 para o anel do card selecionado não ser cortado na rolagem. */}
            <div
                role="radiogroup"
                aria-label="Classe processual"
                className="max-h-[28rem] space-y-5 overflow-y-auto p-1"
            >
                {groups.map((group) => (
                    <div key={group.scope} className="space-y-2">
                        <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {group.label}
                        </h3>

                        <div className="grid gap-3 sm:grid-cols-2">
                            {group.items.map((item) => (
                                <ClassCard
                                    key={item.id}
                                    item={item}
                                    selected={value === item.id}
                                    onSelect={() => onSelect(item.id)}
                                />
                            ))}
                        </div>
                    </div>
                ))}

                {visible.length === 0 && (
                    <p className="py-10 text-center text-sm text-muted-foreground">
                        Nenhuma classe encontrada com esses filtros.
                    </p>
                )}
            </div>
        </div>
    )
}

function ClassCard({
    item,
    selected,
    onSelect,
}: {
    item: ProceduralClassOption
    selected: boolean
    onSelect: () => void
}) {
    const shown = item.jurisdictions.slice(0, MAX_BADGES)
    const rest = item.jurisdictions.length - shown.length

    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={cn(
                'flex h-full flex-col gap-2 rounded-lg border bg-card p-4 text-left transition-colors',
                'hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                selected && 'ring-2 ring-ring',
            )}
        >
            <span className="text-sm leading-snug font-medium text-foreground">{item.name}</span>

            <span className="tabular text-xs text-muted-foreground">
                Classe {item.code}
                {item.abbreviation && ` · ${item.abbreviation}`}
                {item.legal_basis && ` · ${item.legal_basis}`}
            </span>

            <span className="mt-auto flex flex-wrap gap-1 pt-1">
                {item.is_filing_class && <Badge variant="secondary">Abre processo</Badge>}

                {shown.map((tag) => (
                    // O rótulo cheio no title: "Estadual 1º" é o que cabe.
                    <Badge key={tag.value} variant="muted" title={tag.label}>
                        {tag.short_label}
                    </Badge>
                ))}

                {rest > 0 && <Badge variant="outline">+{rest}</Badge>}

                {item.jurisdictions.length === 0 && <Badge variant="outline">Instância não informada</Badge>}
            </span>
        </button>
    )
}

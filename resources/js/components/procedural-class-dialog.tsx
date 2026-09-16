import { Eye } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog'
import type { ProceduralClassOption } from '@/types'

/** O travessão ocupa o lugar do que o CNJ não informou — 94 classes não têm
 *  polo passivo, e uma linha em branco leria como falha da tela. */
const EMPTY = '—'

/**
 * A ficha inteira da classe, que é o que o card não tem espaço para mostrar.
 *
 * O card corta por necessidade: a descrição em duas linhas, quatro matérias de
 * até sete, quatro competências de até 29. Aqui nada é cortado — é o lugar de
 * conferir antes de escolher, e de ler o que ficou atrás de um "+2".
 *
 * Os dados já vieram no payload do seletor, então abrir não custa requisição:
 * a mesma linha que desenha o card desenha esta ficha.
 */
export function ProceduralClassDialog({ item }: { item: ProceduralClassOption }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground data-[state=open]:bg-accent"
                >
                    <Eye />
                    {/* Nomeia a classe: o botão se repete em até 141 cards. */}
                    <span className="sr-only">Ver a ficha completa de {item.name}</span>
                </Button>
            </DialogTrigger>

            {/* `p-0` e o corpo com rolagem própria, como no diálogo de cliente:
                o cabeçalho com o nome da classe fica à vista enquanto a ficha
                rola. */}
            <DialogContent className="gap-0 p-0 sm:max-w-2xl">
                {/* `pr-14` porque o `p-6` daqui sobrepõe o `pr-8` padrão do
                    cabeçalho: sem ele o título passa por baixo do X. */}
                <DialogHeader className="border-b p-6 pr-14">
                    <DialogTitle>{item.name}</DialogTitle>
                    <DialogDescription className="tabular">
                        Classe {item.code}
                        {item.abbreviation && ` · ${item.abbreviation}`}
                        {' · '}
                        {item.scope === 'specific' ? 'Específica da área' : 'Geral (tronco cível)'}
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto p-6">
                    <Section title="Descrição">
                        <p className="text-sm leading-relaxed text-foreground">{item.description ?? EMPTY}</p>
                    </Section>

                    <Section title="Matérias típicas">
                        {item.typical_subjects.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {item.typical_subjects.map((subject) => (
                                    <Badge key={subject} variant="muted" className="font-normal">
                                        {subject}
                                    </Badge>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">{EMPTY}</p>
                        )}
                    </Section>

                    <Section title="Ficha">
                        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                            <Detail label="Natureza" value={item.nature} />
                            <Detail label="Fundamento legal" value={item.legal_basis} />
                            <Detail label="Polo ativo" value={item.active_party} />
                            <Detail label="Polo passivo" value={item.passive_party} />
                            <Detail label="Abre processo" value={yesNo(item.is_filing_class)} />
                            <Detail label="Corre em processo existente" value={yesNo(item.is_cross_cutting)} />
                            <Detail label="Numeração própria" value={yesNo(item.has_own_numbering)} />
                        </dl>
                    </Section>

                    <Section title="Competências">
                        {item.jurisdictions.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {/* O rótulo por extenso, não a abreviação que
                                    cabe no card: é para isso que se abre. */}
                                {item.jurisdictions.map((tag) => (
                                    <Badge key={tag.value} variant="muted" className="font-normal">
                                        {tag.label}
                                    </Badge>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">Não informadas pelo CNJ.</p>
                        )}
                    </Section>

                    <Section title="Hierarquia no CNJ">
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            {item.path.join(' › ')}
                        </p>
                    </Section>
                </div>
            </DialogContent>
        </Dialog>
    )
}

const yesNo = (value: boolean): string => (value ? 'Sim' : 'Não')

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-2">
            <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{title}</h3>
            {children}
        </section>
    )
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm text-foreground">{value ?? EMPTY}</dd>
        </div>
    )
}

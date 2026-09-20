import { Calculator, Gavel, Library } from 'lucide-react'
import type { ComponentType } from 'react'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Section } from './section'

/**
 * Os serviços da plataforma.
 *
 * Somar um serviço é somar um item nesta lista: a grade se ajusta sozinha e
 * nada mais precisa mudar. O ícone é obrigatório porque o cartão é lido pelo
 * par ícone + título, como todo item de navegação do projeto.
 */
const SERVICES: {
    title: string
    description: string
    icon: ComponentType<{ className?: string }>
}[] = [
    {
        title: 'Classificação de Jurisprudência',
        description: 'Encontra e organiza decisões por semelhança de conteúdo, não por palavra-chave.',
        icon: Library,
    },
    {
        title: 'Calculadora de dosimetria de pena',
        description: 'Percorre as três fases da dosimetria e registra cada circunstância considerada.',
        icon: Gavel,
    },
    {
        title: 'Calculadora de débitos judiciais',
        description: 'Atualiza o valor devido com correção monetária e juros até a data escolhida.',
        icon: Calculator,
    },
]

export function ServicesSection() {
    return (
        <Section
            id="servicos"
            title="Serviços"
            description="O que a LexIA faz pelo escritório."
        >
            <div className="grid gap-6 text-left sm:grid-cols-2 lg:grid-cols-3">
                {SERVICES.map((service) => (
                    <Card key={service.title} className="gap-4">
                        <CardHeader>
                            <span className="mb-4 flex size-10 items-center justify-center rounded-lg bg-muted text-foreground">
                                <service.icon className="size-5" />
                            </span>
                            <CardTitle className="text-lg leading-snug">{service.title}</CardTitle>
                            <CardDescription className="mt-2">{service.description}</CardDescription>
                        </CardHeader>
                    </Card>
                ))}
            </div>
        </Section>
    )
}

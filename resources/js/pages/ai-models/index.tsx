import { ModelCatalog, type ModelCatalogProps } from '@/components/model-catalog'

export default function AiModelsIndex(props: ModelCatalogProps) {
    return (
        <ModelCatalog
            {...props}
            title="Modelos de IA"
            subtitle="O modelo padrão é o que todos os agentes usam. A pesquisa de teses, jurisprudência e doutrina exige um modelo do Google Gemini."
            baseUrl="/modelos-de-ia"
            nameHint="O identificador do modelo na API, como gemini-3.7-flash ou gpt-oss:20b."
            confirmDefault={(model) =>
                `Definir ${model.name} como padrão? Todos os agentes passam a usá-lo a partir da próxima requisição.`
            }
        />
    )
}

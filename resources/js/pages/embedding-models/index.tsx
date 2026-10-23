import { ModelCatalog, type ModelCatalogProps } from '@/components/model-catalog'

export default function EmbeddingModelsIndex(props: ModelCatalogProps) {
    return (
        <ModelCatalog
            {...props}
            title="Modelos de embedding"
            subtitle="O modelo padrão vetoriza o catálogo de classes processuais e os temas do STJ."
            baseUrl="/modelos-de-embedding"
            nameHint="O identificador do modelo na API, como gemini-embedding-001 ou nomic-embed-text. Ele precisa gerar vetores de 768 dimensões."
            // Vetor de um modelo não se compara com vetor de outro: a troca
            // refaz os catálogos, e a busca de temas espera por isso.
            confirmDefault={(model) =>
                `Definir ${model.name} como padrão? Os catálogos serão revetorizados em segundo plano, e a busca de temas do STJ fica indisponível até terminar.`
            }
        />
    )
}

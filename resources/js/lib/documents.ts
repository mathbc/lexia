/**
 * As regras dos documentos que instruem a peça, fora do componente.
 *
 * O que entra e o que é recusado não depende de React, e é justamente a parte
 * que precisa valer igual no botão de selecionar e na área de arrastar — duas
 * portas para a mesma porteira.
 */

/** O que o escritório costuma juntar: o processo, as imagens e o texto. */
export const ACCEPTED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx', 'odt']

export const MAX_FILE_SIZE = 20 * 1024 * 1024

export interface DocumentDraft {
    /**
     * Chave local. O `File` não serve de key — dois arquivos podem ter o mesmo
     * nome — e o id do servidor só existe depois que a peça for salva.
     */
    id: string
    file: File
    /** O nome original, com extensão, como a coluna `name` guarda. */
    name: string
    extension: string
    size: number
    description: string
    /** Por que o arquivo foi recusado. Ausente quando ele passou. */
    error?: string
}

/**
 * O sufixo do nome, em minúsculas. Arquivo sem ponto não tem extensão — e um
 * nome que começa com ponto é oculto, não é extensão.
 */
export const extensionOf = (name: string): string => {
    const dot = name.lastIndexOf('.')

    return dot > 0 ? name.slice(dot + 1).toLowerCase() : ''
}

/**
 * Por que este arquivo não entra, ou `undefined` se ele entra.
 *
 * A duplicata é o par nome + tamanho: o navegador não entrega caminho, então é
 * o mais perto que dá para chegar de "é o mesmo arquivo" sem ler os bytes.
 */
const rejectionOf = (file: File, taken: Set<string>): string | undefined => {
    if (!ACCEPTED_EXTENSIONS.includes(extensionOf(file.name))) {
        return `Formato não aceito. Envie ${ACCEPTED_EXTENSIONS.join(', ')}.`
    }

    if (file.size > MAX_FILE_SIZE) {
        return 'Arquivo acima de 20 MB.'
    }

    if (taken.has(`${file.name}:${file.size}`)) {
        return 'Documento já adicionado.'
    }

    return undefined
}

/**
 * Converte o que veio do input ou do drop em rascunhos.
 *
 * O recusado volta na lista com `error` preenchido em vez de ser descartado: um
 * arquivo que some sem explicação é lido como travamento da tela. Quem foi
 * recusado não entra no conjunto de duplicatas — dois arquivos grandes demais
 * devem reclamar do tamanho, não um deles de repetição.
 */
export const toDocumentDrafts = (files: File[], existing: DocumentDraft[]): DocumentDraft[] => {
    const taken = new Set(
        existing.filter((draft) => draft.error === undefined).map((draft) => `${draft.name}:${draft.size}`),
    )

    return files.map((file) => {
        const error = rejectionOf(file, taken)

        if (error === undefined) {
            taken.add(`${file.name}:${file.size}`)
        }

        return {
            id: crypto.randomUUID(),
            file,
            name: file.name,
            extension: extensionOf(file.name),
            size: file.size,
            description: '',
            error,
        }
    })
}

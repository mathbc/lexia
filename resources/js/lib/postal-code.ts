import { digits } from '@/lib/format'

/** O que uma consulta de CEP consegue preencher. */
export interface PostalCodeAddress {
    street: string
    district: string
    city: string
    state: string
}

/**
 * Consulta o CEP nos Correios (via ViaCEP).
 *
 * Devolve só os campos que voltaram preenchidos, de modo que o `set` do
 * formulário nunca apague o que o usuário já digitou à mão. Falha caladamente:
 * uma consulta que não responde não pode travar o cadastro — os campos seguem
 * editáveis.
 */
export const lookupPostalCode = async (value: string): Promise<Partial<PostalCodeAddress> | null> => {
    const raw = digits(value)

    if (raw.length !== 8) {
        return null
    }

    try {
        const response = await fetch(`https://viacep.com.br/ws/${raw}/json/`)
        const data = await response.json()

        if (data.erro) {
            return null
        }

        const patch: Partial<PostalCodeAddress> = {}

        if (data.logradouro) patch.street = data.logradouro
        if (data.bairro) patch.district = data.bairro
        if (data.localidade) patch.city = data.localidade
        if (data.uf) patch.state = data.uf

        return patch
    } catch {
        return null
    }
}

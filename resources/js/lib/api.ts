/** O par que o Laravel publica e lê — `VerifyCsrfToken` escreve um e aceita o outro. */
const XSRF_COOKIE = 'XSRF-TOKEN'
const XSRF_HEADER = 'X-XSRF-TOKEN'

/**
 * Um POST que troca JSON com o próprio backend.
 *
 * É a exceção que precisa de explicação, porque o Inertia cobre toda navegação
 * desta aplicação: a classificação de um caso não devolve tela, devolve um
 * valor que a tela leva adiante. `fetch` cru em vez de axios porque é o que
 * basta — e porque o Inertia 3 deixou de embarcar axios, então trazê-lo de
 * volta por uma requisição só engordaria o bundle sem resolver nada que as
 * quatro linhas de `csrf()` não resolvam.
 *
 * O erro vira `Error` com a frase do servidor quando ela existe, porque é essa
 * frase que a tela mostra: "não foi possível enquadrar o caso agora" diz ao
 * advogado o que fazer, e um 503 não diz nada.
 */
export async function postJson<T>(url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        // É o cookie de sessão que diz quem pergunta. Já é o padrão dos
        // navegadores atuais, e está escrito porque a rota está atrás do `auth`
        // e a requisição não existe sem ele.
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrf(),
        },
        body: JSON.stringify(body),
    })

    // Um 500 em HTML não é JSON: a resposta ilegível não pode virar exceção de
    // parse, senão a tela perde a chance de dizer o que aconteceu.
    const data = await response.json().catch(() => null)

    if (!response.ok) {
        throw new Error(failure(data, response.status))
    }

    return data as T
}

/**
 * O cabeçalho de CSRF, tirado do cookie e não da meta tag.
 *
 * É o mesmo mecanismo do cliente do Inertia — ele lê `XSRF-TOKEN` e manda
 * `X-XSRF-TOKEN` —, e é o que a documentação do Laravel descreve para quem
 * emite uma requisição própria. A diferença entre as duas fontes não é
 * estilística: a meta tag do `app.blade.php` é uma fotografia do token no
 * último carregamento de documento, e o login regenera a sessão — e o token com
 * ela — sem que o navegador recarregue nada, porque o redirecionamento do
 * Fortify é uma visita do Inertia. Dali em diante a meta tag está velha e o
 * servidor responde "CSRF token mismatch". O cookie, esse, é reescrito em toda
 * resposta pelo `VerifyCsrfToken`, então nunca atrasa.
 *
 * Mandar os dois seria pior do que mandar o errado: `getTokenFromRequest` lê
 * `X-CSRF-TOKEN` primeiro e só cai no `X-XSRF-TOKEN` quando o primeiro não vem
 * — a meta tag vencida venceria o cookie bom.
 */
const csrf = (): Record<string, string> => {
    const token = cookie(XSRF_COOKIE)

    return token === null ? {} : { [XSRF_HEADER]: token }
}

/** O valor vai codificado no cabeçalho `Set-Cookie`, e o Laravel o decifra do outro lado. */
const cookie = (name: string): string | null => {
    const value = document.cookie.match(new RegExp('(^|;\\s*)(' + name + ')=([^;]*)'))?.[3]

    return value === undefined ? null : decodeURIComponent(value)
}

/**
 * A mensagem do servidor, seja ela a do 422 do validador — que já vem com o
 * primeiro erro — ou a que a Action escreveu ao recusar.
 */
const failure = (data: unknown, status: number): string => {
    const message = (data as { message?: unknown } | null)?.message

    return typeof message === 'string' && message !== ''
        ? message
        : `A requisição falhou (${status}).`
}

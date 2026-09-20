import { cn } from '@/lib/utils'

/**
 * O corpo da minuta desenhado como documento: a página ABNT em leitura.
 *
 * O texto gravado é puro — o agente é instruído a não escrever markdown —, e a
 * geometria toda mora no `.abnt-page` do `app.css`. O que sobra para cá é a
 * única coisa que o CSS sozinho não alcança: **qual parágrafo é citação**. Um
 * `textarea` pinta o campo inteiro com a mesma régua, e o recuo de 4 cm da NBR
 * 10520 é por bloco; é por isso que a aba tem um modo de leitura e não só o
 * campo de edição.
 *
 * Um parágrafo é citação de dois jeitos, e o primeiro é o que vale:
 *
 * 1. **Marcado à mão.** Toda linha começando por `>`, como num e-mail citado.
 *    O marcador não vai para a tela nem para o banco: ele é gramática de
 *    edição, e o advogado que quer o recuo diz que quer.
 * 2. **Reconhecido.** Um bloco inteiramente entre aspas e maior do que três
 *    linhas — que é exatamente a régua da norma para separar a citação curta,
 *    que fica no corpo entre aspas, da longa, que é recuada. O palpite só
 *    existe porque nada no texto de hoje carrega o marcador: sem ele, a minuta
 *    que o agente escreveu nunca mostraria um recuo.
 *
 * As aspas continuam onde estão nos dois casos. A norma pede a citação longa
 * sem elas, mas apagá-las aqui seria mostrar na tela um texto diferente do que
 * está gravado — e o que está gravado é o que vai ser protocolado.
 */
export function PleadingDocument({
    content,
    className,
}: {
    content: string
    className?: string
}) {
    return (
        <article className={cn('abnt-page', className)}>
            {blocksOf(content).map((block, index) => (
                <p
                    // O bloco é posicional: não há id, não há reordenação e a
                    // lista inteira é refeita a cada tecla.
                    key={index}
                    className={block.citation ? 'abnt-citation' : undefined}
                >
                    {block.text}
                </p>
            ))}
        </article>
    )
}

interface PleadingBlock {
    text: string
    citation: boolean
}

/** O marcador de citação, e o espaço opcional depois dele. */
const CITATION_MARKER = /^ {0,3}> ?/

/** O bloco todo entre aspas — as retas e as tipográficas, não a apóstrofe. */
const QUOTED_BLOCK = /^["“][\s\S]+["”][.,;:]?$/

/**
 * Quantos caracteres fazem "mais de três linhas", a régua da NBR 10520.
 *
 * A coluna de texto tem 16 cm (21 menos as margens de 3 e 2) e a linha de Times
 * 12pt cabe por volta de noventa caracteres; três linhas terminam aqui. É uma
 * aproximação de propósito: medir a linha de verdade exigiria layout, e o erro
 * de um caractere para mais ou para menos muda o recuo de um parágrafo que a
 * norma deixa no limite dos dois tratamentos.
 */
const LONG_CITATION_CHARS = 270

/**
 * O texto puro como a sequência de parágrafos que ele é.
 *
 * O parágrafo é o que a linha em branco separa — é a forma que o agente recebe
 * por instrução e a que o advogado continua escrevendo. As quebras de dentro de
 * um parágrafo ficam, porque os pedidos numerados e o bloco da assinatura são
 * um parágrafo só com várias linhas.
 */
const blocksOf = (content: string): PleadingBlock[] =>
    content
        .split(/\n[ \t]*\n/)
        .filter((paragraph) => paragraph.trim() !== '')
        .map((paragraph) => {
            const lines = paragraph.split('\n')
            const marked = lines.every((line) => CITATION_MARKER.test(line))
            const text = marked
                ? lines.map((line) => line.replace(CITATION_MARKER, '')).join('\n')
                : paragraph

            return {
                text,
                citation:
                    marked ||
                    (text.length > LONG_CITATION_CHARS && QUOTED_BLOCK.test(text.trim())),
            }
        })

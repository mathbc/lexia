import { Sparkles } from 'lucide-react'
import { useEffect, useState } from 'react'
import { AnalysisScan } from '@/components/analysis-scan'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'

interface AnalysisDialogProps {
    open: boolean
    title: string
    /** O que a espera custa e o que o usuário deve (ou não deve) fazer durante ela. */
    hint: string
    /** As etapas do trabalho, na ordem em que acontecem. */
    messages: readonly string[]
    /** Quanto tempo cada mensagem fica na tela, em milissegundos. */
    interval?: number
}

const DEFAULT_INTERVAL = 4000

/**
 * A espera por um agente, quando ela não cabe num botão girando.
 *
 * Congelar campo por campo funciona para o instante de um `submit`; para uma
 * inferência que leva minutos, não. O formulário desabilitado continua sendo o
 * formulário — a barra lateral responde, um menu abre, e nada na tela diz que
 * sair dali joga fora a espera inteira. O diálogo resolve isso sendo o que já
 * é: modal do Radix, com a página inteira atrás de um `pointer-events: none`,
 * o foco preso dentro e a ordem de tabulação junto com ele.
 *
 * Só que este não fecha. Não há X, o Esc não responde e o clique fora não
 * dispensa — porque não existe nada para dispensar: a requisição segue no
 * servidor de qualquer maneira, e uma tela que some daria a entender que foi
 * cancelada. Fechar mesmo é o fim do trabalho, e quem o fecha é quem o abriu.
 *
 * ## As mensagens
 *
 * Elas descrevem as etapas reais do trabalho, na ordem, mas **circulam** em vez
 * de parar na última. É uma escolha, e a alternativa é pior: não há progresso
 * para relatar — é uma requisição só, e o servidor não conta por onde anda —,
 * então uma sequência que terminasse deixaria a tela parada numa frase falsa
 * pelo resto da espera, que é exatamente a aparência de travado. Circulando,
 * cada frase continua verdadeira sobre o que o sistema faz, e o movimento
 * continua sendo sinal de vida.
 *
 * Elas são `aria-hidden`: um `aria-live` que reanuncia a mesma volta a cada
 * vinte segundos atrapalha mais do que informa. O que o leitor de tela recebe
 * é o título e a dica, que não mudam e dizem o essencial.
 */
export function AnalysisDialog({
    open,
    title,
    hint,
    messages,
    interval = DEFAULT_INTERVAL,
}: AnalysisDialogProps) {
    const [step, setStep] = useState(0)

    useEffect(() => {
        if (!open) {
            // Zerar ao fechar, e não ao abrir: uma segunda tentativa recomeça
            // pela primeira etapa, que é onde ela de fato recomeça.
            setStep(0)

            return
        }

        const timer = setInterval(() => setStep((current) => current + 1), interval)

        return () => clearInterval(timer)
    }, [open, interval])

    const message = messages[step % messages.length]

    return (
        <Dialog open={open}>
            <DialogContent
                showClose={false}
                className="max-w-md"
                // As três portas de saída do Radix, fechadas. `onInteractOutside`
                // cobre o que `onPointerDownOutside` não vê — o foco que escapa
                // para fora do diálogo.
                onEscapeKeyDown={(event) => event.preventDefault()}
                onPointerDownOutside={(event) => event.preventDefault()}
                onInteractOutside={(event) => event.preventDefault()}
            >
                <div className="flex flex-col items-center gap-5 py-2 text-center">
                    <AnalysisScan className="size-36">
                        {/* O mesmo ícone do botão que trouxe até aqui: a espera
                            é a continuação daquele clique, não outra coisa. */}
                        <Sparkles className="size-6 text-foreground" />
                    </AnalysisScan>

                    <DialogHeader className="items-center gap-2 pr-0">
                        <DialogTitle>{title}</DialogTitle>

                        {/* Altura reservada para duas linhas: sem ela, uma
                            mensagem curta seguida de uma longa faz o diálogo
                            inteiro pular a cada quatro segundos. */}
                        <p
                            key={step}
                            aria-hidden="true"
                            className="flex min-h-11 items-center text-sm leading-relaxed text-balance text-muted-foreground motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-1 motion-safe:duration-500"
                        >
                            {message}
                        </p>
                    </DialogHeader>

                    <DialogDescription className="text-xs text-balance">{hint}</DialogDescription>
                </div>
            </DialogContent>
        </Dialog>
    )
}

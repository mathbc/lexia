import { Link } from '@inertiajs/react'
import { ChevronDown } from 'lucide-react'
import { DotOrbitField } from '@/components/dot-orbit-field'
import { DottedRing } from '@/components/dotted-ring'
import { Button } from '@/components/ui/button'

/**
 * A entrada do site: a única seção animada.
 *
 * O movimento é contínuo, e não uma entrada que termina: um anel de pontos
 * orbita o conteúdo, e o buraco desse anel é o que abre o vazio onde a marca
 * mora. O resto da página segue estático de propósito.
 */
export function HeroSection() {
    return (
        // `isolate` prende os z-index negativos do fundo a esta seção, e não ao
        // body — sem ele o campo de pontos passaria por baixo da barra do topo
        // e das seções seguintes.
        <section
            id="home"
            className="relative isolate flex min-h-svh flex-col items-center justify-center overflow-hidden px-6 py-32"
        >
            {/* Decorativo: fora da leitura de tela e transparente ao clique,
                para que nada acima dele perca um toque. */}
            <DotOrbitField className="absolute inset-0 -z-10" />

            <div className="flex w-full max-w-2xl flex-col items-center text-center">
                {/* O anel é do tamanho da caixa, e a caixa é quem recebe o
                    mouse: o hover pega o círculo inteiro, e não só a marca. */}
                <DottedRing className="size-64 sm:size-80">
                    <h1 className="font-serif text-5xl font-semibold text-foreground sm:text-6xl">
                        LexIA
                    </h1>
                </DottedRing>

                <p className="mt-4 max-w-md text-muted-foreground">
                    Inteligência aplicada à prática jurídica.
                </p>
                <div className="mt-8 flex gap-3">
                    <Button asChild size="lg">
                        <Link href="/login">Entrar</Link>
                    </Button>
                    <Button asChild size="lg" variant="outline">
                        <Link href="/cadastro">Criar conta</Link>
                    </Button>
                </div>
            </div>

            {/* A página deixou de terminar aqui: a seta é o que diz isso a quem
                chega numa tela cheia. */}
            <a
                href="#servicos"
                className="absolute bottom-8 flex items-center gap-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                Conheça os serviços
                <ChevronDown className="size-4" />
            </a>
        </section>
    )
}

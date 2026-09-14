import { Head, Link } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { JusticeScales } from '@/components/justice-scales'
import { ParticleField } from '@/components/particle-field'

export default function Welcome() {
    return (
        <>
            <Head title="LexIA" />

            {/* A home é sempre escura, independentemente do tema escolhido: a
                classe `dark` na própria seção troca os tokens para todo mundo
                que está dentro — inclusive os botões —, e `isolate` prende os
                z-index negativos do fundo aqui, e não ao body. */}
            <div className="dark relative isolate flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background px-6 py-20 text-foreground">
                {/* Três camadas, da mais funda para a mais rasa: o campo de
                    partículas, a balança e o conteúdo. As duas primeiras são
                    decorativas — fora da leitura de tela e transparentes ao
                    clique —, para que nada acima delas perca um toque. */}
                <ParticleField className="absolute inset-0 -z-20" />

                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 -z-10 flex items-center justify-center"
                >
                    {/* Mais clara que as partículas: a balança é o assunto, o
                        campo é textura. O tom é o `muted-foreground`, não o
                        `foreground`: no tema escuro o branco puro serrilhava as
                        linhas finas e estourava os pontos. A opacidade sobe
                        junto para a figura não perder presença na troca.

                        O tamanho é o que protege a leitura. O vão entre os dois
                        pratos cresce junto com a marca, e é por dentro dele que
                        o texto passa — encolher a balança seria fechar o vão
                        sobre o subtítulo. No celular não há largura para isso,
                        e o que resolve é ela desbotar. */}
                    <JusticeScales className="w-[150%] max-w-6xl text-muted-foreground opacity-45 sm:w-[95%] sm:opacity-70" />
                </div>

                <div className="flex w-full max-w-2xl flex-col items-center text-center">
                    <h1 className="font-serif text-5xl font-semibold text-foreground">LexIA</h1>
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
            </div>
        </>
    )
}

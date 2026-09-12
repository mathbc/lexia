import { Head, Link } from '@inertiajs/react'
import { Button } from '@/components/ui/button'

export default function Welcome() {
    return (
        <>
            <Head title="LexIA" />
            <div className="flex min-h-svh flex-col items-center justify-center px-6 py-20 text-center">
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
        </>
    )
}

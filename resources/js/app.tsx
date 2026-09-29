import { createInertiaApp, router } from '@inertiajs/react'
import { toast } from 'sonner'
import { Toaster } from '@/components/ui/sonner'

// Um sucesso é um toast, e o servidor o escreve com Inertia::flash('success', …).
// A escuta fica aqui, antes do app, porque o flash de um carregamento inicial
// dispara logo depois do router.init(), que roda durante o primeiro render — um
// useEffect chegaria tarde. O Toaster que monta depois recebe os toasts ativos.
router.on('flash', (event) => {
    const { success } = event.detail.flash

    if (success) {
        toast.success(success)
    }
})

// Sem `resolve`: o @inertiajs/vite o injeta (de ./pages). O Toaster fica fora das
// layouts, e um toast sobrevive à troca do AuthLayout para o AppLayout.
createInertiaApp({
    withApp: (app) => (
        <>
            {app}
            <Toaster />
        </>
    ),
})

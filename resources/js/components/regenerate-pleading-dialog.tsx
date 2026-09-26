import { CircleAlert, History, RefreshCw } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'

interface RegeneratePleadingDialogProps {
    /** A versão exibida, que deixa de ser a da aba quando a nova chegar. */
    version: number
    /** Há texto editado e não salvo — o único que se perde de verdade. */
    unsaved: boolean
    disabled: boolean
    onConfirm: () => void
}

/**
 * A pergunta antes de chamar o agente por cima de uma minuta que existe.
 *
 * Diz o que acontece com o que está na tela, que é o que se perde de vista: a
 * versão atual continua gravada, mas deixa de ser a que a aba mostra — e uma
 * edição não salva não está gravada em lugar nenhum, daí ela ser o único aviso
 * em vermelho. Regerar em si não destrói nada, então o botão que confirma é o
 * primário e não o destrutivo.
 *
 * O diálogo traz o próprio gatilho, e quem chama decide só se ele aparece
 * (`can.generate`) e o que acontece no "sim". Confirmar fecha este diálogo no
 * mesmo gesto em que o `AnalysisDialog` abre, e o Radix empilha os dois sem
 * soltar o bloqueio da página no meio da troca.
 */
export function RegeneratePleadingDialog({
    version,
    unsaved,
    disabled,
    onConfirm,
}: RegeneratePleadingDialogProps) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button type="button" variant="outline" disabled={disabled}>
                    <RefreshCw />
                    Gerar novamente
                </Button>
            </AlertDialogTrigger>

            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Gerar a minuta novamente?</AlertDialogTitle>
                    <AlertDialogDescription>
                        O agente redige a versão {version + 1} a partir da peça como ela está
                        agora: enquadramento, partes, fatos, pedidos, teses e julgados.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <Alert>
                    <History />
                    <AlertDescription>
                        A versão {version} continua guardada no histórico, mas deixa de ser a
                        exibida nesta aba.
                    </AlertDescription>
                </Alert>

                {unsaved && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertDescription>
                            As alterações não salvas serão perdidas. Salve uma nova versão antes,
                            se quiser mantê-las.
                        </AlertDescription>
                    </Alert>
                )}

                <AlertDialogFooter>
                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                    <AlertDialogAction onClick={onConfirm}>
                        <RefreshCw />
                        Gerar novamente
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    )
}

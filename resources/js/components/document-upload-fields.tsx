import { FileUp, Trash2, Upload } from 'lucide-react'
import { useRef, useState, type DragEvent } from 'react'
import { RowActions } from '@/components/row-actions'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/field'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ACCEPTED_EXTENSIONS, type DocumentDraft } from '@/lib/documents'
import { formatFileSize } from '@/lib/format'
import { cn } from '@/lib/utils'

/** O que o `accept` do input entende: a mesma lista, com ponto. */
const ACCEPT = ACCEPTED_EXTENSIONS.map((extension) => `.${extension}`).join(',')

interface Props {
    documents: DocumentDraft[]
    onAdd: (files: File[]) => void
    onDescribe: (id: string, description: string) => void
    onRemove: (id: string) => void
    disabled?: boolean
}

/**
 * Os documentos que instruem a peça: contrato, procuração, comprovantes, laudos.
 *
 * Duas portas para a mesma porteira — o botão, que é o caminho acessível e
 * funciona no teclado, e a área de arrastar, que é conveniência de quem já tem
 * a pasta aberta ao lado. As regras de aceite vivem em `@/lib/documents`, fora
 * daqui, porque valem igual nas duas.
 *
 * O arquivo recusado entra na tabela em vermelho, com o motivo, em vez de ser
 * descartado em silêncio: um documento que some sem explicação é lido como
 * travamento da tela, e o advogado fica tentando de novo.
 *
 * Nada é enviado. Os `File` ficam no rascunho até existir a Action que salva a
 * peça inteira — é o mesmo arranjo do réu e dos fatos, e o motivo é o mesmo.
 */
export function DocumentUploadFields({ documents, onAdd, onDescribe, onRemove, disabled = false }: Props) {
    const input = useRef<HTMLInputElement>(null)
    const [dragging, setDragging] = useState(false)

    /**
     * `dragenter` e `dragleave` disparam também ao cruzar cada filho da área.
     * Sem contar as entradas, o realce apagaria ao passar sobre o botão.
     */
    const depth = useRef(0)

    const accepted = documents.filter((document) => document.error === undefined)
    const total = accepted.reduce((sum, document) => sum + document.size, 0)

    const drop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault()
        depth.current = 0
        setDragging(false)

        if (!disabled) {
            onAdd([...event.dataTransfer.files])
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Documentos</CardTitle>
                <CardDescription>
                    {accepted.length === 0
                        ? 'O que instrui a peça: contrato, procuração, comprovantes, laudos.'
                        : `${accepted.length} ${accepted.length === 1 ? 'documento' : 'documentos'} · ${formatFileSize(total)}`}
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-6">
                <div
                    onDragEnter={(event) => {
                        event.preventDefault()
                        depth.current += 1
                        setDragging(true)
                    }}
                    onDragOver={(event) => event.preventDefault()}
                    onDragLeave={(event) => {
                        event.preventDefault()
                        depth.current -= 1
                        if (depth.current <= 0) {
                            depth.current = 0
                            setDragging(false)
                        }
                    }}
                    onDrop={drop}
                    className={cn(
                        'flex flex-col items-center gap-3 rounded-lg border border-dashed px-4 py-10 text-center transition-colors',
                        dragging && !disabled && 'border-primary bg-accent',
                    )}
                >
                    <FileUp className="size-6 text-muted-foreground" />

                    <p className="text-sm text-muted-foreground">
                        Arraste os arquivos até aqui ou selecione no computador.
                    </p>

                    <Button type="button" variant="outline" disabled={disabled} onClick={() => input.current?.click()}>
                        <Upload />
                        Selecionar arquivos
                    </Button>

                    <p className="text-xs text-muted-foreground">
                        {ACCEPTED_EXTENSIONS.join(', ')} · até 20 MB por arquivo
                    </p>

                    <input
                        ref={input}
                        type="file"
                        multiple
                        accept={ACCEPT}
                        className="hidden"
                        onChange={(event) => {
                            onAdd([...(event.target.files ?? [])])
                            // Zera o input: sem isso, reselecionar o mesmo
                            // arquivo depois de removê-lo não dispara `change`.
                            event.target.value = ''
                        }}
                    />
                </div>

                {/* A tabela só existe quando há o que listar: a área pontilhada
                    acima já é o estado vazio, e empilhar dois seria redundante. */}
                {documents.length > 0 && (
                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader className="bg-muted/50">
                                <TableRow>
                                    <TableHead>Documento</TableHead>
                                    <TableHead>Descrição</TableHead>
                                    <TableHead>Tamanho</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">Ações</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                {documents.map((document) => (
                                    <TableRow key={document.id}>
                                        <TableCell className="py-3 whitespace-normal">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-foreground">{document.name}</span>
                                                {document.extension !== '' && (
                                                    <Badge variant="muted">{document.extension}</Badge>
                                                )}
                                            </div>

                                            {document.error !== undefined && (
                                                <p className="mt-1 text-xs text-destructive">{document.error}</p>
                                            )}
                                        </TableCell>

                                        <TableCell className="py-3">
                                            <Input
                                                value={document.description}
                                                onChange={(event) => onDescribe(document.id, event.target.value)}
                                                placeholder="O que este documento comprova"
                                                aria-label={`Descrição de ${document.name}`}
                                                disabled={disabled || document.error !== undefined}
                                            />
                                        </TableCell>

                                        <TableCell className="tabular py-3 text-muted-foreground">
                                            {formatFileSize(document.size)}
                                        </TableCell>

                                        <TableCell className="py-3 text-right">
                                            {/* Sem `confirm`: nada é destruído. O arquivo
                                                continua no computador e desfazer é
                                                selecioná-lo de novo. Quando remover apagar
                                                algo do servidor, a confirmação entra. */}
                                            <RowActions
                                                label={`Ações de ${document.name}`}
                                                actions={[
                                                    {
                                                        label: 'Remover',
                                                        icon: Trash2,
                                                        destructive: true,
                                                        onSelect: () => onRemove(document.id),
                                                    },
                                                ]}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    )
}

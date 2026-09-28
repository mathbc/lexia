import { useForm } from '@inertiajs/react'
import { Plus, Trash2 } from 'lucide-react'
import type { FormEvent } from 'react'
import { RowActions } from '@/components/row-actions'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { Field, Input, Select, Textarea } from '@/components/ui/field'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
    newLegalBasisRow,
    postedBasisIndexes,
    toLegalThesisPayload,
    toThesisForm,
    type LegalBasisRow,
    type LegalThesisForm,
} from '@/lib/forensic-review'
import type { Option, ResearchedThesis } from '@/types'

interface Props {
    legalCaseId: string
    /** A tese a corrigir, ou nulo para cadastrar uma nova. */
    thesis: ResearchedThesis | null
    open: boolean
    onOpenChange: (open: boolean) => void
    /** `LegalThesisType::options()`. */
    thesisTypes: Option[]
    /** `LegalBasisType::options()`, para a coluna de tipo da fundamentação. */
    legalBasisTypes: Option[]
}

/**
 * Cadastrar uma tese à mão, ou corrigir uma que foi cadastrada assim.
 *
 * Existe para quando a pesquisa não basta: os portais fora do ar, ou uma rodada
 * que nada confirmou, e o advogado sabendo exatamente o que quer argumentar. A
 * tese é **gravada ao salvar** — `CreateLegalThesis` ou `UpdateLegalThesis` —, e
 * não guardada até o "Concluir": ela sobrevive a um reload como as da pesquisa,
 * e volta pelas props já com o id que o banco cunhou. O que continua local é só
 * a decisão de mantê-la, como a de qualquer outra.
 *
 * Largo de propósito (`max-w-5xl`): são quatro campos e uma tabela de
 * fundamentação com três colunas editáveis, e o argumento é texto longo. O corpo
 * rola sozinho, para que o título e os botões fiquem à vista.
 *
 * A fundamentação é uma tabela porque é uma lista de registros iguais — tipo,
 * referência, fonte —, a mesma forma do jsonb `legal_bases`. A referência é a
 * citação inteira, como vai para a peça ("Súmula 393 do STJ"): ver o docblock de
 * `LegalThesis` sobre por que a tela não a monta a partir das partes.
 */
export function LegalThesisDialog({ open, onOpenChange, ...props }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {/* `p-0` e o corpo com rolagem própria, como no cadastro de cliente. */}
            <DialogContent className="gap-0 p-0 sm:max-w-5xl">
                {/* O conteúdo desmonta quando o diálogo fecha, então cada
                    abertura começa um formulário novo — vazio no cadastro,
                    com a tese na edição — sem precisar de reset. */}
                <ThesisForm {...props} onClose={() => onOpenChange(false)} />
            </DialogContent>
        </Dialog>
    )
}

function ThesisForm({
    legalCaseId,
    thesis,
    thesisTypes,
    legalBasisTypes,
    onClose,
}: Omit<Props, 'open' | 'onOpenChange'> & { onClose: () => void }) {
    const form = useForm<LegalThesisForm>(toThesisForm(thesis))
    const editing = thesis?.id != null

    const set = (patch: Partial<LegalThesisForm>) => form.setData((current) => ({ ...current, ...patch }))

    const setBasis = (key: string, patch: Partial<LegalBasisRow>) =>
        form.setData((current) => ({
            ...current,
            legal_bases: current.legal_bases.map((row) => (row.key === key ? { ...row, ...patch } : row)),
        }))

    const submit = (event: FormEvent) => {
        event.preventDefault()

        form.transform(toLegalThesisPayload)

        const options = {
            preserveState: true,
            preserveScroll: true,
            // Mesma URL de volta — a própria etapa —: sem `replace`, cada
            // cadastro deixaria uma entrada repetida no histórico.
            replace: true,
            onSuccess: onClose,
        }

        if (editing) {
            form.put(`/pecas/${legalCaseId}/teses/${thesis.id}`, options)
        } else {
            form.post(`/pecas/${legalCaseId}/teses`, options)
        }
    }

    return (
        <>
            {/* `pr-14` porque o `p-6` daqui sobrepõe o `pr-8` padrão do
                cabeçalho: sem ele o título passa por baixo do X no celular. */}
            <DialogHeader className="border-b p-6 pr-14">
                <DialogTitle>{editing ? 'Editar tese' : 'Cadastrar tese'}</DialogTitle>
                <DialogDescription>
                    {editing
                        ? 'A correção é gravada agora, e a tese continua marcada ou desmarcada como estava.'
                        : 'A tese é gravada agora e entra na peça marcada, ao lado das que a pesquisa trouxe. Uma nova pesquisa não a apaga.'}
                </DialogDescription>
            </DialogHeader>

            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto p-6">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            label="Título"
                            required
                            error={form.errors.name}
                            hint="O título que a tese recebe na peça."
                            className="sm:col-span-2"
                        >
                            <Input
                                value={form.data.name}
                                onChange={(event) => set({ name: event.target.value })}
                                placeholder="Da Ilegitimidade Passiva do Sócio-Administrador"
                                disabled={form.processing}
                            />
                        </Field>

                        <Field label="Tipo" required error={form.errors.type}>
                            <Select
                                value={form.data.type}
                                onValueChange={(type) => set({ type })}
                                options={thesisTypes}
                                placeholder="Selecione o tipo"
                                disabled={form.processing}
                                className="w-full"
                            />
                        </Field>
                    </div>

                    <Field
                        label="Argumento"
                        required
                        error={form.errors.description}
                        hint="O que a tese sustenta: o que o dispositivo exige, o fato do relato que o preenche e a consequência."
                    >
                        <Textarea
                            value={form.data.description}
                            onChange={(event) => set({ description: event.target.value })}
                            className="min-h-32"
                            disabled={form.processing}
                        />
                    </Field>

                    <Field
                        label="O que o cliente ganha"
                        error={form.errors.impact}
                        hint="Opcional. O efeito prático de a tese ser acolhida."
                    >
                        <Textarea
                            value={form.data.impact}
                            onChange={(event) => set({ impact: event.target.value })}
                            disabled={form.processing}
                        />
                    </Field>

                    <LegalBasesTable
                        rows={form.data.legal_bases}
                        // As chaves aninhadas (`legal_bases.0.reference`) não
                        // estão no tipo do formulário, que só conhece as de cima.
                        errors={form.errors as Record<string, string | undefined>}
                        legalBasisTypes={legalBasisTypes}
                        disabled={form.processing}
                        onAdd={() => set({ legal_bases: [...form.data.legal_bases, newLegalBasisRow()] })}
                        onChange={setBasis}
                        onRemove={(key) => set({ legal_bases: form.data.legal_bases.filter((row) => row.key !== key) })}
                    />
                </div>

                <DialogFooter className="border-t p-6">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Salvando…' : editing ? 'Salvar alterações' : 'Cadastrar tese'}
                    </Button>
                </DialogFooter>
            </form>
        </>
    )
}

/**
 * A fundamentação como tabela: uma linha por dispositivo, súmula ou tema.
 *
 * A linha totalmente em branco não é enviada — é a que se abriu e não se usou —,
 * e é por isso que o erro de cada célula passa por `postedBasisIndexes`: o
 * servidor numera as linhas que recebeu, não as que a tela mostra.
 */
function LegalBasesTable({
    rows,
    errors,
    legalBasisTypes,
    disabled,
    onAdd,
    onChange,
    onRemove,
}: {
    rows: LegalBasisRow[]
    errors: Record<string, string | undefined>
    legalBasisTypes: Option[]
    disabled: boolean
    onAdd: () => void
    onChange: (key: string, patch: Partial<LegalBasisRow>) => void
    onRemove: (key: string) => void
}) {
    const posted = postedBasisIndexes(rows)

    const errorOf = (index: number, field: keyof LegalBasisRow): string | undefined => {
        const at = posted[index]

        return at === null ? undefined : errors[`legal_bases.${at}.${field}`]
    }

    return (
        <section className="space-y-3">
            <div className="space-y-1">
                <p className="text-sm font-medium">Fundamentação</p>
                <p className="text-xs text-muted-foreground">
                    Os dispositivos, leis, súmulas, ADCs e temas em que a tese se apoia. A referência vai para a peça
                    exatamente como estiver escrita.
                </p>
            </div>

            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead className="w-48">Tipo</TableHead>
                            <TableHead>Referência</TableHead>
                            <TableHead className="w-40">Fonte</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Ações</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {rows.map((row, index) => {
                            const position = index + 1
                            const referenceError = errorOf(index, 'reference')
                            const sourceError = errorOf(index, 'source')
                            const typeError = errorOf(index, 'type')

                            return (
                                <TableRow key={row.key} className="hover:bg-transparent">
                                    <TableCell className="py-3 align-top">
                                        <Select
                                            value={row.type}
                                            onValueChange={(type) => onChange(row.key, { type })}
                                            options={legalBasisTypes}
                                            placeholder="Sem tipo"
                                            clearable
                                            disabled={disabled}
                                            className="w-full"
                                            aria-label={`Tipo do fundamento ${position}`}
                                            aria-invalid={typeError !== undefined}
                                        />
                                        <CellError message={typeError} />
                                    </TableCell>

                                    <TableCell className="py-3 align-top">
                                        <Input
                                            value={row.reference}
                                            onChange={(event) => onChange(row.key, { reference: event.target.value })}
                                            placeholder="Súmula 393 do STJ"
                                            aria-label={`Referência do fundamento ${position}`}
                                            aria-invalid={referenceError !== undefined}
                                            disabled={disabled}
                                            className="min-w-56"
                                        />
                                        <CellError message={referenceError} />
                                    </TableCell>

                                    <TableCell className="py-3 align-top">
                                        <Input
                                            value={row.source}
                                            onChange={(event) => onChange(row.key, { source: event.target.value })}
                                            placeholder="STJ"
                                            maxLength={60}
                                            aria-label={`Fonte do fundamento ${position}`}
                                            aria-invalid={sourceError !== undefined}
                                            disabled={disabled}
                                        />
                                        <CellError message={sourceError} />
                                    </TableCell>

                                    <TableCell className="py-3 text-right align-top">
                                        {/* Sem `confirm`: nada foi gravado ainda,
                                            e desfazer é digitar de novo. */}
                                        <RowActions
                                            label={`Ações do fundamento ${position}`}
                                            actions={[
                                                !disabled && {
                                                    label: 'Remover',
                                                    icon: Trash2,
                                                    destructive: true,
                                                    onSelect: () => onRemove(row.key),
                                                },
                                            ]}
                                        />
                                    </TableCell>
                                </TableRow>
                            )
                        })}

                        {rows.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={4} className="py-8 text-center text-muted-foreground">
                                    Nenhum fundamento adicionado.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            {errors.legal_bases && (
                <p role="alert" className="text-xs font-medium text-destructive">
                    {errors.legal_bases}
                </p>
            )}

            <Button type="button" variant="outline" size="sm" onClick={onAdd} disabled={disabled}>
                <Plus />
                Adicionar fundamento
            </Button>
        </section>
    )
}

/** O erro de uma célula, embaixo do controle — a tabela não tem `Field`. */
function CellError({ message }: { message: string | undefined }) {
    if (message === undefined) {
        return null
    }

    return (
        <p role="alert" className="mt-1 text-xs font-medium whitespace-normal text-destructive">
            {message}
        </p>
    )
}

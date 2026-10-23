import { Head, useForm } from '@inertiajs/react'
import { Pencil, Plus, Star, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { AppLayout } from '@/layouts/app-layout'
import { RowActions } from '@/components/row-actions'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { Field, Input, Select } from '@/components/ui/field'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { CatalogModel, Option } from '@/types'

export interface ModelCatalogProps {
    models: CatalogModel[]
    /** `AiProvider::options()`. */
    providers: Option[]
    can: { create: boolean }
}

interface Props extends ModelCatalogProps {
    title: string
    /** What the default decides, under the title. */
    subtitle: string
    /** `/modelos-de-ia` or `/modelos-de-embedding`. */
    baseUrl: string
    /** The hint under the model field: which ids the catalogue expects. */
    nameHint: string
    /** The question before promoting a row, which for embeddings warns about the re-vectorization. */
    confirmDefault: (model: CatalogModel) => string
}

/**
 * The listing both model catalogues share — text and embedding are one screen
 * with different words.
 *
 * Registering and correcting happen in a dialog over the list: two fields do
 * not earn a page of their own, and the row the lawyer is looking at stays in
 * view. Promoting is a row action, never a field of the form, because it moves
 * the flag off another row too.
 */
export function ModelCatalog({ models, providers, can, title, subtitle, baseUrl, nameHint, confirmDefault }: Props) {
    const [open, setOpen] = useState(false)
    const [editing, setEditing] = useState<CatalogModel | null>(null)

    const openDialog = (model: CatalogModel | null) => {
        setEditing(model)
        setOpen(true)
    }

    return (
        <AppLayout
            title={title}
            subtitle={subtitle}
            actions={
                can.create && (
                    <Button onClick={() => openDialog(null)}>
                        <Plus />
                        Cadastrar modelo
                    </Button>
                )
            }
        >
            <Head title={title} />

            <Card className="gap-0 overflow-hidden py-0">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Modelo</TableHead>
                            <TableHead>Provedor</TableHead>
                            <TableHead>Situação</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Ações</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {models.map((model) => (
                            <TableRow key={model.id}>
                                <TableCell className="py-3 font-mono text-sm whitespace-normal text-foreground">
                                    {model.name}
                                </TableCell>
                                <TableCell className="py-3 text-muted-foreground">{model.provider_label}</TableCell>
                                <TableCell className="py-3">
                                    {model.default ? <Badge variant="success">Padrão</Badge> : <Badge variant="muted">Disponível</Badge>}
                                </TableCell>
                                <TableCell className="py-3 text-right">
                                    <RowActions
                                        label={`Ações de ${model.name}`}
                                        actions={[
                                            model.can.update && {
                                                label: 'Editar',
                                                icon: Pencil,
                                                onSelect: () => openDialog(model),
                                            },
                                            model.can.make_default && {
                                                label: 'Definir como padrão',
                                                icon: Star,
                                                href: `${baseUrl}/${model.id}/padrao`,
                                                method: 'patch',
                                                confirm: confirmDefault(model),
                                                separated: true,
                                            },
                                            model.can.delete && {
                                                label: 'Excluir',
                                                icon: Trash2,
                                                href: `${baseUrl}/${model.id}`,
                                                method: 'delete',
                                                destructive: true,
                                                confirm: `Excluir ${model.name}? A ação não pode ser desfeita.`,
                                                separated: true,
                                            },
                                        ]}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}

                        {models.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={4} className="py-12 text-center whitespace-normal text-muted-foreground">
                                    Nenhum modelo cadastrado. O primeiro cadastrado vira o padrão — ou rode{' '}
                                    <code className="font-mono">php artisan lexia:install</code>.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="gap-0 p-0 sm:max-w-lg">
                    {/* O conteúdo desmonta ao fechar: cada abertura começa um
                        formulário novo, vazio no cadastro e com a linha na
                        edição, sem reset. */}
                    <ModelForm
                        model={editing}
                        providers={providers}
                        baseUrl={baseUrl}
                        nameHint={nameHint}
                        onClose={() => setOpen(false)}
                    />
                </DialogContent>
            </Dialog>
        </AppLayout>
    )
}

function ModelForm({
    model,
    providers,
    baseUrl,
    nameHint,
    onClose,
}: {
    model: CatalogModel | null
    providers: Option[]
    baseUrl: string
    nameHint: string
    onClose: () => void
}) {
    const form = useForm({
        provider: model?.provider ?? '',
        name: model?.name ?? '',
    })

    const submit = (event: FormEvent) => {
        event.preventDefault()

        const options = { preserveScroll: true, onSuccess: onClose }

        if (model) {
            form.put(`${baseUrl}/${model.id}`, options)
        } else {
            form.post(baseUrl, options)
        }
    }

    return (
        <>
            {/* `pr-14` porque o `p-6` sobrepõe o `pr-8` padrão do cabeçalho:
                sem ele o título passa por baixo do X no celular. */}
            <DialogHeader className="border-b p-6 pr-14">
                <DialogTitle>{model ? 'Editar modelo' : 'Cadastrar modelo'}</DialogTitle>
                <DialogDescription>
                    {model?.default
                        ? 'Este é o modelo padrão: a alteração vale a partir da próxima requisição.'
                        : 'Cadastrar não troca o padrão. Use "Definir como padrão" na linha depois de salvar.'}
                </DialogDescription>
            </DialogHeader>

            <form onSubmit={submit}>
                <div className="space-y-4 p-6">
                    <Field label="Provedor" required error={form.errors.provider}>
                        <Select
                            value={form.data.provider}
                            onValueChange={(provider) => form.setData('provider', provider)}
                            options={providers}
                            placeholder="Selecione o provedor"
                            disabled={form.processing}
                            className="w-full"
                        />
                    </Field>

                    <Field label="Modelo" required error={form.errors.name} hint={nameHint}>
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            className="font-mono"
                            autoComplete="off"
                            spellCheck={false}
                            disabled={form.processing}
                        />
                    </Field>
                </div>

                <DialogFooter className="border-t p-6">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Salvando…' : model ? 'Salvar alterações' : 'Cadastrar modelo'}
                    </Button>
                </DialogFooter>
            </form>
        </>
    )
}

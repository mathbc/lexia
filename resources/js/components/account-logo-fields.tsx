import { ImageOff, Trash2, Undo2, Upload } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field } from '@/components/ui/field'
import { formatFileSize } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AccountLogos } from '@/types'

/**
 * O que o formulário diz de cada logo — o par de `AccountLogosData`. Sem
 * arquivo e sem remoção, a logo gravada fica como está: é por isso que
 * "remover" é uma bandeira, e não um `null` no lugar do arquivo.
 */
export interface AccountLogoValues {
    logo: File | null
    logo_dark: File | null
    logo_pleading: File | null
    remove_logo: boolean
    remove_logo_dark: boolean
    remove_logo_pleading: boolean
}

export const NO_LOGO_CHANGES: AccountLogoValues = {
    logo: null,
    logo_dark: null,
    logo_pleading: null,
    remove_logo: false,
    remove_logo_dark: false,
    remove_logo_pleading: false,
}

/** As regras de `logoRules()`, repetidas só para o aviso imediato. */
const ACCEPTED_TYPES = ['image/png', 'image/jpeg']
const MAX_BYTES = 2 * 1024 * 1024

type Errors = Partial<Record<keyof AccountLogoValues, string>>

interface Props {
    values: AccountLogoValues
    errors: Errors
    set: (patch: Partial<AccountLogoValues>) => void
    /** As logos já gravadas; ausente numa conta que ainda não existe. */
    stored?: AccountLogos
    disabled?: boolean
}

/**
 * As logos da conta: uma por tema da interface, e a da minuta.
 *
 * O par da interface divide uma linha; a da minuta ocupa a largura do cartão,
 * porque é a que pode ser larga — a marca com o nome do escritório ao lado —,
 * e uma moldura estreita a mostraria menor do que o timbre vai desenhá-la.
 *
 * Nada é enviado na escolha: o arquivo fica no formulário e só viaja no
 * "Salvar" — ou no "Criar conta" —, junto do resto do cadastro.
 */
export function AccountLogoFields({ values, errors, set, stored, disabled = false }: Props) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Identidade visual</CardTitle>
                <CardDescription>
                    As duas primeiras aparecem no menu lateral, no lugar do ícone, num quadrado de 32 px:
                    prefira o símbolo da marca em imagem quadrada. A da minuta vai no timbre da peça e pode
                    ser mais larga. Fundo transparente, PNG ou JPG, até 2 MB e 4000 × 4000 px.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Logo"
                    hint="A versão principal, sobre fundo claro. Sem a do tema escuro, vale para os dois."
                    error={errors.logo ?? errors.remove_logo}
                >
                    <LogoPicker
                        surface="light"
                        file={values.logo}
                        stored={stored?.light ?? null}
                        removed={values.remove_logo}
                        onPick={(file) => set({ logo: file, remove_logo: false })}
                        onRemove={() => set({ logo: null, remove_logo: stored?.light != null })}
                        onRestore={() => set({ remove_logo: false })}
                        disabled={disabled}
                    />
                </Field>

                <Field
                    label="Logo para tema escuro"
                    hint="A versão que continua legível sobre fundo escuro."
                    error={errors.logo_dark ?? errors.remove_logo_dark}
                >
                    <LogoPicker
                        surface="dark"
                        file={values.logo_dark}
                        stored={stored?.dark ?? null}
                        removed={values.remove_logo_dark}
                        onPick={(file) => set({ logo_dark: file, remove_logo_dark: false })}
                        onRemove={() => set({ logo_dark: null, remove_logo_dark: stored?.dark != null })}
                        onRestore={() => set({ remove_logo_dark: false })}
                        disabled={disabled}
                    />
                </Field>

                <Field
                    className="sm:col-span-2"
                    label="Logo da minuta"
                    hint="Vai no timbre, centralizada acima do nome do escritório, na tela e nos arquivos PDF e DOCX. É impressa sobre papel branco; sem ela, o timbre sai só com o texto."
                    error={errors.logo_pleading ?? errors.remove_logo_pleading}
                >
                    <LogoPicker
                        surface="light"
                        file={values.logo_pleading}
                        stored={stored?.pleading ?? null}
                        removed={values.remove_logo_pleading}
                        onPick={(file) => set({ logo_pleading: file, remove_logo_pleading: false })}
                        onRemove={() => set({ logo_pleading: null, remove_logo_pleading: stored?.pleading != null })}
                        onRestore={() => set({ remove_logo_pleading: false })}
                        disabled={disabled}
                    />
                </Field>
            </CardContent>
        </Card>
    )
}

interface PickerProps {
    /** Enxertados pelo `Field`, e postos no botão: é ele o controle do rótulo. */
    id?: string
    'aria-invalid'?: boolean
    'aria-describedby'?: string
    surface: 'light' | 'dark'
    file: File | null
    stored: string | null
    removed: boolean
    onPick: (file: File) => void
    onRemove: () => void
    onRestore: () => void
    disabled: boolean
}

function LogoPicker({
    id,
    'aria-invalid': invalid,
    'aria-describedby': describedBy,
    surface,
    file,
    stored,
    removed,
    onPick,
    onRemove,
    onRestore,
    disabled,
}: PickerProps) {
    const input = useRef<HTMLInputElement>(null)
    const preview = useObjectUrl(file)
    const [rejection, setRejection] = useState<string | null>(null)

    const src = preview ?? (removed ? null : stored)
    const pendingRemoval = removed && stored !== null && file === null

    const pick = (picked: File | undefined) => {
        if (picked === undefined) {
            return
        }

        const problem = rejectionOf(picked)
        setRejection(problem)

        if (problem === null) {
            onPick(picked)
        }
    }

    return (
        <div className="grid gap-3">
            {/* A moldura força o tema a que a logo se destina, e não o da tela:
                `.light` e `.dark` redefinem os tokens para a subárvore, de modo
                que `bg-background` já é o fundo certo nos dois casos. */}
            <div
                className={cn(
                    surface,
                    'flex h-32 items-center justify-center rounded-lg border bg-background p-4',
                )}
            >
                {src !== null ? (
                    <img src={src} alt="" className="max-h-full max-w-full object-contain" />
                ) : (
                    <ImageOff className="size-6 text-muted-foreground" />
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    id={id}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    onClick={() => input.current?.click()}
                >
                    <Upload />
                    {src !== null ? 'Trocar imagem' : 'Enviar imagem'}
                </Button>

                {src !== null && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={disabled}
                        onClick={() => {
                            setRejection(null)
                            onRemove()
                        }}
                    >
                        <Trash2 />
                        Remover
                    </Button>
                )}

                {pendingRemoval && (
                    <Button type="button" variant="ghost" size="sm" disabled={disabled} onClick={onRestore}>
                        <Undo2 />
                        Desfazer
                    </Button>
                )}
            </div>

            {file !== null && (
                <p className="truncate text-xs text-muted-foreground">
                    {file.name} · {formatFileSize(file.size)} — será gravada ao salvar.
                </p>
            )}

            {pendingRemoval && <p className="text-xs text-muted-foreground">Será removida ao salvar.</p>}

            {rejection !== null && (
                <p role="alert" className="text-xs font-medium text-destructive">
                    {rejection}
                </p>
            )}

            <input
                ref={input}
                type="file"
                accept={ACCEPTED_TYPES.join(',')}
                className="hidden"
                tabIndex={-1}
                onChange={(event) => {
                    pick(event.target.files?.[0])
                    // Zera o input: sem isso, reescolher o mesmo arquivo
                    // depois de removê-lo não dispara `change`.
                    event.target.value = ''
                }}
            />
        </div>
    )
}

/**
 * O motivo da recusa, ou null. A dimensão fica com o servidor: medi-la aqui
 * pediria carregar a imagem, e o `dimensions` de lá responde do mesmo jeito.
 */
function rejectionOf(file: File): string | null {
    if (!ACCEPTED_TYPES.includes(file.type)) {
        return 'Envie a imagem em PNG ou JPG.'
    }

    if (file.size > MAX_BYTES) {
        return `A imagem tem ${formatFileSize(file.size)}; o limite é 2 MB.`
    }

    return null
}

/**
 * Um `blob:` para a pré-visualização, revogado quando o arquivo muda ou o
 * campo sai da tela. Criado no efeito, e não num `useMemo`: no StrictMode o
 * efeito roda duas vezes, e a limpeza da primeira revogaria o endereço que a
 * imagem ainda estivesse usando.
 */
function useObjectUrl(file: File | null): string | null {
    const [url, setUrl] = useState<string | null>(null)

    useEffect(() => {
        if (file === null) {
            setUrl(null)
            return
        }

        const next = URL.createObjectURL(file)
        setUrl(next)

        return () => URL.revokeObjectURL(next)
    }, [file])

    return url
}

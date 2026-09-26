import { Head, router, useForm } from '@inertiajs/react'
import {
    ChevronDown,
    CircleAlert,
    Download,
    Eye,
    FileText,
    FileType,
    Pencil,
    Save,
    Scale,
    Sparkles,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { AnalysisDialog } from '@/components/analysis-dialog'
import { LegalCaseTabs } from '@/components/legal-case-tabs'
import { PleadingDocument } from '@/components/pleading-document'
import { RegeneratePleadingDialog } from '@/components/regenerate-pleading-dialog'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { AppLayout } from '@/layouts/app-layout'
import { formatDate, formatPhone, formatPostalCode } from '@/lib/format'
import type { LegalPleading, PleadingLetterhead } from '@/types'

/**
 * As etapas que o agente redator percorre, para a espera não ser uma tela parada.
 *
 * Circulam em vez de parar na última, pelo motivo que o `AnalysisDialog`
 * documenta: não há progresso a relatar numa requisição só.
 */
const DRAFTING_STEPS = [
    'Lendo o enquadramento e as partes…',
    'Redigindo a qualificação do autor e do réu…',
    'Escrevendo a narrativa dos fatos…',
    'Ordenando os fundamentos a partir das teses…',
    'Numerando os pedidos…',
] as const

interface Props {
    legalCase: {
        id: string
        customer_name: string
        procedural_class: string
        is_draft: boolean
        current_step: string
    }
    /** A última versão, ou nulo quando a geração falhou. */
    pleading: LegalPleading | null
    letterhead: PleadingLetterhead
    can: { update: boolean; generate: boolean; export: boolean }
}

/**
 * A minuta da peça: o timbre do escritório e o documento embaixo dele.
 *
 * A segunda aba de uma peça, e a primeira tela do projeto que mostra a peça
 * como peça em vez de como formulário. A divisão da tela é a divisão do que se
 * pode confiar:
 *
 * - **O timbre é moldura.** Nome do escritório, OAB, endereço e telefone vêm da
 *   conta e do usuário logado, são desenhados aqui e não são editáveis. Nenhum
 *   deles passou por um modelo, que é a razão de estarem fora do texto: um
 *   número de OAB inventado num documento protocolado não tem contrapartida.
 * - **O corpo é rascunho.** O agente escreveu, o advogado corrige, e salvar
 *   grava uma versão nova em vez de sobrescrever — o texto do agente continua
 *   existindo ao lado do texto que o advogado decidiu.
 *
 * As lacunas entre colchetes são contadas em cima e não são erro: a qualificação
 * das partes pede estado civil e profissão, que o cadastro de cliente pede mas não
 * exige, e o agente é instruído a marcar o que falta em vez de inventá-lo — como
 * faz com a comarca e com o número do processo de origem. Preencher a qualificação
 * em `/clientes` é o que apaga essas duas; o aviso existe para que ninguém
 * protocole `[estado civil]` sem ver.
 *
 * ## Por que a tela tem dois modos
 *
 * O documento é o que a aba abre: a página ABNT — Times New Roman 12pt,
 * entrelinhas 1,5, margens de 3 x 2 cm — desenhada como sai na impressora, com
 * a citação longa recuada em 4 cm. É a formatação da minuta e de nada mais no
 * sistema; o painel inteiro continua em IBM Plex Sans.
 *
 * Editar é o segundo modo porque o recuo da citação **é por parágrafo**, e um
 * `textarea` só sabe pintar o campo todo com a mesma régua. A geometria e a
 * tipografia valem nos dois — o que se digita já quebra a linha onde vai
 * quebrar no documento —, e o recuo é o que só a leitura mostra. Gravar volta
 * para o documento: a versão nova é para ser lida.
 *
 * ## Exportar e gerar de novo
 *
 * O PDF e o DOCX saem do servidor com o timbre e a mesma régua ABNT, e imprimem
 * a **última versão salva** — por isso ficam desligados enquanto há alteração não
 * salva: o arquivo baixado tem de ser uma versão que o histórico conhece.
 *
 * "Gerar novamente" chama o agente redator sobre a peça como ela está agora e
 * grava a versão seguinte. Nada é sobrescrito, mas a correção do advogado sai
 * da tela, e é isso que a confirmação pergunta.
 */
export default function LegalCasePleading({ legalCase, pleading, letterhead, can }: Props) {
    const form = useForm({ content: pleading?.content ?? '' })
    const [generating, setGenerating] = useState(false)
    const [editing, setEditing] = useState(false)

    // Uma versão nova chegou por fora do formulário — a primeira redação ou a
    // de novo —, e o `useForm` guarda o valor inicial para sempre. Sem isto a
    // tela mostraria o texto antigo marcado como "alterações não salvas".
    useEffect(() => {
        form.setData('content', pleading?.content ?? '')
        setEditing(false)
    }, [pleading?.id])

    const changed = form.data.content !== (pleading?.content ?? '')
    const gaps = pleading?.placeholders.length ?? 0

    const generate = () => {
        setGenerating(true)

        router.post(
            `/pecas/${legalCase.id}/minuta/gerar`,
            {},
            { onFinish: () => setGenerating(false) },
        )
    }

    // Exportar e salvar aparecem duas vezes, acima do timbre e no rodapé: a
    // minuta tem páginas, e nenhum dos dois gestos deveria custar rolar até o
    // fim dela — nem de volta ao topo depois de corrigir o último parágrafo.
    const exportMenu = can.export && (
        <ExportMenu legalCaseId={legalCase.id} changed={changed} disabled={form.processing} />
    )

    const saveButton = can.update && (
        <Button
            type="button"
            disabled={!changed || form.processing}
            onClick={() =>
                form.put(`/pecas/${legalCase.id}/minuta`, {
                    preserveScroll: true,
                    onSuccess: () => setEditing(false),
                })
            }
        >
            <Save />
            {form.processing ? 'Salvando…' : 'Salvar nova versão'}
        </Button>
    )

    return (
        <AppLayout
            title="Minuta da peça"
            subtitle={`${legalCase.customer_name} · ${legalCase.procedural_class}`}
            tabs={
                <LegalCaseTabs
                    legalCaseId={legalCase.id}
                    available
                    current="pleading"
                />
            }
        >
            <Head title="Minuta da peça" />

            {pleading === null ? (
                <Card className="items-center gap-4 px-6 py-16 text-center">
                    <FileText className="size-8 text-muted-foreground" />

                    <div className="space-y-1">
                        <p className="font-medium">Esta peça ainda não tem minuta.</p>
                        <p className="max-w-prose text-sm text-muted-foreground">
                            A redação não foi concluída quando a peça foi registrada —
                            costuma ser o provedor de IA fora do ar ou uma cota esgotada.
                            As teses e os pedidos continuam salvos.
                        </p>
                    </div>

                    {can.generate && (
                        <Button type="button" onClick={generate} disabled={generating}>
                            <Sparkles />
                            {generating ? 'Redigindo…' : 'Gerar minuta'}
                        </Button>
                    )}
                </Card>
            ) : (
                <div className="space-y-4 pb-4">
                    {gaps > 0 && (
                        <Alert>
                            <CircleAlert />
                            <AlertTitle>
                                {gaps === 1
                                    ? '1 lacuna a preencher'
                                    : `${gaps} lacunas a preencher`}
                            </AlertTitle>
                            <AlertDescription>
                                O que o cadastro não informa foi marcado entre colchetes
                                em vez de ser presumido: {pleading.placeholders.join(', ')}.
                            </AlertDescription>
                        </Alert>
                    )}

                    <Card className="gap-0 overflow-hidden py-0">
                        {(exportMenu || saveButton) && (
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/30 px-6 py-3">
                                <p className="text-xs text-muted-foreground">
                                    Versão {pleading.version}
                                    {changed && ' · alterações não salvas'}
                                </p>

                                <div className="flex flex-wrap items-center gap-2">
                                    {exportMenu}
                                    {saveButton}
                                </div>
                            </div>
                        )}

                        {/* O timbre: moldura, não conteúdo. Fica parado enquanto o
                            documento rola, como o papel timbrado fica. */}
                        <header className="sticky top-0 z-10 space-y-1 border-b bg-card px-6 py-5 text-center">
                            <Scale className="mx-auto text-muted-foreground" />

                            {letterhead.firm && (
                                <p className="text-lg font-semibold tracking-tight text-foreground uppercase">
                                    {letterhead.firm}
                                </p>
                            )}

                            {(letterhead.lawyer || letterhead.oab) && (
                                <p className="text-sm text-foreground">
                                    {[letterhead.lawyer, letterhead.oab]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            )}

                            <p className="text-xs text-muted-foreground">
                                {[
                                    addressLine(letterhead.address),
                                    letterhead.phone && formatPhone(letterhead.phone),
                                    letterhead.email,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </header>

                        {/* O campo não é o `Textarea` do shadcn: ele traz borda,
                            anel, padding e tamanho de fonte de formulário, e
                            aqui os quatro seriam desfeitos um a um para que a
                            régua da ABNT valesse. O que sobra do componente
                            seria o elemento. */}
                        {editing ? (
                            <textarea
                                aria-label="Conteúdo da minuta"
                                value={form.data.content}
                                onChange={(e) => form.setData('content', e.target.value)}
                                disabled={form.processing}
                                spellCheck
                                autoFocus
                                className="abnt-page field-sizing-content block min-h-[60vh] resize-none bg-transparent outline-none disabled:opacity-50"
                            />
                        ) : (
                            <PleadingDocument
                                content={form.data.content}
                                className="min-h-[60vh]"
                            />
                        )}

                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 px-6 py-3">
                            <div className="space-y-0.5">
                                <p className="text-xs text-muted-foreground">
                                    Versão {pleading.version} · gerada em{' '}
                                    {formatDate(pleading.created_at)}
                                    {changed && ' · alterações não salvas'}
                                </p>

                                {editing && (
                                    <p className="text-xs text-muted-foreground">
                                        Um parágrafo com {'>'} no início de cada linha sai
                                        recuado em 4 cm no documento, como a citação longa
                                        da ABNT.
                                    </p>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                {exportMenu}

                                {can.generate && (
                                    <RegeneratePleadingDialog
                                        version={pleading.version}
                                        unsaved={changed}
                                        disabled={form.processing || generating}
                                        onConfirm={generate}
                                    />
                                )}

                                {can.update && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={form.processing}
                                        onClick={() => setEditing((on) => !on)}
                                    >
                                        {editing ? <Eye /> : <Pencil />}
                                        {editing ? 'Ver documento' : 'Editar texto'}
                                    </Button>
                                )}

                                {saveButton}
                            </div>
                        </footer>
                    </Card>
                </div>
            )}

            <AnalysisDialog
                open={generating}
                title="Redigindo a minuta"
                hint="A redação pode levar alguns minutos. Mantenha esta aba aberta: ao terminar, o documento abre aqui mesmo."
                messages={DRAFTING_STEPS}
            />
        </AppLayout>
    )
}

interface ExportMenuProps {
    legalCaseId: string
    /** Há texto não salvo: o arquivo sairia de uma versão que o histórico não conhece. */
    changed: boolean
    disabled: boolean
}

/** O PDF e o DOCX da última versão salva, com o timbre. */
function ExportMenu({ legalCaseId, changed, disabled }: ExportMenuProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={changed || disabled}
                    title={changed ? 'Salve as alterações antes de exportar' : undefined}
                >
                    <Download />
                    Exportar
                    <ChevronDown />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end">
                {/* `<a>` de verdade e não visita do Inertia:
                    a resposta é um arquivo, não uma página. */}
                <DropdownMenuItem asChild>
                    <a href={`/pecas/${legalCaseId}/minuta/pdf`} download>
                        <FileText />
                        PDF
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={`/pecas/${legalCaseId}/minuta/docx`} download>
                        <FileType />
                        Word (DOCX)
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    )
}

/**
 * "Av. Paulista, 1000, Conjunto 1402, Bela Vista, São Paulo/SP, CEP 01310-100".
 *
 * Montada das partes que existem: o complemento falta na maioria dos endereços,
 * e uma vírgula solta no meio da linha do timbre é o tipo de detalhe que denuncia
 * um documento montado por software.
 */
const addressLine = (
    address: PleadingLetterhead['address'],
): string | null => {
    if (address === null) {
        return null
    }

    const parts = [
        address.street,
        address.number,
        address.complement,
        address.district,
        address.city && address.state
            ? `${address.city}/${address.state}`
            : address.city,
        address.postal_code && `CEP ${formatPostalCode(address.postal_code)}`,
    ].filter(Boolean)

    return parts.length > 0 ? parts.join(', ') : null
}

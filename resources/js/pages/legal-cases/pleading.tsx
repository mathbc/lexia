import { Head, router, useForm } from '@inertiajs/react'
import { CircleAlert, FileText, Scale, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { AnalysisDialog } from '@/components/analysis-dialog'
import { LegalCaseTabs } from '@/components/legal-case-tabs'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
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
    can: { update: boolean; generate: boolean }
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
 * das partes pede estado civil e profissão, que o cadastro de cliente não guarda,
 * e o agente é instruído a marcá-las em vez de inventá-las. O aviso existe para
 * que ninguém protocole `[estado civil]` sem ver.
 */
export default function LegalCasePleading({ legalCase, pleading, letterhead, can }: Props) {
    const form = useForm({ content: pleading?.content ?? '' })
    const [generating, setGenerating] = useState(false)

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

                        <Textarea
                            aria-label="Conteúdo da minuta"
                            value={form.data.content}
                            onChange={(e) => form.setData('content', e.target.value)}
                            disabled={!can.update || form.processing}
                            spellCheck
                            className="min-h-[60vh] resize-none rounded-none border-0 px-6 py-6 leading-relaxed shadow-none focus-visible:ring-0"
                        />

                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 px-6 py-3">
                            <p className="text-xs text-muted-foreground">
                                Versão {pleading.version} · gerada em{' '}
                                {formatDate(pleading.created_at)}
                                {changed && ' · alterações não salvas'}
                            </p>

                            {can.update && (
                                <Button
                                    type="button"
                                    disabled={!changed || form.processing}
                                    onClick={() =>
                                        form.put(`/pecas/${legalCase.id}/minuta`, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {form.processing ? 'Salvando…' : 'Salvar nova versão'}
                                </Button>
                            )}
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

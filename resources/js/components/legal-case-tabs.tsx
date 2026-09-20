import { Tabs } from '@/components/ui/tabs'

/**
 * As duas faces de uma peça: o assistente que a monta e o documento que ela é.
 *
 * A aba da minuta só aparece quando há o que ver nela. Enquanto a peça é um
 * rascunho sem nenhuma versão gravada, o documento ainda não existe — ele nasce
 * ao concluir a revisão forense —, e oferecer a aba seria oferecer uma porta
 * para uma tela vazia. É a mesma regra do `AccountTabs`, que esconde "Usuários"
 * de quem não pode geri-los, e a do menu lateral: quem não pode, não vê.
 *
 * Depois de concluída a peça a aba fica, inclusive quando a geração falhou — ali
 * a tela vazia tem o que dizer e um botão para tentar de novo.
 */
export function LegalCaseTabs({
    legalCaseId,
    available,
    current,
}: {
    legalCaseId: string
    /** Há minuta, ou a peça já foi registrada e pode ter uma. */
    available: boolean
    current: 'form' | 'pleading'
}) {
    if (!available) {
        return null
    }

    return (
        <Tabs
            items={[
                {
                    label: 'Assistente',
                    href: `/pecas/${legalCaseId}/editar`,
                    active: current === 'form',
                },
                {
                    label: 'Minuta',
                    href: `/pecas/${legalCaseId}/minuta`,
                    active: current === 'pleading',
                },
            ]}
        />
    )
}

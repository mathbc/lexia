/**
 * Os links do topo, num lugar só.
 *
 * O `href` é uma âncora, não uma rota: a landing é uma página única e cada
 * seção é uma `<Section>` com o mesmo id. Somar um item aqui o faz aparecer de
 * uma vez no menu do desktop, na gaveta do celular e no rodapé.
 */
export interface LandingLink {
    label: string
    href: string
}

export const LANDING_LINKS: LandingLink[] = [
    { label: 'Home', href: '#home' },
    { label: 'Serviços', href: '#servicos' },
    { label: 'Sobre nós', href: '#sobre' },
    { label: 'Contato', href: '#contato' },
]

import { Mail } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Section } from './section'

/** O endereço de contato. Provisório — trocar pelo e-mail real do escritório. */
const CONTACT_EMAIL = 'contato@lexia.com.br'

export function ContactSection() {
    return (
        <Section
            id="contato"
            title="Contato"
            description="Fale com a equipe para conhecer a plataforma."
        >
            <div className="flex justify-center">
                <Button asChild size="lg" variant="outline">
                    <a href={`mailto:${CONTACT_EMAIL}`}>
                        <Mail />
                        {CONTACT_EMAIL}
                    </a>
                </Button>
            </div>
        </Section>
    )
}

import { Head } from '@inertiajs/react'
import { AboutSection } from '@/components/landing/about-section'
import { ContactSection } from '@/components/landing/contact-section'
import { HeroSection } from '@/components/landing/hero-section'
import { LandingNav } from '@/components/landing/landing-nav'
import { ServicesSection } from '@/components/landing/services-section'
import { SiteFooter } from '@/components/landing/site-footer'

/**
 * A landing.
 *
 * A montagem é só a ordem das seções — cada uma mora em
 * `resources/js/components/landing/`, num arquivo com o nome da seção, para
 * que mexer no texto de "Serviços" não passe por aqui.
 */
export default function Welcome() {
    return (
        <>
            <Head title="LexIA" />

            {/* A home é sempre escura, independentemente do tema escolhido: a
                classe `dark` na própria página troca os tokens para todo mundo
                que está dentro — inclusive os botões e os cartões. */}
            <div className="dark min-h-svh bg-background text-foreground">
                <LandingNav />

                <main>
                    <HeroSection />
                    <ServicesSection />
                    <AboutSection />
                    <ContactSection />
                </main>

                <SiteFooter />
            </div>
        </>
    )
}

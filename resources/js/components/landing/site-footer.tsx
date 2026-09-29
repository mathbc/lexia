import { BrandLogo } from './brand-logo'
import { LANDING_LINKS } from './navigation'

export function SiteFooter() {
    return (
        <footer className="border-t border-border px-6 py-10">
            <div className="mx-auto flex w-full max-w-5xl flex-col items-center gap-6 sm:flex-row sm:justify-between">
                <BrandLogo className="text-lg text-foreground" />

                <ul className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
                    {LANDING_LINKS.map((link) => (
                        <li key={link.href}>
                            <a
                                href={link.href}
                                className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {link.label}
                            </a>
                        </li>
                    ))}
                </ul>

                <p className="text-sm text-muted-foreground">
                    © {new Date().getFullYear()} LexIA
                </p>
            </div>
        </footer>
    )
}

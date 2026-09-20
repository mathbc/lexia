import { Scale } from 'lucide-react'
import { LANDING_LINKS } from './navigation'

export function SiteFooter() {
    return (
        <footer className="border-t border-border px-6 py-10">
            <div className="mx-auto flex w-full max-w-5xl flex-col items-center gap-6 sm:flex-row sm:justify-between">
                <div className="flex items-center gap-2 text-foreground">
                    <Scale className="size-4" />
                    <span className="font-semibold">LexIA</span>
                </div>

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

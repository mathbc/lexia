import { LinkTabs } from '@/components/ui/link-tabs'
import type { AccountAbilities } from '@/types'

/**
 * The two faces of an account: its registration data and its people.
 *
 * The users tab is hidden rather than disabled for whoever cannot manage them —
 * a lawyer opening their own account sees a single-section page.
 */
export function AccountTabs({
    accountId,
    can,
    current,
}: {
    accountId: string
    can: AccountAbilities
    current: 'general' | 'users'
}) {
    return (
        <LinkTabs
            items={[
                { label: 'Dados gerais', href: `/contas/${accountId}`, active: current === 'general' },
                ...(can.manage_users
                    ? [{
                        label: 'Usuários',
                        href: `/contas/${accountId}/usuarios`,
                        active: current === 'users',
                    }]
                    : []),
            ]}
        />
    )
}

/** Mirrors HandleInertiaRequests::currentUser(). */
export interface AuthUser {
    id: string
    name: string
    email: string
    role: UserRoleValue
    role_label: string
    type: string
    type_label: string
    is_platform_admin: boolean
    account: {
        id: string
        name: string
        type: string
        type_label: string
        is_operational: boolean
    }
}

export type UserRoleValue = 'account_admin' | 'admin' | 'lawyer'

/** A backed enum published as {value,label} by the server. */
export interface Option {
    value: string
    label: string
}

export interface PageProps {
    auth: { user: AuthUser | null }
    flash: { success: string | null; error: string | null }
    errors: Record<string, string>
    [key: string]: unknown
}

/** Laravel's length-aware paginator, as Inertia serialises it. */
export interface Paginated<T> {
    data: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
    links: { url: string | null; label: string; active: boolean }[]
}

export interface UserRow {
    id: string
    name: string
    email: string
    role: UserRoleValue
    type: string
    enabled: boolean
    oab_number: string | null
    oab_state: string | null
}

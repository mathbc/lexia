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

export type UserRoleValue = 'platform_admin' | 'account_admin' | 'admin' | 'lawyer'

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

/** The accounts table, as the Account model serialises. */
export interface Account {
    id: string
    name: string
    legal_name: string | null
    type: string
    federal_id: string | null
    oab_number: string | null
    oab_state: string | null
    email: string
    phone: string
    postal_code: string
    street: string
    number: string
    complement: string | null
    district: string
    city: string
    state: string
    active: boolean
    enabled: boolean
}

export interface AccountRow extends Account {
    users_count: number
}

/** Mirrors AccountPageProps::for() — shared by both tabs of an account. */
export interface AccountAbilities {
    update: boolean
    toggle_status: boolean
    manage_users: boolean
}

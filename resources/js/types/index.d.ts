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

/** The clients table, as the Customer model serialises. */
export interface Customer {
    id: string
    name: string
    legal_name: string | null
    type: string
    cpf: string | null
    cnpj: string | null
    email: string
    phone: string
    postal_code: string
    street: string
    number: string
    complement: string | null
    district: string
    city: string
    state: string
}

/** Mirrors CustomerPageProps::abilities(); the listing adds `create`. */
export interface CustomerAbilities {
    update: boolean
    delete: boolean
}

/** A practice area, as the PracticeArea model serialises. */
export interface PracticeArea {
    id: string
    slug: string
    label: string
    cnj_subject_roots: number[]
    position: number
}

/**
 * A CNJ procedural class. `code` is the official identifier — the number that
 * shows on the case record — and `scope` only comes through when the row was
 * loaded from a practice area, where it says whether the class belongs to that
 * area or is borrowed from the civil trunk.
 */
export interface ProceduralClass {
    id: string
    code: number
    name: string
    slug: string
    root_code: number
    path: string[]
    abbreviation: string | null
    nature: string | null
    legal_norm: string | null
    legal_article: string | null
    active_party: string | null
    passive_party: string | null
    has_own_numbering: boolean
    is_filing_class: boolean
    is_cross_cutting: boolean
    jurisdictions: string[]
    pivot?: { scope: 'specific' | 'generic' }
}

/** A pleading, as the LegalCase model serialises. */
export interface LegalCase {
    id: string
    customer_id: string
    practice_area_id: string
    procedural_class_id: string
    created_at: string
    updated_at: string
}

/**
 * One CNJ competence, resolved server-side by Jurisdiction::toTag(). `branch`
 * and `degree` are what the class picker filters on; the raw `value` is never
 * shown to anyone.
 */
export interface JurisdictionTag {
    value: string
    label: string
    short_label: string
    branch: string
    degree: string
}

/** A procedural class as ProceduralClassOptionsQuery projects it. */
export interface ProceduralClassOption {
    id: string
    code: number
    name: string
    abbreviation: string | null
    /** Leitura nossa da classe: o que é e quando cabe. Não vem do CNJ. */
    description: string | null
    /** As matérias tipicamente discutidas nela — também editoriais. */
    typical_subjects: string[]
    legal_basis: string | null
    nature: string | null
    active_party: string | null
    passive_party: string | null
    has_own_numbering: boolean
    is_filing_class: boolean
    is_cross_cutting: boolean
    /** O caminho na árvore do CNJ, da raiz até a própria classe. */
    path: string[]
    /** Own to the area, or borrowed from the civil trunk. */
    scope: 'specific' | 'generic'
    jurisdictions: JurisdictionTag[]
}

/** A pleading as LegalCaseIndexQuery projects it for a card. */
export interface LegalCaseCard {
    id: string
    customer: { id: string; display_name: string }
    practice_area: { slug: string; label: string }
    procedural_class: { code: number; name: string; abbreviation: string | null }
    created_at: string | null
}

/** Mirrors LegalCasePageProps::abilities(). */
export interface LegalCaseAbilities {
    view: boolean
    create: boolean
}

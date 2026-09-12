/** Digits only — what the API stores and expects. */
export const digits = (value: string): string => value.replace(/\D/g, '')

export const formatCnpj = (value: string): string =>
    digits(value)
        .slice(0, 14)
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2')

export const formatCpf = (value: string): string =>
    digits(value)
        .slice(0, 11)
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/(\d{3})(\d{1,2})$/, '$1-$2')

export const formatPostalCode = (value: string): string =>
    digits(value).slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2')

/** Handles both 10-digit landlines and 11-digit mobiles. */
export const formatPhone = (value: string): string => {
    const raw = digits(value).slice(0, 11)

    if (raw.length <= 10) {
        return raw.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d)/, '$1-$2')
    }

    return raw.replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d)/, '$1-$2')
}

/**
 * Check digits for CPF/CNPJ, mirroring the server-side Rules.
 *
 * Client-side only to give immediate feedback; the server remains the source
 * of truth.
 */
const modulo11 = (digitsList: number[], weights: number[]): number => {
    const sum = weights.reduce((total, weight, index) => total + (digitsList[index] ?? 0) * weight, 0)
    const remainder = sum % 11

    return remainder < 2 ? 0 : 11 - remainder
}

const allSame = (value: string): boolean => /^(\d)\1+$/.test(value)

export const isValidCpf = (value: string): boolean => {
    const raw = digits(value)
    if (raw.length !== 11 || allSame(raw)) return false

    const nums = [...raw].map(Number)
    const first = modulo11(nums, [10, 9, 8, 7, 6, 5, 4, 3, 2])
    const second = modulo11(nums, [11, 10, 9, 8, 7, 6, 5, 4, 3, 2])

    return nums[9] === first && nums[10] === second
}

export const isValidCnpj = (value: string): boolean => {
    const raw = digits(value)
    if (raw.length !== 14 || allSame(raw)) return false

    const nums = [...raw].map(Number)
    const first = modulo11(nums, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])
    const second = modulo11(nums, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])

    return nums[12] === first && nums[13] === second
}

/**
 * How an account identifies itself legally: a firm by its CNPJ, an individual
 * by their OAB enrolment. Mirrors Account::identifier() on the server.
 */
export const accountIdentifier = (account: {
    type: string
    federal_id: string | null
    oab_number: string | null
    oab_state: string | null
}): string | null => {
    if (account.type === 'law_firm') {
        return account.federal_id ? formatCnpj(account.federal_id) : null
    }

    return account.oab_number && account.oab_state
        ? `OAB/${account.oab_state} ${account.oab_number}`
        : null
}

/** Iniciais para o avatar: as do primeiro e do último nome. */
export const initials = (name: string): string => {
    const parts = name.trim().split(/\s+/)
    const first = parts[0]?.[0] ?? ''
    const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : ''

    return (first + last).toUpperCase()
}

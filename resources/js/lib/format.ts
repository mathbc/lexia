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

import { Field, Input, Select } from '@/components/ui/field'
import type { Option } from '@/types'

export interface UserFormValues {
    name: string
    email: string
    type: string
    role: string
    oab_number: string
    oab_state: string
    birth_date: string
}

interface Props {
    values: UserFormValues
    errors: Partial<Record<keyof UserFormValues, string>>
    onChange: <K extends keyof UserFormValues>(key: K, value: UserFormValues[K]) => void
    types: Option[]
    states: Option[]
    roles: Option[]
    canChangeRole: boolean
}

/**
 * Shared between creating and editing a user, so the two screens cannot drift.
 */
export function UserFormFields({ values, errors, onChange, types, states, roles, canChangeRole }: Props) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Nome" error={errors.name} required>
                <Input value={values.name} onChange={(e) => onChange('name', e.target.value)} autoFocus required />
            </Field>

            <Field label="E-mail" error={errors.email} required>
                <Input type="email" value={values.email} onChange={(e) => onChange('email', e.target.value)} required />
            </Field>

            <Field label="Tipo" error={errors.type} hint="Informativo" required>
                <Select value={values.type} onChange={(e) => onChange('type', e.target.value)} options={types} placeholder="Selecione" />
            </Field>

            {canChangeRole && (
                <Field label="Perfil" error={errors.role} hint="Define as permissões" required>
                    <Select
                        value={values.role}
                        onChange={(e) => onChange('role', e.target.value)}
                        options={roles}
                        placeholder="Selecione"
                    />
                </Field>
            )}

            <Field label="Número da OAB" error={errors.oab_number}>
                <Input
                    value={values.oab_number}
                    onChange={(e) => onChange('oab_number', e.target.value.toUpperCase())}
                    placeholder="123456"
                />
            </Field>

            <Field label="Seccional" error={errors.oab_state}>
                <Select
                    value={values.oab_state}
                    onChange={(e) => onChange('oab_state', e.target.value)}
                    options={states}
                    placeholder="Selecione"
                />
            </Field>

            <Field label="Data de nascimento" error={errors.birth_date}>
                <Input type="date" value={values.birth_date} onChange={(e) => onChange('birth_date', e.target.value)} />
            </Field>
        </div>
    )
}

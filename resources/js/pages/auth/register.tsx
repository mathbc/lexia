import { Head, Link, useForm } from '@inertiajs/react'
import type { FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, Input, Select } from '@/components/ui/field'
import { digits, formatCnpj, formatPhone, formatPostalCode, isValidCnpj } from '@/lib/format'
import type { Option } from '@/types'

interface Props {
    accountTypes: Option[]
    states: Option[]
    userTypes: Option[]
}

/**
 * Sign-up creates the account and its first user together, which is why this
 * is not Fortify's /register.
 */
export default function Register({ accountTypes, states, userTypes }: Props) {
    const form = useForm({
        // account
        name: '',
        legal_name: '',
        type: 'individual',
        federal_id: '',
        oab_number: '',
        oab_state: '',
        email: '',
        phone: '',
        postal_code: '',
        street: '',
        number: '',
        complement: '',
        district: '',
        city: '',
        state: '',
        // first user (AccountAdmin)
        owner_name: '',
        owner_email: '',
        owner_birth_date: '',
        owner_type: 'lawyer',
        password: '',
        password_confirmation: '',
    })

    const isFirm = form.data.type === 'law_firm'
    const cnpjLooksWrong = isFirm && form.data.federal_id.length > 0 && !isValidCnpj(form.data.federal_id)

    const lookupPostalCode = async (value: string) => {
        const raw = digits(value)
        if (raw.length !== 8) return

        try {
            const response = await fetch(`https://viacep.com.br/ws/${raw}/json/`)
            const data = await response.json()
            if (data.erro) return

            form.setData((current) => ({
                ...current,
                street: data.logradouro || current.street,
                district: data.bairro || current.district,
                city: data.localidade || current.city,
                state: data.uf || current.state,
            }))
        } catch {
            // Never block sign-up on a third-party lookup.
        }
    }

    const submit = (event: FormEvent) => {
        event.preventDefault()
        form.post('/cadastro', { onFinish: () => form.reset('password', 'password_confirmation') })
    }

    return (
        <div className="mx-auto max-w-3xl px-6 py-12">
            <Head title="Criar conta" />

            <h1 className="text-center text-3xl font-semibold text-foreground">Criar conta</h1>
            <p className="mt-2 mb-8 text-center text-sm text-muted-foreground">
                Já tem conta?{' '}
                <Link href="/login" className="font-medium text-foreground underline-offset-4 hover:underline">
                    Entre aqui
                </Link>
            </p>

            <form onSubmit={submit} className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Dados da conta</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <Field label="Tipo de conta" error={form.errors.type} required>
                            <Select
                                value={form.data.type}
                                onValueChange={(value) => form.setData('type', value)}
                                options={accountTypes}
                            />
                        </Field>

                        <Field label={isFirm ? 'Nome fantasia' : 'Nome'} error={form.errors.name} required>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </Field>

                        {isFirm ? (
                            <>
                                <Field label="Razão social" error={form.errors.legal_name} required>
                                    <Input value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} />
                                </Field>
                                <Field label="CNPJ" required error={form.errors.federal_id ?? (cnpjLooksWrong ? 'CNPJ inválido.' : undefined)}>
                                    <Input
                                        value={formatCnpj(form.data.federal_id)}
                                        onChange={(e) => form.setData('federal_id', digits(e.target.value))}
                                        inputMode="numeric"
                                        placeholder="00.000.000/0000-00"
                                    />
                                </Field>
                            </>
                        ) : (
                            <>
                                <Field label="Número da OAB" error={form.errors.oab_number} required>
                                    <Input value={form.data.oab_number} onChange={(e) => form.setData('oab_number', e.target.value.toUpperCase())} />
                                </Field>
                                <Field label="Seccional" error={form.errors.oab_state} required>
                                    <Select
                                        value={form.data.oab_state}
                                        onValueChange={(value) => form.setData('oab_state', value)}
                                        options={states}
                                        placeholder="Selecione"
                                    />
                                </Field>
                            </>
                        )}

                        <Field label="E-mail da conta" error={form.errors.email} required>
                            <Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                        </Field>
                        <Field label="Telefone" error={form.errors.phone} required>
                            <Input
                                value={formatPhone(form.data.phone)}
                                onChange={(e) => form.setData('phone', digits(e.target.value))}
                                inputMode="tel"
                                placeholder="(11) 90000-0000"
                            />
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Endereço</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-6">
                        <Field label="CEP" error={form.errors.postal_code} required className="sm:col-span-2">
                            <Input
                                value={formatPostalCode(form.data.postal_code)}
                                onChange={(e) => form.setData('postal_code', digits(e.target.value))}
                                onBlur={(e) => lookupPostalCode(e.target.value)}
                                inputMode="numeric"
                                placeholder="00000-000"
                            />
                        </Field>
                        <Field label="Logradouro" error={form.errors.street} required className="sm:col-span-3">
                            <Input value={form.data.street} onChange={(e) => form.setData('street', e.target.value)} />
                        </Field>
                        <Field label="Número" error={form.errors.number} required>
                            <Input value={form.data.number} onChange={(e) => form.setData('number', e.target.value)} />
                        </Field>
                        <Field label="Complemento" error={form.errors.complement} className="sm:col-span-2">
                            <Input value={form.data.complement} onChange={(e) => form.setData('complement', e.target.value)} />
                        </Field>
                        <Field label="Bairro" error={form.errors.district} required className="sm:col-span-2">
                            <Input value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} />
                        </Field>
                        <Field label="Cidade" error={form.errors.city} required>
                            <Input value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} />
                        </Field>
                        <Field label="UF" error={form.errors.state} required>
                            <Select
                                value={form.data.state}
                                onValueChange={(value) => form.setData('state', value)}
                                options={states}
                                placeholder="UF"
                            />
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Responsável pela conta</CardTitle>
                        <CardDescription>Este usuário será o Admin da Conta.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <Field label="Nome completo" error={form.errors.owner_name} required>
                            <Input value={form.data.owner_name} onChange={(e) => form.setData('owner_name', e.target.value)} required />
                        </Field>
                        <Field label="E-mail de acesso" error={form.errors.owner_email} required>
                            <Input type="email" value={form.data.owner_email} onChange={(e) => form.setData('owner_email', e.target.value)} required />
                        </Field>
                        <Field label="Tipo" error={form.errors.owner_type} required>
                            <Select
                                value={form.data.owner_type}
                                onValueChange={(value) => form.setData('owner_type', value)}
                                options={userTypes}
                            />
                        </Field>
                        <Field label="Data de nascimento" error={form.errors.owner_birth_date}>
                            <Input type="date" value={form.data.owner_birth_date} onChange={(e) => form.setData('owner_birth_date', e.target.value)} />
                        </Field>
                        <Field label="Senha" error={form.errors.password} required>
                            <Input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" required />
                        </Field>
                        <Field label="Confirme a senha" error={form.errors.password_confirmation} required>
                            <Input type="password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" required />
                        </Field>
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Criando conta…' : 'Criar conta'}
                    </Button>
                </div>
            </form>
        </div>
    )
}

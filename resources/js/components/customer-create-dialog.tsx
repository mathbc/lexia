import { useForm } from '@inertiajs/react'
import { Plus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { CustomerFormFields, type CustomerFormValues } from '@/components/customer-form-fields'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog'
import type { Option } from '@/types'

const EMPTY: CustomerFormValues = {
    name: '',
    legal_name: '',
    type: '',
    cpf: '',
    cnpj: '',
    marital_status: '',
    occupation: '',
    birth_date: '',
    email: '',
    phone: '',
    postal_code: '',
    street: '',
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
}

interface Props {
    customerTypes: Option[]
    maritalStatuses: Option[]
    states: Option[]
    /** O cliente recém-criado, para quem chamou já deixá-lo escolhido. */
    onCreated: (customer: Option) => void
}

/**
 * Cadastrar um cliente sem sair da tela em que ele faz falta.
 *
 * São os mesmos campos de `/clientes/novo` — o `CustomerFormFields` é o mesmo
 * componente — e o mesmo destino: `POST /clientes`, com a mesma Policy e as
 * mesmas regras de validação. O que muda é só a volta: `inline` diz à
 * `CreateCustomer` para devolver a página de onde veio em vez de navegar para
 * o cliente novo, e a Action devolve junto o par `{value,label}` que o select
 * precisa.
 *
 * Daí o `preserveState`: o formulário da peça — o passo, a área, a classe —
 * continua de pé enquanto o cadastro acontece por cima dele.
 */
export function CustomerCreateDialog({ customerTypes, maritalStatuses, states, onCreated }: Props) {
    const [open, setOpen] = useState(false)
    const form = useForm<CustomerFormValues>({ ...EMPTY })

    // Fechar é desistir: o rascunho e os erros não sobrevivem para reaparecer
    // fora de contexto na próxima abertura.
    const change = (next: boolean) => {
        setOpen(next)

        if (!next) {
            form.reset()
            form.clearErrors()
        }
    }

    const submit = (event: FormEvent) => {
        event.preventDefault()

        form.transform((data) => ({ ...data, inline: true }))
        form.post('/clientes', {
            preserveState: true,
            preserveScroll: true,
            // Mesma URL de volta: sem `replace`, o cadastro deixaria uma
            // entrada repetida no histórico.
            replace: true,
            onSuccess: (page) => {
                const created = (page.props as { createdCustomer?: Option | null }).createdCustomer

                if (!created) {
                    return
                }

                form.reset()
                form.clearErrors()
                setOpen(false)
                onCreated(created)
            },
        })
    }

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogTrigger asChild>
                <Button type="button" variant="ghost" size="sm" className="-my-1 h-7 px-2 text-xs">
                    <Plus />
                    Novo cliente
                </Button>
            </DialogTrigger>

            {/* `p-0` e o corpo com rolagem própria: o cadastro de cliente é
                longo, e é ele que rola — o título e os botões ficam à vista. */}
            <DialogContent className="gap-0 p-0 sm:max-w-3xl">
                {/* `pr-14` porque o `p-6` daqui sobrepõe o `pr-8` padrão do
                    cabeçalho: sem ele o título passa por baixo do X no celular. */}
                <DialogHeader className="border-b p-6 pr-14">
                    <DialogTitle>Novo cliente</DialogTitle>
                    <DialogDescription>
                        O cliente é cadastrado na sua conta e já fica escolhido nesta peça.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                    <div className="min-h-0 flex-1 space-y-6 overflow-y-auto p-6">
                        <CustomerFormFields
                            values={form.data}
                            errors={form.errors}
                            set={(patch) => form.setData((current) => ({ ...current, ...patch }))}
                            customerTypes={customerTypes}
                            maritalStatuses={maritalStatuses}
                            states={states}
                            disabled={form.processing}
                        />
                    </div>

                    <DialogFooter className="border-t p-6">
                        <Button type="button" variant="outline" onClick={() => change(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Salvando…' : 'Cadastrar cliente'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    )
}

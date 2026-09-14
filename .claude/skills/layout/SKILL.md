---
name: layout
description: Layout and design-system conventions for the LexIA interface — semantic color tokens, Tailwind 4, shadcn/ui (new-york), AppLayout, listing tables and the three-dot row-actions dropdown. Use when building or changing any Inertia/React screen: a table, a form, a filter panel, a page header, or a row action (visualizar, editar, excluir). Also use before adding a color, a class or a new UI component.
---

# Layout

A interface é **Inertia + React 19 + Tailwind 4 + shadcn/ui** no estilo
*new-york*. Os componentes do shadcn não vêm de um pacote: são código do
projeto em `resources/js/components/ui/`, e editá-los localmente é o
mecanismo de customização. `npx shadcn@latest add <componente>` cai no lugar
certo — o `components.json` já aponta para lá e para o `cn` em `@/lib/utils`.

Quem compõe em cima disso — `Pagination`, `RowActions`, `AccountTabs` — vive
em `resources/js/components/`, fora de `ui/`.

## Cores

A paleta é croma 0: preto, branco e cinzas. **Cor só aparece quando significa
alguma coisa** — erro e sucesso. Não existe azul de link, roxo de destaque nem
badge colorido por categoria.

Um componente nunca nomeia um cinza, só o papel que ele cumpre. Os valores
vivem em `:root` e `.dark` em `resources/css/app.css`, e `@theme inline` os
publica como utilitários:

| Token | Papel |
|---|---|
| `bg-background` / `text-foreground` | o corpo da página |
| `bg-card` / `text-card-foreground` | superfície levantada: cartão, cabeçalho, painel de filtros |
| `bg-popover` / `text-popover-foreground` | o que flutua: dropdown, select, tooltip |
| `bg-primary` / `text-primary-foreground` | a ação principal da tela |
| `bg-secondary` | ação secundária, filtro ativo |
| `bg-muted` / `text-muted-foreground` | fundo discreto e texto de apoio |
| `bg-accent` / `text-accent-foreground` | estado de hover e foco de item interativo |
| `bg-destructive` / `text-destructive` | erro, exclusão, revogação |
| `bg-success` / `text-success` | confirmação, badge de ativo |
| `border-border`, `bg-input`, `ring-ring` | borda, campo, anel de foco |
| `bg-sidebar*` | o menu lateral, que é branco como os cartões |

Regras que caem daí:

- **Nunca** `bg-gray-100`, `text-slate-500`, `#fff`, `rgb(...)` ou
  `bg-[#eee]`. Para um cinza avulso que nenhum papel cobre, `neutral-*` do
  Tailwind — a escala que a paleta já usa —, e só.
- Precisa de um tom entre dois? Use opacidade sobre o token:
  `bg-muted/50`, `bg-destructive/10`, `ring-ring/50`.
- `dark:` é exceção. O tema é uma classe na raiz e o token já troca sozinho;
  escrever `dark:` num componente costuma ser sinal de que o cinza foi
  nomeado direto.
- Cor nova, ou marca futura, entra em `app.css` e em nenhum outro arquivo.

## Tailwind 4

- **Não existe `tailwind.config.js`.** O tema é CSS: `@theme` para o que é
  estático (fontes), `@theme inline` para o que aponta para variável de tempo
  de execução (as cores). `@source` já varre blade e `tsx`.
- A classe precisa existir **literal** no código: `text-${tone}-500` não
  compila. Variação é `cva` no componente, não string montada.
- `cn()` (clsx + tailwind-merge) sempre que um componente aceita `className`:
  é o que deixa a chamada sobrescrever sem duplicar utilitário.
- `size-8` em vez de `h-8 w-8`; `gap-*` em vez de margem entre irmãos.
- Raio pelo `--radius`: `rounded-md`, `rounded-lg`.
- Ícones são do **lucide-react** e não levam tamanho: o `[&_svg]:size-4` do
  `Button` e do `DropdownMenuItem` já resolve.

## A página

`AppLayout` é uma grade de altura fixa — só a coluna do meio rola. Ele recebe:

| Prop | Para quê |
|---|---|
| `title` / `subtitle` | o nome do registro e o que o identifica (documento, status) |
| `actions` | **uma** ação principal, à direita do cabeçalho |
| `tabs` | as seções de um mesmo registro, sempre como URL |
| `filters` / `activeFilters` | o painel lateral direito e o contador no botão |

Filtro não vai no corpo da página: vai no painel, e toda navegação de filtro
usa `preserveState` + `replace`, para que o painel siga aberto e o histórico
não encha.

## A tabela de listagem

```tsx
<Card className="gap-0 overflow-hidden py-0">
    <Table>
        <TableHeader className="bg-muted/50">
            <TableRow>
                <TableHead>Nome</TableHead>
                <TableHead>Status</TableHead>
                {/* A coluna de ações é a última, estreita e sem rótulo visível. */}
                <TableHead className="w-12">
                    <span className="sr-only">Ações</span>
                </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            {rows.data.map((row) => (
                <TableRow key={row.id}>
                    <TableCell className="py-3 whitespace-normal">…</TableCell>
                    <TableCell className="py-3">
                        <Badge variant={row.active ? 'success' : 'muted'}>…</Badge>
                    </TableCell>
                    <TableCell className="py-3 text-right">
                        <RowActions label={`Ações de ${row.name}`} actions={[…]} />
                    </TableCell>
                </TableRow>
            ))}

            {rows.data.length === 0 && (
                <TableRow className="hover:bg-transparent">
                    <TableCell colSpan={3} className="py-12 text-center text-muted-foreground">
                        Nenhum registro encontrado.
                    </TableCell>
                </TableRow>
            )}
        </TableBody>
    </Table>
</Card>

<Pagination page={rows} label="Paginação de contas" />
```

- Uma única coluna pode quebrar linha (`whitespace-normal`), normalmente a do
  nome; o resto fica em `whitespace-nowrap`, que é o padrão da `TableCell`.
- Número lido em coluna leva `tabular`.
- Estado vazio é uma linha com `colSpan`, nunca uma tabela sem `tbody`.

## Ações de linha: o botão de três pontos

**Toda ação de uma linha vive dentro de `RowActions`**
(`@/components/row-actions`). Não se desenha `Editar`, `Excluir` ou `Abrir`
soltos na linha: a coluna guarda um botão só — `MoreHorizontal`, `ghost`,
`size-8` — independentemente de quantas ações existam. É o que permite somar
uma operação sem alargar a tabela.

```tsx
<RowActions
    label={`Ações de ${user.name}`}
    actions={[
        { label: 'Visualizar', icon: Eye, href: `${base}/${user.id}` },
        can.update && { label: 'Editar', icon: Pencil, href: `${base}/${user.id}/editar` },
        can.toggle_status && {
            label: user.enabled ? 'Desabilitar acesso' : 'Habilitar acesso',
            icon: user.enabled ? UserX : UserCheck,
            href: `${base}/${user.id}/status`,
            method: 'patch',
            separated: true,
        },
        can.delete && {
            label: 'Excluir',
            icon: Trash2,
            href: `${base}/${user.id}`,
            method: 'delete',
            destructive: true,
            confirm: `Excluir ${user.name}? A ação não pode ser desfeita.`,
            separated: true,
        },
    ]}
/>
```

A API, e o porquê de cada parte:

| Campo | Regra |
|---|---|
| `label` | verbo no infinitivo: *Visualizar*, *Editar*, *Excluir*, *Desabilitar acesso* |
| `icon` | obrigatório — o menu é lido pelo par ícone + rótulo, como o menu lateral |
| `href` + `method` | `get` vira `<a>` de verdade (ctrl+clique funciona); os outros métodos viram botão |
| `onSelect` | para o que não é navegação: abrir um diálogo, chamar o `router` |
| `destructive` | pinta de vermelho; reservado ao que destrói ou revoga |
| `confirm` | pergunta antes; obrigatório no que não se desfaz |
| `separated` | abre um grupo com um separador acima |

E as regras que não estão no tipo:

1. **Ordem fixa:** ler → editar → mudar estado → destruir. O destrutivo é
   sempre o último, e sempre separado do resto.
2. **Quem não pode, não vê.** Um item inalcançável some da lista em vez de
   aparecer desabilitado — a mesma regra do menu lateral. Itens `false`,
   `null` e `undefined` são descartados, então a chamada escreve
   `can.update && { … }`.
3. **A permissão vem do servidor.** A página recebe `can` da Action (ver
   `AccountPageProps::for()`); nada de recalcular hierarquia de papel no
   cliente. A Policy continua sendo a defesa real — o menu só evita oferecer
   uma porta fechada.
4. **Sem ação nenhuma, sem botão.** `RowActions` devolve `null` com a lista
   vazia, e a linha fica limpa.
5. O `label` do menu nomeia a linha (`Ações de Fulano`) porque a coluna se
   repete em toda a tabela e o leitor de tela precisa distinguir uma da outra.

A confirmação hoje é o `window.confirm`, isolado dentro de `RowActions`:
quando existir um `AlertDialog` no projeto, troca-se ali, e nenhuma chamada
muda.

## Formulários

- `Field` é a unidade: rótulo, controle, dica e erro. Ele enxerta `id` e
  `aria-invalid` no filho, que é o que liga rótulo e erro a um `Select` do
  Radix — um botão, não um `<select>`.
- O `Select` que `field.tsx` reexporta é o `SelectInput`: fala
  `onValueChange`, não `onChange`, e reserva a string vazia para "nada
  escolhido".
- Botão principal à direita, `Cancelar` como `outline` ao lado, e a ação
  destrutiva (desativar, revogar) à esquerda, separada delas.
- Rótulo, placeholder e mensagem em português; a prop e o campo, em inglês.

## Antes de dar por pronto

- [ ] Nenhuma cor literal nem `gray-*`/`slate-*`: só token semântico.
- [ ] Nenhuma ação solta na linha da tabela — tudo dentro de `RowActions`.
- [ ] O que o usuário não pode fazer não aparece, e a Policy também barra.
- [ ] Destrutivo é vermelho, é o último e pede confirmação.
- [ ] Tabela tem estado vazio, e a coluna de ações tem `sr-only`.
- [ ] Ícone em todo item de menu e em toda ação principal.
- [ ] `npm run types:check` passa.

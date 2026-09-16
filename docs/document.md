# Document — o documento que instrui a peça

`App\Domain\Documents\Models\Document` · tabela `documents`

## Em uma frase

Um **arquivo que acompanha a peça** para provar o que ela afirma: o contrato, a
procuração, o comprovante de pagamento, o laudo, o boletim de ocorrência.

## O conceito

No processo civil, a petição inicial vai acompanhada dos **documentos
indispensáveis à propositura da ação** (CPC/2015, art. 320) — e, na prática, de
todos os documentos que sustentam os fatos narrados, porque a prova documental
deve ser oferecida com a inicial (art. 434). "Instruir" a peça é exatamente
isso: juntar a ela os papéis que a sustentam.

O LexIA modela cada um desses arquivos como uma linha. O advogado escolhe os
arquivos no computador, dá a cada um uma descrição — *o que este documento
comprova* — e eles viajam junto com a peça.

### O que `Document` NÃO é

- **Não é a peça redigida.** O texto que o LexIA produz é a peça (`LegalCase`);
  o documento é o anexo que veio de fora, já pronto, e que ninguém edita aqui.
- **Não é um modelo nem um template.** Não se reusa entre peças: o mesmo
  contrato juntado em duas peças são duas linhas, cada uma com a descrição que
  faz sentido no seu processo.
- **Não é biblioteca do cliente.** Não existe listagem de documentos fora de uma
  peça, e nada liga um documento diretamente a um `Customer`.

## Como o LexIA modela

| Coluna | Tipo | Papel |
|---|---|---|
| `id` | uuid | chave primária |
| `account_id` | uuid FK → `accounts` | a conta que juntou; `cascadeOnDelete` |
| `legal_case_id` | uuid FK → `legal_cases` | a peça instruída; `cascadeOnDelete` |
| `name` | string | o nome original do arquivo, **com** extensão |
| `description` | text, nulo | o que o advogado escreve sobre o arquivo |
| `extension` | string(16) | `pdf`, `png`, `docx`… |
| `size` | bigint | bytes, como o navegador reporta |
| `created_at` / `updated_at` / `deleted_at` | timestamp | `SoftDeletes` |

Índice: `(account_id, legal_case_id)` — toda leitura parte da conta e desce para
uma peça.

`account_id` é redundante com o da peça, e de propósito: é o que permite ao
`AccountScope` estreitar a tabela sem um join a cada leitura.

`name` guarda a extensão e `extension` a repete. A duplicação é deliberada: o
sufixo é lido muito mais do que escrito — um badge por linha, um filtro por tipo
— e não deve custar cirurgia de string a cada vez.

### Multitenancy

`BelongsToAccount` aplica o `AccountScope` e preenche `account_id` no `creating`
a partir do usuário autenticado. A `DocumentPolicy` repete a regra da peça: a
fronteira é absoluta, nem `PlatformAdmin` atravessa, porque não há tela de
documento para a equipe LexIA.

### Relações

```php
$document->account;    // BelongsTo Account
$document->legalCase;  // BelongsTo LegalCase
$case->documents;      // HasMany Document
```

## Invariantes

1. **Um documento não existe fora de uma peça.** As duas FKs são cascata:
   apagar a conta ou a peça leva os anexos junto.
2. **Documento e peça na mesma conta.** Garantido hoje pelo escopo na hora de
   resolver o `legal_case_id`; quando existir rota, a Policy é a defesa real —
   route-model binding roda antes do middleware de tenant (ver `CLAUDE.md`).
3. **A extensão reflete o nome.** Quem grava deriva `extension` de `name`; nada
   no banco verifica isso.

## O que ainda não existe

**O arquivo em si.** Não há `disk`, não há `path`, não há `Storage`, não há
Action nem rota — a tela da etapa 4 segura os `File` em memória no navegador e
não envia nada, como as etapas do réu e dos fatos. A coluna que aponta para os
bytes nasce junto com a Action que os grava, e com ela vêm as decisões que
faltam: disco (o `local` privado, quase certamente — documento de processo não é
público), checksum para deduplicar, antivírus, e se o nome no disco é o uuid ou
o nome original higienizado.

Também não existem: classificação do documento (procuração, contrato, prova),
ordem de juntada, e a paginação que o PDF final precisa citar.

## Fonte da verdade

- Model: `app/Domain/Documents/Models/Document.php`
- Policy: `app/Domain/Documents/Policies/DocumentPolicy.php`
- Migration: `database/migrations/2026_09_15_130004_create_documents_table.php`
- Factory: `database/factories/DocumentFactory.php`
- Tela: `resources/js/components/document-upload-fields.tsx` e as regras de
  aceite em `resources/js/lib/documents.ts`
- Testes: `tests/Feature/Documents/ManageDocumentsTest.php`

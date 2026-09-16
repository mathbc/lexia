# Documentação de domínio

Contexto teórico das entidades do LexIA, escrito para ser lido por um modelo de
IA antes de mexer no código. Responde a "o que essa classe representa no mundo
real e o que o produto garante sobre ela"; **não** substitui o código, que
continua sendo a fonte da verdade sobre comportamento.

| Documento | Entidade | Natureza do dado |
|---|---|---|
| [legal-case.md](legal-case.md) | `LegalCase` | transacional, por conta |
| [practice-area.md](practice-area.md) | `PracticeArea` | referência, global |
| [procedural-class.md](procedural-class.md) | `ProceduralClass` | referência, global |
| [glossary.md](glossary.md) | — | vocabulário jurídico brasileiro |

## O desenho em uma figura

```
Account (a conta/escritório — fronteira do multitenancy)
 └── Customer (o cliente do escritório)
      └── LegalCase (a peça jurídica sendo redigida)
           ├── practice_area_id ──> PracticeArea     (área do direito, taxonomia nossa)
           └── procedural_class_id ─> ProceduralClass (classe do CNJ, taxonomia oficial)

PracticeArea ──< practice_area_procedural_class >── ProceduralClass
                 pivot com scope: 'specific' | 'generic'
```

## As três leituras que evitam erro

1. **Duas entidades são catálogo, uma é dado do cliente.** `PracticeArea` e
   `ProceduralClass` não têm `account_id`, não têm soft delete e não são
   editáveis pelo produto: chegam por migration e mudam só quando o CNJ
   republica a TPU. `LegalCase` é o oposto — nasce de um caso de uso, usa
   `BelongsToAccount` e é apagada logicamente.
2. **A relação área ↔ classe é muitos-para-muitos.** Não existe, e nunca deve
   existir, uma coluna `practice_area_id` em `procedural_classes`: a classe 7
   ("Procedimento Comum Cível") serve 15 das 24 áreas.
3. **O banco não garante que a classe pertence à área escolhida.** As duas
   chaves em `legal_cases` são independentes; o par válido vive no pivot e a
   validação é responsabilidade da Action. Ver
   [legal-case.md](legal-case.md#invariantes).

## Estado da implementação

Em 15/09/2026 existem os três models, as migrations, o catálogo carregado e os
testes. **Não existem ainda** Actions, rotas, Policy nem telas para `LegalCase`
— os campos que o advogado preenche (réu, fatos, tutelas, pedidos) ainda não
estão no schema. Cada documento marca explicitamente o que é hoje e o que é
intenção.

## Manutenção

Mudou a modelagem, mudou o catálogo ou entrou um campo novo? Atualize o
documento correspondente no mesmo commit. Números citados aqui (615 classes, 24
áreas, 1.237 vínculos) vêm dos JSON em `database/data/` e envelhecem a cada
ressincronização com o CNJ — veja `database/data/README.md`.

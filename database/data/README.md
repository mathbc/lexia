# Catálogo processual do CNJ

Dado de referência carregado por migration (`*_seed_procedural_catalog.php`), não por
seeder: produção precisa dele tanto quanto uma máquina de desenvolvimento, porque nenhuma
peça pode ser montada sem ele.

| Arquivo | Conteúdo |
|---|---|
| `practice-areas.json` | 24 áreas de atuação e os 1.237 vínculos com as classes |
| `procedural-classes.json` | 615 classes processuais ativas |

## Origem

Tabelas Processuais Unificadas (TPU) do CNJ, versão de **12/09/2026**, obtidas do web
service SGT: `https://www.cnj.jus.br/sgt/sgt_ws.php?wsdl` — SOAP público, sem
autenticação. Dado público (Resolução CNJ nº 46/2007).

## Por que as áreas de atuação são nossas, e não do CNJ

**O CNJ não agrupa classes processuais por área jurídica.** A Tabela de Classes tem dez
raízes organizadas por *natureza do processo* (Processo Cível e do Trabalho, Processo
Criminal, Processo Eleitoral…), não por ramo do direito. Quem é organizado por área é a
Tabela de **Assuntos**, cujas 22 raízes são literalmente as áreas — e é para elas que
`cnj_subject_roots` aponta.

Direito Imobiliário e Direito Empresarial sequer existem como área no CNJ (estão dentro
de Direito Civil), mas é assim que o advogado pensa. Daí a taxonomia curada.

Consequência: a relação área ↔ classe é **muitos-para-muitos**. O código 7
("Procedimento Comum Cível") serve civil, consumidor, imobiliário, família e tributário.

## Campos que merecem explicação

- **`description`** e **`typical_subjects`** — redação **nossa**, não do CNJ. O glossário
  do SGT é transcrição do texto legal e não diz ao advogado quando a classe é a certa;
  aqui está o que ela é, quando cabe e para que serve, mais as matérias tipicamente
  discutidas nela. Não são códigos da Tabela de Assuntos, e uma ressincronização com o
  CNJ não os traz de volta: preserve-os ao regerar os JSON. Revise antes de expor como
  conteúdo jurídico ao usuário final.
- **`scope`** (no vínculo) — `specific`, classe própria daquela área, ou `generic`,
  classe do tronco cível que serve qualquer área cível. Ordene as específicas primeiro:
  é a diferença entre um select usável e um com 120 itens.
- **`is_filing_class`** — abre processo novo. É o filtro de uma tela de petição inicial:
  326 das 615.
- **`is_cross_cutting`** — incidentes, recursos, cartas precatórias, cumprimento de
  sentença. Não oferecer numa inicial.
- **`nature`** — vem sujo do CNJ (41 valores distintos para o que deveria ser um conjunto
  fechado, com typos e variações de caixa). Guardado como string justamente por isso; os
  dois booleanos acima são o que o produto consulta.
- **`jurisdictions`** — as 29 competências do CNJ (ramo de justiça × grau). Filtre por
  aqui antes de exibir a lista.

## Ressincronização

O CNJ altera a TPU com frequência. `getDataUltimaVersao()` no web service devolve a data
da última versão — compare com a do topo deste arquivo e só reprocesse quando mudar.
Regerar os dois JSON e acrescentar uma migration que chama a mesma rotina de carga, que é
idempotente (upsert por `code` e por `slug`). `description` e `typical_subjects` são
editoriais: reaproveite-os por `code` em vez de esperá-los do web service.

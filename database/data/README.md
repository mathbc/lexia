# Catálogo processual do CNJ

Dado de referência carregado por migration (`*_seed_procedural_catalog.php`), não por
seeder: produção precisa dele tanto quanto uma máquina de desenvolvimento, porque nenhuma
peça pode ser montada sem ele.

| Arquivo | Conteúdo |
|---|---|
| `practice-areas.json` | 24 áreas de atuação e os 1.756 vínculos com as classes |
| `procedural-classes.json` | 615 classes processuais ativas |
| `judicial-systems.json` | 5 sistemas judiciais e os 31 vínculos com os 27 tribunais de justiça — ver [Sistemas judiciais](#sistemas-judiciais) |

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

- **`description`**, **`typical_subjects`** e **`legal_bases`** — redação **nossa**, não
  do CNJ. O glossário do SGT é transcrição do texto legal e não diz ao advogado quando a
  classe é a certa. **Uma ressincronização com o CNJ não os traz de volta: preserve-os
  por `code` ao regerar os JSON.** Revise antes de expor como conteúdo jurídico ao
  usuário final.

  A `description` das 326 classes de ajuizamento segue uma fórmula, porque quem a lê é
  um relato leigo e não um índice: **o que se pede ao juiz + o pressuposto que abre a
  classe + o prazo ou requisito próprio, quando é ele que a distingue + os instrumentos
  práticos**. Comparar as duas gerações mostra o que mudou:

  > *antes* — "Ação incidental de conhecimento, distribuída por dependência e autuada em
  > apartado, pela qual o executado se opõe à execução…"
  >
  > *agora* — "Defesa do executado em execução de título extrajudicial já em curso,
  > independentemente de penhora, depósito ou caução, **no prazo de 15 dias da citação**…"

- **`legal_bases`** — os artigos que a peça daquela classe cita, como lista de
  `{norm, article, note}`, e não como texto corrido. É o que mais desempata classes de
  nome parecido: `[172] Embargos à Execução` são 15 dias do art. 915 do CPC sem garantia
  nenhuma; `[1118] Embargos à Execução Fiscal` são 30 dias do art. 16 da LEF **depois**
  de garantido o juízo. Não confundir com `legal_norm`/`legal_article`, que vêm do CNJ e
  dizem onde a *classe* está definida, não o que a peça invoca.
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

## Como o vínculo área ↔ classe é decidido

O CNJ não classifica classe por ramo do direito — o registro de uma classe não tem
campo de área, só as 29 competências e a posição na árvore. Quem decide o vínculo,
então, é o **`path`**, que é a árvore oficial, mais duas regras:

1. **A raiz do `path` resolve o caso fácil.** `PROCESSO CRIMINAL` é penal,
   `PROCESSO ELEITORAL` é eleitoral, `SUPREMO TRIBUNAL FEDERAL` e
   `SUPERIOR TRIBUNAL DE JUSTIÇA` são Tribunais Superiores, e assim por diante. Sete
   das dez raízes se esgotam aqui.

2. **`PROCESSO CÍVEL E DO TRABALHO` se parte em três**, e é onde mora a dificuldade:
   - qualquer nó com *Trabalhista* no `path` (`Procedimentos Trabalhistas`,
     `Recursos Trabalhistas`, `Processo de Execução Trabalhista`,
     `Incidentes Trabalhistas`) é `specific` de Direito do Trabalho;
   - `Procedimentos Especiais` é o ramo em que a classe **tem matéria própria** —
     Despejo é imobiliário, Divórcio é família, Recuperação Judicial é empresarial.
     É a única parte que exige curadoria, classe a classe;
   - **todo o resto é o tronco cível** — procedimento comum, cumprimento de sentença,
     liquidação, execução, embargos, recursos, cartas, incidentes, tutela provisória —
     e entra como `generic` em **todas** as áreas cíveis. É o que estava quebrado:
     `Embargos à Execução` (172), `Cumprimento de sentença` (156) e
     `Embargos de Declaração Cível` (1689) não alcançavam nenhuma área cível.

Duas ressalvas sobre o tronco, ambas necessárias:

- **A competência oficial é o filtro.** Uma classe do tronco só chega ao Direito do
  Trabalho se o CNJ a marcar em `just_trab_*` — por isso `Procedimento Comum Cível`
  não aparece lá (a Justiça do Trabalho usa `Ação Trabalhista - Rito Ordinário`), mas
  `Embargos à Execução` aparece. E uma classe confinada a `just_trab`, `just_elei` ou
  `just_mil` não chega às áreas comuns.
- **Nem tudo que está no tronco é genérico.** `Execução Fiscal`, `Embargos à Execução
  Fiscal` e `Cautelar Fiscal` são tributário e administrativo; `Execução Hipotecária
  do SFH` é imobiliário; `Medidas Protetivas (Maria da Penha)` é família e penal — o
  glossário do CNJ diz "medida protetiva CÍVEL", e o da classe 124 (Lei de Imprensa)
  diz, com todas as letras, "a matéria é CRIMINAL". Essas ficam presas às suas áreas.

No caminho inverso, um punhado de `Procedimentos Especiais` é instrumento processual e
não matéria — Monitória, Mandado de Segurança, Embargos de Terceiro, Ação Rescisória,
Consignação em Pagamento. Esses guardam a área de origem como `specific` **e** viajam
como `generic` para as demais áreas cíveis.

O resultado é que nenhuma das 615 classes fica órfã, e o tronco cível — 74 classes —
está presente em toda área cível, em vez de ter sido despejado só em Direito do
Trabalho, que era o estado anterior.

## Ressincronização

O CNJ altera a TPU com frequência. `getDataUltimaVersao()` no web service devolve a data
da última versão — compare com a do topo deste arquivo e só reprocesse quando mudar.
Regerar os dois JSON e acrescentar uma migration que chama a mesma rotina de carga, que é
idempotente (upsert por `code` e por `slug`). `description`, `typical_subjects` e
`legal_bases` são editoriais: reaproveite-os por `code` em vez de esperá-los do web
service.

O glossário oficial de cada classe sai de `getArrayDetalhesItemPublicoWS(<code>, 'C')`,
que devolve `dispositivo_legal`, `artigo` e o texto de lei transcrito — é a fonte contra
a qual as descrições foram escritas e revisadas.

**Depois de qualquer migration que mexa em `description`, `typical_subjects` ou
`legal_bases`, rode `php artisan lexia:embed-procedural-classes`**: os vetores descrevem
o texto anterior, e a migration de recarga zera os hashes justamente para que a próxima
passada os refaça.

## Sistemas judiciais

`judicial-systems.json` é carregado pela mesma via, e pelo mesmo motivo: a etapa 1 da peça
oferece o sistema, e produção precisa dele tanto quanto uma máquina de desenvolvimento. A
carga vigente é `2026_09_30_130001_reload_judicial_systems_with_urls.php`, que é a primeira,
`2026_09_29_120001_seed_judicial_systems.php`, com a `url` gravada ao lado do `status`.

Não é dado do CNJ. É um **levantamento de 29/09/2026** de qual sistema eletrônico cada
tribunal de justiça estadual usa, transcrito sem acréscimos. Duas chaves:

- **`systems`** — eproc, PJe, e-SAJ, Projudi e Tucujuris. O `slug` é a chave natural,
  como o das áreas, e `position` é o alcance: o que mais tribunais usam vem primeiro no
  select.
- **`courts`** — um objeto por UF, na ordem do levantamento, com a sigla do tribunal e
  os sistemas que ele usa. A sigla é gravada e não derivada da UF porque não se deriva:
  o do DF é o TJDFT. Quatro tribunais usam dois sistemas (AP, RN, RR, SP).

O `status` de cada vínculo é o `AdoptionStatus`:

| Valor | No levantamento |
|---|---|
| `active` | o sistema sozinho, ou os dois de "e-SAJ / eproc" |
| `transition` | "eproc / transição" |
| `implementation` | "eproc / implantação", "eproc em implantação" |
| `coexistence` | "eproc / coexistência com sistemas anteriores" |

### O endereço de cada vínculo

`url` é onde o advogado abre aquele sistema **naquele tribunal** para protocolar uma
inicial de 1º grau. Mora no vínculo e não no sistema porque cada tribunal roda a própria
instância: o eproc do TJSC é `eproc1g.tjsc.jus.br`, o do TJTO é
`eproc1.tjto.jus.br/eprocV2_prod_1grau/`. **Levantamento de 30/09/2026**, cada endereço lido
no site do próprio tribunal e conferido de pé.

Duas regras de escrita:

- **O endereço que o tribunal publica, e não a tela de login.** Quase todos redirecionam
  para um login único — o PDPJ (`sso.cloud.pje.jus.br`) ou o do próprio tribunal
  (`sso.tjsc.jus.br`) — com um `state` de uso único na URL; copiar esse destino gravaria
  um link que vence. `pje.tjpe.jus.br/1g`, e não o host de nuvem para onde ele manda hoje.
- **Sempre `*.jus.br`, sempre HTTPS**, e o host termina na sigla do tribunal. O teste
  confere as três coisas.

`null` é o vínculo que **não recebe inicial nova** — não há para onde mandar o advogado, e
a tela deixa esse tribunal fora do botão. São quatro, e em todos é o mapa que está
atrás do tribunal, não o endereço que falta:

| Vínculo | O que o tribunal diz |
|---|---|
| TJAM × e-SAJ | desde 01/01/2025 a inicial de 1º e 2º grau é só pelo Projudi (`projudi.tjam.jus.br/projudi/`); o e-SAJ ficou com os processos não migrados |
| TJRN × e-SAJ | desligado em 05/06/2023 (Portaria Conjunta 37/2022); tudo foi para o PJe |
| TJRR × PJe | instância legada, que o site do tribunal não oferece; o advogado é mandado ao Projudi |
| TJES × eproc | convênio com o TRF4 assinado em 09/2025, sem instância publicada; a inicial segue pelo PJe (`pje.tjes.jus.br`) |

E duas ressalvas que o endereço não resolve. O **TJSP tem duas instâncias de 1º grau do
eproc**: `eproc1g` (cível, JEC, empresarial, registros públicos) é a gravada, e
`eproc1g-ef.tjsp.jus.br` recebe Fazenda Pública, JEFAZ, acidentes do trabalho e
previdenciário. O **PJe do TJMT** saiu do ar num ataque em 21/09/2026; o endereço é o
certo e fica.

### Atualização

Este mapa envelhece mais depressa que a TPU: o eproc está em expansão, e 8 dos 31
vínculos descrevem um tribunal mudando de sistema. Atualizar é editar o JSON e
acrescentar uma migration que roda a mesma rotina — **copiando a carga vigente**, e não a
primeira, que refaria os vínculos sem endereço. A rotina é idempotente — upsert dos
sistemas por `slug`, e os vínculos refeitos inteiros, porque um tribunal que deixa um
sistema some do arquivo e um upsert deixaria o vínculo velho no lugar.
`JudicialSystemCatalogTest` fixa as contagens e muda junto.

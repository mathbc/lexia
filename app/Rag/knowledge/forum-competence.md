# Competência e endereçamento — base de análise

Guia para decidir **a quem** a petição inicial é dirigida: em que justiça ela tramita, em
que foro (a cidade) e em que juízo (a vara ou o juizado). A área de atuação e a classe
processual já foram decididas e chegam junto do relato, com as partes reduzidas ao que
decide a competência — o tipo de cada uma, a cidade e a UF.

Duas premissas valem para tudo o que segue:

- **O cliente é sempre o autor.** A peça que o sistema redige é a petição inicial que ele
  propõe; o réu é a outra parte.
- **Você não escreve o endereçamento.** Você escolhe o juízo (`division`) e diz **de onde
  vem a cidade** (`forum_source`); o sistema monta a frase, na forma neutra
  ("Excelentíssimo(a) Senhor(a) Juiz(a) de Direito da Vara Cível da Comarca de …"), e copia
  a cidade do cadastro quando ela vem de lá.

## A ordem da decisão

Decida nesta ordem, porque cada passo limita o seguinte:

1. **A justiça** — Estadual, Federal ou do Trabalho. É competência absoluta (em razão da
   pessoa ou da matéria), e errar aqui anula o processo.
2. **O foro** — a cidade. É, em regra, competência relativa (territorial), com as
   exceções absolutas marcadas abaixo.
3. **O juízo** — a vara ou o juizado dentro do foro, conforme a matéria, a parte e o valor.

## 1. A justiça

**Justiça Federal** (CF, art. 109, I; CPC, art. 45) quando for parte — autora, ré,
assistente ou oponente — a União, uma **autarquia federal** (INSS, IBAMA, ANATEL,
universidade federal, os conselhos de fiscalização profissional), uma **fundação pública
federal** ou uma **empresa pública federal** (Caixa Econômica Federal, Correios). Exceções
que continuam na Justiça Estadual mesmo com esses entes: falência e recuperação judicial,
insolvência civil e **acidente do trabalho**.

**Justiça do Trabalho** (CF, art. 114) para o que nasce da relação de trabalho: verbas
rescisórias, horas extras, vínculo de emprego, dano moral no emprego, FGTS não depositado
pelo empregador. A classe processual trabalhista já diz isso; o relato de um empregado
contra o empregador também.

**Justiça Estadual** é a residual: tudo o que não é das outras duas. Inclui os Estados, o
Distrito Federal, os Municípios e as suas autarquias (que vão para a Fazenda Pública
estadual), os bancos privados, as operadoras de plano de saúde, as concessionárias.

### Os desempates que mais erram

- **Banco do Brasil e Petrobras são sociedades de economia mista**, não empresas públicas:
  Justiça **Estadual**. A **Caixa Econômica Federal** é empresa pública: Justiça
  **Federal**. É a confusão mais comum da matéria.
- **Benefício acidentário** (auxílio-acidente, aposentadoria por invalidez decorrente de
  acidente do trabalho) contra o INSS é da Justiça **Estadual** (CF, art. 109, I, parte
  final). Benefício previdenciário comum contra o INSS é da **Federal**.
- **Competência delegada** (CF, art. 109, § 3º; Lei 5.010/66, art. 15, III): a causa
  previdenciária pode ir à Justiça Estadual quando a comarca do domicílio do segurado fica
  a mais de 70 km de município sede de vara federal. Só use se o relato disser que não há
  vara federal próxima; na dúvida, Justiça Federal, e diga na justificativa que a
  delegação deve ser conferida.
- **Contra a União** a ação pode ser proposta na seção judiciária do domicílio do autor,
  onde ocorreu o ato ou fato, onde está a coisa, ou no Distrito Federal (CF, art. 109,
  § 2º; CPC, art. 51, parágrafo único). O domicílio do autor é o mais cômodo para o
  cliente, e é o que se sugere. Vale para as autarquias federais, como o INSS.

## 2. O foro — a cidade

### A regra geral, e as regras que a afastam

A regra geral é o **domicílio do réu** (CPC, art. 46). Antes de aplicá-la, confira se o
caso cai numa das regras especiais abaixo — elas existem, quase todas, para **proteger o
autor**, e ignorá-las é a saída fácil que mais prejudica o cliente.

**Foros que favorecem o autor** (`forum_source: plaintiff_address`):

- **Consumidor contra fornecedor**: domicílio do consumidor autor (CDC, art. 101, I). É
  opção dele, e é a que se sugere.
- **Alimentos**: domicílio ou residência do alimentando (CPC, art. 53, II). O alimentando
  menor mora com quem o representa — quase sempre o cliente.
- **Divórcio, separação, anulação de casamento, reconhecimento ou dissolução de união
  estável** (CPC, art. 53, I), nesta ordem: (a) domicílio do guardião de filho incapaz;
  (b) último domicílio do casal, se não há filho incapaz; (c) domicílio do réu, se nenhum
  dos dois mora mais no antigo domicílio do casal; (d) domicílio da vítima de violência
  doméstica e familiar. Quando o cliente é o guardião, ou a vítima, é o domicílio dele.
- **Pessoa idosa**, na causa sobre direito previsto no Estatuto: residência do idoso (CPC,
  art. 53, III, e). A idade do cliente vem na lista das partes quando ele é pessoa física.
- **Reparação de dano por delito ou acidente de veículo** (inclusive aeronave): domicílio
  do autor ou local do fato, à escolha dele (CPC, art. 53, V).
- **Estado ou Distrito Federal réu**: domicílio do autor, local do ato ou fato, situação
  da coisa ou capital do ente (CPC, art. 52, parágrafo único).
- **Juizado Especial Cível, reparação de dano de qualquer natureza**: domicílio do autor
  ou local do ato ou fato (Lei 9.099/95, art. 4º, III).
- **Violência doméstica, na parte cível da Lei Maria da Penha**: domicílio ou residência
  da ofendida, lugar do fato ou domicílio do agressor, à escolha dela (Lei 11.340/06,
  art. 15).
- **Réu sem domicílio no Brasil**, ou com domicílio incerto ou desconhecido: domicílio do
  autor (CPC, art. 46, §§ 2º e 3º).

**Foros do réu** (`forum_source: defendant_address`):

- **Regra geral**, para direito pessoal e direito real sobre móveis (CPC, art. 46).
- **Pessoa jurídica ré**: o lugar da sede (CPC, art. 53, III, a) — ou da agência ou
  sucursal, quanto às obrigações que ela contraiu ali (art. 53, III, b; aí o lugar vem do
  relato). O endereço do réu cadastrado costuma ser a sede.
- **Réu incapaz**: domicílio do representante ou assistente (CPC, art. 50).
- **Execução de título extrajudicial**: domicílio do executado, o foro de eleição do
  título ou a situação dos bens (CPC, art. 781, I).
- **Vários réus com domicílios diferentes**: qualquer deles, à escolha do autor (CPC,
  art. 46, § 4º).

**Foros de lugar** (`forum_source: facts` — a cidade vem do relato):

- **Direito real sobre imóvel** (propriedade, vizinhança, servidão, divisão e demarcação,
  nunciação de obra nova), **usucapião** e **possessória imobiliária**: foro de situação
  da coisa, e aqui a competência é **absoluta** (CPC, art. 47, *caput* e § 2º). Nos
  demais direitos reais sobre imóvel o autor pode optar pelo domicílio do réu ou pelo foro
  de eleição (art. 47, § 1º).
- **Locação** — despejo, consignação de aluguel, revisional, renovatória: foro do lugar do
  imóvel, salvo foro de eleição no contrato (Lei 8.245/91, art. 58, II).
- **Inventário, partilha e ações contra o espólio**: domicílio do autor da herança (CPC,
  art. 48); sem domicílio certo, a situação dos imóveis.
- **Réu ausente**: último domicílio dele (CPC, art. 49).
- **Cumprimento de obrigação**: lugar onde ela deve ser satisfeita (CPC, art. 53, III, d).
- **Reparação de dano** fora das hipóteses acima: lugar do ato ou fato (CPC, art. 53,
  IV, a).
- **Ação civil pública**: local do dano, com competência funcional (Lei 7.347/85, art. 2º).
- **Falência e recuperação judicial**: local do principal estabelecimento do devedor (Lei
  11.101/05, art. 3º).
- **Reclamação trabalhista**: localidade onde o empregado **prestou os serviços**, ainda
  que contratado noutro lugar (CLT, art. 651). Empregador que atua fora do lugar do
  contrato: foro da celebração ou da prestação, à escolha do empregado (art. 651, § 3º).
  Se o relato não disser onde o cliente trabalhou, use `plaintiff_address` e diga na
  justificativa que o local da prestação precisa ser confirmado.
- **Queixa-crime**: lugar em que se consumou a infração (CPP, art. 70), ou o domicílio do
  réu, à escolha do querelante na ação exclusivamente privada (CPP, art. 73).
- **Mandado de segurança**: sede da autoridade coatora. Contra autoridade federal, também
  o domicílio do impetrante (CF, art. 109, § 2º).
- **Foro de eleição** (CPC, art. 63): só vale se constar de instrumento escrito, aludir
  expressamente ao negócio e guardar pertinência com o domicílio ou a residência de uma
  das partes ou com o local da obrigação (§ 1º). Cláusula abusiva pode ser declarada
  ineficaz de ofício antes da citação (§ 3º), e o ajuizamento em juízo aleatório — sem
  vínculo com as partes nem com o negócio — é prática abusiva (§ 5º). Contra consumidor, a
  eleição só vale quando o favorece. Use o foro de eleição só quando o relato disser qual é
  e ele passar nesses filtros.

### O que muda o foro e o autor não escolhe

- **A ação acessória vai ao juízo da principal** (CPC, art. 61). Se o relato mencionar um
  processo já em curso entre as mesmas partes sobre o mesmo negócio, diga na justificativa
  que a petição deve ser distribuída por dependência a ele — conexão e continência
  (arts. 54 a 58) reúnem as causas no juízo prevento (art. 59).
- **Competência absoluta não se derroga** (CPC, art. 62): a da matéria, a da pessoa e a
  funcional — e, territorialmente, a do imóvel (art. 47, § 2º), a da pessoa idosa nas
  ações do Estatuto (Lei 10.741/03, art. 80), a da ação civil pública e a dos juizados
  federais e da Fazenda Pública onde instalados. Nenhuma cláusula as afasta.
- **A competência se fixa na distribuição** (CPC, art. 43): mudança posterior de domicílio
  não a altera. Escolha o foro pelo que o relato diz de hoje.
- **O autor que erra perde tempo, não o direito**: a incompetência relativa só é
  reconhecida se o réu a alegar (arts. 64 e 65), mas a absoluta é declarada de ofício e
  desloca o processo (art. 64, § 1º). Por isso a justiça e as competências absolutas vêm
  antes da comodidade.

### De onde vem a cidade — o identificador de `forum_source`

- `plaintiff_address` — o domicílio do cliente, cadastrado. O sistema copia a cidade e a
  UF do cadastro; não escreva `city` nem `state`.
- `defendant_address` — o endereço do réu, cadastrado. O sistema copia; não escreva
  `city` nem `state`. Se o endereço do réu não estiver na lista das partes, o sistema
  deixa a comarca em branco para o advogado.
- `facts` — um lugar que o **relato escreve**: onde fica o imóvel, onde aconteceu o fato,
  onde o empregado trabalhou, o foro que o contrato elegeu. Escreva em `city` o município
  **como o relato o escreve**, sem a UF, e a UF em `state`. Se o relato não nomeia a
  cidade, `city` é nulo — e o sistema deixa a lacuna para o advogado. Nunca complete com a
  capital do estado nem com a cidade de uma das partes.

## 3. O juízo — o identificador de `division`

A lista que vem ao fim das instruções já está filtrada pela classe processual: só aparecem
os juízos em que ela pode ser proposta. Escolha o **especializado** quando a matéria tiver
um; em comarca pequena pode existir só a vara única, e o advogado ajusta.

**Justiça Estadual**

- `civil` — **Vara Cível**. O residual cível: contratos, cobrança, responsabilidade civil,
  consumidor, execução de título, possessória, despejo, obrigação de fazer contra
  particular, plano de saúde.
- `family` — **Vara de Família e Sucessões**. Divórcio, união estável, alimentos, guarda,
  visitas, investigação de paternidade, inventário, arrolamento e alvará.
- `public_treasury` — **Vara da Fazenda Pública**. Estado, Distrito Federal, Município ou
  autarquia e fundação deles como parte, acima do teto do juizado ou em matéria que o
  juizado exclui.
- `treasury_small_claims` — **Juizado Especial da Fazenda Pública**. Causa contra Estado,
  DF, Município ou autarquia deles até **60 salários mínimos**; onde instalado, a
  competência é **absoluta** (Lei 12.153/09, art. 2º, § 4º). Não cabe mandado de
  segurança, desapropriação, improbidade, execução fiscal nem demanda coletiva.
- `small_claims` — **Juizado Especial Cível**. Causa de menor complexidade até **40
  salários mínimos** (Lei 9.099/95, art. 3º), com autor pessoa física capaz ou
  microempresa. É **opção** do autor, e renuncia ao que exceder o teto (art. 3º, § 3º).
  Não cabe com ente público no polo, com incapaz, em alimentos, família, falência, causa
  fiscal ou acidente do trabalho, nem quando a prova depende de perícia complexa.
- `public_records` — **Vara de Registros Públicos**. Retificação de registro civil,
  suscitação de dúvida, retificação de área no registro de imóveis.
- `business` — **Vara Empresarial**. Conflitos societários, dissolução de sociedade,
  propriedade industrial, onde houver vara própria.
- `bankruptcy` — **Vara de Falências e Recuperações Judiciais**.
- `tax_enforcement` — **Vara de Execuções Fiscais**. A execução fiscal que a Fazenda
  propõe.
- `childhood` — **Vara da Infância e da Juventude**. Adoção, destituição do poder
  familiar, medidas de proteção à criança em situação de risco (ECA, arts. 98 e 148).
  Guarda e alimentos **entre os pais** são da Vara de Família.
- `domestic_violence` — **Juizado de Violência Doméstica e Familiar contra a Mulher**.
  Medidas protetivas e a parte cível da Lei 11.340/06.
- `criminal` — **Vara Criminal**. Queixa-crime e demais ações penais de primeiro grau.
- `criminal_small_claims` — **Juizado Especial Criminal**. Infração de menor potencial
  ofensivo (pena máxima de até dois anos).
- `criminal_enforcement` — **Vara de Execuções Penais**.

**Justiça Federal**

- `federal_small_claims` — **Juizado Especial Federal**. Causa contra a União, o INSS, a
  CEF ou outro ente federal até **60 salários mínimos**, com autor pessoa física ou
  microempresa; onde instalado, a competência é **absoluta** (Lei 10.259/01, art. 3º,
  § 3º). É onde cai a maior parte das ações previdenciárias. Não cabe mandado de
  segurança, desapropriação, improbidade, execução fiscal, demanda coletiva nem anulação
  de ato administrativo federal que não seja previdenciário ou de lançamento fiscal.
- `federal` — **Vara Federal**. Os mesmos entes, acima do teto ou nas matérias que o
  juizado exclui.

**Justiça do Trabalho**

- `labor` — **Vara do Trabalho**.

**Nulo** quando o foro competente não é um juízo de primeiro grau desta lista: competência
originária de tribunal (mandado de segurança contra ato de governador ou de secretário de
Estado, ação rescisória), Justiça Eleitoral ou Justiça Militar. Diga na justificativa qual
é o órgão.

## A base legal — `legal_basis`

Os dispositivos que fixam a justiça, o foro e o juízo, escritos como numa petição e
separados por ponto e vírgula: "CPC, art. 53, II; Lei 5.478/68". **Só lei**: nunca súmula,
tema, enunciado ou julgado, nem pelo número nem pelo nome.

## As saídas fáceis

São as respostas que atraem o relato ambíguo.

- **O domicílio do réu por hábito.** A regra geral perde para quase todas as regras
  especiais, e elas protegem justamente o autor — consumidor, alimentando, idoso, guardião
  do filho incapaz, vítima de violência doméstica, vítima de acidente de veículo. Confira
  a lista antes de aplicar o art. 46.
- **A cidade inventada.** Se o relato não diz onde fica o imóvel, onde o fato aconteceu ou
  onde o cliente trabalhou, `city` é nulo. A capital do estado não é resposta, e a cidade
  do cliente só é resposta quando a regra aponta para o domicílio dele.
- **O juizado pelo valor.** Um valor pequeno não basta: o JEC é opção do autor, e não cabe
  com perícia complexa, ente público, incapaz, alimentos ou família. Na dúvida, a vara.
- **A Justiça Federal para qualquer banco.** Só a Caixa Econômica Federal leva à Federal.
  Banco do Brasil, bancos privados e cooperativas de crédito ficam na Estadual.
- **O INSS sempre na Federal.** O benefício acidentário é da Estadual.
- **A Vara da Infância para qualquer criança.** Guarda, visitas e alimentos entre os pais
  vão à Vara de Família; a da Infância é para a criança em situação de risco e a adoção.
- **A cláusula de eleição aceita sem filtro.** Ela não vale contra o consumidor, nem sem
  pertinência com as partes ou com o negócio (CPC, art. 63, §§ 1º e 5º).

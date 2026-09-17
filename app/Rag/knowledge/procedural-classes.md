# Classes processuais — base de escolha

Guia para escolher, dentro de uma área de atuação já decidida, a **classe processual
do CNJ** sob a qual a peça será autuada. A lista de classes permitidas chega junto do
relato; este documento diz como decidir entre elas.

Os códigos aparecem sempre entre colchetes — `[7]`, `[12372]` —, no mesmo formato da
lista de candidatas. Devolva **apenas o número**.

A área sai dos fatos. A classe sai do **pedido**: do que se quer do juiz.

A lista de candidatas chega ordenada por proximidade com o relato, e a maioria delas
vem descrita — o que se pede, o pressuposto, o prazo próprio e a base legal. Quando uma
classe chega só com o nome, é porque a lista não coube inteira no prompt, **não** porque
ela esteja descartada: ela continua sendo uma resposta válida, e as do tronco cível
estão descritas aqui embaixo.

Leia a base legal quando duas classes parecerem próximas: é com frequência o
dispositivo que as separa. Os 30 dias do art. 16 da LEF, contados da garantia do juízo,
são `[1118] Embargos à Execução Fiscal`; os 15 dias do art. 915 do CPC, contados da
citação e sem garantia nenhuma, são `[172] Embargos à Execução`.

## Como escolher a classe

1. **Identifique o pedido, não o acontecimento.** "Bateram no meu portão e fugiram" é
   Direito Civil pelos fatos; a classe sai do que se pede — indenização, e não
   reintegração de posse. Quando o relato narra vários fatos, o pedido principal é o
   que decide; diga na justificativa que há pedido cumulado, se houver.
2. **Existe título executivo?** Se o relato menciona contrato assinado por duas
   testemunhas, cheque, nota promissória, duplicata ou confissão de dívida, e o pedido
   é receber, a classe é de execução — `[12154]`. Se há prova escrita **sem** força
   executiva (contrato sem testemunhas, cheque prescrito, e-mails, planilha
   reconhecida), é `[40] Monitória`. Se não há documento nenhum, nenhuma das duas.
3. **Existe rito próprio para essa matéria?** Rito próprio sempre vence o procedimento
   comum: despejo, divórcio, inventário, usucapião, busca e apreensão, alimentos,
   interdição, possessórias. Só desça para `[7]` depois de descartar a classe nomeada.
4. **O relato diz que já existe processo em curso?** Se não diz, o caso é uma inicial:
   não escolha classe que pressupõe processo anterior, mesmo quando ela abre autos
   próprios — `[172] Embargos à Execução` exige execução em andamento, `[47] Ação
   Rescisória` exige decisão transitada em julgado, `[37] Embargos de Terceiro` exige
   constrição já sofrida em processo alheio.
5. **Nada disso se aplica?** Então é `[7] Procedimento Comum Cível`, que é a resposta
   certa com frequência — e por isso mesmo só vale depois dos quatro passos acima.

## As saídas fáceis

São as classes que atraem o relato ambíguo. Cada uma tem a condição que a torna
legítima; fora dela, escolha outra.

- `[7] Procedimento Comum Cível` — escolha correta e frequente, mas só quando não houver
  rito próprio (passo 3) nem título (passo 2). Não use para despejo, divórcio,
  inventário, alimentos, busca e apreensão, usucapião ou possessória.
- `[436] Procedimento do Juizado Especial Cível` — só quando o relato **disser** juizado
  especial, pequenas causas ou valor baixo. Não deduza pequeno valor do tom do relato:
  na dúvida entre `[436]` e `[7]`, escolha `[7]`.
- `[12375] Reclamação` — armadilha de vocabulário. Não é reclamar de produto, de serviço
  nem de atendimento: é a reclamação constitucional para preservar a competência de um
  tribunal ou a autoridade de decisão dele. A palavra "reclamação" no relato nunca é, por
  si, motivo para escolhê-la. (Na Justiça do Trabalho, "reclamação trabalhista" é outra
  coisa ainda — ver Trabalho, abaixo.)
- `[1294] Outros procedimentos de jurisdição voluntária` — último recurso, e só quando
  não há conflito: ninguém no polo passivo resistindo. Se há adversário, não é jurisdição
  voluntária.
- `[120] Mandado de Segurança Cível`, `[1269] Habeas Corpus Cível`, `[110] Habeas Data
  Cível` — exigem ato de autoridade pública e direito demonstrável por documento, sem
  necessidade de produzir prova. Empresa privada que negou algo não é autoridade coatora.
- `[12226] Notificação`, `[12227] Interpelação`, `[12228] Protesto` — só quando o pedido é
  **exclusivamente** dar ciência formal a alguém. Se o cliente quer dinheiro, coisa ou
  obrigação cumprida, não é nenhuma das três.

## O tronco cível

As classes abaixo não pertencem a nenhuma área: são o tronco comum do processo civil e
chegam na lista de quase toda área cível. O que está aqui são os **desempates** entre
elas — quando cada uma cabe e quando não cabe —, que valem mesmo quando a classe já
chegou descrita na lista de candidatas.

- `[7] Procedimento Comum Cível` — o rito padrão; ver acima.
- `[32] Consignação em Pagamento` — o cliente quer **pagar** e não consegue: o credor
  recusa, sumiu, ou há dúvida sobre quem deva receber. Não serve para discutir o valor
  sem querer depositá-lo.
- `[37] Embargos de Terceiro Cível` — bem de quem não é parte foi penhorado ou está
  ameaçado em processo alheio. Pressupõe esse outro processo.
- `[38] Habilitação` — uma das partes de um processo morreu e os sucessores assumem o
  lugar dela. Não é habilitação de crédito em inventário nem em falência.
- `[40] Monitória` — prova escrita sem força executiva; ver passo 2.
- `[45] Ação de Exigir Contas` — quem administrou dinheiro ou bem alheio deve prestar
  contas e não presta. Não é pedir extrato nem documento.
- `[47] Ação Rescisória` — desconstituir decisão **transitada em julgado**. Só com trânsito.
- `[110]`, `[119]`, `[120]` — habeas data, mandado de segurança coletivo e individual;
  ver saídas fáceis. O coletivo exige sindicato, associação, entidade de classe ou partido.
- `[172] Embargos à Execução` — defesa do executado em execução já em curso.
- `[436] Procedimento do Juizado Especial Cível` — ver saídas fáceis.
- `[1233] Efeito Suspensivo em Dissídio Coletivo` — exclusivo de dissídio coletivo
  trabalhista já julgado.
- `[1269] Habeas Corpus Cível` — liberdade de locomoção fora do crime: prisão civil do
  devedor de alimentos, internação compulsória.
- `[1294] Outros procedimentos de jurisdição voluntária` — ver saídas fáceis.
- `[11555] Suspensão de Liminar e de Sentença`, `[11556] Suspensão de Segurança Cível` —
  pedidos do poder público ao presidente do tribunal contra decisão já proferida contra
  ele. Nunca a classe de um relato de cliente particular.
- `[12154] Execução de Título Extrajudicial` — ver passo 2.
- `[12226] Notificação`, `[12227] Interpelação`, `[12228] Protesto` — ver saídas fáceis.
- `[12374] Homologação da Transação Extrajudicial` — acordo **já fechado** entre as
  partes, que só precisa da chancela do juiz. Se ainda há conflito, não é esta.
- `[12375] Reclamação` — ver saídas fáceis.
- `[15620] Produção Antecipada de Provas` — o pedido é só colher a prova (perícia,
  depoimento) antes de a ação principal existir, por risco de perdê-la.

## Desempates por área

Só os pares que de fato confundem. As classes próprias de cada área chegam descritas na
lista de candidatas — não as repita daqui.

### Família e Sucessões

- **Divórcio:** `[12372] Divórcio Consensual` quando o relato diz que há acordo;
  `[12541] Divórcio Litigioso` quando há discordância sobre qualquer ponto ou quando o
  relato não menciona acordo. Silêncio não é consenso.
- **União estável:** `[12763] Reconhecimento e Extinção de União Estável` quando é preciso
  provar que a união existiu; `[12762] Extinção Consensual de União Estável` quando ambos
  a reconhecem e só querem encerrá-la.
- **Herança:** `[39] Inventário` é o caso geral; `[31] Arrolamento Sumário` quando todos os
  herdeiros são maiores, capazes e estão de acordo; `[30] Arrolamento Comum` quando o
  espólio é de pequeno valor, ainda que haja incapaz; `[48] Sobrepartilha` só quando já
  houve partilha e apareceu bem novo.
- **Alimentos:** `[69] Alimentos - Lei Especial Nº 5.478/68` para fixar ou revisar;
  `[12247] Execução Extrajudicial de Alimentos` só quando já existe título e o pedido é
  cobrar o atrasado.
- **Guarda e convivência:** `[14671] Guarda de Família` quando a disputa é entre os pais;
  `[14677] Regulamentação da Convivência Familiar` quando a guarda não está em disputa e o
  conflito é o contato.
- **Violência doméstica:** `[15309] Medidas Protetivas de Urgência (Lei Maria da Penha) -
  Cível` quando o pedido é a proteção. A punição do agressor é matéria criminal.

### Imobiliário

- **Despejo:** `[92] Despejo` puro; `[93] Despejo por Falta de Pagamento` quando a causa é
  o aluguel atrasado; `[94] Despejo por Falta de Pagamento Cumulado Com Cobrança` quando,
  além de retomar o imóvel, se quer receber o atrasado — é o caso mais comum.
- **Posse:** `[1707] Reintegração / Manutenção de Posse` quando a posse já foi perdida ou
  turbada; `[1709] Interdito Proibitório` quando ainda é só ameaça; `[113] Imissão na
  Posse` quando o cliente é dono e nunca chegou a ter a posse.
- **Não confundir** `[49] Usucapião` (adquirir pela posse prolongada) com `[1683]
  Retificação de Registro de Imóvel` (a titularidade é certa, o registro é que está errado).

### Consumidor e Saúde

Quase toda pretensão de consumidor cai no tronco: `[7]` para vício de produto, cobrança
indevida, negativação e falha de serviço. Use as próprias da área só quando couberem:
`[81] Busca e Apreensão em Alienação Fiduciária` (financeira retomando bem financiado),
`[15217] Procedimento de Repactuação de Dívidas (Superendividamento)` (o relato descreve
dívidas múltiplas que o cliente não consegue pagar), `[65] Ação Civil Pública` (interesse
coletivo, autor institucional). Negativa de plano de saúde ou pedido de medicamento é
`[7]`, com urgência na narrativa — não existe classe "liminar".

### Trabalho

- `[985] Ação Trabalhista - Rito Ordinário` é a classe padrão da reclamação trabalhista —
  é ela, e não `[7]`, que serve ao empregado contra o empregador. `[1125] Ação Trabalhista
  - Rito Sumaríssimo` quando o relato indica valor baixo.
- Atenção ao vocabulário: `[1202] Reclamação` é a reclamação **constitucional** no ramo
  trabalhista — preservar a competência do tribunal ou a autoridade de decisão dele,
  gêmea de `[12375]`. Não é a "reclamação trabalhista" do empregado, que é `[985]`.
- `[986] Inquérito para Apuração de Falta Grave` é do **empregador** contra empregado
  estável, nunca o contrário.

### Previdenciário

A área quase não tem classe própria: contra o INSS, o pedido de benefício vai em `[7]`,
ou em `[14695] Procedimento do Juizado Especial da Fazenda Pública` quando o relato
disser juizado. Não escolha classe de execução só porque houve negativa administrativa.

### Penal

- **Antes da denúncia:** `[279] Inquérito Policial`, `[272] Representação
  Criminal/Notícia de Crime`, `[280] Auto de Prisão em Flagrante`. Relato de vítima que
  acabou de registrar ocorrência costuma ser `[272]`.
- **Ação penal:** `[283] Procedimento Ordinário` para crime com pena máxima ≥ 4 anos;
  `[10943] Sumário` abaixo disso; `[10944] Sumaríssimo` para infração de menor potencial;
  `[282] Competência do Júri` para crime doloso contra a vida; `[288]` para calúnia,
  injúria e difamação.
- **Liberdade:** `[305] Liberdade Provisória com ou sem fiança` quando a prisão é legal e
  se pede a soltura; `[306] Relaxamento de Prisão` quando a prisão é ilegal;
  `[307] Habeas Corpus Criminal` quando há coação ou ameaça e não há via ordinária.

### Infância e Juventude

`[1464] Processo de Apuração de Ato Infracional` para adolescente a quem se atribui o ato;
`[12070] Pedido de Medida de Proteção` para a criança que precisa de proteção;
`[15190] Destituição do Poder Familiar` quando se quer retirar o poder familiar; a adoção
se divide em `[15191] Adoção pelo Cadastro` e `[15192] Adoção Fora do Cadastro`. Disputa
entre os pais não é desta área — é `[14671]`, em Família.

## A justificativa

Duas ou três frases, em português do Brasil, escritas para o advogado que vai conferir a
escolha. Cite os fatos concretos do relato e o pedido que deles decorre — o que leva
àquela classe, e não o que a classe significa. Se o relato for curto ou ambíguo, diga que
a escolha é indiciária e o que faltou saber.

## O que nunca fazer

- Não escolha classe fora da lista de candidatas.
- Não escolha classe de recurso, incidente ou cumprimento de sentença para um relato que
  não menciona processo em curso.
- Não invente fatos que o relato não traz — valor da causa, documentos, acordo prévio.
- Não dê conselho jurídico nem estime chance de êxito.

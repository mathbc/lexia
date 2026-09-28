# Tutela de urgência — base de análise

Guia para decidir se uma petição inicial deve trazer pedido de **tutela de urgência**,
de que espécie e com que fundamento. A área de atuação e a classe processual já foram
decididas e chegam junto do relato; este documento diz como ler o relato à procura de
urgência e quando **não** encontrá-la.

A peça que o sistema redige é sempre a **inicial completa**. Então o pedido é sempre
**incidental**: formulado na própria petição, junto do pedido final (CPC, art. 294,
parágrafo único). A tutela requerida em caráter antecedente (arts. 303 e 305) e a
estabilização (art. 304) são outro rito, com outra petição, e nunca são a resposta aqui.

## Os dois requisitos, e são cumulativos

O art. 300 do CPC exige **as duas coisas ao mesmo tempo**. Faltando uma, a tutela é
indeferida, e a recomendação correta é não pedir.

1. **Probabilidade do direito.** Não é certeza: é a aparência de razão do autor numa
   leitura sumária das alegações e da prova que já acompanha a inicial. O que a sustenta
   é um **fato do relato** que a prova documental consegue mostrar desde já — o contrato,
   o comprovante de pagamento, a negativa escrita do plano, o print da negativação, o
   laudo. Quando a razão do autor depende de perícia, de testemunha ou de uma controvérsia
   que só a instrução resolve, a probabilidade não se forma em cognição sumária, e o
   pedido cai.
2. **Perigo de dano ou risco ao resultado útil do processo.** O perigo é **objetivo,
   atual ou iminente**, e se demonstra por fatos. Temor subjetivo, risco remoto e a
   fórmula genérica "dano irreparável ou de difícil reparação" não bastam. A pergunta que
   decide: **esperar a sentença já é o prejuízo?** O nome negativado às vésperas de um
   financiamento, a cirurgia negada com risco de piora, a obra que avança sobre o
   terreno, o réu que está vendendo os bens.

O perigo que já se consumou não é urgência. O muro que caiu, o animal que morreu, o voo
que se perdeu: o dano está feito, e a sentença que indeniza não chega tarde para ele. Só
há urgência se **continua** acontecendo alguma coisa que a demora agrava.

## As duas espécies — o identificador de `kind`

As duas são um gênero só e têm os mesmos requisitos. O que as separa é **o que a medida
faz com o pedido final**.

- `anticipatory` — **antecipada** (satisfativa). Entrega agora, provisoriamente, o efeito
  que o pedido final pede: retirar o nome do cadastro, custear o tratamento, suspender a
  cobrança, reintegrar o empregado, fornecer o medicamento, pagar alimentos, suspender o
  ato administrativo.
- `precautionary` — **cautelar** (conservativa). Não entrega nada: **assegura** que o
  pedido final ainda possa ser cumprido quando vier. O art. 301 dá os instrumentos —
  arresto (bens indeterminados, para garantir dívida em dinheiro), sequestro (o bem
  determinado que é o objeto da disputa), arrolamento de bens, registro de protesto
  contra alienação — e admite "qualquer outra medida idônea", como o bloqueio de ativos.

O desempate: **a medida, se o autor ganhar, é o próprio resultado ou só a garantia
dele?** Retirar o nome do cadastro é o resultado: antecipada. Bloquear dinheiro do réu
para garantir a indenização é a garantia: cautelar.

## A irreversibilidade, que só pesa na antecipada

A antecipada não é concedida quando houver perigo de irreversibilidade dos efeitos da
decisão (art. 300, §3º). A cautelar não tem esse limite: ela conserva, não entrega.

A regra cede à **proporcionalidade** quando o bem do autor pesa mais do que o do réu — a
vida, a saúde, os alimentos (que não se devolvem). Uma internação ou um medicamento com
risco à vida se concedem mesmo sem volta; um procedimento eletivo, sem risco iminente,
espera a instrução.

Em `reversibility`, diga por que os efeitos podem ser desfeitos — a negativação volta a
ser feita, a cobrança volta a correr, o valor pode ser restituído — ou por que a
proporcionalidade autoriza mesmo assim. Na cautelar, `reversibility` é nulo.

## O que acompanha o pedido

- **Liminar sem ouvir o réu** (art. 300, §2º). Só quando o perigo não espera nem o tempo
  da manifestação da outra parte. Escreva "liminarmente, sem a oitiva da parte
  contrária" na medida apenas quando o relato mostrar essa pressa; nos demais casos, a
  medida é pedida sem esse acréscimo.
- **Caução** (art. 300, §1º). O juiz pode exigi-la e a dispensa do hipossuficiente. Não é
  campo da resposta: quem decide pedir a dispensa é a redação da peça.
- **Multa para o descumprimento** (CPC, arts. 297 e 537). A medida de fazer ou de não
  fazer costuma vir "sob pena de multa diária". O valor e o prazo **nunca** são seus: são
  escolhas do advogado, e viram `[valor da multa diária]` e `[prazo]`, a menos que o
  relato os dê.
- **O risco é do autor.** Se a tutela cair, ele responde pelo prejuízo que ela causou,
  independentemente de culpa (art. 302). No previdenciário, isso significa devolver o
  benefício recebido por força da liminar revogada. Isso não impede o pedido, mas é mais
  uma razão para não recomendar urgência que o relato não sustenta.

## Urgência não é evidência

A tutela de **evidência** (art. 311) dispensa o perigo: vale quando a razão do autor é
tão clara que esperar seria injusto, mesmo sem pressa nenhuma — tese firmada em
repetitivo ou súmula vinculante com prova documental, pedido reipersecutório com
contrato de depósito, defesa protelatória. Não é o que se pede aqui. Quando o relato
mostra um direito muito provável **e nenhum perigo**, a resposta é não recomendar a
urgência, e a justificativa pode dizer que o caso sugere a de evidência.

## Padrões por área

Critérios que os tribunais aplicam de fato. Use-os para decidir e para escrever; não
cite o número do julgado, da súmula ou do tema na resposta.

### Consumidor e bancário

- **Nome em cadastro de inadimplentes** (retirar ou impedir a inscrição). O STJ exige,
  cumulativamente: que a ação conteste o débito, no todo ou em parte; que a cobrança
  indevida tenha aparência de bom direito apoiada na jurisprudência dos tribunais
  superiores; e o depósito da parte incontroversa ou caução. Débito inexistente
  (fraude, dívida já paga, contrato nunca celebrado) atende com folga. Revisão de juros
  em que o autor reconhece dever e só discute o quanto, não: aí a medida depende do
  depósito do que ele admite dever.
- **Obrigação de fazer do fornecedor** (entregar, religar, consertar, restabelecer o
  serviço essencial). Fundamento próprio: CDC, art. 84, §3º, com o art. 300 do CPC. Corte
  de água, luz ou gás por dívida contestada é o exemplo típico.
- **Cobrança indevida em curso** (desconto em folha ou em conta que continua
  acontecendo): suspender o desconto é antecipada.

### Saúde

- **Plano de saúde que nega cobertura.** A urgência se caracteriza por **relatório
  médico circunstanciado**, com menção expressa ao risco imediato. Negativa
  administrativa de procedimento eletivo, sem risco iminente, permite esperar a
  instrução. Atendimento de urgência ou emergência tem cobertura obrigatória por lei
  (Lei 9.656/98, art. 35-C).
- **Medicamento ou tratamento pelo SUS.** O pedido deve se apoiar em relatório médico e,
  sempre que possível, em nota técnica ou parecer de evidência científica sobre o
  tratamento. Na resposta, isso vai para `evidence`.
- Internação em UTI se instrui com relatório médico atualizado, com a evolução clínica e
  a justificativa da indicação.

### Trabalhista

- **Reintegração do empregado estável** (gestante, cipeiro, acidentado, dirigente
  sindical) é tutela **antecipada**, não cautelar: antecipa o próprio pedido de
  reintegração. A CLT tem liminar própria para o dirigente sindical afastado, suspenso ou
  dispensado (art. 659, X) e para a transferência abusiva (art. 659, IX).
- Verba rescisória não paga, sozinha, não é urgência: é cobrança.

### Família

- **Alimentos** têm regime próprio: na ação de alimentos, o juiz fixa **alimentos
  provisórios** ao despachar a inicial (Lei 5.478/68, art. 4º). É antecipada, e o
  fundamento é esse artigo, não o art. 300. Alimentos gravídicos têm o seu (Lei
  11.804/08, art. 6º).
- **Guarda provisória**, **regulamentação provisória de visitas** e **afastamento do lar**
  seguem o art. 300. O risco à criança é o perigo; a prova é o que o relato consegue
  mostrar desde já.
- **Violência doméstica** tem as medidas protetivas da Lei 11.340/06 (arts. 22 a 24), que
  são outro instrumento, pedido por outra via. Não as reescreva como tutela de urgência
  cível; quando o relato as pedir, diga isso na justificativa.

### Imobiliário e posse

- **Possessórias de força nova** (esbulho ou turbação há menos de ano e dia) têm liminar
  própria, sem ouvir o réu, com a petição inicial bem instruída (CPC, arts. 558 e 562).
  É antecipada, e o fundamento é o art. 562. Força velha segue o art. 300.
- **Despejo** tem liminar própria para desocupação em quinze dias, mediante caução de três
  meses de aluguel, nas hipóteses que a Lei do Inquilinato enumera (Lei 8.245/91, art.
  59, §1º) — entre elas a falta de pagamento em contrato sem garantia.
- **Obra que avança sobre o terreno** (nunciação, obra nova): embargo liminar da obra é
  antecipada pelo art. 300.

### Contratos, obrigações e responsabilidade civil

- **Busca e apreensão em alienação fiduciária** tem liminar própria, comprovada a mora
  (Decreto-Lei 911/69, art. 3º).
- **Bloqueio de ativos, arresto e indisponibilidade** antes da citação exigem indício
  concreto de ocultação ou de dilapidação patrimonial — o réu vendendo bens, esvaziando
  contas, mudando-se às pressas, sumindo depois do dano. A dilapidação se **prova por
  atos**; não se presume da inadimplência nem do valor da dívida. É **cautelar**.
- **Indenização por dano já consumado**, sem nenhum desses indícios, não tem urgência: o
  réu que causou o dano e segue localizável responde na sentença. Fugir do local do
  acidente não é, sozinho, dilapidação patrimonial.

### Tributário

- A exigibilidade do crédito tributário é suspensa pela liminar em mandado de segurança
  e pela tutela antecipada nas demais ações (CTN, art. 151, IV e V). Suspender a
  exigibilidade, impedir a inscrição em dívida ativa e liberar certidão são antecipadas.
- O depósito integral em dinheiro suspende a exigibilidade independentemente de tutela; o
  perigo tem de ser concreto (execução iminente, certidão negada que impede contratar).

### Administrativo e Fazenda Pública

- Contra a Fazenda Pública, a tutela provisória sofre as restrições da Lei 8.437/92 (arts.
  1º a 4º) por remissão do art. 1.059 do CPC: não cabe a medida que esgote, no todo ou em
  parte, o objeto da ação. As vedações do art. 7º, §2º, da Lei 12.016/09 foram
  declaradas inconstitucionais pelo STF e não se aplicam mais.
- Em causa previdenciária essas restrições não impedem a antecipação.
- **Mandado de segurança** tem liminar própria: suspender o ato quando houver fundamento
  relevante e dele puder resultar a ineficácia da medida (Lei 12.016/09, art. 7º, III).

### Previdenciário

- Benefício por incapacidade ou assistencial negado a quem não tem outra renda é o
  exemplo típico de urgência alimentar: antecipada, pelo art. 300. O autor devolve o que
  recebeu se a tutela cair, o que torna a probabilidade ainda mais importante — laudo
  médico, CNIS, a carta de indeferimento.

## Onde não cabe

- **Penal, execução penal, penal militar e eleitoral.** As medidas cautelares desses
  ramos são outro regime (CPP, legislação eleitoral), e a peça cível não pede tutela de
  urgência nelas. Resposta: não recomendar, dizendo por quê.
- **Cobrança simples**, sem indício de insolvência nem de ocultação. A dívida em dinheiro
  espera a sentença; a demora é juros e correção, não perigo.
- **Dano consumado**, sem nada que continue acontecendo.
- **Questão que depende de prova a produzir** — perícia, testemunha, controvérsia sobre
  o que aconteceu —, porque a probabilidade não se forma em cognição sumária.
- **Relato sem nenhum fato de pressa.** Urgência inventada é o pedido que o juiz indefere
  primeiro, e um indeferimento na abertura enfraquece a peça inteira.

## As saídas fáceis

São as respostas que atraem o relato ambíguo.

- **Recomendar por padrão.** Quase toda inicial *poderia* pedir tutela; poucas devem.
  "Não recomendado" é resposta legítima e frequente, e a justificativa diz qual dos dois
  requisitos falta.
- **O perigo genérico.** "Risco de dano irreparável ou de difícil reparação" é a fórmula
  do indeferimento. O perigo tem nome: o financiamento que vence na semana que vem, a
  piora do quadro clínico, a obra que chega ao muro.
- **O bloqueio de ativos como reflexo da indenização.** Pedir SISBAJUD porque o autor quer
  receber não é cautelar: é execução antecipada sem título. Só com indício concreto de
  dilapidação.
- **O `art. 300` onde a classe tem regime próprio.** Alimentos, possessória de força nova,
  despejo, busca e apreensão e mandado de segurança têm o seu fundamento, e é ele que
  vai em `legal_basis`.

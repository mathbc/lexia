# Áreas de atuação — base de classificação

Guia de referência para classificar a descrição dos fatos de uma peça jurídica em uma
das áreas de atuação do catálogo. Cada seção traz o `slug` exato que deve ser devolvido,
os fatos que caracterizam a área, as palavras que costumam aparecer no relato e os
desempates com as áreas vizinhas.

## Como classificar

1. Leia o relato e identifique **quem fez o quê a quem**: quem é a outra parte (empresa,
   patrão, INSS, poder público, particular, familiar), qual foi a conduta e qual foi o
   prejuízo.
2. **A outra parte é o que mais decide a área.** O mesmo fato muda de ramo conforme o
   réu: não receber um valor devido é trabalhista se o réu é o empregador, previdenciário
   se é o INSS, consumidor se é um fornecedor e civil se é um particular.
3. Escolha a área **pelo mérito do conflito**, não pelo procedimento em que ele será
   discutido.
4. Relato curto, vago ou incompleto ainda deve ser classificado: escolha a área mais
   provável a partir dos indícios presentes e diga na justificativa que a base é
   indiciária.
5. Se o relato encostar em duas áreas, escolha aquela de onde nasce o **pedido
   principal**. Um acidente sofrido a caminho do trabalho é trabalhista quando o que se
   discute é o vínculo e suas verbas, e civil quando o que se discute é o conserto do
   carro.

## Três áreas que quase nunca são a resposta

`processual-geral`, `administrativo-judiciario` e `tribunais-superiores` são áreas
**procedimentais**: existem para peças sobre o próprio andamento do processo, não sobre o
conflito que o originou.

- `processual-geral` — só quando o relato é sobre um incidente processual em si (uma
  carta precatória, um conflito de competência), sem mérito próprio.
- `administrativo-judiciario` — só para procedimentos administrativos internos do
  tribunal (pedidos de providências, precatórios, correições).
- `tribunais-superiores` — só quando a peça já nasce como recurso ou ação originária no
  STF ou no STJ, e o relato diz isso.

Nunca use nenhuma das três como saída para um relato de mérito que ficou ambíguo. Diante
da dúvida, escolha a área do mérito e sinalize a incerteza na justificativa.

---

## Direito Civil
- **slug:** `civil`
- **Fatos típicos:** negócios jurídicos entre particulares, descumprimento de contrato,
  cobrança de dívida particular, responsabilidade civil por dano material ou moral fora
  de relação de consumo ou de trabalho, acidente de trânsito entre particulares, conflito
  de vizinhança, direitos da personalidade, uso indevido de imagem ou nome.
- **Palavras-chave:** contrato, acordo, empréstimo, vizinho, bateu no meu carro, calúnia,
  indenização, particular, amigo, sócio informal.
- **Não confundir com:** `consumidor`, quando a outra parte é fornecedor profissional;
  `imobiliario`, quando o objeto é um imóvel; `empresarial`, quando a relação é entre
  empresas. É a área residual do tronco cível: use quando nenhuma área mais específica
  couber.

## Direito de Família e Sucessões
- **slug:** `familia-sucessoes`
- **Fatos típicos:** casamento, união estável e seu reconhecimento ou dissolução,
  divórcio, guarda e convivência dos filhos, pensão alimentícia, investigação ou negatória
  de paternidade, inventário, partilha, testamento, herança, curatela e interdição,
  violência doméstica na esfera cível (medidas protetivas).
- **Palavras-chave:** ex-marido, ex-esposa, companheiro, filhos, pensão, guarda, divórcio,
  separação, herdeiros, falecimento, espólio, inventário, alimentos.
- **Não confundir com:** `infancia-juventude`, quando o foco é a proteção da criança pelo
  Estado (acolhimento, ato infracional, adoção por via do ECA) e não o conflito entre os
  pais; `penal`, quando o pedido é a punição criminal do agressor.

## Direito Imobiliário
- **slug:** `imobiliario`
- **Fatos típicos:** compra e venda de imóvel, locação, despejo, cobrança de aluguel,
  usucapião, posse e propriedade, reintegração de posse, condomínio e suas taxas, obras
  irregulares, incorporação, financiamento habitacional, adjudicação compulsória.
- **Palavras-chave:** imóvel, apartamento, casa, terreno, aluguel, inquilino, locatário,
  locador, despejo, condomínio, síndico, escritura, posse, invasão.
- **Não confundir com:** `civil`, do qual é um recorte — prefira `imobiliario` sempre que
  o imóvel for o objeto central; `registros-publicos`, quando o problema é o registro em
  si (matrícula, averbação) e não a titularidade.

## Direito do Consumidor
- **slug:** `consumidor`
- **Fatos típicos:** relação entre consumidor final e fornecedor profissional de produto
  ou serviço — produto com vício ou defeito, recusa de garantia, falha na prestação de
  serviço, cobrança indevida, negativação no SPC/Serasa, voo cancelado ou atrasado,
  problema com plano de telefonia, energia ou internet, compra pela internet, tarifas e
  contratos bancários, publicidade enganosa.
- **Palavras-chave:** comprei, loja, produto, defeito, garantia, SAC, protocolo,
  cancelamento, cobrança, banco, fatura, cartão, negativado, propaganda enganosa, voo,
  operadora, assinatura.
- **Não confundir com:** `civil`, quando dos dois lados há particulares e não há
  fornecedor profissional; `saude`, quando o objeto é negativa de tratamento ou medicamento
  por plano de saúde ou pelo SUS.

## Direito Empresarial e Falimentar
- **slug:** `empresarial`
- **Fatos típicos:** conflito entre empresas ou entre sócios, dissolução de sociedade,
  apuração de haveres, contratos empresariais, títulos de crédito, duplicata, cheque,
  execução de dívida entre empresas, recuperação judicial, falência, marca, nome
  empresarial e concorrência desleal.
- **Palavras-chave:** sócio, empresa, quotas, contrato social, duplicata, cheque, nota
  promissória, distrato, recuperação judicial, falência, marca, franquia, fornecedor.
- **Não confundir com:** `consumidor`, quando a empresa figura como fornecedora diante de
  um consumidor final; `civil`, quando o contrato é entre particulares sem atividade
  empresarial; `tributario`, quando a discussão é o tributo em si.

## Direito do Trabalho
- **slug:** `trabalhista`
- **Fatos típicos:** relação entre empregado e empregador — vínculo não registrado,
  demissão sem justa causa ou com justa causa contestada, verbas rescisórias não pagas,
  horas extras, adicional de insalubridade ou periculosidade, assédio moral ou sexual no
  trabalho, acidente de trabalho e doença ocupacional, equiparação salarial, FGTS, jornada
  e banco de horas, rescisão indireta, trabalho intermitente e por aplicativo.
- **Palavras-chave:** empresa, patrão, chefe, empregado, funcionário, carteira assinada,
  CTPS, salário, demitido, dispensa, justa causa, aviso prévio, rescisão, FGTS, turno,
  escala, hora extra, insalubridade, CAT.
- **Não confundir com:** `previdenciario`, quando o réu é o INSS e o pedido é o benefício
  (mesmo que a origem seja um acidente de trabalho); `administrativo`, quando o vínculo é
  estatutário e a parte é servidor público concursado.

## Direito Previdenciário
- **slug:** `previdenciario`
- **Fatos típicos:** relação do segurado com o INSS — negativa ou revisão de aposentadoria,
  auxílio-doença e auxílio por incapacidade, BPC/LOAS, pensão por morte, salário-maternidade,
  auxílio-acidente, perícia médica negativa, contagem de tempo de contribuição, averbação
  de tempo especial.
- **Palavras-chave:** INSS, aposentadoria, benefício, auxílio, perícia, carência, tempo de
  contribuição, segurado, LOAS, BPC, DER, indeferido.
- **Não confundir com:** `trabalhista`, quando o réu é o empregador; `saude`, quando o
  pedido é tratamento médico e não benefício em dinheiro; `administrativo`, quando o
  regime é próprio de servidor público e não o RGPS.

## Direito Tributário
- **slug:** `tributario`
- **Fatos típicos:** lançamento, cobrança ou execução de tributo, auto de infração,
  repetição de indébito, imunidade e isenção, ICMS, ISS, IPTU, IPVA, IRPF e IRPJ,
  contribuições, parcelamento, certidão negativa, exclusão do ICMS da base de cálculo.
- **Palavras-chave:** imposto, tributo, taxa, contribuição, Receita Federal, fisco, auto
  de infração, execução fiscal, CDA, ICMS, ISS, IPTU, IPVA, imposto de renda, restituição.
- **Não confundir com:** `administrativo`, quando o litígio com o poder público não é
  sobre tributo; `empresarial`, quando a discussão é societária.

## Direito Administrativo e Fazenda Pública
- **slug:** `administrativo`
- **Fatos típicos:** litígio com a Administração Pública — concurso público e sua
  eliminação, servidor público estatutário e seus vencimentos, licitação e contrato
  administrativo, multa de trânsito, sanção administrativa, improbidade, responsabilidade
  civil do Estado, desapropriação, poder de polícia, alvará e licença.
- **Palavras-chave:** prefeitura, município, Estado, União, autarquia, servidor público,
  concurso, edital, licitação, pregão, multa de trânsito, DETRAN, processo administrativo,
  desapropriação.
- **Não confundir com:** `tributario`, quando o objeto é tributo; `constitucional`, quando
  a discussão não é o ato concreto mas a validade da norma; `educacao` e `saude`, quando o
  pedido é a prestação específica desses serviços.

## Direito Constitucional
- **slug:** `constitucional`
- **Fatos típicos:** violação direta de direito fundamental, mandado de segurança contra
  ato de autoridade, habeas data, mandado de injunção, controle de constitucionalidade,
  arguição de descumprimento de preceito fundamental, liberdade de expressão, de reunião
  e de crença.
- **Palavras-chave:** inconstitucional, direito fundamental, mandado de segurança,
  autoridade coatora, liminar constitucional, ADI, ADPF, garantia constitucional.
- **Não confundir com:** `administrativo`, que é a área do ato administrativo concreto —
  reserve `constitucional` para quando a tese central é a própria norma ou a garantia
  fundamental.

## Direito Ambiental
- **slug:** `ambiental`
- **Fatos típicos:** dano ambiental, poluição, desmatamento, licenciamento ambiental,
  área de preservação permanente, multa de órgão ambiental, resíduos, recuperação de área
  degradada, unidade de conservação.
- **Palavras-chave:** meio ambiente, IBAMA, poluição, desmatamento, licença ambiental,
  APP, nascente, esgoto, contaminação, aterro.
- **Não confundir com:** `administrativo`, quando a multa é apenas o veículo e o mérito é
  ambiental — prefira `ambiental`.

## Direito da Saúde
- **slug:** `saude`
- **Fatos típicos:** negativa de cobertura por plano de saúde, recusa de procedimento,
  medicamento de alto custo, fornecimento de tratamento pelo SUS, internação, leito de
  UTI, home care, erro médico, reajuste abusivo de mensalidade de plano.
- **Palavras-chave:** plano de saúde, convênio, SUS, medicamento, cirurgia, internação,
  UTI, tratamento, ANS, carência, negativa de cobertura, erro médico.
- **Não confundir com:** `consumidor`, do qual é um recorte — prefira `saude` sempre que o
  objeto for prestação de saúde; `previdenciario`, quando o pedido é benefício em dinheiro
  por incapacidade e não tratamento.

## Registros Públicos e Notarial
- **slug:** `registros-publicos`
- **Fatos típicos:** retificação de registro civil, mudança de nome ou de gênero,
  averbação, registro tardio de nascimento, dúvida registral, retificação de área ou de
  matrícula de imóvel, ata notarial, escritura e reconhecimento de firma.
- **Palavras-chave:** cartório, registro civil, certidão, matrícula, averbação, retificação,
  tabelião, oficial de registro, escritura, dúvida registral.
- **Não confundir com:** `familia-sucessoes`, quando a mudança de estado civil é
  consequência de divórcio ou reconhecimento de união; `imobiliario`, quando se discute a
  titularidade e não o registro.

## Direito Marítimo
- **slug:** `maritimo`
- **Fatos típicos:** transporte marítimo de carga, avaria, afretamento, demurrage,
  responsabilidade do armador, acidente de navegação, seguro marítimo, salvamento,
  tripulação embarcada.
- **Palavras-chave:** navio, embarcação, armador, porto, contêiner, carga, afretamento,
  demurrage, conhecimento de embarque, capitania dos portos.
- **Não confundir com:** `empresarial`, quando o contrato não envolve navegação;
  `internacional`, quando o núcleo é o conflito de leis e não a navegação.

## Direito Internacional
- **slug:** `internacional`
- **Fatos típicos:** homologação de sentença estrangeira, carta rogatória, cooperação
  jurídica internacional, contrato internacional e conflito de leis, extradição, sequestro
  internacional de crianças, imunidade de jurisdição, direito do estrangeiro.
- **Palavras-chave:** estrangeiro, exterior, sentença estrangeira, rogatória, tratado,
  homologação, extradição, consulado, residência, visto.
- **Não confundir com:** `familia-sucessoes`, quando o conflito familiar é interno mesmo
  com parte residente fora do país.

## Direito Penal e Processo Penal
- **slug:** `penal`
- **Fatos típicos:** conduta tipificada como crime ou contravenção — furto, roubo,
  estelionato e golpes, lesão corporal, ameaça, homicídio, tráfico, crimes contra a honra,
  violência doméstica na esfera criminal, prisão em flagrante, inquérito, denúncia do
  Ministério Público, defesa do réu, pedido de liberdade provisória.
- **Palavras-chave:** polícia, delegacia, boletim de ocorrência, BO, preso, flagrante,
  furtaram, roubaram, agressão, ameaça, golpe, estelionato, inquérito, denúncia, réu,
  audiência de custódia.
- **Não confundir com:** `execucao-penal`, quando já há condenação e o pedido é sobre o
  cumprimento da pena; `penal-militar`, quando o fato é militar; `civil` ou `consumidor`,
  quando o pedido é indenização e não punição criminal.

## Execução Penal
- **slug:** `execucao-penal`
- **Fatos típicos:** cumprimento de pena já imposta — progressão de regime, livramento
  condicional, remição por trabalho ou estudo, indulto e comutação, falta disciplinar,
  unificação de penas, transferência de unidade prisional, saída temporária.
- **Palavras-chave:** progressão, regime semiaberto, condenado, cumprindo pena, remição,
  livramento condicional, indulto, falta grave, presídio, VEP, atestado de pena.
- **Não confundir com:** `penal`, que cobre tudo até o trânsito em julgado. O divisor é a
  existência de condenação definitiva em execução.

## Direito Penal Militar
- **slug:** `penal-militar`
- **Fatos típicos:** crime militar praticado por militar das Forças Armadas ou das
  polícias militares — deserção, insubmissão, violência contra superior, crimes em serviço
  ou em área sob administração militar.
- **Palavras-chave:** militar, quartel, caserna, deserção, insubmissão, superior
  hierárquico, justiça militar, IPM, praça, oficial.
- **Não confundir com:** `penal`, quando o autor é civil ou o crime não é militar;
  `administrativo`, quando a questão é disciplinar ou remuneratória e não criminal.

## Direito Eleitoral
- **slug:** `eleitoral`
- **Fatos típicos:** registro de candidatura, impugnação, propaganda eleitoral irregular,
  abuso de poder econômico ou político, prestação de contas de campanha, inelegibilidade,
  diplomação, crimes eleitorais, filiação partidária.
- **Palavras-chave:** eleição, candidato, partido, urna, propaganda eleitoral, TSE, TRE,
  diplomação, inelegível, prestação de contas, zona eleitoral.
- **Não confundir com:** `penal`, quando o crime não é eleitoral; `administrativo`, quando
  o ato é de gestão e não de disputa eleitoral.

## Infância e Juventude
- **slug:** `infancia-juventude`
- **Fatos típicos:** proteção de criança e adolescente pelo Estado — ato infracional e
  medida socioeducativa, acolhimento institucional, destituição do poder familiar, adoção,
  guarda para fins de proteção, medidas protetivas do ECA, trabalho infantil, matrícula e
  vaga em creche quando o fundamento é o ECA.
- **Palavras-chave:** menor, adolescente, criança, ECA, conselho tutelar, ato infracional,
  medida socioeducativa, acolhimento, abrigo, adoção, destituição do poder familiar.
- **Não confundir com:** `familia-sucessoes`, quando a disputa é entre os pais (guarda,
  pensão, convivência) sem intervenção protetiva do Estado.

## Direito à Educação
- **slug:** `educacao`
- **Fatos típicos:** vaga em creche ou escola, transferência, matrícula negada, mensalidade
  escolar e reajuste, FIES e ProUni, reconhecimento de diploma, revalidação, atendimento
  educacional especializado, bullying no ambiente escolar, retenção de documento escolar.
- **Palavras-chave:** escola, creche, faculdade, universidade, matrícula, mensalidade,
  vaga, transferência, diploma, FIES, ProUni, MEC, histórico escolar.
- **Não confundir com:** `consumidor`, quando a instituição é privada e o conflito é
  puramente contratual — prefira `educacao` sempre que o objeto for o direito à educação
  em si; `infancia-juventude`, quando o fundamento invocado é o ECA.

## Procedimentos Administrativos do Judiciário
- **slug:** `administrativo-judiciario`
- **Fatos típicos:** procedimentos internos do tribunal — pedido de providências,
  reclamação disciplinar contra serventia, precatório e requisição de pequeno valor,
  correição, sindicância, restauração de autos.
- **Palavras-chave:** precatório, RPV, pedido de providências, corregedoria, serventia,
  autos extraviados.
- **Não confundir com:** `administrativo`, que é o litígio com a Administração Pública em
  geral. Aqui a Administração em questão é a do próprio Judiciário.

## Tribunais Superiores (STF/STJ)
- **slug:** `tribunais-superiores`
- **Fatos típicos:** peça que nasce originariamente no STF ou no STJ — recurso
  extraordinário e especial já naquela instância, agravo em recurso especial, reclamação,
  suspensão de liminar, ações originárias de competência dessas cortes.
- **Palavras-chave:** STF, STJ, recurso extraordinário, recurso especial, repercussão
  geral, reclamação, agravo em recurso especial.
- **Não confundir com:** qualquer área de mérito. Só use quando o relato disser
  explicitamente que a peça é dirigida a um tribunal superior.

## Processual (transversal)
- **slug:** `processual-geral`
- **Fatos típicos:** incidentes sem mérito próprio — carta precatória, conflito de
  competência, exceção de incompetência, habilitação, procedimentos que servem a qualquer
  ramo.
- **Palavras-chave:** carta precatória, conflito de competência, incidente, habilitação.
- **Não confundir com:** a área do mérito. É o último recurso da classificação e nunca a
  resposta para um relato de fatos comum.

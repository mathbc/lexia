# AI

Pasta de convenção do `laravel/ai`: `php artisan make:agent` e `php artisan make:tool`
geram em `app/Ai/Agents` e `app/Ai/Tools`. O conhecimento que os agentes leem fica em
`app/Rag`.

## Agentes

| Agente | O que faz |
|---|---|
| `PracticeAreaClassificationAgent` | Lê a descrição dos fatos e devolve a área de atuação, com justificativa |
| `ProceduralClassSelectionAgent` | Dentro da área já decidida, escolhe a classe processual do CNJ, com justificativa |
| `DefendantExtractionAgent` | Lê a descrição dos fatos e devolve os dados do réu nos doze campos `defendant_*` de `legal_cases` |
| `RequirementExtractionAgent` | Lê a descrição dos fatos e devolve a lista do que o cliente pede ao juízo, cada pedido com a frase e o valor |
| `FactsRefinementAgent` | Reescreve o relato do cliente como a narrativa de fatos de uma inicial: registro formal, terceira pessoa, ordem cronológica — e o peso que uma perda irreparável tem |

Um agente não é chamado direto da tela: quem o expõe é uma Action do domínio
(`ClassifyPracticeArea`, `SelectProceduralClass`, `ExtractLegalCaseDefendant`,
`ExtractLegalCaseRequirements`, `RefineLegalCaseFacts`), que carrega o contexto, resolve
a resposta em modelo e é o ponto por onde o caso de uso entra. `ClassifyLegalCase` chama
os quatro primeiros — encadeia os dois iniciais e acrescenta os outros dois, que é coisa
diferente de encadear; a seção abaixo diz por quê. O quinto fica de fora dela de
propósito: ele reescreve um relato que já está na tela, e não lê um relato para preencher
uma.

## Por que dois agentes, e não um com ferramenta

As classes que uma peça pode receber são as vinculadas à área dela — então a lista de
respostas permitidas só existe **depois** que a área foi decidida. Uma ferramenta que
buscasse as classes no meio de uma única conversa daria ao modelo a mesma informação,
mas devolveria a resposta ao terreno da boa vontade: o código da classe deixaria de ser
restringido por gramática e passaria a ser validado depois do fato. Duas chamadas, cada
uma com seu `enum`, custam uma inferência a mais e compram a garantia.

O preço é latência: dois `Timeout(180)` em série não cabem num request síncrono. A tela
que pedir isso vai despachar, não esperar.

## Os dois extratores: acrescentados à cadeia, não encadeados

Encadear é o que as duas primeiras chamadas fazem: a segunda **precisa** da resposta da
primeira, porque a lista de classes candidatas não existe antes de a área ser conhecida.
Os agentes do réu e dos pedidos não precisam de nenhuma das duas, nem um do outro — leem
os mesmos fatos e respondem outras perguntas. Vêm depois no tempo, e não na dependência.

O que os trouxe para dentro de `ClassifyLegalCase` foi o chamador. O preenchimento
inteligente é um gesto só: o advogado escreve o relato uma vez e espera uma vez. Pedir o
réu e os pedidos em viagens separadas faria a mesma tela esperar três, pelo mesmo relato.
As Actions continuam chamáveis sozinhas, e é assim que a tela que quiser só uma das
sugestões — a etapa 2 ou a etapa 4 de uma peça já salva — deve chamá-las: uma inferência,
e não quatro.

Como a dependência não existe, a falha também não se propaga: um erro de qualquer dos
dois é absorvido por `ClassifyLegalCase`, reportado ao log e devolvido como `null` naquela
chave. O enquadramento custou minutos e sobrevive; a etapa abre em branco, que é como ela
abria antes de haver agente nenhum. Cuidado com a distinção que o payload carrega em
ambos: `null` é a extração que falhou, doze campos nulos dentro de um `DefendantData` são
o relato que não identifica ninguém, e uma lista vazia dentro de um `RequirementListData`
é o relato que não pede nada — as duas últimas são respostas legítimas e frequentes.

A diferença de natureza é maior do que a de momento. Os dois agentes de enquadramento
**escolhem** uma linha de catálogo, e todo o trabalho deles é escolher bem; o do réu
**copia**, e um dado inventado vira um nome errado numa petição. Daí o `Temperature(0.1)`
e daí as instruções gastarem mais linhas dizendo o que *não* preencher — o endereço do
autor, o local do fato, a placa no lugar do CPF — do que descrevendo os campos.

O dos pedidos faz as duas coisas ao mesmo tempo, e é o que o torna o mais delicado dos
quatro: **quais** pedidos existem é leitura do relato, e a **frase** de cada um é
composição. Por isso o `Temperature(0.2)` e por isso a única regra do projeto que um
prompt não conseguiu segurar — ver a seção do dinheiro abaixo.

Os doze campos do réu são `required()` **e** `nullable()` ao mesmo tempo, o que não é contradição
e sim o desenho: a gramática obriga as doze chaves a existirem e faz do `null` uma
resposta de primeira classe. Chaves opcionais deixariam um modelo pequeno omitir as que
não achou, e então uma chave ausente e uma chave que o modelo nunca considerou ficariam
indistinguíveis. Um réu é descrito, não cadastrado: nulo é o caso comum.

As chaves são os nomes das colunas `defendant_*`, escritos como a tabela os escreve, e é
isso que faz a resposta cair inteira em `DefendantData` — o mesmo objeto que
`UpdateLegalCaseDefendant` já recebe, com as máscaras removidas e a UF resolvida em
`BrazilianState`. Renomear uma chave no `schema()` não quebra nada visivelmente: deixa uma
coluna vazia. O teste em `tests/Agents/DefendantExtractionTest.php` compara a lista de
chaves justamente por isso.

O agente de pedidos repete o arranjo com a lista: devolve `RequirementListData`, o mesmo
objeto que `SaveLegalCaseRequirements` já recebe, e `amount` é `required()` **e**
`nullable()` pela mesma razão — a maioria dos pedidos não tem cifra.

## O dinheiro de um pedido, e a guarda que o prompt não deu

O agente de pedidos é o único que devolve **dinheiro**, e é onde mora a única regra deste
projeto que as instruções não conseguiram sustentar. Medido contra o `qwen2.5:7b`:

- somar não é o problema. A instrução "nunca some" se sustenta, e um relato com "R$ 600,00
  por mês" durante sete meses não vira um pedido de R$ 4.200,00;
- **compor**, sim. Para um caso cujo relato não nomeia cifra de dano moral, o modelo
  devolveu R$ 5.000,00 numa rodada e R$ 12.000,00 noutra — números do que um pedido daquele
  tipo costuma custar. Nenhuma redação do prompt derrubou isso de forma estável, e uma
  cifra composta chega bem-formada, plausível na frase ao lado e indistinguível de uma que
  o cliente escreveu.

Daí `RequirementListData::fromAgent()` receber **o relato** junto da resposta e recusar
toda cifra que os fatos não escrevam. É uma guarda estrutural onde o prompt não alcançou,
e ela é generosa de propósito: qualquer número do texto autoriza, seja prazo, data ou
número de porta. Ela não decide se a cifra pertence àquele pedido — isso é juízo, e é do
advogado que revisa. Ela recusa o que veio do nada.

O custo conhecido é o relato que escreve o dinheiro por extenso: "quinze mil reais" não
ancora nada, o pedido perde uma cifra a que tinha direito, e a frase continua dizendo
quanto é. É o lado certo do erro — um campo em branco em vez de um número seguro de si.

Vale saber o que a guarda **não** faz: ela protege a coluna, não a prosa. A frase pode
continuar nomeando a cifra recusada, e essa combinação — campo vazio ao lado de frase com
valor — é, convenientemente, a cara de um pedido que pede uma segunda olhada.

## O relato reescrito, e o que muda quando a resposta é prosa

`FactsRefinementAgent` é o quinto agente e o primeiro cuja resposta é **texto**. Os
outros quatro respondem perguntas sobre um relato e cada resposta é um valor que cai
numa coluna; este devolve o próprio relato, reescrito. Não há `enum` para restringir um
parágrafo, e a gramática obriga a chave a existir sem obrigar as frases a serem
verdadeiras — é a mesma diferença que separa escolher de copiar, um degrau acima.

Ele faz duas coisas, e a segunda é condicional:

1. **Registro.** O relato do cliente — fora de ordem, em primeira pessoa, com a ortografia
   de quem digita no celular — vira português formal em terceira pessoa, "o Autor" e "a
   Ré", em ordem cronológica, com o termo leigo trocado pelo próprio. O que precisa
   sobreviver a essa passagem é a pessoa: um resumo genérico, que serviria a qualquer caso
   parecido, é o pior resultado possível.
2. **Peso.** Quando o relato narra uma perda que não se repõe — morte na família, o animal
   da casa, o bem de valor afetivo insubstituível, a humilhação, o tratamento negado —, um
   parágrafo frio não é sobriedade, é a peça deixando de dizer aquilo para o que existe.
   Quando o caso é cobrança, atraso de entrega ou contrato entre empresas, o mesmo
   tratamento perde o juiz. As duas listas estão escritas nas instruções para que a
   decisão seja tomada, e não sentida, e o agente a declara em `impact_basis`: o fato do
   relato que autorizou o destaque, ou nulo — que é a resposta da maioria.

O destaque é definido como **o fato narrado dito por inteiro**, nunca como adjetivo.
"Convivia com o animal havia nove anos" está no relato; "dor indescritível" não está em
lugar nenhum. E diagnóstico, tratamento e afastamento são fatos novos, que se provam com
documento — nomear o sofrimento que decorre do fato é permitido, inventar a consequência
dele não é.

### O dossiê

Este é o único agente que vê a peça em volta do relato: área, classe do CNJ, cliente, réu
e os pedidos já registrados. Os quatro mudam a escrita — como as partes se chamam, de que
tipo de história se trata e quais pretensões o texto precisa sustentar. Quem monta isso é
`LegalCaseDossier`, que documenta o que fica de fora, a começar pelos próprios fatos: o
relato é o que vai no *prompt*, e repeti-lo no sistema gastaria a janela duas vezes.

Cuidado com um efeito colateral já observado: um modelo pequeno copia o dossiê para
dentro da narrativa se ele não for apresentado como contexto. A instrução que segura isso
é "um dado que está aqui e não está no relato é, para a narrativa, um fato que você não
tem".

### A cifra inventada, e a guarda que relata em vez de apagar

Mesma falha do agente de pedidos, em outra roupa: dado "R$ 4.800,00 por mês" e "cinco
meses em aberto", o modelo fecha o parágrafo com "totalizando R$ 24.000,00". A soma está
certa e o fato é inventado — ninguém pediu aquele número —, e em prosa ele se lê
exatamente como uma cifra que o cliente escreveu.

Duas coisas o seguram, e a ordem importa:

- **No prompt, proibir os conectivos funcionou onde proibir a operação não funcionava.**
  "Nunca some" sozinho não se sustentou; "não escreva *totalizando*, *no total de*,
  *perfazendo*, *somando*, *o que equivale a*" sustentou. São as palavras que só existem
  para introduzir um número calculado, e um modelo pequeno reconhece uma palavra melhor do
  que uma operação.
- **No código, `RefinedFactsData::unsupportedAmounts`** lista toda cifra que a reescrita
  nomeia e o relato não escreve. Diferente do `amount` de um pedido, ela **relata em vez
  de apagar**: uma coluna pode ficar em branco, uma frase não — recusar o trecho deixaria
  um buraco no meio da história. A tela mostra a lista ao lado do texto que está
  oferecendo, e o advogado sabe onde olhar. Testes determinísticos em
  `tests/Unit/Domain/RefinedFactsDataTest.php`.

Só cifra é conferida, de propósito: número solto muda de forma numa reescrita legítima —
"sete meses" vira "7 meses" — e reprovar isso seria reprovar o trabalho.

### O que o modelo de hoje não segura

Medido com o mesmo prompt e os mesmos dois casos de `tests/Agents/FactsRefinementTest.php`:

| | `qwen2.5:7b` (configurado) | `qwen3.8:27b` |
|---|---|---|
| Registro, terceira pessoa, sem coloquialismo | sim | sim |
| Destaque só onde cabe, com `impact_basis` | sim | sim |
| Não compor um total | no caso curto, sim; no longo, às vezes não | sim |
| Carregar a oração encaixada — "as coisas da minha mãe, **que faleceu em janeiro**" | não | sim |

A segunda falha é a séria, e é uma **ausência**: some justamente o fato sobre o qual o
caso inteiro se apoia, e nenhuma guarda pega o que não está escrito. O `impact_basis`
costuma mencionar a morte que a narrativa perdeu, o que é um bom sinal de que a instrução
chega e a redação é que não a carrega.

É por isso que a saída deste agente é uma **minuta para o advogado aceitar**, e não um
campo que se preenche sozinho. A tela que expuser isto deve mostrar os dois textos lado a
lado; `RefineLegalCaseFacts` diz o mesmo no docblock. E trocar `OLLAMA_TEXT_MODEL` é uma
linha de `.env`: é o agente que mais ganha com um modelo maior.

## Contexto: `AI_CONTEXT_WINDOW`, e por que ele é explícito

Sem `num_ctx` o tamanho de contexto é o default do daemon, e o Ollama **trunca em
silêncio** quando o prompt estoura — a resposta volta plausível, construída sobre um
prompt que perdeu o fim. O guia de áreas sozinho tem 19 KB.

O valor é medido, não estimado. O maior prompt de sistema que o agente de classe monta
— Penal, 45 candidatas, 31 delas descritas — tem **10.946 tokens** pelo
`prompt_eval_count` do próprio Ollama. Somando o schema que o gateway anexa, um relato
longo de cliente e a resposta, 24576 deixa folga real onde 16384 deixava quase nenhuma.
Para remedir depois de mexer nos prompts:

```bash
curl -s http://localhost:11434/api/generate -d '{"model":"qwen2.5:7b","prompt":"…","stream":false,"options":{"num_predict":1}}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN),true)["prompt_eval_count"].PHP_EOL;'
```

O número é **um só, e não é do modelo**: `ai.context_window` (env `AI_CONTEXT_WINDOW`,
24576 por padrão) descreve o tamanho dos prompts que este projeto escreve, e quem o
entrega ao provider é o trait `App\Ai\Concerns\UsesConfiguredContextWindow`. Um agente
novo usa o trait e implementa `HasProviderOptions`; nada nele precisa saber qual modelo
está respondendo. O trait só emite `num_ctx` para o driver `ollama`, que é quem conhece
essa chave.

Cuidado ao mexer: `providerOptions` cai na chave `options` do corpo, mas `think`,
`format` e `keep_alive` são içados para o topo — não coloque `think` ali.

## Provider e modelo

Ollama local, configurado em `config/ai.php`: `qwen2.5:7b` para texto e
`nomic-embed-text` (768 dimensões) para embeddings. É o único provider declarado —
a história do cliente não sai da infraestrutura do escritório para ser classificada.

**Nenhum agente carrega `#[Model]`.** O modelo é nomeado em um lugar só,
`ai.providers.ollama.models.text` (env `OLLAMA_TEXT_MODEL`), e sem o atributo o SDK
resolve cada agente por `defaultTextModel()`. Trocar de modelo é editar o `.env` e
rodar `php artisan config:clear`.

## Três armadilhas, nenhuma exclusiva de um modelo

1. **Nunca mande `think: false`.** O `gpt-oss:20b` respondia com `content` vazio, e a
   linha qwen3 também raciocina por padrão. Deixado em paz, o Ollama separa o raciocínio
   em `message.thinking` e o JSON chega limpo em `message.content`, que é o que a saída
   estruturada consome. O `qwen2.5:7b` de hoje não raciocina, então a armadilha está
   dormente — o que não é motivo para acrescentar a chave: ela volta a morder no dia em
   que o `OLLAMA_TEXT_MODEL` apontar para um modelo que pensa.
2. **A chave `models` no `config/ai.php` é obrigatória.** Sem ela o `OllamaProvider` cai
   no default do pacote, `qwen3.5:4b`, que este projeto não baixa.
3. **O modelo precisa suportar `tools`/saída estruturada.** O `format` do Ollama vira
   gramática; um modelo sem essa capacidade devolve JSON só por boa vontade. Confira com
   `ollama show <modelo>` antes de trocar.

## Busca vetorial no catálogo

O agente de classe processual não recebe mais todas as candidatas descritas. As 615
classes têm uma coluna `vector(768)` (pgvector), e `SelectProceduralClass` ordena as
candidatas da área pela proximidade com o relato antes de montar o prompt: as mais
próximas chegam com descrição, matérias típicas e base legal, as demais só com nome e
código.

**Todas continuam no `enum`.** O que o vetor decide é orçamento de janela, nunca o
conjunto de respostas possíveis — a garantia de gramática segue intacta.

É isso que paga o catálogo enriquecido: as descrições passaram a carregar prazo, gatilho
e instrumentos, e a lista inteira da maior área não caberia ao lado do guia. Detalhes,
inclusive por que o documento markdown continua indo inteiro, em `app/Rag/README.md`.

## Saída estruturada

Um agente que implementa `HasStructuredOutput` tem seu `schema()` convertido em gramática
pelo Ollama. `enum()` num campo string é o que impede o modelo de inventar um valor — é
assim que a classificação de área garante devolver um dos 24 slugs do catálogo, e não uma
área plausível que não existe.

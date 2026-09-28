<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Concurrency Driver
    |--------------------------------------------------------------------------
    |
    | O arquivo existe por um motivo só: tornar o driver uma linha de `.env`.
    |
    | `ClassifyLegalCase` roda as quatro etapas básicas de uma peça dentro de um
    | `Concurrency::run`, e o driver decide o que isso significa. Em `process` —
    | o default do framework, e o único que serve a um request web, já que o
    | `fork` recusa rodar fora do console — cada task é um `php artisan
    | invoke-serialized-closure`, com o framework subindo de novo e o retorno
    | atravessando `serialize()`.
    |
    | Duas razões para trocar para `sync`, que corre tudo em série no mesmo
    | processo. A primeira é o teste: um dublê registrado no container do
    | processo de teste não cruza para um processo filho, então a suíte padrão
    | fixa `sync` no `phpunit.xml` para que os mocks valham e nada vá a um
    | provedor pago. A segunda é operação: se a cota do provedor reclamar das
    | requisições simultâneas, `CONCURRENCY_DRIVER=sync` devolve o
    | comportamento em série sem tocar em código.
    |
    | O `process` deste projeto não é o do framework: `AppServiceProvider` o
    | registra como `App\Domain\Shared\Concurrency\IsolatedProcessDriver`,
    | que dá a cada task o teto abaixo e devolve nulo na chave da que não
    | voltou, em vez de descartar as que voltaram. O docblock dele diz por quê.
    |
    */

    'default' => env('CONCURRENCY_DRIVER', 'process'),

    /*
    |--------------------------------------------------------------------------
    | Task Timeout
    |--------------------------------------------------------------------------
    |
    | Quanto tempo uma task pode levar antes de o processo filho ser abatido.
    |
    | O `ProcessDriver` do framework não chama `timeout()`, e o `PendingProcess`
    | traz 60 s de fábrica — menos do que a pesquisa de teses leva sozinha. O
    | request morria com `exceeded the timeout of 60 seconds`, e levava junto a
    | seleção de temas que já tinha voltado.
    |
    | 840 s fica entre dois tetos, e a ordem entre eles é o que importa:
    |
    | - **acima** da soma dos `#[Timeout(360)]` da task mais longa — dois
    |   agentes em série, 720 s, nas duas metades da revisão forense —, para que
    |   quem desista primeiro seja o cliente HTTP do agente. A exceção dele é
    |   capturada dentro do filho, que a relata com o stack trace de verdade;
    |   abater o processo é a rede, não o caminho.
    | - **abaixo** de `ai.request_time_limit` (900 s), para que o pai ainda
    |   esteja vivo quando um filho for abatido e grave o que os outros
    |   trouxeram. Um teto igual ao do request mataria o pai primeiro, e nada
    |   seria gravado.
    |
    | Quem mexer num dos três números confere os outros dois.
    |
    */

    'timeout' => (int) env('CONCURRENCY_TIMEOUT', 840),

];

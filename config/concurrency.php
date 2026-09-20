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
    */

    'default' => env('CONCURRENCY_DRIVER', 'process'),

];

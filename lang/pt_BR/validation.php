<?php

declare(strict_types=1);

/**
 * Only the messages this application actually produces are translated here;
 * anything missing falls back to lang/en/validation.php.
 */
return [
    'accepted' => 'O campo :attribute deve ser aceito.',
    'after' => 'O campo :attribute deve ser uma data posterior a :date.',
    'before' => 'O campo :attribute deve ser uma data anterior a :date.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'confirmed' => 'A confirmação do campo :attribute não confere.',
    'date' => 'O campo :attribute não é uma data válida.',
    'email' => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'enum' => 'A opção selecionada para :attribute é inválida.',
    'exists' => 'A opção selecionada para :attribute é inválida.',
    'in' => 'A opção selecionada para :attribute é inválida.',
    'max' => [
        'string' => 'O campo :attribute não pode ter mais que :max caracteres.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
    ],
    'min' => [
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
        'numeric' => 'O campo :attribute deve ser no mínimo :min.',
    ],
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_with' => 'O campo :attribute é obrigatório quando :values está presente.',
    'string' => 'O campo :attribute deve ser um texto.',
    'unique' => 'Este :attribute já está em uso.',

    // Brazilian documents — see App\Domain\Shared\Rules.
    'cnpj' => 'O :attribute informado não é válido.',
    'cpf' => 'O :attribute informado não é válido.',
    'oab_number' => 'O :attribute deve conter até 6 dígitos, com uma letra opcional ao final.',

    'password' => [
        'letters' => 'A senha deve conter ao menos uma letra.',
        'mixed' => 'A senha deve conter ao menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'A senha deve conter ao menos um número.',
        'symbols' => 'A senha deve conter ao menos um símbolo.',
        'uncompromised' => 'A senha informada apareceu em um vazamento de dados. Escolha outra.',
    ],

    // Renders enum values in required_if messages as their human label
    // instead of the raw backing value.
    'values' => [
        'type' => [
            'law_firm' => 'Pessoa Jurídica',
            'individual' => 'Pessoa Física',
        ],
    ],

    'attributes' => [],
];

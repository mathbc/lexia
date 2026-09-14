{{--
    Convite de acesso.

    Tudo é inline e em tabela porque cliente de e-mail não tem Tailwind nem
    folha externa; as cores são a tradução em hex dos tokens de
    `resources/css/app.css` — croma 0, como o resto do produto.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Seu acesso à LexIA</title>
</head>
<body style="margin:0; padding:0; width:100%; background-color:#f7f7f7;">
    {{-- Preheader: o trecho que o cliente mostra ao lado do assunto. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Defina sua senha e comece a usar a LexIA.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f7f7f7;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:560px; width:100%;">

                    <tr>
                        <td align="left" style="padding-bottom:20px;">
                            <span style="font-family:Georgia,'Times New Roman',serif; font-size:22px; font-weight:600; color:#232323; letter-spacing:-0.01em;">
                                LexIA
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color:#ffffff; border:1px solid #e2e2e2; border-radius:10px; padding:32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="font-family:Helvetica,Arial,sans-serif; font-size:20px; line-height:1.3; font-weight:600; color:#232323; padding-bottom:16px;">
                                        Você foi convidado para a LexIA
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:1.6; color:#232323; padding-bottom:12px;">
                                        Olá, {{ $user->name }}.
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:1.6; color:#232323; padding-bottom:24px;">
                                        Criamos um acesso para você em <strong style="font-weight:600;">{{ $accountName }}</strong>,
                                        com o perfil <strong style="font-weight:600;">{{ $roleLabel }}</strong>.
                                        Ninguém além de você conhece a senha desta conta — defina a sua para entrar.
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom:24px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td align="center" style="background-color:#1c1c1c; border-radius:8px;">
                                                    <a href="{{ $url }}"
                                                       style="display:inline-block; padding:12px 22px; font-family:Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; line-height:1; color:#ffffff; text-decoration:none;">
                                                        Definir minha senha
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="font-family:Helvetica,Arial,sans-serif; font-size:13px; line-height:1.6; color:#6b6b6b; padding-bottom:20px;">
                                        Este link vale por {{ $expiresInMinutes }} minutos. Se ele expirar, peça um novo
                                        em <a href="{{ route('password.request') }}" style="color:#232323;">Esqueci minha senha</a>,
                                        usando o e-mail {{ $user->email }}.
                                    </td>
                                </tr>

                                <tr>
                                    <td style="border-top:1px solid #e2e2e2; padding-top:20px; font-family:Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#6b6b6b; word-break:break-all;">
                                        Se o botão não funcionar, copie e cole este endereço no navegador:<br>
                                        <a href="{{ $url }}" style="color:#6b6b6b;">{{ $url }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-top:20px; font-family:Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#6b6b6b;">
                            Se você não esperava este convite, ignore este e-mail — nenhuma senha é criada até que
                            alguém use o link acima.<br>
                            © {{ date('Y') }} LexIA. Este é um e-mail automático; não responda.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>

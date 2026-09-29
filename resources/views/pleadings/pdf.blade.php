{{-- A página ABNT da minuta, para o dompdf. Espelha o `.abnt-page` do app.css;
     o timbre é `position: fixed` dentro da margem superior, então se repete em
     toda página como o papel timbrado. Tudo centrado, e a logo da minuta acima
     das três linhas: empilhada, ela não cabe nos 2 cm que o timbre tem dentro
     da margem de 3 cm, e a margem cresce exatamente a altura dela
     (`PleadingFile::topMargin()`) — o texto, o fio e o corpo descem juntos, e
     sem logo continuam exatamente onde sempre estiveram. A altura do timbre é
     mínima, e não fixa, para que um endereço longo que quebre em duas linhas
     desça o fio em vez de ser cortado por ele. --}}
@use('App\Domain\LegalPleadings\Support\PleadingLogo')
@php($top = $file->topMargin())
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $file->title }}</title>
    <style>
        @page { margin: {{ $top }}cm 2cm 2cm 3cm; }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #000;
        }

        header {
            position: fixed;
            top: -{{ round($top - 0.5, 2) }}cm;
            left: 0;
            right: 0;
            min-height: {{ round($top - 1, 2) }}cm;
            text-align: center;
            line-height: 1.25;
            border-bottom: 0.5pt solid #bbb;
        }

        header img { display: block; margin: 0 auto {{ PleadingLogo::GAP_CM }}cm; }

        header .firm { font-size: 13pt; font-weight: bold; }
        header .signer { font-size: 10pt; }
        header .contact { font-size: 8pt; color: #666; }

        p { margin: 0; }
        p + p { margin-top: 1.5em; }
        p.citation { margin-left: 4cm; }
    </style>
</head>
<body>
    <header>
        @if ($file->logo)
            @php($size = $file->logo->size())
            <img src="{{ $file->logo->dataUri() }}" alt="" style="width: {{ $size['width'] }}cm; height: {{ $size['height'] }}cm">
        @endif
        @if ($file->letterhead['firm'])
            <div class="firm">{{ $file->letterhead['firm'] }}</div>
        @endif
        @if ($file->letterhead['signer'])
            <div class="signer">{{ $file->letterhead['signer'] }}</div>
        @endif
        @if ($file->letterhead['contact'])
            <div class="contact">{{ $file->letterhead['contact'] }}</div>
        @endif
    </header>

    <main>
        @foreach ($file->blocks as $block)
            <p @class(['citation' => $block['citation']])>{!! collect($block['lines'])->map(fn ($line) => e($line))->implode('<br>') !!}</p>
        @endforeach
    </main>
</body>
</html>

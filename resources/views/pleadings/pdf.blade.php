{{-- A página ABNT da minuta, para o dompdf. Espelha o `.abnt-page` do app.css;
     o timbre é `position: fixed` dentro da margem superior, então se repete em
     toda página como o papel timbrado. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $file->title }}</title>
    <style>
        @page { margin: 3cm 2cm 2cm 3cm; }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #000;
        }

        header {
            position: fixed;
            top: -2.5cm;
            left: 0;
            right: 0;
            height: 2cm;
            text-align: center;
            line-height: 1.25;
            border-bottom: 0.5pt solid #bbb;
        }

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

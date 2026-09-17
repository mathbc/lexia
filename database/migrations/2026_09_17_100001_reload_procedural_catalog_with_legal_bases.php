<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recarrega as 615 classes com as descrições reescritas e os artigos de base.
 *
 * É o protocolo de ressincronização que `database/data/README.md` descreve:
 * trocam-se os JSON e acrescenta-se uma migration que roda a mesma rotina de
 * carga, idempotente por `code`. As áreas e os vínculos não mudaram, então só
 * as classes são reescritas aqui.
 *
 * Pelo mesmo motivo da migration original, isto passa pelo query builder e não
 * pelos models: uma migration precisa continuar funcionando quando os casts, os
 * escopos e os defaults deles mudarem por baixo dela.
 *
 * Só as colunas editoriais entram na lista de atualização do upsert. `name`,
 * `path`, `jurisdictions` e as demais vêm do CNJ e não foram tocadas; deixá-las
 * de fora torna esta migration segura de reexecutar mesmo depois de uma
 * ressincronização futura com a TPU.
 */
return new class extends Migration
{
    private const int CHUNK = 500;

    public function up(): void
    {
        $now = now();

        $rows = array_map(
            fn (array $class): array => [
                // Só serve ao ramo INSERT do upsert, que na prática não roda:
                // os 615 códigos já existem e a coluna fica de fora da lista
                // de atualização, então a linha mantém o id que os processos
                // já apontam.
                'id' => (string) Str::uuid(),
                'code' => (int) $class['code'],
                'name' => (string) $class['name'],
                'slug' => (string) $class['slug'],
                'description' => $class['description'] === null ? null : (string) $class['description'],
                'typical_subjects' => $this->toJson($class['typical_subjects']),
                'legal_bases' => $this->toJson($class['legal_bases'] ?? []),
                'root_code' => (int) $class['root_code'],
                'path' => $this->toJson($class['path']),
                'jurisdictions' => $this->toJson($class['jurisdictions']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->read('procedural-classes.json'),
        );

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('procedural_classes')->upsert($chunk, ['code'], [
                'description', 'typical_subjects', 'legal_bases', 'updated_at',
            ]);
        }

        // As descrições mudaram, então todo vetor gerado antes desta carga
        // descreve um texto que não existe mais. Zerar o hash é o que faz
        // `lexia:embed-procedural-classes` reembutir o catálogo inteiro na
        // próxima execução, em vez de confiar num vetor obsoleto.
        DB::table('procedural_classes')->update([
            'embedding' => null,
            'embedding_hash' => null,
            'embedded_at' => null,
        ]);
    }

    public function down(): void
    {
        // Não há para onde voltar: o texto anterior das descrições vivia no
        // JSON que esta carga substituiu. Reverter a migration de esquema
        // derruba a coluna inteira, que é o rollback de verdade.
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $file): array
    {
        $path = database_path("data/{$file}");
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Catálogo processual ausente: {$path}.");
        }

        /** @var list<array<string, mixed>> */
        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function toJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
};

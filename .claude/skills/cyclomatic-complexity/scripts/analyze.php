#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cyclomatic complexity report for this codebase.
 *
 * Built directly on nikic/php-parser, which Laravel already vendors, so there
 * is no extra dependency. sebastian/complexity was the obvious candidate and
 * is also already vendored, but its visitor asserts that a method's parent is
 * a class or a trait and therefore crashes on PHP enums — of which this
 * codebase has several.
 *
 * M = decision points + 1, per McCabe. Counted: if, elseif, while, do, for,
 * foreach, case (with a condition), catch, &&, ||, and, or, ??, ?:, and each
 * non-default match arm.
 *
 * Usage:
 *   php analyze.php [paths...] [--threshold=N] [--json] [--top=N]
 *
 * Exit codes: 0 within threshold, 1 over it, 2 bad usage.
 */

$autoload = null;

foreach ([__DIR__.'/../../../../vendor/autoload.php', getcwd().'/vendor/autoload.php'] as $candidate) {
    if (file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "Could not find vendor/autoload.php. Run this from the project root.\n");
    exit(2);
}

require $autoload;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** McCabe's bands, as used in the reference article. */
const BANDS = [
    [1, 10, 'simples', 'risco baixo'],
    [11, 20, 'moderada', 'vale refatorar'],
    [21, 50, 'complexa', 'risco alto'],
    [51, PHP_INT_MAX, 'intestável', 'quebrar agora'],
];

function band(int $score): array
{
    foreach (BANDS as [$min, $max, $label, $advice]) {
        if ($score >= $min && $score <= $max) {
            return [$label, $advice];
        }
    }

    return ['desconhecida', ''];
}

/**
 * Collects one entry per function-like node.
 *
 * Nested closures are reported as their own units, and their decision points
 * are not folded back into the enclosing method — otherwise a method holding
 * a couple of collection callbacks would look far worse than it reads.
 */
final class ComplexityVisitor extends NodeVisitorAbstract
{
    /** @var list<array{name: string, complexity: int, line: int}> */
    public array $units = [];

    /** @var list<array{name: string, complexity: int, line: int}> */
    private array $stack = [];

    private string $context = '';

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike && isset($node->name)) {
            $this->context = $node->name->toString();
        }

        if ($this->isFunctionLike($node)) {
            $this->stack[] = [
                'name' => $this->nameFor($node),
                'complexity' => 1,
                'line' => $node->getStartLine(),
            ];

            return null;
        }

        if ($this->stack !== []) {
            $last = count($this->stack) - 1;
            $this->stack[$last]['complexity'] += $this->decisionPointsIn($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($this->isFunctionLike($node)) {
            $this->units[] = array_pop($this->stack);
        }

        return null;
    }

    private function isFunctionLike(Node $node): bool
    {
        return $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction;
    }

    private function nameFor(Node $node): string
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            return ($this->context !== '' ? $this->context.'::' : '').$node->name->toString();
        }

        if ($node instanceof Node\Stmt\Function_) {
            return $node->name->toString();
        }

        $kind = $node instanceof Node\Expr\ArrowFunction ? 'fn' : 'closure';

        return ($this->context !== '' ? $this->context.'::' : '')."{{$kind}@{$node->getStartLine()}}";
    }

    /**
     * How much this single node adds. A match adds one per non-default arm.
     */
    private function decisionPointsIn(Node $node): int
    {
        if ($node instanceof Node\Expr\Match_) {
            return count(array_filter(
                $node->arms,
                static fn (Node\MatchArm $arm): bool => $arm->conds !== null,
            ));
        }

        if ($node instanceof Node\Stmt\Case_) {
            // `default:` carries no condition and is not a branch.
            return $node->cond === null ? 0 : 1;
        }

        $counts = $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\Catch_
            || $node instanceof Node\Expr\Ternary
            || $node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\LogicalOr
            || $node instanceof Node\Expr\BinaryOp\Coalesce
            || $node instanceof Node\Expr\AssignOp\Coalesce;

        return $counts ? 1 : 0;
    }
}

// Parsed by hand rather than with getopt(), which stops at the first
// non-option argument and would silently ignore flags placed after a path.
$threshold = 10;
$top = 15;
$asJson = false;
$paths = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        fwrite(STDOUT, "php analyze.php [paths...] [--threshold=N] [--json] [--top=N]\n");
        exit(0);
    }

    if ($arg === '--json') {
        $asJson = true;
    } elseif (str_starts_with($arg, '--threshold=')) {
        $threshold = (int) substr($arg, 12);
    } elseif (str_starts_with($arg, '--top=')) {
        $top = (int) substr($arg, 6);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } else {
        $paths[] = $arg;
    }
}

if ($paths === []) {
    $paths = ['app'];
}

$files = [];

foreach ($paths as $path) {
    if (is_file($path)) {
        $files[] = $path;

        continue;
    }

    if (! is_dir($path)) {
        fwrite(STDERR, "Path not found: {$path}\n");
        exit(2);
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

$parser = (new ParserFactory)->createForHostVersion();
$units = [];

foreach ($files as $file) {
    try {
        $ast = $parser->parse((string) file_get_contents($file));
    } catch (PhpParser\Error $error) {
        fwrite(STDERR, "Parse error in {$file}: {$error->getMessage()}\n");

        continue;
    }

    if ($ast === null) {
        continue;
    }

    $visitor = new ComplexityVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    foreach ($visitor->units as $unit) {
        $units[] = [...$unit, 'file' => $file];
    }
}

usort($units, static fn (array $a, array $b): int => $b['complexity'] <=> $a['complexity']);

$over = array_values(array_filter(
    $units,
    static fn (array $unit): bool => $unit['complexity'] > $threshold,
));

$total = count($units);
$average = $total === 0 ? 0.0 : round(array_sum(array_column($units, 'complexity')) / $total, 2);

if ($asJson) {
    fwrite(STDOUT, json_encode([
        'threshold' => $threshold,
        'files' => count($files),
        'units' => $total,
        'average' => $average,
        'max' => $units[0]['complexity'] ?? 0,
        'over_threshold' => $over,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    exit($over === [] ? 0 : 1);
}

printf("Complexidade ciclomática — limite %d\n", $threshold);
printf("%d arquivos · %d unidades · média %.2f · máxima %d\n\n", count($files), $total, $average, $units[0]['complexity'] ?? 0);

if ($over !== []) {
    printf("ACIMA DO LIMITE (%d)\n", count($over));

    foreach ($over as $unit) {
        [$label, $advice] = band($unit['complexity']);
        printf("  %3d  %s  (%s — %s)\n", $unit['complexity'], $unit['name'], $label, $advice);
        printf("       %s:%d\n", $unit['file'], $unit['line']);
    }

    print("\nComo reduzir: extrair as condições para métodos com nome de negócio,\n");
    print("trocar cadeias de if/else por match ou polimorfismo, e usar early\n");
    print("return para eliminar aninhamento.\n\n");
} else {
    print("Tudo dentro do limite.\n\n");
}

printf("Maiores complexidades:\n");

foreach (array_slice($units, 0, $top) as $unit) {
    [$label] = band($unit['complexity']);
    printf("  %3d  %-56s %s\n", $unit['complexity'], $unit['name'], $label);
}

exit($over === [] ? 0 : 1);

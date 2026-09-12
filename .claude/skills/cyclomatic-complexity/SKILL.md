---
name: cyclomatic-complexity
description: Measure and enforce cyclomatic complexity (McCabe) on the PHP code in this project. Use when asked to check complexity, find over-complex methods, review whether a change made code harder to follow, or before finishing a refactor. Also use after writing a method with several branches, to confirm it stays within the agreed limit.
---

# Cyclomatic complexity

Cyclomatic complexity counts the independent paths through a unit of code:
`M = decision points + 1`. It is a proxy for how many test cases a method
needs and for how hard it is to hold in your head.

## Running it

```bash
php .claude/skills/cyclomatic-complexity/scripts/analyze.php app
```

Options:

| Flag | Default | Meaning |
|---|---|---|
| `--threshold=N` | `10` | Complexity above which a unit is reported |
| `--top=N` | `15` | How many of the worst units to list |
| `--json` | off | Machine-readable output |

Paths default to `app`. Pass any files or directories instead:

```bash
php .claude/skills/cyclomatic-complexity/scripts/analyze.php app/Domain database --threshold=8
php .claude/skills/cyclomatic-complexity/scripts/analyze.php app --json
```

Exit code is `0` when everything is within the threshold and `1` when
something exceeds it, so it works in CI and in a pre-commit hook.

## What counts as a decision point

`if`, `elseif`, `while`, `do`, `for`, `foreach`, `catch`, a `case` that has a
condition, the operators `&&`, `||`, `and`, `or`, `??`, `??=`, the ternary
`?:`, and each non-`default` arm of a `match`.

`else` and `default` add nothing — they are the fall-through path that the
base 1 already accounts for.

Closures and arrow functions are reported as their own units and are **not**
folded into the enclosing method. A method holding two collection callbacks
reads as three simple things, not one complicated one.

## Bands

| Complexity | Reading | What to do |
|---|---|---|
| 1–10 | simples | Nothing. |
| 11–20 | moderada | Refactor when you are next in the file. |
| 21–50 | complexa | Refactor now; this is where defects concentrate. |
| > 50 | intestável | Cannot be meaningfully tested. Break it up. |

This project holds `app/` at **≤ 10**.

## Reducing it

- **Extract the condition into a named method.** `if ($user->role->outranks($other->role))`
  reads better than the comparison inlined, and moves a decision point out.
- **Replace an if/else chain with `match`,** or with polymorphism when the
  branches are behaviour rather than values.
- **Early return** instead of nesting. Each avoided `else` is one less level.
- **A flat lookup table is not a branch.** A 27-arm `match` mapping a UF to a
  state name scores 28 while carrying no branch risk. Make it a `const array`
  and it scores 1 — see `BrazilianState::NAMES`.

## Why this script and not a package

`phpmd`, `pdepend` and `phpinsights` all measure this, but each drags in a
dependency tree for one metric.

`sebastian/complexity` is already vendored (PHPUnit depends on it) and was the
obvious choice, **but it crashes on PHP enums**: its visitor asserts that a
method's parent node is a class or a trait, and `Enum_` is neither. This
codebase is enum-heavy, so the script walks the AST with `nikic/php-parser`
directly — also already vendored, via Laravel.

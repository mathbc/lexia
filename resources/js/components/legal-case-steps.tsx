import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Um passo da trilha: o rótulo vem do enum no servidor, a descrição é cópia
 * de tela e não tem contraparte lá.
 *
 * Chama-se `StepItem` e não `LegalCaseStep` porque este último é o nome do
 * enum — quem monta o assistente importa os dois.
 */
export interface StepItem {
    label: string;
    description: string;
}

interface Props {
    steps: StepItem[];
    current: number;
    /**
     * O último passo que dá para abrir hoje. O que vem depois fica inerte em
     * vez de sumir: a timeline existe justamente para mostrar o caminho
     * inteiro, inclusive o trecho que ainda não se anda.
     */
    reachable: number;
    onSelect: (index: number) => void;
}

/**
 * A timeline de preenchimento da peça: coluna à esquerda em telas largas,
 * trilha horizontal abaixo disso.
 *
 * Três estados por passo — concluído, atual e futuro — e só eles carregam cor:
 * o marcador do passo concluído é `bg-primary`, o atual ganha o anel de foco e
 * o futuro fica em `bg-muted`.
 */
export function LegalCaseSteps({ steps, current, reachable, onSelect }: Props) {
    return (
        // `min-w-0` porque a nav é item de grade, e item de grade não encolhe
        // abaixo do próprio conteúdo sem isso: a trilha horizontal empurrava a
        // coluna inteira para fora da tela no telefone.
        <nav aria-label="Etapas da peça" className="min-w-0">
            {/* Abaixo de `lg` a trilha rola sozinha em vez de espremer os
                rótulos: com meia dúzia de etapas, repartir 400px deixaria cada
                uma com um par de letras. */}
            <ol className="flex gap-1 overflow-x-auto lg:flex-col lg:gap-0 lg:overflow-x-visible">
                {steps.map((step, index) => {
                    const done = index < current;
                    const active = index === current;
                    const enabled = index <= reachable;
                    const last = index === steps.length - 1;

                    return (
                        <li
                            key={step.label}
                            className="flex min-w-0 shrink-0 lg:flex-none"
                        >
                            <button
                                type="button"
                                disabled={!enabled}
                                aria-current={active ? "step" : undefined}
                                onClick={() => onSelect(index)}
                                className={cn(
                                    "flex w-full items-stretch gap-3 rounded-md p-2 text-left transition-colors",
                                    enabled
                                        ? "hover:bg-accent"
                                        : "cursor-not-allowed opacity-60",
                                )}
                            >
                                {/* Marcador e a linha que liga ao próximo, que
                                    só existe na vertical. */}
                                <span className="flex flex-col items-center">
                                    <span
                                        className={cn(
                                            "flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium",
                                            done &&
                                                "border-transparent bg-primary text-primary-foreground",
                                            active &&
                                                "border-primary bg-background text-foreground ring-2 ring-ring/40",
                                            !done &&
                                                !active &&
                                                "border-border bg-muted text-muted-foreground",
                                        )}
                                    >
                                        {done ? (
                                            <Check className="size-3.5" />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>

                                    {!last && (
                                        <span
                                            aria-hidden
                                            className={cn(
                                                "my-1 hidden w-px flex-1 lg:block",
                                                done
                                                    ? "bg-primary"
                                                    : "bg-border",
                                            )}
                                        />
                                    )}
                                </span>

                                <span
                                    className={cn(
                                        "min-w-0 pb-1",
                                        !last && "lg:pb-6",
                                    )}
                                >
                                    <span
                                        className={cn(
                                            "block truncate text-sm lg:whitespace-normal",
                                            active
                                                ? "font-medium text-foreground"
                                                : "text-muted-foreground",
                                            done && "text-foreground",
                                        )}
                                    >
                                        {step.label}
                                    </span>
                                    {/* A descrição só cabe na coluna vertical. */}
                                    <span className="mt-0.5 hidden text-xs text-muted-foreground lg:block">
                                        {step.description}
                                    </span>
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

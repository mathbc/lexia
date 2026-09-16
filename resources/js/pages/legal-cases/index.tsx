import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import { AppLayout } from "@/layouts/app-layout";
import { LegalCaseModeDialog } from "@/components/legal-case-mode-dialog";
import { Pagination } from "@/components/pagination";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Field, Input, Select } from "@/components/ui/field";
import { formatDate } from "@/lib/format";
import { cn } from "@/lib/utils";
import type {
    LegalCaseAbilities,
    LegalCaseCard,
    Option,
    Paginated,
} from "@/types";

interface Filters {
    search?: string;
    customer?: string;
    practice_area?: string;
    /** 'draft' ou 'final' — string porque viaja na query. */
    status?: string;
}

interface Props {
    legalCases: Paginated<LegalCaseCard>;
    filters: Filters;
    customers: Option[];
    practiceAreas: Option[];
    /** LegalCaseOptions::statuses() — o português fica no servidor. */
    statuses: Option[];
    can: LegalCaseAbilities;
}

export default function LegalCasesIndex({
    legalCases,
    filters,
    customers,
    practiceAreas,
    statuses,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? "");

    // preserveState mantém foco, rolagem e o painel de filtros aberto;
    // replace evita empilhar histórico a cada filtro.
    const apply = (next: Filters) => {
        router.get(
            "/pecas",
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    };

    const clear = () => {
        setSearch("");
        router.get("/pecas", {}, { preserveState: true, replace: true });
    };

    const activeFilters = [
        filters.search,
        filters.customer,
        filters.practice_area,
        filters.status,
    ].filter(Boolean).length;

    return (
        <AppLayout
            title="Peças Jurídicas"
            actions={can.create && <LegalCaseModeDialog />}
            activeFilters={activeFilters}
            filters={
                <>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ search });
                        }}
                    >
                        <Field
                            label="Busca"
                            hint="Cliente, classe processual ou código CNJ"
                        >
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar"
                                aria-label="Buscar peças"
                            />
                        </Field>
                    </form>

                    <Field label="Cliente">
                        <Select
                            value={filters.customer ?? ""}
                            onValueChange={(value) =>
                                apply({ customer: value || undefined })
                            }
                            options={customers}
                            placeholder="Todos os clientes"
                            clearable
                        />
                    </Field>

                    <Field label="Área de atuação">
                        <Select
                            value={filters.practice_area ?? ""}
                            onValueChange={(value) =>
                                apply({ practice_area: value || undefined })
                            }
                            options={practiceAreas}
                            placeholder="Todas as áreas"
                            clearable
                        />
                    </Field>

                    <Field label="Situação">
                        <Select
                            value={filters.status ?? ""}
                            onValueChange={(value) =>
                                apply({ status: value || undefined })
                            }
                            options={statuses}
                            placeholder="Todas as situações"
                            clearable
                        />
                    </Field>

                    {activeFilters > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            onClick={clear}
                        >
                            Limpar filtros
                        </Button>
                    )}
                </>
            }
        >
            <Head title="Peças Jurídicas" />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {legalCases.data.map((legalCase) => {
                    const card = (
                        <Card
                            className={cn(
                                "h-full gap-4 py-5",
                                can.update &&
                                    "transition-colors hover:bg-accent/40",
                            )}
                        >
                            <CardHeader className="px-5">
                                <CardTitle className="text-base">
                                    {legalCase.customer.display_name}
                                </CardTitle>
                                <CardDescription>
                                    {legalCase.practice_area.label}
                                </CardDescription>
                            </CardHeader>

                            <CardContent className="flex-1 px-5">
                                <p className="text-sm text-foreground">
                                    {legalCase.procedural_class.name}
                                </p>
                                <p className="tabular mt-1 text-xs text-muted-foreground">
                                    Classe CNJ {legalCase.procedural_class.code}
                                    {legalCase.procedural_class.abbreviation &&
                                        ` · ${legalCase.procedural_class.abbreviation}`}
                                </p>
                            </CardContent>

                            {/* Situação primeiro, etapa depois: a etapa só diz
                                alguma coisa enquanto a peça é rascunho. Só a
                                situação encerrada ganha cor. */}
                            <CardFooter className="flex-wrap gap-2 px-5">
                                <Badge
                                    variant={
                                        legalCase.is_draft ? "muted" : "success"
                                    }
                                >
                                    {legalCase.is_draft
                                        ? "Rascunho"
                                        : "Finalizada"}
                                </Badge>

                                {legalCase.is_draft && (
                                    <Badge variant="outline">
                                        {legalCase.current_step_label}
                                    </Badge>
                                )}

                                <Badge variant="muted">
                                    Criada em {formatDate(legalCase.created_at)}
                                </Badge>
                            </CardFooter>
                        </Card>
                    );

                    // O card inteiro é a porta de retomada, e é um `<a>` de
                    // verdade para que ctrl+clique continue funcionando. Quem
                    // não pode editar recebe o cartão sem link — a Policy é a
                    // defesa de verdade.
                    return can.update ? (
                        <Link
                            key={legalCase.id}
                            href={`/pecas/${legalCase.id}/editar`}
                            aria-label={`Abrir peça de ${legalCase.customer.display_name}`}
                            className="rounded-xl focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            {card}
                        </Link>
                    ) : (
                        <div key={legalCase.id}>{card}</div>
                    );
                })}
            </div>

            {legalCases.data.length === 0 && (
                <Card className="items-center py-12 text-center text-muted-foreground">
                    Nenhuma peça encontrada.
                </Card>
            )}

            <Pagination page={legalCases} label="Paginação de peças" />
        </AppLayout>
    );
}

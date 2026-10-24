import { router, usePoll } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { isPending } from "@/hooks/use-assisted-fill";
import type { ResearchRuns, ResearchSubject } from "@/types";

/** Ver `POLL_INTERVAL` em `use-assisted-fill`. */
const POLL_INTERVAL = 3000;

export interface ResearchRunsState {
    /** Os assuntos que travam a tela: pendentes na fila, ou recém-gravados e
        ainda chegando nas props. */
    pending: ResearchSubject[];
    /** Os pendentes que nenhum worker pegou ainda — o diálogo diz "na fila". */
    queued: ResearchSubject[];
    /** Os assuntos cuja última rodada falhou. */
    failed: ResearchSubject[];
    /** O motivo da falha, quando ele acrescenta ao alerta da aba. */
    failures: Partial<Record<ResearchSubject, string>>;
    /** Se o assunto já teve alguma rodada — o gatilho de abertura não refaz. */
    hasRun: (subject: ResearchSubject) => boolean;
}

/**
 * As pesquisas das etapas 5 e 6 enquanto rodam na fila `inference`.
 *
 * O `POST` de cada pesquisa só enfileira (ver `RunLegalCaseForensicReview` e
 * `RunLegalCaseJurisprudence`), e o estado de cada assunto mora numa
 * `research_runs` que chega aqui em `researchRuns`. Daí tudo ser derivado das
 * props, e não de estado local: um reload no meio da pesquisa reabre a etapa
 * travada, e a falha de ontem continua dita na aba.
 *
 * Enquanto houver assunto pendente, o `usePoll` do Inertia recarrega só
 * `researchRuns` — uma consulta. Quando um assunto deixa de estar pendente, as
 * linhas dele já estão gravadas, e só então a peça é recarregada; até essa
 * recarga voltar o assunto segue em `pending`, para que o diálogo não feche
 * sobre uma aba que ainda não recebeu o que a pesquisa achou.
 */
export function useResearchRuns(runs: ResearchRuns): ResearchRunsState {
    const subjects = Object.keys(runs) as ResearchSubject[];
    const inFlight = subjects.filter((subject) =>
        isPending(runs[subject]?.status),
    );
    const signature = inFlight.join(",");

    const [landing, setLanding] = useState<ResearchSubject[]>([]);
    const previous = useRef<ResearchSubject[]>(inFlight);

    const { start, stop } = usePoll(
        POLL_INTERVAL,
        { only: ["researchRuns"] },
        { autoStart: false },
    );

    useEffect(() => {
        if (inFlight.length > 0) {
            start();
        } else {
            stop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [signature, start, stop]);

    useEffect(() => {
        const landed = previous.current.filter(
            (subject) => !inFlight.includes(subject),
        );

        previous.current = inFlight;

        if (landed.length === 0) {
            return;
        }

        setLanding((current) => [...current, ...landed]);

        router.reload({
            only: ["legalCase", "researchRuns"],
            onFinish: () =>
                setLanding((current) =>
                    current.filter((subject) => !landed.includes(subject)),
                ),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [signature]);

    const failures: Partial<Record<ResearchSubject, string>> = {};

    for (const subject of subjects) {
        const failure = runs[subject]?.failure;

        if (runs[subject]?.status === "failed" && failure) {
            failures[subject] = failure;
        }
    }

    return {
        pending: [...new Set([...inFlight, ...landing])],
        queued: subjects.filter((subject) => runs[subject]?.status === "queued"),
        failed: subjects.filter((subject) => runs[subject]?.status === "failed"),
        failures,
        hasRun: (subject) => runs[subject] !== undefined,
    };
}

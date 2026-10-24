import { router, usePoll } from "@inertiajs/react";
import { useEffect } from "react";
import { stashHandoff } from "@/lib/legal-case-handoff";
import type { AssistedFill } from "@/types";

/**
 * De quanto em quanto tempo a tela pergunta pelo preenchimento. A análise leva
 * de dezenas de segundos a minutos, então três segundos de atraso na volta não
 * pesam — e cada pergunta é uma recarga parcial que só lê uma linha.
 */
const POLL_INTERVAL = 3000;

export const isPending = (status: string | undefined): boolean =>
    status === "queued" || status === "running";

/**
 * A espera da tela da peça nova pelo preenchimento inteligente.
 *
 * O `POST /pecas/classificar` só enfileira e redireciona para
 * `/pecas/nova?preenchimento={id}`; quem faz o trabalho é um worker da fila
 * `inference` (ver `RunAssistedFill`). Esta tela fica travada atrás do diálogo
 * e consulta a prop `assistedFill` por recarga parcial — o `usePoll` do
 * Inertia, ligado só enquanto o preenchimento está pendente.
 *
 * Quando ele termina, a tela sai, sempre com `replace`: voltar no navegador
 * para uma espera que já acabou só a faria sair de novo.
 *
 * - **com peça**: o assistente dela, na etapa 1, com tudo gravado;
 * - **sem peça** (sem classe, ou a gravação caiu): o enquadramento segue para
 *   `/pecas/nova?area=` pela entrega do `sessionStorage`, a reserva de sempre;
 * - **recusado ou falho**: o formulário do preenchimento, com o relato e o
 *   cliente de volta e o motivo onde ele deve aparecer.
 *
 * O diálogo não fecha antes da navegação — quem o controla é a própria prop, e
 * ela some junto com a tela.
 */
export function useAssistedFill(fill: AssistedFill | null): void {
    const pending = isPending(fill?.status);

    const { start, stop } = usePoll(
        POLL_INTERVAL,
        { only: ["assistedFill"] },
        { autoStart: false },
    );

    useEffect(() => {
        if (pending) {
            start();
        } else {
            stop();
        }
    }, [pending, start, stop]);

    useEffect(() => {
        if (fill && !isPending(fill.status)) {
            leave(fill);
        }
        // Só o desfecho dispara a saída: o mesmo preenchimento lido de novo
        // não navega duas vezes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fill?.id, fill?.status]);
}

function leave(fill: AssistedFill): void {
    if (fill.status === "completed" && fill.legal_case_id) {
        router.visit(`/pecas/${fill.legal_case_id}/editar`, { replace: true });

        return;
    }

    if (fill.status === "completed" && fill.result) {
        const area = fill.result.practice_area.slug;

        stashHandoff({
            practice_area: area,
            customer_id: fill.customer_id ?? "",
            // Sem classe, o assistente abre a lista da área para o advogado
            // escolher; o nulo de cada extração é a etapa que falhou, e a
            // etapa correspondente abre em branco, como sempre abriu.
            procedural_class_id: fill.result.procedural_class?.id ?? "",
            facts: fill.facts ?? "",
            defendant: fill.result.defendant,
            requirements: fill.result.requirements,
            injunctive_relief: fill.result.injunctive_relief,
            court_addressing: fill.result.court_addressing,
        });

        router.visit(`/pecas/nova?area=${encodeURIComponent(area)}`, {
            replace: true,
        });

        return;
    }

    router.visit(`/pecas/nova/inteligente?preenchimento=${fill.id}`, {
        replace: true,
    });
}

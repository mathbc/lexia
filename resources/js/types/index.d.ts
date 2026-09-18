/** Mirrors HandleInertiaRequests::currentUser(). */
export interface AuthUser {
    id: string;
    name: string;
    email: string;
    role: UserRoleValue;
    role_label: string;
    type: string;
    type_label: string;
    is_platform_admin: boolean;
    account: {
        id: string;
        name: string;
        type: string;
        type_label: string;
        is_operational: boolean;
    };
}

export type UserRoleValue =
    "platform_admin" | "account_admin" | "admin" | "lawyer";

/** A backed enum published as {value,label} by the server. */
export interface Option {
    value: string;
    label: string;
}

export interface PageProps {
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

/** Laravel's length-aware paginator, as Inertia serialises it. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface UserRow {
    id: string;
    name: string;
    email: string;
    role: UserRoleValue;
    type: string;
    enabled: boolean;
    oab_number: string | null;
    oab_state: string | null;
}

/** The accounts table, as the Account model serialises. */
export interface Account {
    id: string;
    name: string;
    legal_name: string | null;
    type: string;
    federal_id: string | null;
    oab_number: string | null;
    oab_state: string | null;
    email: string;
    phone: string;
    postal_code: string;
    street: string;
    number: string;
    complement: string | null;
    district: string;
    city: string;
    state: string;
    active: boolean;
    enabled: boolean;
}

export interface AccountRow extends Account {
    users_count: number;
}

/** Mirrors AccountPageProps::for() — shared by both tabs of an account. */
export interface AccountAbilities {
    update: boolean;
    toggle_status: boolean;
    manage_users: boolean;
}

/** The clients table, as the Customer model serialises. */
export interface Customer {
    id: string;
    name: string;
    legal_name: string | null;
    type: string;
    cpf: string | null;
    cnpj: string | null;
    email: string;
    phone: string;
    postal_code: string;
    street: string;
    number: string;
    complement: string | null;
    district: string;
    city: string;
    state: string;
}

/** Mirrors CustomerPageProps::abilities(); the listing adds `create`. */
export interface CustomerAbilities {
    update: boolean;
    delete: boolean;
}

/** A practice area, as the PracticeArea model serialises. */
export interface PracticeArea {
    id: string;
    slug: string;
    label: string;
    cnj_subject_roots: number[];
    position: number;
}

/**
 * A CNJ procedural class. `code` is the official identifier — the number that
 * shows on the case record — and `scope` only comes through when the row was
 * loaded from a practice area, where it says whether the class belongs to that
 * area or is borrowed from the civil trunk.
 */
export interface ProceduralClass {
    id: string;
    code: number;
    name: string;
    slug: string;
    root_code: number;
    path: string[];
    abbreviation: string | null;
    nature: string | null;
    legal_norm: string | null;
    legal_article: string | null;
    active_party: string | null;
    passive_party: string | null;
    has_own_numbering: boolean;
    is_filing_class: boolean;
    is_cross_cutting: boolean;
    jurisdictions: string[];
    pivot?: { scope: "specific" | "generic" };
}

/** A pleading, as the LegalCase model serialises. */
export interface LegalCase {
    id: string;
    customer_id: string;
    practice_area_id: string;
    procedural_class_id: string;
    created_at: string;
    updated_at: string;
}

/**
 * A file that instructs a pleading, as the Document model serialises.
 *
 * `name` is the original filename, extension included, and `extension` repeats
 * that suffix so a listing never has to take the string apart. The draft the
 * form works with is another thing entirely — it carries a browser `File` and
 * lives in `@/lib/documents`.
 */
export interface Document {
    id: string;
    legal_case_id: string;
    name: string;
    description: string | null;
    extension: string;
    size: number;
    created_at: string;
    updated_at: string;
}

/**
 * One CNJ competence, resolved server-side by Jurisdiction::toTag(). `branch`
 * and `degree` are what the class picker filters on; the raw `value` is never
 * shown to anyone.
 */
export interface JurisdictionTag {
    value: string;
    label: string;
    short_label: string;
    branch: string;
    degree: string;
}

/** A procedural class as ProceduralClassOptionsQuery projects it. */
export interface ProceduralClassOption {
    id: string;
    code: number;
    name: string;
    abbreviation: string | null;
    /** Leitura nossa da classe: o que é e quando cabe. Não vem do CNJ. */
    description: string | null;
    /** As matérias tipicamente discutidas nela — também editoriais. */
    typical_subjects: string[];
    legal_basis: string | null;
    nature: string | null;
    active_party: string | null;
    passive_party: string | null;
    has_own_numbering: boolean;
    is_filing_class: boolean;
    is_cross_cutting: boolean;
    /** O caminho na árvore do CNJ, da raiz até a própria classe. */
    path: string[];
    /** Own to the area, or borrowed from the civil trunk. */
    scope: "specific" | "generic";
    jurisdictions: JurisdictionTag[];
}

/** Espelha App\Domain\LegalCases\Enums\LegalCaseStep. */
export type LegalCaseStepValue =
    "basics" | "defendant" | "facts" | "requirements" | "documents" | "review";

/** A pleading as LegalCaseIndexQuery projects it for a card. */
export interface LegalCaseCard {
    id: string;
    customer: { id: string; display_name: string };
    practice_area: { slug: string; label: string };
    procedural_class: {
        code: number;
        name: string;
        abbreviation: string | null;
    };
    /** Até onde o preenchimento chegou — marca d'água, não última edição. */
    current_step: LegalCaseStepValue;
    current_step_label: string;
    is_draft: boolean;
    created_at: string | null;
}

/**
 * A peça salva, como LegalCaseFormProps::draft() a projeta para o assistente.
 *
 * Cada grupo chega sob a chave que o componente de campos correspondente já
 * espera, de modo que a tela hidrate sem traduzir nada.
 */
export interface LegalCaseDraft {
    id: string;
    customer_id: string;
    /** O slug, nunca o uuid — ver LegalCaseOptions::practiceAreas(). */
    practice_area: string;
    procedural_class_id: string;
    court_addressing: string;
    current_step: LegalCaseStepValue;
    is_draft: boolean;
    defendant: Record<string, string>;
    facts: {
        facts: string;
        injunctive_relief: boolean;
        injunctive_relief_description: string;
    };
    /** Com os ids reais do servidor, e não os do crypto.randomUUID(). */
    requirements: { id: string; description: string; amount: string }[];
}

/**
 * Os doze campos `defendant_*` como `DefendantData::toArray()` os publica.
 *
 * O nulo aqui é "o relato não diz", e é o caso comum: um réu é descrito, não
 * cadastrado. Por isso os campos do formulário não têm esta forma — lá o vazio
 * é a string vazia, porque um controle sem valor deixa de ser controlado.
 */
export interface DefendantSuggestion {
    defendant_name: string | null;
    defendant_document: string | null;
    defendant_email: string | null;
    defendant_phone: string | null;
    defendant_postal_code: string | null;
    defendant_street: string | null;
    defendant_number: string | null;
    defendant_complement: string | null;
    defendant_district: string | null;
    defendant_city: string | null;
    defendant_state: string | null;
    defendant_notes: string | null;
}

/**
 * Um pedido que o agente leu do relato, como `RequirementListData::toArray()`
 * o publica.
 *
 * Não se chama `RequirementSuggestion` porque esse nome já é de outra coisa em
 * `@/lib/requirements`: lá são os pedidos frequentes, texto de praxe que a tela
 * oferece num clique e que não sai de relato nenhum. Estes saem, e é essa a
 * diferença que os dois nomes precisam guardar.
 *
 * O valor vem em decimal ("25200.00") e não mascarado — quem mascara é o campo
 * que o desenha. Nulo é o pedido sem cifra, que é a maioria, e também a cifra
 * que o servidor recusou por não estar escrita nos fatos.
 */
export interface ExtractedRequirement {
    description: string;
    amount: string | null;
}

/**
 * O que os agentes leram de um relato — espelha
 * `LegalCaseClassification::toArray()`, a resposta de `POST /pecas/classificar`.
 *
 * A área viaja com o slug e a classe com o uuid, que são exatamente as formas
 * que o assistente preenche: `?area=` na URL e `procedural_class_id` no
 * formulário. A classe é nula quando a área não oferece nenhuma de ajuizamento
 * — hoje nenhuma das 24 está nessa situação.
 *
 * O réu é nulo quando a extração falhou, que não é o mesmo que um relato sem
 * réu identificado: esse chega como doze campos nulos dentro do objeto. Os
 * pedidos carregam a mesma distinção, com a lista vazia no lugar dos nulos.
 */
export interface LegalCaseClassification {
    practice_area: { id: string; slug: string; label: string };
    practice_area_justification: string;
    procedural_class: { id: string; code: number; name: string } | null;
    procedural_class_justification: string | null;
    defendant: DefendantSuggestion | null;
    requirements: ExtractedRequirement[] | null;
}

/** Mirrors LegalCasePageProps::abilities(). */
export interface LegalCaseAbilities {
    view: boolean;
    create: boolean;
    update: boolean;
}

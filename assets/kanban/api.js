/*
 * Client minimal de l'API REST /api/v1 pour le Kanban : session (cookie) + jeton CSRF en en-tête.
 * Les erreurs sont au format RFC 7807 (application/problem+json).
 */
export class ApiError extends Error {
    constructor(problem, status) {
        super(problem.detail || problem.title || `Erreur HTTP ${status}`);
        this.problem = problem;
        this.status = status;
    }
}

export function createApi({ apiBase, csrfHeader, csrfToken }) {
    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: { Accept: 'application/json', ...(options.headers || {}) },
        });
        const isJson = (response.headers.get('Content-Type') || '').includes('json');
        const body = isJson ? await response.json() : null;
        if (!response.ok) {
            throw new ApiError(body || { title: response.statusText }, response.status);
        }

        return body;
    }

    return {
        /** Incidents des statuts demandés (page de 100 au plus). */
        list(statuses, limit = 100) {
            const params = new URLSearchParams({ status: statuses.join(','), limit: String(limit) });

            return request(`${apiBase}?${params}`).then((page) => page.items);
        },

        /** Applique une transition ; If-Match porte la version connue (verrou optimiste). */
        transition(incident, transition, payload = {}) {
            return request(`${apiBase}/${encodeURIComponent(incident.reference)}/transitions/${encodeURIComponent(transition)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'If-Match': `"${incident.version}"`,
                    [csrfHeader]: csrfToken,
                },
                body: JSON.stringify(payload),
            });
        },
    };
}

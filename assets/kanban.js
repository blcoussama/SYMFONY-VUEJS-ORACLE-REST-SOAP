/*
 * Tableau Kanban des incidents — Vue.js 3 (build ESM navigateur via l'importmap, sans SFC ni Node).
 *
 * - colonnes = statuts du Workflow ; cartes colorées par priorité ;
 * - glisser-déposer HTML5 : la carte est déplacée tout de suite, puis l'API REST applique la
 *   transition ; en cas de refus (403/409/422) la carte revient à sa place et le motif s'affiche ;
 * - alternative au clavier : menu « Actions » sur chaque carte (mêmes transitions).
 */
import { createApp, reactive, computed, nextTick } from 'vue/dist/vue.esm-browser.prod.js';
import { createApi } from './kanban/api.js';

const root = document.getElementById('kanban-app');
const config = JSON.parse(root.dataset.config);
const api = createApi({ apiBase: config.apiBase, csrfHeader: config.csrfHeader, csrfToken: root.dataset.csrfToken });

const OPEN = ['NOUVEAU', 'QUALIFIE', 'ASSIGNE', 'EN_COURS', 'EN_ATTENTE'];
const DONE = ['RESOLU', 'CLOS', 'ANNULE'];

const KanbanCard = {
    props: { incident: { type: Object, required: true }, busy: Boolean, transitionsFor: { type: Function, required: true } },
    emits: ['drag-start', 'drag-end', 'action'],
    computed: {
        actions() {
            return this.transitionsFor(this.incident);
        },
        url() {
            return config.incidentUrl.replace('INC-0000-000000', this.incident.reference);
        },
        slaClass() {
            return {
                BREACHED: 'text-bg-danger', MISSED: 'text-bg-danger', AT_RISK: 'text-bg-warning',
                ON_TRACK: 'text-bg-success', MET: 'text-bg-success',
            }[this.incident.sla.state] || 'text-bg-light border';
        },
    },
    template: `
        <article class="card kanban-card shadow-sm" :class="['prio-' + incident.priority, { 'is-busy': busy }]"
                 draggable="true" :aria-busy="busy ? 'true' : 'false'"
                 @dragstart="$emit('drag-start', $event, incident)" @dragend="$emit('drag-end')">
            <div class="card-body p-2">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <a :href="url" class="fw-semibold small text-decoration-none">{{ incident.reference }}</a>
                    <span class="badge" :class="'prio-badge-' + incident.priority">{{ incident.priority }}</span>
                </div>
                <p class="small mb-1 mt-1 kanban-title">{{ incident.title }}</p>
                <div class="small text-body-secondary">{{ incident.site.code }} · {{ incident.site.city }}</div>
                <div class="d-flex justify-content-between align-items-center mt-2 gap-1">
                    <span class="badge" :class="slaClass" :title="'Échéance SLA : ' + incident.sla.dueAt">{{ incident.sla.label }}</span>
                    <span v-if="incident.assignee" class="small text-body-secondary text-truncate" :title="incident.assignee.name">{{ incident.assignee.name }}</span>
                </div>
                <div v-if="actions.length" class="dropdown mt-2">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                            :aria-label="'Actions pour ' + incident.reference">Actions</button>
                    <ul class="dropdown-menu">
                        <li v-for="t in actions" :key="t.name">
                            <button class="dropdown-item" type="button" @click="$emit('action', incident, t)">{{ t.label }} → {{ t.toLabel }}</button>
                        </li>
                    </ul>
                </div>
            </div>
        </article>`,
};

const app = createApp({
    components: { KanbanCard },
    setup() {
        const state = reactive({
            incidents: [],
            loading: true,
            message: null,
            dragged: null,
            busy: {},
            modal: null,
        });

        const labels = Object.fromEntries(config.columns.map((c) => [c.status, c.label]));
        const transitions = config.transitions.map((t) => ({ ...t, toLabel: labels[t.to] }));
        const columns = computed(() => config.columns.map((column) => ({
            ...column,
            cards: state.incidents.filter((i) => i.status === column.status),
        })));

        const findTransition = (incident, toStatus) => transitions.find((t) => t.from === incident.status && t.to === toStatus);
        // Transitions proposées par le serveur pour l'utilisateur courant (gardes du Workflow comprises).
        const transitionsFor = (incident) => transitions.filter((t) => t.from === incident.status && incident.allowedTransitions.includes(t.name));

        function notify(type, text) {
            state.message = { type, text };
        }

        async function load() {
            state.loading = true;
            try {
                const [open, done] = await Promise.all([api.list(OPEN, 100), api.list(DONE, 30)]);
                state.incidents = [...open, ...done];
            } catch (error) {
                notify('danger', `Chargement impossible : ${error.message}`);
            } finally {
                state.loading = false;
            }
        }

        function onDragStart(event, incident) {
            state.dragged = incident;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', incident.reference);
        }

        function onDragEnd() {
            state.dragged = null;
        }

        function dropState(column) {
            if (!state.dragged || state.dragged.status === column.status) {
                return '';
            }
            const transition = findTransition(state.dragged, column.status);

            return transition && state.dragged.allowedTransitions.includes(transition.name) ? 'drop-allowed' : 'drop-denied';
        }

        function onDragOver(event, column) {
            if (state.dragged && state.dragged.status !== column.status) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            }
        }

        function onDrop(event, column) {
            event.preventDefault();
            const incident = state.dragged;
            state.dragged = null;
            if (!incident || incident.status === column.status) {
                return;
            }
            const transition = findTransition(incident, column.status);
            if (!transition) {
                notify('warning', `${incident.reference} : passage de « ${labels[incident.status]} » à « ${column.label} » impossible.`);

                return;
            }
            start(incident, transition);
        }

        // Certaines transitions demandent une saisie : technicien (assigner), compte rendu (résoudre).
        function start(incident, transition) {
            if (transition.name === 'assigner') {
                state.modal = { incident, transition, field: 'assignee', value: config.technicians[0]?.email || '', title: `Assigner ${incident.reference}` };
            } else if (transition.name === 'resoudre') {
                state.modal = { incident, transition, field: 'report', value: '', title: `Résoudre ${incident.reference}` };
            } else {
                apply(incident, transition, {});
            }
            if (state.modal) {
                nextTick(() => document.getElementById('kanban-modal-input')?.focus());
            }
        }

        function confirmModal() {
            const { incident, transition, field, value } = state.modal;
            state.modal = null;
            apply(incident, transition, value ? { [field]: value } : {});
        }

        async function apply(incident, transition, payload) {
            const previous = incident.status;
            incident.status = transition.to; // déplacement immédiat…
            state.busy[incident.reference] = true;
            try {
                const updated = await api.transition({ ...incident, status: previous }, transition.name, payload);
                Object.assign(incident, updated);
                notify('success', `${incident.reference} : ${transition.label} → ${labels[incident.status]}.`);
            } catch (error) {
                incident.status = previous; // …annulé si l'API refuse
                notify('danger', `${incident.reference} : ${error.message}`);
                if (error.status === 409 && error.problem?.type?.endsWith('version-conflict')) {
                    load();
                }
            } finally {
                delete state.busy[incident.reference];
            }
        }

        load();

        return { state, columns, config, transitionsFor, onDragStart, onDragEnd, onDragOver, onDrop, dropState, start, confirmModal, load };
    },
    template: `
        <div>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <p class="text-body-secondary small mb-0">Glissez une carte vers une autre colonne, ou utilisez son menu « Actions ».</p>
                <button class="btn btn-sm btn-outline-primary" type="button" @click="load" :disabled="state.loading">Actualiser</button>
            </div>
            <div aria-live="polite" class="kanban-message">
                <div v-if="state.message" class="alert alert-dismissible py-2" :class="'alert-' + state.message.type" role="status">
                    {{ state.message.text }}
                    <button type="button" class="btn-close" aria-label="Fermer" @click="state.message = null"></button>
                </div>
            </div>
            <div v-if="state.loading && !state.incidents.length" class="text-center py-5" role="status">
                <div class="spinner-border text-primary" aria-hidden="true"></div>
                <p class="mt-2">Chargement des incidents…</p>
            </div>
            <div v-else class="kanban-board">
                <section v-for="column in columns" :key="column.status" class="kanban-column" :class="dropState(column)"
                         :aria-label="column.label" @dragover="onDragOver($event, column)" @drop="onDrop($event, column)">
                    <header class="kanban-column-header d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">{{ column.label }}</h2>
                        <span class="badge text-bg-secondary rounded-pill">{{ column.cards.length }}</span>
                    </header>
                    <div class="kanban-cards">
                        <kanban-card v-for="incident in column.cards" :key="incident.reference" :incident="incident"
                                     :busy="!!state.busy[incident.reference]" :transitions-for="transitionsFor"
                                     @drag-start="onDragStart" @drag-end="onDragEnd" @action="start"></kanban-card>
                        <p v-if="!column.cards.length" class="text-body-secondary small text-center my-3">Aucun incident</p>
                    </div>
                </section>
            </div>

            <div v-if="state.modal" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="kanban-modal-title" @keydown.esc="state.modal = null">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" @submit.prevent="confirmModal">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="kanban-modal-title">{{ state.modal.title }}</h2>
                            <button type="button" class="btn-close" aria-label="Fermer" @click="state.modal = null"></button>
                        </div>
                        <div class="modal-body">
                            <template v-if="state.modal.field === 'assignee'">
                                <label for="kanban-modal-input" class="form-label">Technicien</label>
                                <select id="kanban-modal-input" class="form-select" v-model="state.modal.value" required>
                                    <option v-for="t in config.technicians" :key="t.email" :value="t.email">{{ t.name }}</option>
                                </select>
                            </template>
                            <template v-else>
                                <label for="kanban-modal-input" class="form-label">Compte rendu d’intervention</label>
                                <textarea id="kanban-modal-input" class="form-control" rows="4" maxlength="2000" v-model="state.modal.value"
                                          placeholder="Laisser vide si l’intervention a déjà été terminée avec un compte rendu."></textarea>
                            </template>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" @click="state.modal = null">Annuler</button>
                            <button type="submit" class="btn btn-primary">{{ state.modal.transition.label }}</button>
                        </div>
                    </form>
                </div>
            </div>
            <div v-if="state.modal" class="modal-backdrop show"></div>
        </div>`,
});

app.mount(root);

/*
 * Liste des incidents : filtrage dynamique en AJAX avec jQuery.
 * Le tableau (et la pagination) est rechargé sans recharger la page ; l'URL est mise à jour
 * pour que la vue reste partageable. Sans JavaScript, le formulaire GET fonctionne normalement.
 */
import $ from 'jquery';
import { refreshSites } from './js/region-sites.js';

$(() => {
    const $form = $('#incident-filters');
    const $results = $('#incident-results');
    const $status = $('#incident-results-status');
    if (!$form.length || !$results.length) {
        return;
    }

    let pending = null;
    let timer = null;

    const urlFromForm = () => {
        const params = $form.serializeArray().filter((field) => field.value !== '');

        return `${$form.attr('action')}${params.length ? `?${$.param(params)}` : ''}`;
    };

    const load = (url) => {
        if (pending) {
            pending.abort();
        }
        $results.attr('aria-busy', 'true').addClass('is-loading');
        pending = $.ajax({ url, dataType: 'html', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .done((html) => {
                $results.html(html);
                window.history.replaceState(null, '', url);
                $status.text($results.find('[data-total]').data('total-label') || 'Liste mise à jour.');
            })
            .fail((xhr, textStatus) => {
                if (textStatus !== 'abort') {
                    $status.text('Impossible de charger les incidents.');
                    $results.prepend('<div class="alert alert-danger" role="alert">Impossible de charger les incidents. Réessayez.</div>');
                }
            })
            .always(() => {
                $results.attr('aria-busy', 'false').removeClass('is-loading');
                pending = null;
            });
    };

    // Région → sites, puis rechargement de la liste.
    $form.on('change', '#filter-region', function () {
        refreshSites($(this), $('#filter-site')).always(() => load(urlFromForm()));
    });
    $form.on('change', 'select:not(#filter-region), input[type="checkbox"]', () => load(urlFromForm()));
    $form.on('input', 'input[type="search"]', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => load(urlFromForm()), 350);
    });
    $form.on('submit', (event) => {
        event.preventDefault();
        load(urlFromForm());
    });
    $form.on('click', '[data-reset]', (event) => {
        event.preventDefault();
        $form.find('select').val('');
        $form.find('input[type="search"]').val('');
        $form.find('input[type="checkbox"]').prop('checked', false);
        refreshSites($('#filter-region'), $('#filter-site')).always(() => load(urlFromForm()));
    });

    // Tri et pagination : liens du bloc de résultats.
    $results.on('click', 'a[data-ajax-link]', function (event) {
        event.preventDefault();
        load(this.href);
        $results[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

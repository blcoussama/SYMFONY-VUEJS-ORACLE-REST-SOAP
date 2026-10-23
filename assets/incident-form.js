/*
 * Formulaire de déclaration : la liste des sites suit la région choisie (jQuery + /ajax/sites).
 */
import $ from 'jquery';
import { refreshSites } from './js/region-sites.js';

$(() => {
    const $region = $('[data-region-select]');
    if (!$region.length) {
        return;
    }
    const $site = $($region.data('sitesTarget'));
    $region.on('change', () => refreshSites($region, $site));
});

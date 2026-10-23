import $ from 'jquery';

/**
 * Sélection dépendante région → sites (jQuery) : recharge la liste des sites de la région
 * choisie depuis le point d'accès JSON /ajax/sites, en conservant le site choisi s'il en fait partie.
 *
 * @param {jQuery} $region liste des régions
 * @param {jQuery} $site   liste des sites (la première option sert de libellé « tous »)
 * @returns {jQuery.jqXHR}
 */
export function refreshSites($region, $site) {
    const url = $region.data('sitesUrl');
    const region = $region.val();
    const current = $site.val();
    const $placeholder = $site.find('option').first().clone();

    $site.prop('disabled', true).attr('aria-busy', 'true');

    return $.getJSON(url, region ? { region } : {})
        .done((sites) => {
            $site.empty().append($placeholder);
            sites.forEach((site) => {
                $site.append($('<option>', { value: site.id, text: site.label, 'data-region': site.region }));
            });
            if (sites.some((site) => String(site.id) === String(current))) {
                $site.val(current);
            } else {
                $site.val('');
            }
        })
        .always(() => {
            $site.prop('disabled', false).attr('aria-busy', 'false');
        });
}

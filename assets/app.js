/*
 * Point d'entrée commun à toutes les pages (importmap, sans étape de build Node).
 * Bootstrap 5 (CSS + JS : menus, fenêtres) et la feuille de style de NetOps.
 */
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';
import 'bootstrap';

// Confirmation des actions sensibles (révocation, désactivation, suppression).
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

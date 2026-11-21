import Alpine from 'alpinejs';
import appearance from './appearance';
import lightbox from './lightbox';

window.Alpine = Alpine;

Alpine.data('ogLightbox', lightbox);
Alpine.store('appearance', appearance);

Alpine.start();

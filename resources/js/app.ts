import { library } from '@fortawesome/fontawesome-svg-core';
import { fab } from '@fortawesome/free-brands-svg-icons';
import { far } from '@fortawesome/free-regular-svg-icons';
import { fas } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { createApp, createSSRApp, h } from 'vue';
import AppToast from '@/components/AppToast.vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeGoogleAnalytics } from '@/lib/googleAnalytics';

library.add(fas, far, fab);

const appName = import.meta.env.VITE_APP_NAME || 'Snitch';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name.startsWith('marketing/'):
            case name.startsWith('blog/'):
            case name.startsWith('errors/'):
            case name.startsWith('onboarding/'):
            case name.startsWith('claim/'):
                return null;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#F0C400',
    },
    setup({ el, App, props, plugin }) {
        // Vite SSR calls setup with el=null and expects the Vue app returned.
        const vueApp = (el ? createApp : createSSRApp)({
            render: () => [h(App, props), h(AppToast)],
        });

        vueApp
            .use(plugin)
            .use(createPinia())
            .component('font-awesome-icon', FontAwesomeIcon);

        if (el) {
            vueApp.mount(el);
        }

        return vueApp;
    },
});

if (typeof window !== 'undefined') {
    // This will set light / dark mode on page load...
    initializeTheme();

    // This will listen for flash toast data from the server...
    initializeFlashToast();

    initializeGoogleAnalytics();
}

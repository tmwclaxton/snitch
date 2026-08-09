<script setup lang="ts">
import LegalDocument from '@/components/marketing/LegalDocument.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <LegalDocument title="Cookie notice" updated="9 August 2026">
            <p>
                This notice explains cookies used by Snitch on
                www.snitchsocial.net.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Essential cookies
            </h2>
            <p>
                We use session cookies required for authentication and CSRF
                protection so you can sign in with WorkOS and use the app
                securely.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Preference cookies
            </h2>
            <p>
                We store lightweight preferences such as appearance
                (light/dark/system) and sidebar open state so the UI stays
                consistent between visits.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Payments
            </h2>
            <p>
                Checkout and the customer portal run on Stripe. When you start a
                paid plan, Stripe may set its own cookies on stripe.com. Those
                are controlled by Stripe, not by Snitch session cookies on this
                site.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Analytics
            </h2>
            <p>
                Snitch does not ship third-party marketing analytics cookies by
                default. The public Analytics page shows aggregate product
                counters without advertising trackers. If we add marketing
                analytics later, this page will be updated before those cookies
                are enabled.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Managing cookies
            </h2>
            <p>
                You can clear cookies in your browser settings. Clearing session
                cookies will sign you out.
            </p>
        </LegalDocument>
    </div>
</template>

<script setup lang="ts">
import LegalDocument from '@/components/marketing/LegalDocument.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <LegalDocument title="Cookie notice" updated="14 August 2026">
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
                We use Google Analytics 4 (gtag.js, measurement ID
                G-Y3VFH257B5) to understand how the website and installed PWA
                are used: page views, display mode (browser vs standalone),
                and whether the app was installed. Google sets first-party
                cookies such as <code class="text-sm">_ga</code> and
                <code class="text-sm">_ga_*</code> on snitchsocial.net. Hits
                use the Beacon API so they still send when the PWA is
                backgrounded. We do not send account emails or workspace
                content to Google. The public Analytics page is a separate
                product dashboard of aggregate Snitch counters, not this
                tracker.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Referral attribution
            </h2>
            <p>
                When you arrive via a partner link with a
                <code class="text-sm">?ref=</code> code, Snitch may set a
                first-party cookie (<code class="text-sm">snitch_ref</code>) for
                up to 90 days so signup can be credited even if the query
                parameter is dropped on later pages. We also mirror the code in
                session during auth. This is attribution only - not advertising
                tracking.
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

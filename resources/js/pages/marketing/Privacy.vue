<script setup lang="ts">
import LegalDocument from '@/components/marketing/LegalDocument.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <LegalDocument title="Privacy policy" updated="7 August 2026">
            <p>
                This policy describes how Snitch ("we") handles information when
                you use the product.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Account information
            </h2>
            <p>
                Authentication is provided by WorkOS. We store the account
                details needed to run your personal workspace (such as name and
                email) and brand context you provide during onboarding.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Competitor and post data
            </h2>
            <p>
                When you track public social accounts, we process publicly
                available profile and post data through third-party collection
                services (including Apify actors) so we can show a unified feed.
                We do not ask you to provide private credentials for competitor
                accounts, and we do not claim to access private or
                authenticated-only content.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                AI analysis
            </h2>
            <p>
                Public post media and captions may be sent to AI providers (such
                as NanoGPT) to generate structured analysis - hooks, visual
                summaries, SFX notes, and related insights. Analysis output is
                stored in your workspace so you can review it in Feed and
                Winners.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Cookies and sessions
            </h2>
            <p>
                We use session cookies required to keep you signed in, plus
                preference cookies such as appearance. See the Cookies page for
                details.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Contact
            </h2>
            <p>
                Privacy questions: use the Contact form or email
                hello@snitchsocial.net.
            </p>
        </LegalDocument>
    </div>
</template>

<script setup lang="ts">
import LegalDocument from '@/components/marketing/LegalDocument.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <LegalDocument title="Privacy policy" updated="14 August 2026">
            <p>
                This policy describes how Snitch ("we") handles information when
                you use the product at www.snitchsocial.net.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Account information
            </h2>
            <p>
                Authentication is provided by WorkOS. We store the account
                details needed to run your personal workspace (such as name and
                email) and brand context you provide during onboarding or in
                Brand settings.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Snitch and post data
            </h2>
            <p>
                When you track public social accounts, we process publicly
                available profile and post data through third-party collection
                services (including Apify actors) so we can show a unified feed.
                We do not ask you to provide private credentials for snitch
                accounts, and we do not claim to access private or
                authenticated-only content. Import focuses on public reels and
                Shorts-style video.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                AI analysis
            </h2>
            <p>
                Public post media and captions may be sent to AI providers (such
                as NanoGPT) to generate structured analysis - hooks, visual
                summaries, SFX notes, catalogue terms, and related insights.
                Analysis output is stored in your workspace so you can review it
                in Feed, Explore, and Winners.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Billing
            </h2>
            <p>
                Paid plans and checkout are processed by Stripe. We store
                Stripe customer and subscription identifiers, plan status, and
                trial end dates needed for billing access. Card numbers
                and payment credentials are handled by Stripe, not stored by
                Snitch. See Stripe's privacy policy for how they process payment
                data.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Public blog and analytics
            </h2>
            <p>
                The public Blog and Analytics pages show marketing content and
                aggregate product counters. They are not used to publish your
                private workspace data, handles, or captions. Separately, Google
                Analytics 4 records page views for the website and installed
                PWA (including standalone display mode). That usage data is
                processed by Google; we do not send your email, brand profile,
                or tracked-account content with those hits.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Cookies and sessions
            </h2>
            <p>
                We use session cookies required to keep you signed in, plus
                preference cookies such as appearance. We also use Google
                Analytics 4 cookies to measure site and PWA usage. See the
                Cookies page for details.
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

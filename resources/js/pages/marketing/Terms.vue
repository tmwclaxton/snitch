<script setup lang="ts">
import LegalDocument from '@/components/marketing/LegalDocument.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <LegalDocument title="Terms of use" updated="9 August 2026">
            <p>
                These terms govern use of Snitch at www.snitchsocial.net.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                The service
            </h2>
            <p>
                Snitch is a personal competitor social tracker. You may create
                one personal workspace, track public accounts you choose, view
                analysis of public posts, filter craft terms in Explore, and
                configure winner rules. Competitor slots are capped by your plan
                (Free, Basic, or Pro).
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Trials and billing
            </h2>
            <p>
                New accounts may start with a time-limited trial at Basic
                competitor limits without entering a card. After the trial, you
                stay on Free unless you subscribe. Paid plans are billed through
                Stripe. You can manage or cancel a subscription in the billing
                portal. Prices and caps may change; material changes will be
                reflected on the Pricing page.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Acceptable use
            </h2>
            <p>
                You agree to use Snitch only with public social content and in
                ways that respect platform terms and applicable law. Do not use
                Snitch to attempt access to private accounts, bypass technical
                controls, harass individuals, or scrape content you are not
                allowed to collect.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Public data only
            </h2>
            <p>
                Snitch is designed around publicly available posts and profiles.
                We do not claim to retrieve private, friends-only, or
                authenticated-gated content on your behalf.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                AI disclaimer
            </h2>
            <p>
                Analysis, scoring, suggest results, and "how to copy" notes are
                generated with automated systems and may be incomplete or wrong.
                Use them as creative research aids, not as guarantees of
                performance or legal clearance for remakes.
            </p>

            <h2 class="snitch-display text-xl text-snitch-ink">
                Accounts
            </h2>
            <p>
                You are responsible for activity under your WorkOS-authenticated
                account. We may suspend access for abuse or security risk.
            </p>
        </LegalDocument>
    </div>
</template>

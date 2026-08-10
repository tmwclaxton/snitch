import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as exploreIndex } from '@/actions/App/Http/Controllers/ExploreController';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import { index as influencersIndex } from '@/actions/App/Http/Controllers/InfluencerController';
import { edit as brandEdit } from '@/routes/brand';
import { index as competitorsIndex } from '@/routes/competitors';

export type ChargeLink = {
    type: string;
    id?: number;
    label: string;
};

export type ChargeRow = {
    id: number;
    action: string;
    description: string;
    link: ChargeLink | null;
    vendor: string;
    amount_pence: number;
    balance_after_pence?: number;
    created_at: string | null;
};

export function chargeLinkHref(link: ChargeLink | null | undefined): string | null {
    if (!link) {
        return null;
    }

    switch (link.type) {
        case 'post':
            return link.id != null ? feedShow.url(link.id) : null;
        case 'tracked_account':
            return link.id != null ? competitorShow.url(link.id) : null;
        case 'competitors':
            return competitorsIndex.url();
        case 'influencers':
            return influencersIndex.url();
        case 'brand':
            return brandEdit.url();
        case 'explore':
            return exploreIndex.url();
        default:
            return null;
    }
}

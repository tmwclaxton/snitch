<?php

namespace App\Mcp\Support;

use App\Models\User;

/**
 * Brand readiness checks so agents do not discover rivals/creators against a stale profile.
 */
class BrandContext
{
    /**
     * @return list<string>
     */
    public static function warningsFor(User $user): array
    {
        $brand = $user->brandProfile;
        $warnings = [];

        if ($brand === null) {
            $warnings[] = 'No brand profile. Call update_brand or start_brand_autofill with the target website before suggest_competitors / find_influencers.';

            return $warnings;
        }

        if (blank($brand->website)) {
            $warnings[] = 'Brand website is empty. Set website (e.g. via start_brand_autofill) so suggestions match the right company.';
        }

        if (blank($brand->name)) {
            $warnings[] = 'Brand name is empty. Update the brand name to match the product you are researching.';
        }

        if (blank($brand->description)) {
            $warnings[] = 'Brand description is empty. Autofill or update_brand with positioning so discovery briefs are on-niche.';
        }

        return $warnings;
    }
}

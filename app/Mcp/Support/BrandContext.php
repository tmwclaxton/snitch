<?php

namespace App\Mcp\Support;

use App\Models\User;
use Laravel\Mcp\Response;

/**
 * Brand readiness checks so agents do not discover rivals/creators against a stale profile.
 */
class BrandContext
{
    /**
     * Hard blockers for billable discovery (suggest / find).
     * Niche needs brand description, stored competitor_brief, or a per-run suggest brief.
     *
     * @return list<string>
     */
    public static function blockingErrorsFor(User $user, ?string $suggestBrief = null): array
    {
        $brand = $user->brandProfile;
        $errors = [];

        if ($brand === null) {
            $errors[] = 'No brand profile. Call update_brand or start_brand_autofill with the target website before suggest_competitors / find_influencers.';

            return $errors;
        }

        if (blank($brand->website)) {
            $errors[] = 'Brand website is blank. Set website via update_brand or start_brand_autofill before discovery.';
        }

        if (blank($brand->name)) {
            $errors[] = 'Brand name is blank. Set name via update_brand or start_brand_autofill before discovery.';
        }

        $hasNiche = filled($brand->description)
            || filled($brand->competitor_brief)
            || filled($suggestBrief);

        if (! $hasNiche) {
            $errors[] = 'Brand niche is blank. Set description via update_brand or start_brand_autofill, or pass brief on suggest_competitors, so discovery searches the niche instead of an ambiguous name.';
        }

        return $errors;
    }

    /**
     * Block suggest/find when brand is missing or website/name/niche blank.
     */
    public static function assertReady(User $user, ?string $suggestBrief = null): ?Response
    {
        $errors = self::blockingErrorsFor($user, $suggestBrief);
        if ($errors === []) {
            return null;
        }

        return Response::error(
            implode(' ', $errors).' next_step: Call update_brand or start_brand_autofill (or pass brief on suggest_competitors), then get_brand before suggest_competitors / find_influencers.'
        );
    }

    /**
     * Soft warnings for whoami / get_brand (includes empty description and fuzzy name/host mismatch).
     *
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

        if (blank($brand->description) && blank($brand->competitor_brief)) {
            $warnings[] = 'Brand description and competitor brief are blank (blocks suggest_competitors / find_influencers unless you pass brief). Autofill, update_brand, or pass brief so discovery stays on-niche.';
        } elseif (blank($brand->description)) {
            $warnings[] = 'Brand description is blank. Autofill or update_brand with positioning; competitor_brief alone can unlock suggest.';
        }

        if (
            filled($brand->name)
            && filled($brand->website)
            && self::nameLooksUnrelatedToWebsite((string) $brand->name, (string) $brand->website)
        ) {
            $warnings[] = 'Brand name looks unrelated to the website host. Confirm update_brand / start_brand_autofill targeted the right company before discovery.';
        }

        return $warnings;
    }

    public static function nameLooksUnrelatedToWebsite(string $name, string $website): bool
    {
        $host = parse_url($website, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        $hostBase = explode('.', $host)[0] ?? '';
        $hostNorm = preg_replace('/[^a-z0-9]+/', '', $hostBase) ?? '';
        $nameNorm = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name) ?? '');

        if (strlen($nameNorm) < 3 || strlen($hostNorm) < 3) {
            return false;
        }

        if (str_contains($hostNorm, $nameNorm) || str_contains($nameNorm, $hostNorm)) {
            return false;
        }

        $tokens = preg_split('/[^a-z0-9]+/i', $name) ?: [];
        foreach ($tokens as $token) {
            $t = strtolower($token);
            if (strlen($t) >= 4 && str_contains($host, $t)) {
                return false;
            }
        }

        return true;
    }
}

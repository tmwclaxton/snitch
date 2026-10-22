<?php

namespace Tests\Concerns;

use App\Http\Middleware\RedirectRetiredProductPages;

/**
 * Soft-retired product GETs redirect to /dashboard in the browser.
 * Feature tests that still exercise those controllers opt out of the redirect.
 */
trait WithoutRetiredPageRedirects
{
    protected function setUpWithoutRetiredPageRedirects(): void
    {
        $this->withoutMiddleware(RedirectRetiredProductPages::class);
    }
}

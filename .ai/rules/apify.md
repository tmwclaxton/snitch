---
paths:
  - 'app/Services/Apify/**'
---

# Apify

## Product scope is reel and short-video only
Skip images, carousels, text-only, and items without a resolvable video media_url on sync. Prefer PostType::Reel for short video. YouTube imports Shorts only (skip long-form). Feed/analysis/winners operate on reel-like types only.

## LinkedIn actors are company vs profile
Default `snitch.apify.actors.linkedin` is `apimaestro/linkedin-company-posts` (`company_name`). Personal `/in/` resolves use `linkedin_profile` (`apimaestro/linkedin-profile-posts`, `username`). Do not send a `urls` array - that input is invalid for both actors.

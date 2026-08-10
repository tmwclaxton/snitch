<?php

namespace App\Enums;

enum BillingVendor: string
{
    case Apify = 'apify';
    case NanoGpt = 'nanogpt';
    case Firecrawl = 'firecrawl';
    case TikHub = 'tikhub';
    case Snitch = 'snitch';
    case Bonus = 'bonus';
    case Topup = 'topup';
}

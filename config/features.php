<?php

return [

    /*
    | Ad Library (/ad-library) stays off until Meta ads sync is ready for GA.
    | Set SNITCH_AD_LIBRARY=true to restore the sidebar link and page.
    */
    'ad_library' => (bool) env('SNITCH_AD_LIBRARY', false),

];

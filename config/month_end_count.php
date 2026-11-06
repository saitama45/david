<?php

return [
    /*
     * Whether the Month End Count template (Download Count Template) carries the Current SOH
     * column, the Inventory Movement Report's Theoretical SOH of each line. Taken off for now on
     * 2026-11-06 at the business's request; set MEC_TEMPLATE_CURRENT_SOH=true, or change the
     * default here, to bring it back. The upload reads columns by heading, so templates with or
     * without it both upload; without it a count line's Current SOH is stored as 0.
     */
    'template_current_soh' => (bool) env('MEC_TEMPLATE_CURRENT_SOH', false),
];

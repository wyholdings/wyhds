<?php

$defaultSlot = $_ENV['ADSENSE_SLOT_DEFAULT'] ?? '8432108065';

return [
    'client' => $_ENV['ADSENSE_CLIENT'] ?? 'ca-pub-5028641394927049',
    'slots' => [
        'top' => $_ENV['ADSENSE_SLOT_TOP'] ?? $defaultSlot,
        'middle' => $_ENV['ADSENSE_SLOT_MIDDLE'] ?? $defaultSlot,
        'after_faq' => $_ENV['ADSENSE_SLOT_AFTER_FAQ'] ?? ($_ENV['ADSENSE_SLOT_MIDDLE'] ?? $defaultSlot),
        'footer' => $_ENV['ADSENSE_SLOT_BOTTOM'] ?? $defaultSlot,
        'default' => $defaultSlot,
    ],
];

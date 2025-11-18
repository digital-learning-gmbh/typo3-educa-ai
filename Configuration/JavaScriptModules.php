<?php

return [
    'dependencies' => ['core', 'backend'],
    'imports' => [
        // Definiere hier einen Alias.
        // '@educa_ai_typo3_seo/' wird nun auf den Public-Ordner deiner Extension zeigen.
        '@educa_ai_typo3_seo/' => 'EXT:educa_ai_typo3_seo/Resources/Public/JavaScript/',
    ],
];
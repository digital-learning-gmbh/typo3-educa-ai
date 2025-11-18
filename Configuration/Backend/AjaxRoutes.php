<?php

/**
 * Registriert die AJAX-Route für unseren SEO-Berechnungs-Button
 */
return [
    // Der Name der Route. Muss eindeutig sein.
    'educa_ai_typo3_seo_calculate' => [
        // Der Pfad, der in der URL verwendet wird
        'path' => '/educa-ai/typo3-seo/calculate',
        // Die Zielmethode, die aufgerufen wird
        // Format: Klassenname::Methodenname
        'target' => \EducaAiTypo3Seo\EducaAiTypo3Seo\Controller\AjaxController::class . '::calculateAction',
    ],
];

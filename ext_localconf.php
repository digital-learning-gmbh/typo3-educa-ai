<?php
defined('TYPO3') || die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\EducaAiTypo3Seo\EducaAiTypo3Seo\Command\SeoCalculationCommand::class] = [
    'extension' => 'educa_ai_typo3_seo',
    'title' => 'LLL:EXT:educa_ai_typo3_seo/Resources/Private/Language/locallang.xlf:scheduler.title',
    'description' => 'LLL:EXT:educa_ai_typo3_seo/Resources/Private/Language/locallang.xlf:scheduler.description',
    'additionalFields' => '' // Hier könnten später eigene Felder für den Task hinzukommen
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1660000000] = [
    'nodeName' => 'educaAiTypo3SeoCalculateButton',
    'priority' => 40,
    'class' => \EducaAiTypo3Seo\EducaAiTypo3Seo\Backend\Form\Element\CalculateButtonNodeFactory::class,
];

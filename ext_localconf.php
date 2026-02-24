<?php
defined('TYPO3') || die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1660000000] = [
    'nodeName' => 'educaAiTypo3SeoCalculateButton',
    'priority' => 40,
    'class' => \EducaAiTypo3Seo\EducaAiTypo3Seo\Backend\Form\Element\CalculateButtonNodeFactory::class,
];

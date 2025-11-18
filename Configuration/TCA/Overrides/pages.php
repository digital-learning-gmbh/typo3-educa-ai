<?php
defined('TYPO3') || die();

// Definieren Sie das neue Feld (unser zukünftiger Button)
$tempColumns = [
    'tx_educa_ai_typo3_seo_calculate' => [
        // Das Label, das neben dem Button erscheinen würde (wir werden es meist ausblenden)
        'label' => 'LLL:EXT:educa_ai_typo3_seo/Resources/Private/Language/locallang_db.xlf:pages.tx_educa_ai_typo3_seo_calculate',
        'config' => [
            'type' => 'user', // 'user' ist ein spezieller Typ für benutzerdefinierte Elemente
            'renderType' => 'educaAiTypo3SeoCalculateButton', // Unser EIGENER, den wir noch erstellen müssen!
        ],
    ],

    'tx_educa_ai_typo3_seo_calculate_desc' => [
        'label' => 'LLL:EXT:educa_ai_typo3_seo/Resources/Private/Language/locallang_db.xlf:pages.tx_educa_ai_typo3_seo_calculate', 
        'config' => [
            'type' => 'user',
            'renderType' => 'educaAiTypo3SeoCalculateButton',
        ],
    ],
];

// Füge die neue Spalte temporär zu den TCA-Definitionen von TYPO3 hinzu
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', $tempColumns);

// Füge das neue Feld zum SEO-Tab hinzu.
// Das Feld wird im Tab "seo" nach dem Feld "seo_title" eingefügt.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    'tx_educa_ai_typo3_seo_calculate', // Der Name Ihres Feldes
    '', // Gilt für alle Seitentypen
    'after:keywords'
);

// Hinzufügen nach dem Feld 'description' (das ist die SEO Beschreibung)
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    'tx_educa_ai_typo3_seo_calculate_desc', // Derselbe Feldname
    '', // Gilt für alle Seitentypen
    'after:description'
 );
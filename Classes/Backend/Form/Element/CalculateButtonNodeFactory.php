<?php
namespace EducaAiTypo3Seo\EducaAiTypo3Seo\Backend\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Localization\LanguageService;

class CalculateButtonNodeFactory extends AbstractFormElement
{
    public function render(): array
    {
        $result = $this->initializeResultArray();
        $pageId = $this->data['databaseRow']['uid'];
        /** @var LanguageService $lang */
        $lang = $this->getLanguageService();
        $ll = 'LLL:EXT:educa_ai_typo3_seo/Resources/Private/Language/locallang_db.xlf:';

        // --- Button 1: Generiert nur für leere Felder ---
        $buttonGenerate = '<button type="button" class="btn btn-default"'
            // Key change: Using data-action as a selector for JS instead of a fixed ID.
            . ' data-action="calculate-seo"'
            . ' data-page-id="' . htmlspecialchars((string)$pageId) . '"'
            // Key change: Explicitly set override to false for this button.
            . ' data-override="false"'
            // Texte für "Berechnung läuft"
            . ' data-calculating-title="' . htmlspecialchars($lang->sL($ll . 'js.notification.calculating.title')) . '"'
            . ' data-calculating-message="' . htmlspecialchars($lang->sL($ll . 'js.notification.calculating.message')) . '"'
            // Texte für "Erfolg"
            . ' data-success-title="' . htmlspecialchars($lang->sL($ll . 'js.notification.success.title')) . '"'
            . ' data-success-message="' . htmlspecialchars($lang->sL($ll . 'js.notification.success.message')) . '"'
            // Texte für "Fehler"
            . ' data-error-title="' . htmlspecialchars($lang->sL($ll . 'js.notification.error.title')) . '"'
            . ' data-error-message="' . htmlspecialchars($lang->sL($ll . 'js.notification.error.message')) . '"'
            . '>';
        // Verwenden Sie ein Icon für bessere UI
        $buttonGenerate .= htmlspecialchars($lang->sL($ll . 'pages.tx_educa_ai_typo3_seo_calculate.buttonLabel'));
        $buttonGenerate .= '</button>';


        // --- Button 2: Generiert und überschreibt bestehende Felder ---
        $buttonOverride = '<button type="button" class="btn btn-default" style="margin-left: 5px;"'
            // Key change: Also uses data-action so the same JS logic applies.
            . ' data-action="calculate-seo"'
            . ' data-page-id="' . htmlspecialchars((string)$pageId) . '"'
            // Key change: Explicitly set override to true for this button.
            . ' data-override="true"'
            // Wir können dieselben Benachrichtigungstexte wiederverwenden, oder spezifische definieren.
            // Besser sind spezifische Texte für eine klarere User Experience.
            . ' data-calculating-title="' . htmlspecialchars($lang->sL($ll . 'js.notification.calculating.title')) . '"'
            . ' data-calculating-message="' . htmlspecialchars($lang->sL($ll . 'js.notification.calculating.override.message')) . '"'
            . ' data-success-title="' . htmlspecialchars($lang->sL($ll . 'js.notification.success.title')) . '"'
            . ' data-success-message="' . htmlspecialchars($lang->sL($ll . 'js.notification.success.override.message')) . '"'
            . ' data-error-title="' . htmlspecialchars($lang->sL($ll . 'js.notification.error.title')) . '"'
            . ' data-error-message="' . htmlspecialchars($lang->sL($ll . 'js.notification.error.message')) . '"'
            . '>';
        $buttonOverride .= htmlspecialchars($lang->sL($ll . 'pages.tx_educa_ai_typo3_seo_calculate.buttonLabelOverride'));
        $buttonOverride .= '</button>';

        // Beide Buttons in einem Container ausgeben
        $result['html'] = '<div><p>Mit diesem Tool können automatisch Werte für die Felder SEO->Titel für Suchmaschinen, SEO->Beschreibung für Suchmaschinen, Metadaten->Teaser / Kurzbeschreibung, Metadaten->Schlagworte. 
        Sie haben die Möglichkeit fehlende Metadaten automatisch ergänzen zu lassen oder alle Metadaten neu zu berechnen.</p>' . $buttonGenerate . $buttonOverride . '</div>';

        // Das Laden des JavaScript-Moduls bleibt gleich und ist korrekt für TYPO3 v12.
        $result['javaScriptModules'][] = JavaScriptModuleInstruction::create(
            // Alias aus der ext_localconf.php (via JavaScriptModules.php)
            '@educa_ai_typo3_seo/CalculateButton.js'
        );

        return $result;
    }
}
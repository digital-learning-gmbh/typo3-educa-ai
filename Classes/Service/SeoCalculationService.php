<?php
namespace EducaAiTypo3Seo\EducaAiTypo3Seo\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SeoCalculationService
{
    private LoggerInterface $logger;
    private array $extensionConfiguration;
    private array $lastGeneratedData = [];


    private const FIELD_LENGTH_LIMITS = [
        'seo_title'   => 70,   // Striktes Limit gemäß Anforderung
        'description' => 255,
        'abstract'    => 255,
        'keywords'    => 255,
    ];
    /**
     * Die Datenbankfelder, die von der KI befüllt werden können.
     * @var string[]
     */
    private const TARGET_FIELDS = ['seo_title', 'description', 'abstract', 'keywords'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionConfiguration $extConfig
    ) {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
        $this->extensionConfiguration = $this->extConfig->get('educa_ai_typo3_seo');
    }

    /**
     * Gibt ein Array der zuletzt generierten Daten zurück.
     */
    public function getLastGeneratedData(): array
    {
        return $this->lastGeneratedData;
    }

    /**
     * Orchestriert die KI-gestützte Befüllung oder Ergänzung von SEO-Feldern für eine Seite.
     *
     * @param int $pageId Die UID der Seite
     * @param bool $update Sollen die generierten Daten direkt in die DB geschrieben werden?
     * @param bool $allowOverride Wenn true, werden bereits gefüllte Felder komplett neu generiert.
     *                            Wenn false (Standard), werden gefüllte Felder intelligent ergänzt.
     * @return bool True bei Erfolg, false bei einem kritischen Fehler.
     */
    public function calculateForPage(int $pageId, bool $update = false, bool $allowOverride = false): bool
    {
        $this->logger->info(sprintf(
            'Starting SEO calculation for page %d (Update: %s, Override: %s)',
            $pageId,
            $update ? 'true' : 'false',
            $allowOverride ? 'true' : 'false'
        ));

        // 1. Sammle alle notwendigen Daten von der Seite.
        $pageContext = $this->gatherPageContext($pageId);
        if ($pageContext === null) {
            $this->logger->warning(sprintf('Could not gather context for page %d. Skipping.', $pageId));
            return false;
        }

        if (empty($pageContext['plainTextContent'])) {
            $this->logger->warning(sprintf('Could not render content for page %d. Skipping AI calls.', $pageId));
            return false;
        }

        $dataToUpdate = [];
        $this->lastGeneratedData = [];

        // Konfiguration: Sollen bereits gefüllte Felder übersprungen werden?
        $skipFilledFields = (bool)($this->extensionConfiguration['skipFilledFields'] ?? true);

        // 2. Gehe jedes Zielfeld durch und generiere/ergänze den Inhalt.
        foreach (self::TARGET_FIELDS as $fieldName) {
            $existingValue = trim($pageContext['pageRecord'][$fieldName] ?? '');

            $this->logger->info(sprintf(
                'Processing field "%s" for page %d. Existing content: %s',
                $fieldName,
                $pageId,
                empty($existingValue) ? 'no' : 'yes'
            ));

            // Wenn skipFilledFields aktiv ist und wir nicht im Override-Modus sind,
            // überspringe Felder, die bereits einen Wert enthalten.
            if ($skipFilledFields && !$allowOverride && !empty($existingValue)) {
                $this->logger->info(sprintf(
                    'Skipping field "%s" for page %d: already filled and skipFilledFields is enabled.',
                    $fieldName,
                    $pageId
                ));
                continue;
            }

            // Wähle das passende Prompt-Template für das aktuelle Feld.
            // Die Logik, ob neu erstellt oder ergänzt wird, steckt in den Build-Methoden.
            $prompt = match ($fieldName) {
                'seo_title' => $this->buildSeoTitlePrompt($pageContext, $existingValue, $allowOverride),
                'description' => $this->buildDescriptionPrompt($pageContext, $existingValue, $allowOverride),
                'abstract' => $this->buildAbstractPrompt($pageContext, $existingValue, $allowOverride),
                'keywords' => $this->buildKeywordsPrompt($pageContext, $existingValue, $allowOverride),
                default => null,
            };

            $max_tokens = match ($fieldName) {
                'seo_title' => 50,
                'description' => 150,
                'abstract' => 200,
                'keywords' => 200,
                default => 150,
            };

            if ($prompt === null) {
                continue;
            }

            // Rufe die KI auf, um den Inhalt zu generieren.
            $generatedValue = $this->generateTextFromApi($prompt, $max_tokens);

            if ($generatedValue !== null) {
                $this->logger->debug(sprintf('AI generated for "%s": "%s"', $fieldName, $generatedValue));

                $finalValue = $generatedValue;
                // Speziallogik für Keywords: Alte und neue kombinieren, wenn nicht überschrieben wird.
                if ($fieldName === 'keywords' && !$allowOverride && !empty($existingValue)) {
                    $oldKeywords = array_map('trim', explode(',', $existingValue));
                    $newKeywords = array_map('trim', explode(',', $generatedValue));
                    // Kombinieren, Duplikate und leere Einträge entfernen
                    $allKeywords = array_unique(array_filter(array_merge($oldKeywords, $newKeywords)));
                    $finalValue = implode(', ', $allKeywords);
                }
                
                $limit = self::FIELD_LENGTH_LIMITS[$fieldName] ?? 255;
                if (mb_strlen($finalValue, 'UTF-8') > $limit) {
                    $originalLength = mb_strlen($finalValue, 'UTF-8');
                    // Kürzen, aber nicht mitten im Wort
                    $truncatedValue = mb_substr($finalValue, 0, $limit, 'UTF-8');
                    $lastSpace = mb_strrpos($truncatedValue, ' ', 0, 'UTF-8');
                    
                    if ($lastSpace !== false) {
                        // Kürze am letzten Leerzeichen, um ganze Wörter zu erhalten
                        $finalValue = mb_substr($truncatedValue, 0, $lastSpace, 'UTF-8');
                    } else {
                        // Kein Leerzeichen gefunden, harter Schnitt
                        $finalValue = $truncatedValue;
                    }

                    $this->logger->info(
                        sprintf('Generated content for field "%s" was too long and has been truncated.', $fieldName),
                        [
                            'pageId' => $pageId,
                            'original_length' => $originalLength,
                            'limit' => $limit,
                            'truncated_length' => mb_strlen($finalValue, 'UTF-8'),
                            'original_value' => $generatedValue,
                            'final_value' => $finalValue,
                        ]
                    );
                }

                $dataToUpdate[$fieldName] = $finalValue;
                $this->lastGeneratedData[$fieldName] = $finalValue;
            } else {
                $this->logger->error(sprintf('Failed to fetch AI content for field "%s" for page %d.', $fieldName, $pageId));
            }
        }

        // 3. Wenn es Daten zum Aktualisieren gibt und $update true ist, schreibe sie in die DB.
        if ($update && !empty($dataToUpdate)) {
            $this->logger->info(sprintf('Updating database for page %d with new AI data.', $pageId), ['data' => $dataToUpdate]);
            $connection = $this->connectionPool->getConnectionForTable('pages');
            $connection->update(
                'pages',
                $dataToUpdate,
                ['uid' => $pageId]
            );
            $this->logger->info(sprintf('Successfully updated database for page %d.', $pageId));
        } elseif (!$update && !empty($dataToUpdate)) {
            $this->logger->info('Generated AI data is available but database update was not requested.', ['data' => $this->lastGeneratedData]);
        } else {
            $this->logger->info(sprintf('No new data was generated or needed to be updated for page %d.', $pageId));
        }

        return true;
    }

    /**
     * Sammelt alle relevanten Informationen über eine Seite.
     * (Diese Methode bleibt unverändert)
     *
     * @return array|null Ein Array mit Kontextinformationen oder null bei Fehler.
     */
    private function gatherPageContext(int $pageId): ?array
    {
        try {
            // 1. Hole Datenbank-Felder (Titel, SEO-Felder, etc.)
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $pageRecord = $queryBuilder
                ->select('uid', 'title', 'seo_title', 'description', 'abstract', 'keywords')
                ->from('pages')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();

            if ($pageRecord === false) {
                return null;
            }

            // 2. Finde die Site-Konfiguration
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $siteLanguage = $site->getDefaultLanguage();
            $uri = $site->getRouter()->generateUri($pageId, ['_language' => $siteLanguage->getLanguageId()]);

            // 3. Rendere das komplette HTML der Seite
            $client = GeneralUtility::makeInstance(Client::class, ['verify' => false]);
            $response = $client->request('GET', (string)$uri, ['headers' => ['X-TYPO3-Rendering-Purpose' => 'seo-calculation'], 'http_errors' => false]);
            
            if ($response->getStatusCode() !== 200) {
                 $this->logger->error('Failed to render page HTML for page ' . $pageId, ['status' => $response->getStatusCode()]);
                 return null;
            }
            $htmlContent = $response->getBody()->getContents();

            // 4. Extrahiere strukturierte Daten aus dem HTML
            $h1 = preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $htmlContent, $matches) ? strip_tags($matches[1]) : 'Nicht gefunden';
            preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $htmlContent, $matches);
            $h2s = array_map('strip_tags', $matches[1]);

            // 5. Konvertiere HTML zu reinem Text
            $plainText = preg_replace('/\s+/', ' ', strip_tags($htmlContent));

            return [
                'pageRecord' => $pageRecord,
                'site' => $site,
                'uriPath' => $uri->getPath(),
                'language' => $siteLanguage->getLocale(),
                'h1' => trim($h1),
                'h2s' => array_map('trim', $h2s),
                'plainTextContent' => trim($plainText),
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to gather page context for page ' . $pageId . ': ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    // --- PROMPT TEMPLATE METHODS ---

    private function buildKeywordsPrompt(array $context, string $existingKeywords, bool $allowOverride): string
    {
        $taskInstruction = '';
        if (!$allowOverride && !empty($existingKeywords)) {
            $taskInstruction = <<<TEXT
            **Aufgabe:** Füge der folgenden Liste von Schlagworten 3-5 weitere, passende und einzigartige Begriffe hinzu. Wiederhole keine Begriffe aus der bestehenden Liste.
            **Bestehende Schlagworte:** {$existingKeywords}
            TEXT;
        } else {
            $taskInstruction = '**Aufgabe:** Erzeuge aus dem folgenden Kontext 5–10 präzise Schlagworte (Metadaten), die den Inhalt klar und spezifisch beschreiben.';
        }

        return <<<PROMPT
        Du bist ein Metadaten- und Schlagwort-Generator für das Bildungsportal Niedersachsen.
        {$taskInstruction}
        
        **Vermeide:**
        - Zu allgemeine Begriffe wie: Bildung, Berufliche Bildung, Berufsbildende Schulen, Schule, Portal, Lehrkräfte, Fachberatung, Fortbildung, Rahmenrichtlinien, Kerncurriculum, Curriculare Vorgaben
        - Begriffe, die exakt im Seitentitel oder der Hauptüberschrift (H1) vorkommen
        - Wiederholungen oder Synonyme
        
        **Bevorzuge:**
        - Fachspezifische Themenbegriffe (z. B. Fächer, Bildungsgänge, Methoden, Zielgruppen, Projekte, Qualifikationen)
        - Maximal drei Wörter pro Schlagwort
        
        **Analyse-Kontext:**
        - Sprache: {$context['language']}
        - Seitentitel: {$context['pageRecord']['title']}
        - Hauptüberschrift (H1): {$context['h1']}
        
        **Seiteninhalt:**
        {$context['plainTextContent']}
        
        **Ausgabe:**
        Nur die Schlagworte, kommagetrennt, ohne Einleitung oder Nummerierung. Du antwortest IMMER auf deutsch.
        PROMPT;
    }

    private function buildSeoTitlePrompt(array $context, string $existingValue, bool $allowOverride): string
    {
        $baseTitle = !empty($existingValue) ? $existingValue : $context['pageRecord']['title'];
        $taskInstruction = '';

        if (!$allowOverride && !empty($existingValue)) {
            $taskInstruction = "Optimiere und ergänze den folgenden Titel für Suchmaschinen. Behalte die Kernbotschaft bei, aber mache ihn prägnanter und klick-anregender.";
        } else {
            $taskInstruction = "Erstelle einen prägnanten, suchmaschinenoptimierten Seitentitel (SEO Title) für die folgende Seite, basierend auf dem Seitentitel.";
        }
        
        $contentSnippet = substr($context['plainTextContent'], 0, 500) . '...';

        return <<<PROMPT
        Du bist ein SEO-Experte. {$taskInstruction}
        
        **Anforderungen:**
        - Länge: Maximal 60 Zeichen.
        - Inhalt: Das Hauptkeyword oder Thema der Seite muss enthalten sein.
        - Stil: Klick-anregend und informativ.
        
        **Analyse-Kontext:**
        - Zu optimierender Titel: {$baseTitle}
        - Hauptüberschrift (H1): {$context['h1']}
        - Seiteninhalt (Auszug): {$contentSnippet}
        
        **Ausgabe:**
        Nur der reine, optimierte SEO-Titel, ohne Anführungszeichen oder weitere Erklärungen. Du antwortest IMMER auf deutsch.
        PROMPT;
    }

    private function buildDescriptionPrompt(array $context, string $existingValue, bool $allowOverride): string
    {
        $taskInstruction = '';
        $existingContentContext = '';

        if (!$allowOverride && !empty($existingValue)) {
            $taskInstruction = "Ergänze die folgende Meta-Beschreibung sinnvoll zu einem oder zwei vollständigen Sätzen. Verbessere den Text, um ihn noch ansprechender und informativer zu gestalten, ohne die Zeichenbegrenzung zu sprengen.";
            $existingContentContext = "**Bestehende Beschreibung:** {$existingValue}";
        } else {
            $taskInstruction = "Verfasse eine ansprechende Meta-Beschreibung für die folgende Seite.";
        }
        
        $contentSnippet = substr($context['plainTextContent'], 0, 800) . '...';

        return <<<PROMPT
        Du bist ein SEO-Texter. {$taskInstruction}
        
        **Anforderungen:**
        - Länge: Zwischen 140 und 155 Zeichen.
        - Inhalt: Fasse den Hauptnutzen der Seite zusammen und integriere das wichtigste Keyword.
        - Stil: Aktivierend, wecke Neugier. Muss in vollständigen Sätzen formuliert sein.
        
        **Analyse-Kontext:**
        - Seitentitel: {$context['pageRecord']['title']}
        - Hauptüberschrift (H1): {$context['h1']}
        - Seiteninhalt (Auszug): {$contentSnippet}
        {$existingContentContext}
        
        **Ausgabe:**
        Nur die reine, vollständige Meta-Beschreibung, ohne Anführungszeichen oder weitere Erklärungen. Du antwortest IMMER auf deutsch.
        PROMPT;
    }

    private function buildAbstractPrompt(array $context, string $existingValue, bool $allowOverride): string
    {
        $taskInstruction = '';
        $existingContentContext = '';

        if (!$allowOverride && !empty($existingValue)) {
            $taskInstruction = "Ergänze die folgende Zusammenfassung (Abstract) sinnvoll. Stelle sicher, dass das Ergebnis ein oder zwei vollständige, sachliche Sätze ergibt.";
            $existingContentContext = "**Bestehender Abstract:** {$existingValue}";
        } else {
            $taskInstruction = "Erstelle eine kurze, sachliche Zusammenfassung (Abstract) des Seiteninhalts.";
        }
        
        $contentSnippet = substr($context['plainTextContent'], 0, 800) . '...';

        return <<<PROMPT
        Du bist ein Redakteur. {$taskInstruction}
        
        **Anforderungen:**
        - Länge: Ein bis zwei Sätze, maximal 250 Zeichen.
        - Inhalt: Beschreibe den Kerninhalt der Seite objektiv und informativ.
        - Stil: Neutral und präzise. Vermeide werbliche Sprache oder Call-to-Actions. Muss in vollständigen Sätzen formuliert sein.
        
        **Analyse-Kontext:**
        - Seitentitel: {$context['pageRecord']['title']}
        - Hauptüberschrift (H1): {$context['h1']}
        - Seiteninhalt (Auszug): {$contentSnippet}
        {$existingContentContext}
        
        **Ausgabe:**
        Nur der reine, vollständige Abstract-Text, ohne Anführungszeichen oder weitere Erklärungen. Du antwortest IMMER auf deutsch.
        PROMPT;
    }

        /**
     * Stellt eine Anfrage an eine OpenAI-kompatible API mit verbessertem Logging und UTF-8-Prüfung.
     */
    private function generateTextFromApi(string $promptContent, int $maxTokens, array $logContext = []): ?string
    {
        $apiKey = $this->extensionConfiguration['apiKey'] ?? '';
        $apiUrl = $this->extensionConfiguration['apiUrl'] ?? '';
        $apiModel = $this->extensionConfiguration['apiModel'] ?? 'gpt-oss-120b';

        if (empty($apiKey) || empty($apiUrl)) {
            $this->logger->error('API key or API URL is not configured in extension settings.', $logContext);
            return null;
        }

        $systemPrompt = 'Du bist ein hilfreicher SEO-Assistent und folgst den Anweisungen exakt.';
        $payload = [
            'model' => $apiModel,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $promptContent]
            ],
            'temperature' => 1,
            'max_completion_tokens' => $maxTokens,
        ];

        // DIAGNOSE: Manuelle Prüfung VOR dem Senden an Guzzle, um den fehlerhaften String zu finden.
        foreach ($payload['messages'] as $index => $message) {
            if (isset($message['content']) && is_string($message['content']) && !mb_check_encoding($message['content'], 'UTF-8')) {
                $this->logger->critical(
                    'FATAL: Malformed UTF-8 string detected in payload before json_encode. Aborting API call.',
                    array_merge($logContext, [
                        'problem_location' => sprintf('messages[%d][content]', $index),
                        'problem_role' => $message['role'],
                        'problematic_content_base64' => base64_encode($message['content'])
                    ])
                );
                return null; // Wichtig: Funktion abbrechen, damit der Guzzle-Fehler gar nicht erst auftritt.
            }
        }
        
        $maxAttempts = 3;
        $attemptDelaySeconds = 2;
        $client = GeneralUtility::makeInstance(Client::class);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->logger->debug(sprintf('Sending AI API request (Attempt %d/%d)', $attempt, $maxAttempts), $logContext);
                $response = $client->post($apiUrl, [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
                    'json' => $payload,
                    'timeout' => 90,
                ]);

                $responseBody = (string)$response->getBody();
                $responseData = json_decode($responseBody, true);

                if (isset($responseData['error'])) {
                    $this->logger->error('AI API returned an error in the response body.', array_merge($logContext, ['apiError' => $responseData['error']]));
                    return null;
                }

                $content = $responseData['choices'][0]['message']['content'] ?? null;
                if ($content === null) {
                    $this->logger->warning('AI API response did not contain the expected content.', array_merge($logContext, ['responseBody' => $responseBody]));
                    return null;
                }
                
                return trim($content, " \t\n\r\0\x0B.\"");

            } catch (RequestException $e) {
                $errorContext = array_merge($logContext, ['exceptionMessage' => $e->getMessage()]);
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $errorContext['statusCode'] = $response->getStatusCode();
                    $errorContext['responseBody'] = (string)$response->getBody();
                }
                $this->logger->error(sprintf('Guzzle HTTP error during AI API request (Attempt %d/%d)', $attempt, $maxAttempts), $errorContext);
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Generic error during AI API request (Attempt %d/%d): %s', $attempt, $maxAttempts, $e->getMessage()), array_merge($logContext, ['exception' => $e]));
            }

            if ($attempt < $maxAttempts) {
                $this->logger->warning(sprintf('Waiting %d seconds before retrying...', $attemptDelaySeconds), $logContext);
                sleep($attemptDelaySeconds);
            }
        }

        $this->logger->critical('AI API request failed after all attempts. Giving up.', $logContext);
        return null;
    }

    /**
     * REPARATUR: Bereinigt einen String robust, um sicherzustellen, dass er gültiges UTF-8 ist.
     * Verwendet iconv, um ungültige Byte-Sequenzen einfach zu ignorieren und zu entfernen.
     */
    private function sanitizeUtf8(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // 1. Zuerst HTML-Entitäten dekodieren. Dies wandelt z.B. &amp; in & um.
        // ENT_QUOTES stellt sicher, dass auch ' und " behandelt werden.
        // false am Ende verhindert double encoding.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 2. Ersetze unsichtbare Steuerzeichen und ungültige Zeichen, die oft Probleme machen.
        // Regex \p{C} passt auf alle "Control Characters".
        $text = preg_replace('/[\x00-\x1F\x7F\p{C}]/u', '', $text);

        // 3. Verwende iconv, um alle verbleibenden ungültigen Byte-Sequenzen zu verwerfen.
        // Dies ist der stärkste Schritt.
        $sanitizedText = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        // 4. Fallback, falls iconv fehlschlägt.
        if ($sanitizedText === false) {
             // mb_convert_encoding ist ein guter Fallback.
             return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return $sanitizedText;
    }
}
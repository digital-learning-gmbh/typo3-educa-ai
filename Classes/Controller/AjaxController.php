<?php
namespace EducaAiTypo3Seo\EducaAiTypo3Seo\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use EducaAiTypo3Seo\EducaAiTypo3Seo\Service\SeoCalculationService;

class AjaxController
{
    public function __construct(
        private readonly SeoCalculationService $seoCalculationService,
    ) {
    }

    /**
     * AJAX Action to trigger the SEO calculation for a specific page.
     *
     * This action is called from the TYPO3 backend via JavaScript. It expects
     * a POST request with a JSON body.
     *
     * Example Body:
     * {
     *   "page": 123,
     *   "override": "true" // or "false"
     * }
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function calculateAction(ServerRequestInterface $request): ResponseInterface
    {
        // 1. Get parameters from the request body
        $parsedBody = $request->getParsedBody();
        $pageId = (int)($parsedBody['page'] ?? 0);
        
        // The 'override' parameter from JavaScript will determine if existing fields are overwritten.
        // We safely cast the string 'true' to a boolean true, everything else becomes false.
        $allowOverride = ($parsedBody['override'] ?? 'false') === 'true';

        if ($pageId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Ungültige Seiten-ID übermittelt.'], 400);
        }

        // 3. Execute the service logic with the provided parameters.
        // The second parameter 'true' instructs the service to directly update the database.
        $success = $this->seoCalculationService->calculateForPage($pageId, true, $allowOverride);

        if ($success) {
            // 4. Get the result from the service. This is now an array of all generated fields.
            $generatedData = $this->seoCalculationService->getLastGeneratedData();

            if (!empty($generatedData)) {
                $fieldCount = count($generatedData);
                $message = sprintf(
                    '%d SEO-Feld(er) erfolgreich per KI aktualisiert!',
                    $fieldCount
                );

                // Send the generated data back to the JavaScript.
                // The key 'data' must be used in the JS to populate the form fields.
                // It returns an object like {seo_title: "...", description: "...", keywords: "..."}
                return new JsonResponse([
                    'success' => true,
                    'message' => $message,
                    'data' => $generatedData
                ]);
            }

            // This case happens if the operation was successful, but no fields needed an update
            // (e.g., all were filled and allowOverride was false).
            return new JsonResponse([
                'success' => true,
                'message' => 'Keine Felder zum Aktualisieren. Alle Felder waren bereits befüllt.',
                'data' => [] // Send empty data object
            ]);
        }

        // 5. Handle general calculation failures (e.g., API communication error)
        return new JsonResponse(['success' => false, 'message' => 'Ein Fehler ist bei der Kommunikation mit der KI-API aufgetreten.'], 500);
    }
}
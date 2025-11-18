<?php
namespace EducaAiTypo3Seo\EducaAiTypo3Seo\Command;

use EducaAiTypo3Seo\EducaAiTypo3Seo\Service\SeoCalculationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

class SeoCalculationCommand extends Command
{
    /**
     * @var SeoCalculationService
     */
    private readonly SeoCalculationService $seoCalculationService;

    /**
     * @var ConnectionPool
     */
    private readonly ConnectionPool $connectionPool;

    public function __construct(SeoCalculationService $seoCalculationService, ConnectionPool $connectionPool)
    {
        parent::__construct();
        $this->seoCalculationService = $seoCalculationService;
        $this->connectionPool = $connectionPool;
    }

    /**
     * Konfiguriert den Befehl
     */
    protected function configure(): void
    {
        $this->setName('educa-ai:seo:calculate');
        $this->setHelp('Berechnet SEO-Daten für eine gegebene Startseite und alle ihre Unterseiten rekursiv. Standardmäßig im Dry-Run-Modus und ergänzt vorhandene Felder.');
        $this->setDescription('Startet die SEO-Berechnung für einen Seitenbaum.');

        $this->addArgument(
            'rootPageId',
            InputArgument::REQUIRED,
            'Die UID der Startseite, ab der die Berechnung beginnen soll.'
        );

        $this->addOption(
            'force', // Name der Option
            'f',     // Optionaler Shortcut (z.B. -f)
            InputOption::VALUE_NONE, // Es ist ein Flag, kein Wert wird erwartet
            'Führt die Änderungen tatsächlich in der Datenbank durch (deaktiviert den Dry-Run-Modus).'
        );

        // --- NEUE OPTION FÜR ALLOW-OVERRIDE ---
        $this->addOption(
            'allow-override', // Name der Option
            'o',              // Optionaler Shortcut
            InputOption::VALUE_NONE, // Es ist ein Flag
            'Erlaubt das Überschreiben bereits gefüllter SEO-Felder. Standardmäßig werden Felder intelligent ergänzt.'
        );
    }

    /**
     * Führt den Befehl aus
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rootPageId = (int)$input->getArgument('rootPageId');
        $isForceMode = (bool)$input->getOption('force');
        $isAllowOverride = (bool)$input->getOption('allow-override'); // <-- NEUE OPTION LESEN

        if ($rootPageId <= 0) {
            $io->error('Ungültige rootPageId angegeben. Bitte geben Sie eine positive Zahl an.');
            return Command::FAILURE;
        }

        $io->title('Starte SEO-Datenberechnung');
        $io->writeln('Startseite: ' . $rootPageId);
        $io->newLine();

        if ($isForceMode) {
            $io->warning('FORCE MODUS: Änderungen werden in die Datenbank geschrieben!');
        } else {
            $io->note('DRY RUN MODUS: Es werden keine Daten in die Datenbank geschrieben. Benutze die --force Option, um Änderungen zu speichern.');
        }

        // --- ZUSÄTZLICHE INFORMATION ÜBER DEN ÜBERSCHREIB-MODUS ---
        if ($isAllowOverride) {
            $io->warning('ÜBERSCHREIB-MODUS: Bereits gefüllte Felder werden von der KI KOMPLETT NEU generiert!');
        } else {
            $io->note('ERGÄNZUNGS-MODUS: Bereits gefüllte Felder werden von der KI INTELLIGENT ERGÄNZT oder optimiert (Standard).');
        }
        $io->newLine();


        try {
            // Starte den rekursiven Prozess und übergebe alle Modi
            $this->processPageAndSubpages($rootPageId, $io, $isForceMode, $isAllowOverride);
        } catch (\Exception $e) {
            $io->error('Ein unerwarteter Fehler ist aufgetreten: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('SEO-Datenberechnung für alle Seiten erfolgreich abgeschlossen.');

        return Command::SUCCESS;
    }

    /**
     * Verarbeitet eine einzelne Seite und ruft sich selbst für alle Unterseiten auf.
     *
     * @param int $pageId Die UID der zu verarbeitenden Seite
     * @param SymfonyStyle $io Das I/O-Objekt für die Konsolenausgabe
     * @param bool $isForceMode Wenn true, werden Daten in die DB geschrieben
     * @param bool $isAllowOverride Wenn true, werden bereits gefüllte Felder komplett neu generiert.
     */
    private function processPageAndSubpages(int $pageId, SymfonyStyle $io, bool $isForceMode, bool $isAllowOverride): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $pageRecord = $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)))
            ->andWhere($queryBuilder->expr()->eq('hidden', 0))
            ->andWhere($queryBuilder->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchAssociative();

        if ($pageRecord === false) {
            $io->warning(sprintf('Seite mit UID %d nicht gefunden oder nicht sichtbar. Wird übersprungen.', $pageId));
            return;
        }

        $io->writeln(sprintf('-> Verarbeite Seite: "%s" (UID: %d)', $pageRecord['title'], $pageId));

        // Den Service aufrufen und den $isForceMode als 'update'-Flag und $isAllowOverride übergeben
        $success = $this->seoCalculationService->calculateForPage($pageId, $isForceMode, $isAllowOverride);

        if ($success) {
            $generatedData = $this->seoCalculationService->getLastGeneratedData(); // <-- geänderte Methode auf dem Service
            
            if (!empty($generatedData)) {
                $io->comment('   Generierte/Aktualisierte Daten:');
                foreach ($generatedData as $fieldName => $value) {
                    $action = ($isAllowOverride || empty(trim($pageRecord[$fieldName] ?? ''))) ? 'generiert' : 'ergänzt/optimiert';
                    $io->writeln(sprintf('      - %s (%s): %s', $fieldName, $action, $value));
                }
            } else {
                $io->writeln('   => Keine neuen Daten generiert oder vorhandene sind aktuell.');
            }
        } else {
            $io->warning(sprintf('   => Berechnung für Seite %d fehlgeschlagen.', $pageId));
        }

        $subpages = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)))
            ->andWhere($queryBuilder->expr()->eq('hidden', 0))
            ->andWhere($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($subpages as $subpage) {
            // Wichtig: Die Modi an die rekursiven Aufrufe weitergeben
            $this->processPageAndSubpages((int)$subpage['uid'], $io, $isForceMode, $isAllowOverride);
        }
    }
}
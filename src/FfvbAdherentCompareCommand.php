<?php

namespace NblCalendar;

use Goutte\Client;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;

class FfvbAdherentCompareCommand extends SymfonyCommand
{

    public function __construct()
    {
        $dotenv = new Dotenv();
        $dotenv->load(__DIR__ . '/../.env');

        parent::__construct();
    }

    public function configure()
    {
        $this->setName('ffvbadherent:compare')
            ->setDescription("Compare les adhérents saisis dans Kalisport et sur le site de la FFVB, à partir des fichiers d'export Kalisport & FFVB.")
            ->addArgument('ffvb-export', InputArgument::REQUIRED, 'Export FFVB')
            ->addArgument('kalisport-export', InputArgument::REQUIRED, 'Export Kalisport')
            ->addOption('kalisport-no-licence', null, InputOption::VALUE_NONE, 'Retrouve les adhérents Kalisport sans numéro de licence FFVB.');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Comparaison de la liste des licenciés source FFVB / Kalisport:");
        $ffvbLicencies = $kalisportLicencies = [];

        $row = 1;
        if (($handle = fopen($input->getArgument('ffvb-export'), "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                if ($row === 1) {
                    // Vérification du type du fichier source : nombre de colonnes et entêtes colonnes
                    if (count($data) != 70 or $data[0] !== 'lnumlic' or $data[1] !== 'Nom' or $data[2] !== 'Prenom' or $data[4] != 'Categorie') {
                        $output->writeln("<error>Fichier source ne semble pas être un export de licenciés de la FFVB !</error>");
                        exit;
                    }
                    $row++;
                    continue;
                }

                // Traitement des lignes de données
                $keys = array(
                    'licence'   => 0,
                    'nom'       => 1,
                    'prenom'    => 2,
                    'categorie' => 4,
                );
                // Récupération des licenciés sources FFVB
                foreach ($keys as $key => $index) {
                    if (!empty($data[0])) {
                        $ffvbLicencies[$data[$keys['licence']]][$key] = $data[$index];
                    }
                }
                $row++;
            }
            fclose($handle);
        }

        $row = 1;
        if (($handle = fopen($input->getArgument('kalisport-export'), "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row === 1) {
                    // Vérification du type du fichier source : nombre de colonnes et entêtes colonnes
                    if (count($data) != 91 or $data[28] !== 'NUM_LICENCE' or $data[1] !== 'NOM' or $data[2] !== 'PRENOM') {
                        $output->writeln("<error>Fichier source ne semble pas être un export de licenciés de la FFVB !</error>");
                        exit;
                    }
//                    print_r($data);

                    $row++;
                    continue;
                }
                $keys = array(
                    'licence' => 28,
                    'nom'     => 1,
                    'prenom'  => 2,
                );

                foreach ($keys as $key => $index) {
                    if (!empty($data[0])) {
                        $kalisportLicencies[$data[$keys['licence']]][$key] = $data[$index];
                    }
                }
                $row++;
            }
            fclose($handle);
        }

        // Build diff arrays
        $ffvbLicencesWithNoKalisport = array_diff_key($ffvbLicencies, $kalisportLicencies);
        $kalisportLicenciesWithNoLicence = array_diff_key($kalisportLicencies, $ffvbLicencies);

        if ($input->getOption('kalisport-no-licence')) {
            // Retrieve licencie with no num licence
            $noNumLicence = [];
            foreach ($kalisportLicencies as $licencie) {
                if (empty($licencie['licence'])) {
                    $noNumLicence[] = $licencie;
                }
            }

            if (!empty($noNumLicence)) {
                // Write file
                $filename = './output/Kalisport-with-no-licence-' . date('Ymd-His') . '.csv';
                $handle = fopen($filename, 'w');
                $firstRow = true;
                foreach ($noNumLicence as $unknownLicence) {
                    if ($firstRow) {
                        // Add entête
                        fputcsv($handle, array_keys($unknownLicence), ';');
                        $firstRow = false;
                    }
                    // On n'écrit pas le club géré dans les clubs adverses
                    fputcsv($handle, $unknownLicence, ';');
                }
                fclose($handle);
                $output->writeln('<info>Fichier des adhérent Kalisport sans numéro de licence (' . $filename . ').</info>');
            }
        }

        // Write files
        // Write ffvbLicencesWithNoKalisport
        $filename = './output/FFVB-licences-with-no-Kalisport-' . date('Ymd-His') . '.csv';
        $handle   = fopen($filename, 'w');
        $firstRow = true;
        foreach ($ffvbLicencesWithNoKalisport as $unknownLicence) {
            if ($firstRow) {
                // Add entête
                fputcsv($handle, array_keys($unknownLicence), ';');
                $firstRow = false;
            }
            // On n'écrit pas le club géré dans les clubs adverses
            fputcsv($handle, $unknownLicence, ';');
        }
        fclose($handle);
        $output->writeln('<info>Fichier des licenciés FFVB absent de Kalisport généré ('.$filename.').</info>');

        // Write $kalisportLicenciesWithNoLicence
        $filename = './output/Kalisport-with-no-FFVB-licence-' . date('Ymd-His') . '.csv';
        $handle   = fopen($filename, 'w');
        $firstRow = true;
        foreach ($kalisportLicenciesWithNoLicence as $unknownLicence) {
            if ($firstRow) {
                // Add entête
                fputcsv($handle, array_keys($unknownLicence), ';');
                $firstRow = false;
            }
            // On n'écrit pas le club géré dans les clubs adverses
            fputcsv($handle, $unknownLicence, ';');
        }
        fclose($handle);
        $output->writeln('<info>Fichier des adhérents Kalisport sans licence FFVB ('.$filename.').</info>');
    }
}

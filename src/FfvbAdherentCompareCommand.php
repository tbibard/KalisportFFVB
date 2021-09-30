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
            ->addOption('kalisport-no-licence', null, InputOption::VALUE_NONE, 'Retrouve les adhérents sans numéro de licence FFVB.');
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
                    if (count($data) != 69 or $data[0] !== 'lnumlic' or $data[1] !== 'Nom' or $data[2] !== 'Prenom') {
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
                        $ffvbLicencies[$data[0]][$key] = $data[$index];
                    }
                }
                $row++;
            }
            fclose($handle);
        }
//        print_r($ffvbLicencies);
//        exit;

        $row = 1;
        if (($handle = fopen($input->getArgument('kalisport-export'), "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row === 1) {
                    // Vérification du type du fichier source : nombre de colonnes et entêtes colonnes
                    if (count($data) != 74 or $data[18] !== 'NUM_LICENCE' or $data[1] !== 'NOM' or $data[2] !== 'PRENOM') {
                        $output->writeln("<error>Fichier source ne semble pas être un export de licenciés de la FFVB !</error>");
                        exit;
                    }
//                    print_r($data);

                    $row++;
                    continue;
                }
                $keys = array(
                    'licence' => 18,
                    'nom'     => 1,
                    'prenom'  => 2,
                );

                // Traitement des lignes de données
                // Récupération des licenciés sources Kalisport
                if ($input->getOption('kalisport-no-licence')) {
                    $mappedKey = 0;
                } else {
                    $mappedKey = 0;
                }

                foreach ($keys as $key => $index) {
                    $kalisportLicencies[$data[$mappedKey]][$key] = $data[$index];

                }
                $row++;
            }
            fclose($handle);

        }

        if ($input->getOption('kalisport-no-licence')) {
            // Retrieve licencie with no num licence
            $noNumLicence = [];
            foreach ($kalisportLicencies as $id => $licencie) {
                if (empty($licencie['licence'])) {
                    $noNumLicence[] = $licencie;
                }
            }
            print_r($noNumLicence);
            exit;
        }

//        print_r($kalisportLicencies);
//        print_r($ffvbLicencies);

        // Retrieve licencie from FFVB with licence is unknown in Kalisport
        $unknownLicences = [];
        foreach ($ffvbLicencies as $num => $licencie) {
            if (!array_key_exists($num, $kalisportLicencies)) {
                $unknownLicences[] = $licencie;
            }
        }

        // Write file
        $handle   = fopen('./output/FFVB-licence-Kalisport-' . date('Ymd-His') . '.csv', 'w');
        $firstRow = true;
        foreach ($unknownLicences as $unknownLicence) {
            if ($firstRow) {
                // Add entête
                fputcsv($handle, array_keys($unknownLicence), ';');
                $firstRow = false;
            }
            // On n'écrit pas le club géré dans les clubs adverses
            fputcsv($handle, $unknownLicence, ';');
        }
        fclose($handle);
        $output->writeln('<info>Fichier des licenciés FFVB absent de Kalisport généré.</info>');
    }
}    

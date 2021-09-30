<?php

namespace NblCalendar;

use Goutte\Client;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;

class FfvbCalendrierBuildClubAdversesCommand extends SymfonyCommand
{

    public function __construct()
    {
        $dotenv = new Dotenv();
        $dotenv->load(__DIR__ . '/../.env');

        parent::__construct();
    }

    public function configure()
    {
        $this->setName('ffvbcalendrier:build')
            ->setDescription("Construit un fichier d'import de clubs adverses et un fichier de programme à partir d'un export de calendrier de la FFVB.")
            ->setHelp('
Structure du fichier de la FFVB:
    1 => Numéro de la journée
    2 => Identifiant FFVB du match dans le championnat
    3 => Date du match
    4 => Heure du match
    5 => Identifiant FFVB du club recevant
    6 => Nom du club recevant
    7 => Identifiant FFVB du club visiteur
    8 => Nom du club visiteur
    12 => Nom de la salle         
    13 => Set
    14 => Score
            ')
            ->addArgument('filename', InputArgument::REQUIRED, 'filename')
            ->addArgument('equipe', InputArgument::REQUIRED, 'code equipe Kalisport')
            ->addOption('division', null, InputOption::VALUE_REQUIRED, 'Libellé de la division/championnat')
            ->addOption('ffvb-equipe', null, InputOption::VALUE_REQUIRED, 'Si deux équipes du club dans le même championnat, 
                identifie le nom de l\'équipe côté ffvb')
            ->addOption('only-adverses', null, InputOption::VALUE_NONE, "build uniquement le fichier des équipes adverses");
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Construction d'un fichier d'import de Clubs adverses:");

        $clubs = [];
        $row   = 1;
        if (($handle = fopen($input->getArgument('filename'), "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                if ($row === 1) {
                    // Vérification du type du fichier source : nombre de colonnes et entêtes colonnes
                    if (count($data) != 16 or $data[5] !== 'EQA_no' or $data[7] !== 'EQB_no') {
                        $output->writeln("<error>Fichier source ne semble pas être un export de calendrier de la FFVB !</error>");
                        exit;
                    }

                    $row++;
                    continue;
                }

                // Traitement des lignes de données
                // Récupération des identifiants de club
                // même pour le club géré besoin pour calendrier avec 2 équipes du club dans même championnat
                foreach ([5, 7] as $key) {
                    if (!empty($data[$key]) and $data[$key] != '671556') {
                        $clubs[$data[$key]] = $data[$key];
                    }
                }
                $row++;
            }
            fclose($handle);
        }

        // Build export file
        if (!empty($clubs)) {
            $clubsAdversesDatas = [];

            $output->writeln('- récupération des infos pour les '.count($clubs).' clubs:');
            foreach ($clubs as $club) {
                $clubInfos = $this->getClubInfosById($club);

                $clubsAdversesDatas[$clubInfos['id']] = [
                    'ID'                    => '',
                    'NOM'                   => $clubInfos['nom'],
                    'ABREVIATION'           => $this->buildClubAbbreviation($clubInfos['nom']),
                    'NUMERO_FEDERAL'        => $clubInfos['id'],
                    'COULEUR1'              => '',
                    'COULEUR2'              => '',
                    'SITE_INTERNET'         => $clubInfos['website'],
                    'FACEBOOK'              => '',
                    'TWITTER'               => '',
                    'VISIBLE_SITE_INTERNET' => '',
                    'ADRESSE1'              => '',
                    'ADRESSE2'              => '',
                    'CODE_POSTAL'           => '',
                    'VILLE'                 => '',
                    'PAYS'                  => 'France',
                    'REGION'                => $clubInfos['ligue'],
                    'DEPARTEMENT'           => $clubInfos['comite'],
                    'EMAIL'                 => $clubInfos['mail'],
                    'TELEPHONE'             => $clubInfos['portable'],
                    'LATITUDE'              => '',
                    'LONGITUDE'             => '',
                    'TEMPS_TRAJET'          => '',
                    'INFOS_COMPLEMENTAIRES' => 'Correspondant: '.$clubInfos['correspondant'],
                ];
                $output->writeln('-- club '.$club.': '.$clubInfos['nom']);
            }

            // Write import file
            $handle = fopen('./output/import-clubs-adverses-'.date('Ymd-His').'.csv', 'w');
            $firstRow = true;
            foreach ($clubsAdversesDatas as $clubAdverseData) {
                if ($firstRow) {
                    // Add entête
                    fputcsv($handle, array_keys($clubAdverseData), ';');
                    $firstRow = false;
                }
                // On n'écrit pas le club géré dans les clubs adverses
                if ($clubAdverseData['NUMERO_FEDERAL'] != getenv('FFVB_CLUB_ID')) {
                    fputcsv($handle, $clubAdverseData, ';');
                }
            }
            fclose($handle);
            $output->writeln('<info>Fichier des clubs adverses générés.</info>');

            if ($input->getOption('only-adverses')) {
                exit;
            }

            // Write calendrier
            $output->writeln('Gestion du calendrier:');
            $matchDatas = [];
            $row        = 1;
            if (($handle = fopen($input->getArgument('filename'), "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                    if ($row === 1) {
                        $row++;
                        continue;
                    }

                    $lieu = $evenement = $clubAdverseId = null;

                    // Traitement des lignes de données
                    // Check si ligne correspond à un match avec une équipe appartenant au club géré
                    if (!empty($data[5]) and !empty($data[7]) and $data[5] != '671556' and $data[7] != '671556' and 
                        ($data[5] == getenv('FFVB_CLUB_ID') or $data[7] == getenv('FFVB_CLUB_ID'))) {
                        // Vérifie si match match entre deux équipes du même club
                        if ($data[5] == getenv('FFVB_CLUB_ID') and $data[7] == getenv('FFVB_CLUB_ID')
                            and empty($input->getOption('ffvb-equipe'))) {
                            $output->writeln('<error>Match entre deux équipes du même club, vous devez indiquer le nom ffvb de votre équipe (option ffvb-equipe) !</error>');
                            exit;
                        }

                        // Check si match entre deux équipes du même club
                        if ($data[5] == getenv('FFVB_CLUB_ID') and $data[7] == getenv('FFVB_CLUB_ID')) {
                            // Check si ffvb-equipe apparait dans l'un des deux clubs opposés
                            if (strtoupper($input->getOption('ffvb-equipe')) != strtoupper($data[6]) and
                                strtoupper($input->getOption('ffvb-equipe')) != strtoupper($data[8])) {
                                continue;
                            }

                            if (strtoupper($input->getOption('ffvb-equipe')) == strtoupper($data[6])) {
                                // Match à domicile
                                $lieu          = 'Domicile';
                                $evenement     = $data[8];
                                $clubAdverseId = $data[7];
                            } else {
                                // Match extérieur
                                $lieu          = 'Extérieur';
                                $evenement     = $data[6];
                                $clubAdverseId = $data[5];
                            }
                        } else {
                            // Cas ou une seule équipe du club participe à la rencontre
                            if (!empty($input->getOption('ffvb-equipe'))) {
                                // On élimine un match avec une autre équipe du club dans le même championnat (géré ci-dessus)
                                if ($data[5] == getenv('FFVB_CLUB_ID') and strtoupper($data[6]) != strtoupper($input->getOption('ffvb-equipe')) or 
                                    $data[7] == getenv('FFVB_CLUB_ID') and strtoupper($data[8]) != strtoupper($input->getOption('ffvb-equipe'))) {
                                    continue;
                                }
                            }
                            
                            // Match entre deux clubs différents
                            if ($data[5] == getenv('FFVB_CLUB_ID')) {
                                // Match à domicile
                                $lieu          = 'Domicile';
                                $evenement     = $data[8];
                                $clubAdverseId = $data[7];
                            } else {
                                // Match extérieur
                                $lieu          = 'Extérieur';
                                $evenement     = $data[6];
                                $clubAdverseId = $data[5];
                            }
                        }

                        $dateHeureMatch = \DateTime::createFromFormat('Y-m-d H:i', $data[3] . ' ' . $data[4]);

                        // Build match data
                        $matchDatas[$data[2]] = [
                            'EQUIPE'                   => $input->getArgument('equipe'),
                            'EVENEMENT'                => utf8_encode($evenement),
                            'TYPE_EVENEMENT'           => 'Match de championnat',
                            'CLUB_ADVERSE'             => $clubsAdversesDatas[$clubAdverseId]['NOM'],
                            'NUMERO_JOURNEE'           => $data[1],
                            'NUMERO_RENCONTRE'         => $data[2],
                            'DIVISION'                 => $input->getOption('division'),
                            'INFO_COMPLEMENTAIRE'      => '',
                            'DATE'                     => $dateHeureMatch->format('d/m/Y'),
                            'LIEU'                     => $lieu,
                            'LIEU_PRECISION'           => utf8_encode($data[12]),
                            'PLANIFIE'                 => 1,
                            'HEURE_RDV'                => '',
                            'HEURE_DEBUT'              => $dateHeureMatch->format('H:i'),
                            'HEURE_FIN'                => '',
                            'SCORE_LOCAUX'             => '',
                            'SCORE_VISITEURS'          => '',
                            'SCORE_PERIODE1_LOCAUX'    => '',
                            'SCORE_PERIODE1_VISITEURS' => '',
                            'SCORE_PERIODE2_LOCAUX'    => '',
                            'SCORE_PERIODE2_VISITEURS' => '',
                            'SCORE_PERIODE3_LOCAUX'    => '',
                            'SCORE_PERIODE3_VISITEURS' => '',
                            'SCORE_PERIODE4_LOCAUX'    => '',
                            'SCORE_PERIODE4_VISITEURS' => '',
                            'SCORE_OT_LOCAUX'          => '',
                            'SCORE_OT_VISITEURS'       => '',
                        ];

                        // Gestion des scores si défini
                        if (!empty($data[9])) {
                            $sets = explode('/', $data[9]);
                            if (count($sets) == 2) {
                                $matchDatas[$data[2]]['SCORE_LOCAUX']    = trim($sets[0]);
                                $matchDatas[$data[2]]['SCORE_VISITEURS'] = trim($sets[1]);
                            }
                        }
                        $output->writeln('- Journée '.$data[1].' / match: '.$data[2].' => '.utf8_encode($evenement).' le '.$dateHeureMatch->format('d/m/Y').' à '.$dateHeureMatch->format('H:i'));
                    }
                    $row++;
                }
                fclose($handle);

                // Write matchs datas
                if (empty($input->getOption('ffvb-equipe'))) {
                    $handle = fopen('./output/import-calendrier-'.date('Ymd-His').'-' . $input->getArgument('equipe') . '.csv', 'w');
                } else {
                    $handle = fopen('./output/import-calendrier-'.date('Ymd-His').'-' . $input->getArgument('equipe').
                        '-'.str_replace(' ', '-', $input->getOption('ffvb-equipe')).'.csv', 'w');
                }
                $firstRow = true;
                foreach ($matchDatas as $matchData) {
                    if ($firstRow) {
                        // Add entête
                        fputcsv($handle, array_keys($matchData), ';');
                        $firstRow = false;
                    }
                    fputcsv($handle, $matchData, ';');
                }
                fclose($handle);
                $output->writeln('<info>Fichier du programme/calendrier de l\'équipe généré.</info>');
            }
        }
    }

    private function getClubInfosById($id)
    {
        $infos = [
            'id'           => $id,
            'nom'          => '',
            'ligue'        => '',
            'comite'       => '',
            'weblink_ffvb' => 'http://www.ffvbbeach.org/ffvbapp/adressier/rech_aff.php?id_club=' . $id,
            'portable'     => '',
            'mail'         => '',
            'website'      => '',
        ];

        $client  = new Client();
        $crawler = $client->request('GET', 'http://www.ffvbbeach.org/ffvbapp/adressier/rech_aff.php?id_club=' . $id);

        // Retrieve Club Name
        $data         = trim($crawler->filter('table td.titreblanc_gd div')->text());
        $infos['nom'] = str_replace($id . ' ', '', $data);

        // Retrieve coordonnées
        $tableDatas = $crawler->filter('table')->eq(4);
        switch(count($tableDatas->filter('tr'))) {
            case 4:
                $infos['portable'] = $tableDatas->filter('td')->eq(2)->text();
                $infos['mail']     = $tableDatas->filter('td')->eq(4)->text();
                $infos['website']  = $tableDatas->filter('td')->eq(6)->text();
            break;

            case 5:
                $infos['portable'] = $tableDatas->filter('td')->eq(4)->text();
                $infos['mail']     = $tableDatas->filter('td')->eq(6)->text();
                $infos['website']  = $tableDatas->filter('td')->eq(8)->text();
            break;

            case 6:
                $infos['portable'] = $tableDatas->filter('td')->eq(4)->text();
                $infos['mail']     = $tableDatas->filter('td')->eq(8)->text();
                $infos['website']  = $tableDatas->filter('td')->eq(10)->text();
            break;
        }

        // Retrieve Correspondant
        $tableDatas = $crawler->filter('table')->eq(6);
        $infos['correspondant'] = $tableDatas->filter('td')->eq(2)->text();

        // Retrieve couleur du club
        // $crawler->filter('table td.titrearticle div')->each(function($node) {
        //     echo str_replace('Couleurs du club:', '', trim($node->text()))."\n";
        // });

        // Retrieve Ligue / Comite
        $data            = $crawler->filter('table td.liensuite4_gd');
        if (count($data->eq(1)) != 0) {
            $infos['ligue']  = $data->eq(1)->text();
        }
        if (count($data->eq(3)) != 0) {
            $infos['comite'] = $data->eq(3)->text();
        }

        return $infos;
    }

    private function buildClubAbbreviation($clubName)
    {
        $abbr = '';

        $skipWords = ['AS', 'A.S.', 'VOLLEY', 'VOLLEY-BALL', 'BEACH-BALL', 'V.B.', 'VB', 'CLUB', 'MUNICIPALE', 'SPORT'];
        $clubParts = explode(' ', str_replace("'", '', $clubName));

        foreach ($clubParts as $key => $clubPart) {
            if (in_array($clubPart, $skipWords)) {
                unset($clubParts[$key]);
            }
        }

        if (count($clubParts) > 2) {
            foreach ($clubParts as $clubPart) {
                $abbr .= strtoupper(substr($clubPart, 0, 1));
            }
        } elseif (count($clubParts) > 1) {
            foreach ($clubParts as $clubPart) {
                $abbr .= strtoupper(substr($clubPart, 0, 2));
            }
        } else {
            foreach ($clubParts as $clubPart) {
                $abbr .= strtoupper(substr($clubPart, 0, 4));
            }
        }

        return $abbr;
    }
}    

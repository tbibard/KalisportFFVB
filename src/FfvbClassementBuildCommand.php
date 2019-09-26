<?php

namespace NblCalendar;

use Goutte\Client;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Dotenv\Dotenv;

class FfvbClassementBuildCommand extends SymfonyCommand
{
    protected $saison = null;
    protected $codent = null;
    protected $poule = null;

    protected $classementBaseUrl = 'http://www.ffvbbeach.org/ffvbapp/resu/vbspo_calendrier.php';

    public function __construct()
    {
        $dotenv = new Dotenv();
        $dotenv->load(__DIR__ . '/../.env');

        parent::__construct();
    }

    public function configure()
    {
        $this->setName('ffvbclassement:build')
            ->setDescription("Crawl le site de la FFVB à partir de certaines options et retourne un tableau html représentant le classement d'une équipe.")
            ->setHelp('')
            ->addArgument('codent', InputArgument::REQUIRED, 'Variable codent de la FFVB')
            ->addArgument('poule', InputArgument::REQUIRED, 'Variable poule de la FFVB')
            ->addOption('saison', null, InputOption::VALUE_REQUIRED, 'Saison')
            ->addOption('keep-all', true, InputOption::VALUE_NONE, 'Conserver toutes les colonnes');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('saison')) {
            $this->saison = $input->getOption('saison');
        } else {
            if ((int) date('n') >= 9 ) {
                $this->saison = date('Y').'/'.(date('Y')+1);
            } else {
                $this->saison = (date('Y')-1).'/'.date('Y');
            }
        }

        $classementUri = $this->classementBaseUrl.'?saison='.$this->saison.'&codent='.$input->getArgument('codent').
            '&poule='.$input->getArgument('poule');

        $client  = new Client();
        $crawler = $client->request('GET', $classementUri);

        // Retrieve the third table in the html
        $data = $crawler->filter('table')->eq(2);

        // Add minimal config to HTMLPurifier
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'table,tr,td');
        $purifier = new \HTMLPurifier($config);

        // Purify html
        $clean = $purifier->purify('<table>'.$data->html().'</table>');
        $html = str_replace("\n", '', $clean);;

        $doc = new \DomDocument();
        $doc->loadHTML($html);

        if (!$input->getOption('keep-all')) {
            // Build td index for column to remove
            $tdToRemove = [];
            foreach ($doc->getElementsByTagName('tr')[0]->getElementsByTagName('td') as $key => $td) {
                if (in_array($td->nodeValue, ['Set.P', 'Set.C', 'Pts.P', 'Pts.C', 'Coeff.S', 'Coeff.P'])) {
                    array_push($tdToRemove, $key);
                }
            }

            foreach ($doc->getElementsByTagName('tr') as $tr) {
                foreach ($tdToRemove as $key => $index) {
                    $td = $tr->getElementsByTagName('td')->item($index - $key);
                    $tr->removeChild($td);
                }
            }
        }
        
        echo str_replace("\n", "", $doc->saveHTML($doc->getElementsByTagName('table')->item(0)))."\n";
    }
}

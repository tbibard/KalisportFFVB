#!/usr/bin/env php

<?php
require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use NblCalendar\FfvbCalendrierBuildClubAdversesCommand;

$app = new Application('FfvbToKalisport App', 'v0.0.1');
$app->add(new FfvbCalendrierBuildClubAdversesCommand());
$app->run();
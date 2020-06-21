<?php declare(strict_types = 1);

require_once __DIR__ . '/vendor/autoload.php';

$config = \Nette\Neon\Neon::decode(\Nette\Utils\FileSystem::read(__DIR__ . '/config.neon'));

$app = new \Symfony\Component\Console\Application();
$app->add(new \FakturoidInvoiceGenerator\GenerateInvoiceCommand($config));

$app->run();

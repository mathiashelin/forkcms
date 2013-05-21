<?php
// bootstrap.php
require_once "vendor/autoload.php";

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Console\ConsoleRunner;

$paths = array("/Users/jelmersnoeck/Projects/ContentBlocks/Entity");
$isDevMode = true;

// the connection configuration
$dbParams = array(
    'driver'   => 'pdo_mysql',
    'user'     => 'forkcms',
    'password' => 'forkcms',
    'dbname'   => 'forkcms',
);

$config = Setup::createAnnotationMetadataConfiguration($paths, $isDevMode);
$entityManager = EntityManager::create($dbParams, $config);
$platform = $entityManager->getConnection()->getDatabasePlatform();
$platform->registerDoctrineTypeMapping('enum', 'string');

// @todo: fix orm prefix
// 40:        $entityGenerator->setAnnotationPrefix('ORM\\');

return ConsoleRunner::createHelperSet($entityManager);

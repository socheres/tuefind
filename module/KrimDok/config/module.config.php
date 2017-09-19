<?php
namespace KrimDok\Module\Config;

$config = array(
    'controllers' => [
        'factories' => [
            'browse' => 'KrimDok\Controller\Factory::getBrowseController',
        ],
        'invokables' => [
            'fidsystematik' => 'KrimDok\Controller\FIDSystematikController',
            'help' => 'KrimDok\Controller\HelpController',
            'search' => 'KrimDok\Controller\SearchController',
            'static_pages' => 'KrimDok\Controller\StaticPagesController',
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'newitems' => 'KrimDok\Controller\Plugin\Factory::getNewItems',
        ],
    ],
    'router' => [
        'routes' => [
            'static-catalogs' => [
                'type' => 'Zend\Mvc\Router\Http\Literal',
                'options' => [
                    'route'    => '/static/catalogs',
                    'defaults' => [
                        'controller' => 'static_pages',
                        'action'     => 'catalogs',
                    ],
                ],
            ],
        ],
    ],
    'vufind' => [
        'plugin_managers' => [
            'ils_driver' => [
                'factories' => [
                    'KrimDok' => 'KrimDok\ILS\Driver\Factory::getKrimDok'
                ],
            ],
            'recorddriver' => [
                'factories' => [
                    'solrmarc' => 'KrimDok\RecordDriver\Factory::getSolrMarc'
                ],
            ],
            'search_params' => [
                'abstract_factories' => ['KrimDok\Search\Params\PluginFactory'],
            ],
        ],
        'recorddriver_tabs' => [
            'VuFind\RecordDriver\SolrMarc' => [
                'tabs' => [
                    'Similar' => null,
                ],
            ],
        ],
    ],
);

$recordRoutes = [];
$dynamicRoutes = [];
$staticRoutes = [
    'FIDSystematik/Home',
    'Help/FAQ',
];

$routeGenerator = new \VuFind\Route\RouteGenerator();
$routeGenerator->addRecordRoutes($config, $recordRoutes);
$routeGenerator->addDynamicRoutes($config, $dynamicRoutes);
$routeGenerator->addStaticRoutes($config, $staticRoutes);

return $config;

<?php
namespace CuratedTextLinks;

use CuratedTextLinks\Controller\Admin\AnnotationController;
use CuratedTextLinks\Controller\Admin\BatchController;
use CuratedTextLinks\Controller\Admin\BulkController;
use CuratedTextLinks\Controller\Admin\SettingsController;
use CuratedTextLinks\Controller\Site\AnnotationController as SiteAnnotationController;
use CuratedTextLinks\Form\BulkApplyForm;
use CuratedTextLinks\Form\SettingsForm;
use CuratedTextLinks\Service\AnnotationService;
use CuratedTextLinks\Site\BlockLayout\Network;
use CuratedTextLinks\Site\BlockLayout\ReadingCards;
use CuratedTextLinks\Site\ResourcePageBlockLayout\ItemNetwork;
use CuratedTextLinks\View\Helper\CuratedTextLinkedDescription;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Psr\Container\ContainerInterface;

return [
    'controllers' => [
        'factories' => [
            AnnotationController::class => InvokableFactory::class,
            BatchController::class => InvokableFactory::class,
            BulkController::class => InvokableFactory::class,
            SettingsController::class => InvokableFactory::class,
            SiteAnnotationController::class => InvokableFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            BulkApplyForm::class => InvokableFactory::class,
            SettingsForm::class => InvokableFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            AnnotationService::class => function (ContainerInterface $services) {
                return new AnnotationService($services);
            },
        ],
    ],
    'block_layouts' => [
        'factories' => [
            'curatedTextNetwork' => function (ContainerInterface $services) {
                return new Network($services->get(AnnotationService::class));
            },
            'curatedTextReadingCards' => function (ContainerInterface $services) {
                return new ReadingCards($services->get(AnnotationService::class));
            },
        ],
    ],
    'resource_page_block_layouts' => [
        'factories' => [
            'curatedTextItemNetwork' => function (ContainerInterface $services) {
                return new ItemNetwork($services->get(AnnotationService::class));
            },
        ],
    ],
    'view_helpers' => [
        'factories' => [
            CuratedTextLinkedDescription::class => function (ContainerInterface $services) {
                return new CuratedTextLinkedDescription($services->get(AnnotationService::class));
            },
        ],
        'aliases' => [
            'curatedTextLinkedDescription' => CuratedTextLinkedDescription::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'curated-text-links' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/curated-text-links',
                            'defaults' => [
                                '__NAMESPACE__' => 'CuratedTextLinks\Controller\Admin',
                                'controller' => AnnotationController::class,
                                'action' => 'browse',
                                '__ADMIN__' => true,
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'bulk' => [
                                'type' => 'Literal',
                                'options' => ['route' => '/bulk', 'defaults' => ['controller' => BulkController::class, 'action' => 'index']],
                            ],
                            'batch' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/batch[/:action[/:id]]', 'defaults' => ['controller' => BatchController::class, 'action' => 'browse']],
                            ],
                            'settings' => [
                                'type' => 'Literal',
                                'options' => ['route' => '/settings', 'defaults' => ['controller' => SettingsController::class, 'action' => 'index']],
                            ],
                            'update' => [
                                'type' => 'Literal',
                                'options' => ['route' => '/update', 'defaults' => ['controller' => AnnotationController::class, 'action' => 'update']],
                            ],
                            'id' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/:action/:id', 'constraints' => ['id' => '\d+'], 'defaults' => ['controller' => AnnotationController::class]],
                            ],
                        ],
                    ],
                ],
            ],
            'curated-text-links' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/admin/curated-text-links',
                    'defaults' => [
                        '__NAMESPACE__' => 'CuratedTextLinks\Controller\Admin',
                        'controller' => AnnotationController::class,
                        'action' => 'browse',
                        '__ADMIN__' => true,
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'bulk' => [
                        'type' => 'Literal',
                        'options' => ['route' => '/bulk', 'defaults' => ['controller' => BulkController::class, 'action' => 'index']],
                    ],
                    'batch' => [
                        'type' => 'Segment',
                        'options' => ['route' => '/batch[/:action[/:id]]', 'defaults' => ['controller' => BatchController::class, 'action' => 'browse']],
                    ],
                    'settings' => [
                        'type' => 'Literal',
                        'options' => ['route' => '/settings', 'defaults' => ['controller' => SettingsController::class, 'action' => 'index']],
                    ],
                    'update' => [
                        'type' => 'Literal',
                        'options' => ['route' => '/update', 'defaults' => ['controller' => AnnotationController::class, 'action' => 'update']],
                    ],
                    'id' => [
                        'type' => 'Segment',
                        'options' => ['route' => '/:action/:id', 'constraints' => ['id' => '\d+'], 'defaults' => ['controller' => AnnotationController::class]],
                    ],
                ],
            ],
            'site' => [
                'child_routes' => [
                    'curated-text-links' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/curated-text-links/:action',
                            'defaults' => [
                                '__NAMESPACE__' => 'CuratedTextLinks\Controller\Site',
                                'controller' => SiteAnnotationController::class,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'curated-text-links' => [
                'label' => 'Curated Text Links',
                'route' => 'admin/curated-text-links',
                'resource' => AnnotationController::class,
                'pages' => [
                    ['label' => 'Annotations', 'route' => 'admin/curated-text-links', 'resource' => AnnotationController::class],
                    ['label' => 'Bulk apply', 'route' => 'admin/curated-text-links/bulk', 'resource' => BulkController::class],
                    ['label' => 'Batches', 'route' => 'admin/curated-text-links/batch', 'resource' => BatchController::class],
                    ['label' => 'Settings', 'route' => 'admin/curated-text-links/settings', 'resource' => SettingsController::class],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
];

<?php

namespace IIIFStorage;

use Digirati\OmekaShared\Helper\UrlHelper;
use IIIFStorage\Controller\CollectionController;
use IIIFStorage\Controller\ManifestController;
use IIIFStorage\Controller\ResourceController;
use IIIFStorage\Listener\ImportContentListener;
use IIIFStorage\Listener\ViewContentListener;
use IIIFStorage\Utility\Router;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Form\SiteSettingsForm;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Acl;
use Zend\Config\Factory;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Form\Element\Checkbox;
use Zend\Form\Element\Text;
use Zend\Form\Fieldset;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ModuleManager\Listener\ConfigMergerInterface;
use Zend\ModuleManager\ModuleEvent;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\MvcEvent;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule implements ConfigProviderInterface
{
    private $config;

    public function loadVendor()
    {
        if (file_exists(__DIR__ . '/build/vendor-dist/autoload.php')) {
            require_once __DIR__ . '/build/vendor-dist/autoload.php';
        } elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }
    }

    public function getConfig()
    {
        if ($this->config) {
            return $this->config;
        }

        // Load our composer dependencies.
        $this->loadVendor();

        // Load our configuration.
        $this->config = Factory::fromFiles(
            glob(__DIR__ . '/config/*.config.*')
        );

        return $this->config;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $container = $this->getServiceLocator();

        $importContent = $container->get(ImportContentListener::class);
        $importContent->attach($sharedEventManager);

        $viewContent = $container->get(ViewContentListener::class);
        $viewContent->attach($sharedEventManager);

        $sharedEventManager->attach(SiteSettingsForm::class, 'form.add_elements', function(Event $event) {
            /** @var SiteSettingsForm $form */
            $form = $event->getTarget();

            $form->add(
                (new Fieldset('crowd-sourcing'))
                    ->add(
                        (new Checkbox('cs-bookmarking'))
                            ->setOptions([
                                'label' => 'Enable bookmarking',
                                'info' => 'Allows logged in users to bookmark IIIF canvases'
                            ])
                            ->setValue($form->getSiteSettings()->get('cs-bookmarking', true))
                    )
                    ->add(
                        (new Checkbox('cs-mark-as-complete'))
                            ->setOptions([
                                'label' => 'Enable mark as complete',
                                'info' => 'Allows users to mark a page as complete, allowing no further annotations'
                            ])
                            ->setValue($form->getSiteSettings()->get('cs-mark-as-complete', true))
                    )
                    ->add(
                        (new Checkbox('cs-annotation-studio'))
                            ->setOptions([
                                'label' => 'Enable user created annotations',
                                'info' => 'Allows users to create annotations'
                            ])
                            ->setValue($form->getSiteSettings()->get('cs-annotation-studio', true))
                    )
                    ->add(
                        (new Checkbox('cs-flagging'))
                            ->setOptions([
                                'label' => 'Enable user flagging',
                                'info' => 'Allows users to flag content (requires backend service)'
                            ])
                            ->setValue($form->getSiteSettings()->get('cs-flagging', true))
                    )
                    ->add(
                        (new Checkbox('cs-show-annotations'))
                            ->setOptions([
                                'label' => 'Enable users to see annotations',
                                'info' => 'Allows users to see created annotations'
                            ])
                            ->setValue($form->getSiteSettings()->get('cs-show-annotations', true))
                    )
                    ->setOptions([
                        'label' => 'Crowd sourcing',
                    ])
            );
        });

        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.before',
            array($this, 'onView')
        );
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        /** @var Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            null,
            [
                ResourceController::class,
                ManifestController::class,
                CollectionController::class,
            ]
        );
    }

    public function init(ModuleManager $moduleManager)
    {
        $events = $moduleManager->getEventManager();

        // Registering a listener at default priority, 1, which will trigger
        // after the ConfigListener merges config.
        $events->attach(ModuleEvent::EVENT_MERGE_CONFIG, array($this, 'onMergeConfig'));
    }

    public function onView(Event $e) {
        /** @var PhpRenderer $renderer */
        $renderer = $e->getTarget();
        /** @var \Zend\View\Variables $variables */
        $variables = $renderer->vars();
        /** @var \Zend\Mvc\Controller\Plugin\Redirect $redirect */
        $redirect = $this->getServiceLocator()->get('ControllerPluginManager')->get('redirect');
        /** @var UrlHelper $url */
        $url = $this->getServiceLocator()->get(UrlHelper::class);
        /** @var Router $router */
        $router = $this->getServiceLocator()->get(Router::class);
        /** @var ItemRepresentation $item */
        $item = $variables['item'];

        $resourceClass = $item->resourceClass();

        if (!$resourceClass) {
            return null;
        }
        switch ($resourceClass->term()) {
            case 'sc:Manifest':
                return $redirect->toUrl($router->manifest($item));
            case 'sc:Collection':
                return $redirect->toUrl($router->collection($item));
            case 'sc:Canvas':
                return $redirect->toUrl($router->canvas($item));
        }
        return null;
    }

    public function onMergeConfig(ModuleEvent $e)
    {
        $configListener = $e->getConfigListener();
        $config = $configListener->getMergedConfig(false);

        // Modify the configuration; here, we'll remove a specific key:
        if (isset($config['navigation']['AdminResource'])) {
            foreach ($config['navigation']['AdminResource'] as &$adminResource) {
                if (
                    $adminResource['class'] === 'items' &&
                    $adminResource['controller'] === 'item' &&
                    $adminResource['action'] === 'browse'
                ) {
                    $adminResource['pages'][] = [
                        'label' => 'Manifests', // @translate
                        'route' => 'admin/default',
                        'controller' => 'item',
                        'visible' => true,
                        'action' => '',
                        'resource' => 'Omeka\Controller\Admin\Item',
                        'privilege' => 'browse',
                        'query' => ['resource_class_id' => 117] // @todo find way to dynamically do this at the config point.
                    ];
                    $adminResource['pages'][] = [
                        'label' => 'Canvases', // @translate
                        'route' => 'admin/default',
                        'controller' => 'item',
                        'visible' => true,
                        'action' => '',
                        'resource' => 'Omeka\Controller\Admin\Item',
                        'privilege' => 'browse',
                        'query' => ['resource_class_id' => 115] // @todo find way to dynamically do this at the config point.
                    ];
                }
                if (
                    $adminResource['class'] === 'item-sets' &&
                    $adminResource['controller'] === 'item-set' &&
                    $adminResource['action'] === 'browse'
                ) {
                    $adminResource['pages'][] = [
                        'label' => 'Collections', // @translate
                        'route' => 'admin/default',
                        'controller' => 'item-set',
                        'visible' => true,
                        'action' => '',
                        'resource' => 'Omeka\Controller\Admin\ItemSet',
                        'privilege' => 'browse',
                        'query' => ['resource_class_id' => 119] // @todo find way to dynamically do this at the config point.
                    ];
                }
            }
        }

        // Pass the changed configuration back to the listener:
        $configListener->setMergedConfig($config);
    }
}

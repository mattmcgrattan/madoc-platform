<?php

namespace IIIFStorage\Media;


use IIIFStorage\JsonBuilder\ManifestBuilder;
use IIIFStorage\Utility\Router;
use Omeka\Api\Manager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Media\Renderer\RendererInterface;
use Zend\View\Model\ViewModel;
use Zend\View\Renderer\PhpRenderer;
use ZfcTwig\View\TwigRenderer;

class ManifestSnippetRenderer implements RendererInterface, MediaPageBlockDualRender
{
    use RenderMedia;

    /**
     * @var TwigRenderer
     */
    private $twig;
    /**
     * @var Manager
     */
    private $api;
    /**
     * @var ManifestBuilder
     */
    private $manifestBuilder;
    /**
     * @var Router
     */
    private $router;

    public function __construct(
        TwigRenderer $twig,
        Manager $api,
        ManifestBuilder $manifestBuilder,
        Router $router
    ) {
        $this->twig = $twig;
        $this->api = $api;
        $this->manifestBuilder = $manifestBuilder;
        $this->router = $router;
    }

    /**
     * Render the provided media.
     *
     * @param PhpRenderer $view
     * @param array $data
     * @param array $options
     * @return string
     */
    public function renderFromData(PhpRenderer $view, array $data, array $options = [])
    {
        if (!$data['manifest']) return '';

        /** @var ItemRepresentation $manifestRepresentation */
        $manifestRepresentation = $this->api->read('items', $data['manifest'])->getContent();
        if (!$manifestRepresentation) return '';

        $manifest = $this->manifestBuilder->buildResource($manifestRepresentation);

        try {
            /** @var ValueRepresentation $partOf */
            $partOf = $manifestRepresentation->value('dcterms:isPartOf');
            $collectionId = $partOf->valueResource()->id();
        } catch (\Throwable $e) {
            $collectionId = null;
        }

        $vm = new ViewModel([
            'collection' => $collectionId,
            'manifest' => $manifest->getManifest(),
            'router' => $this->router,
            'resource' => $manifest,
        ]);

        $vm->setTemplate('iiif-storage/media/manifest-snippet');
        return $this->twig->render($vm);
    }

    public function pageBlockOptions(SitePageBlockRepresentation $pageBlock): array
    {
        return [];
    }
}
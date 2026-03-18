<?php

declare(strict_types=1);

namespace Bepo\DocumentPreview\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use Shopware\Core\Checkout\Document\DocumentConfigurationFactory;
use Shopware\Core\Checkout\Document\Renderer\OrderDocumentCriteriaFactory;
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Twig\DocumentTemplateRenderer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class DocumentPreviewController extends AbstractController
{
    private const TEMPLATE_MAP = [
        'invoice' => '@Framework/documents/invoice.html.twig',
        'delivery_note' => '@Framework/documents/delivery_note.html.twig',
        'credit_note' => '@Framework/documents/credit_note.html.twig',
        'storno' => '@Framework/documents/storno.html.twig',
    ];

    public function __construct(
        private readonly DocumentTemplateRenderer $templateRenderer,
        private readonly DocumentConfigLoader $configLoader,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $documentTypeRepository,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Serves the static HTML UI shell. No server-side data is included.
     * All data access requires a valid admin API token obtained via the login form.
     */
    #[Route(
        path: '/api/_action/bepo-document-preview/ui',
        name: 'api.action.bepo-document-preview.ui',
        methods: ['GET'],
        defaults: ['auth_required' => false, '_routeScope' => ['api']],
    )]
    public function ui(): Response
    {
        $html = file_get_contents(__DIR__ . '/../Resources/views/document-preview.html');

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    #[Route(
        path: '/api/_action/bepo-document-preview/orders',
        name: 'api.action.bepo-document-preview.orders',
        methods: ['GET'],
    )]
    public function orders(Request $request, Context $context): JsonResponse
    {
        $term = $request->query->getString('term', '');

        $criteria = new Criteria();
        $criteria->setLimit(25);
        $criteria->addSorting(new FieldSorting('orderNumber', FieldSorting::DESCENDING));
        $criteria->addAssociation('orderCustomer');

        if ($term !== '') {
            $criteria->addFilter(new ContainsFilter('orderNumber', $term));
        }

        $orders = $this->orderRepository->search($criteria, $context);

        $result = [];
        foreach ($orders as $order) {
            $customer = $order->getOrderCustomer();
            $result[] = [
                'id' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'customerName' => $customer ? $customer->getFirstName() . ' ' . $customer->getLastName() : 'Unknown',
                'amountTotal' => $order->getAmountTotal(),
                'orderDate' => $order->getOrderDateTime()->format('Y-m-d H:i'),
            ];
        }

        return new JsonResponse($result);
    }

    #[Route(
        path: '/api/_action/bepo-document-preview/document-types',
        name: 'api.action.bepo-document-preview.document-types',
        methods: ['GET'],
    )]
    public function documentTypes(Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $types = $this->documentTypeRepository->search($criteria, $context);

        $result = [];
        foreach ($types as $type) {
            $result[] = [
                'id' => $type->getId(),
                'name' => $type->getName(),
                'technicalName' => $type->getTechnicalName(),
            ];
        }

        return new JsonResponse($result);
    }

    #[Route(
        path: '/api/_action/bepo-document-preview/config',
        name: 'api.action.bepo-document-preview.config',
        methods: ['GET'],
    )]
    public function config(Request $request, Context $context): JsonResponse
    {
        $orderId = $request->query->getString('orderId');
        $documentType = $request->query->getString('documentType', 'invoice');

        if ($orderId === '') {
            return new JsonResponse(['error' => 'orderId is required'], 400);
        }

        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }

        $config = $this->configLoader->load($documentType, $order->getSalesChannelId(), $context);

        // Serialize and clean up for display
        $data = $config->jsonSerialize();

        // Remove internal/non-useful fields
        unset(
            $data['id'],
            $data['extensions'],
            $data['_uniqueIdentifier'],
            $data['versionId'],
            $data['translated'],
            $data['apiAlias'],
            $data['deliveryCountries'],
            $data['documentTypeId'],
            $data['diplayLineItemPosition'],
            $data['displayInCustomerAccount'],
            $data['fileTypes'],
        );

        // Add preview defaults
        if (empty($data['documentNumber'])) {
            $data['documentNumber'] = 'PREVIEW-001';
        }
        $data['documentDate'] ??= (new \DateTime())->format('Y-m-d H:i:s');

        // Add type-specific custom fields
        $custom = $data['custom'] ?? [];
        match ($documentType) {
            'invoice' => $custom['invoiceNumber'] ??= $data['documentNumber'],
            'delivery_note' => $custom['deliveryNoteNumber'] ??= $data['documentNumber'],
            'credit_note' => $custom['creditNoteNumber'] ??= $data['documentNumber'],
            'storno' => $custom['stornoNumber'] ??= $data['documentNumber'],
            default => null,
        };
        $data['custom'] = $custom;

        return new JsonResponse($data);
    }

    #[Route(
        path: '/api/_action/bepo-document-preview/render',
        name: 'api.action.bepo-document-preview.render',
        methods: ['POST'],
    )]
    public function renderPdf(Request $request, Context $context): Response
    {
        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $this->doRender($data, (string) ($data['fileType'] ?? 'pdf'), $context);
    }

    #[Route(
        path: '/api/_action/bepo-document-preview/render-html',
        name: 'api.action.bepo-document-preview.render-html',
        methods: ['POST'],
    )]
    public function renderHtml(Request $request, Context $context): Response
    {
        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $this->doRender($data, 'html', $context);
    }

    private function doRender(array $data, string $fileType, Context $context): Response
    {
        $orderId = (string) ($data['orderId'] ?? '');
        $documentType = (string) ($data['documentType'] ?? 'invoice');
        $configOverrides = (array) ($data['config'] ?? []);

        if ($orderId === '') {
            return new JsonResponse(['error' => 'orderId is required'], 400);
        }

        $template = self::TEMPLATE_MAP[$documentType] ?? null;
        if ($template === null) {
            return new JsonResponse(['error' => 'Unknown document type: ' . $documentType], 400);
        }

        try {
            // Load order with all associations needed for document rendering
            $criteria = OrderDocumentCriteriaFactory::create([$orderId]);
            $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

            if ($order === null) {
                return new JsonResponse(['error' => 'Order not found'], 404);
            }

            // Load document configuration from DB and merge user overrides
            $config = $this->configLoader->load($documentType, $order->getSalesChannelId(), $context);
            $config->merge($configOverrides);

            if ($config->getDocumentNumber() === null) {
                $config->setDocumentNumber('PREVIEW-001');
            }
            if ($config->getDocumentDate() === null) {
                $config->documentDate = (new \DateTime())->format('Y-m-d H:i:s');
            }

            // Set type-specific custom fields
            $custom = $config->custom ?? [];
            match ($documentType) {
                'invoice' => $custom['invoiceNumber'] ??= $config->getDocumentNumber(),
                'delivery_note' => $custom['deliveryNoteNumber'] ??= $config->getDocumentNumber(),
                'credit_note' => $custom['creditNoteNumber'] ??= $config->getDocumentNumber(),
                'storno' => $custom['stornoNumber'] ??= $config->getDocumentNumber(),
                default => null,
            };
            $config->custom = $custom;

            // Build template parameters
            $parameters = [
                'order' => $order,
                'config' => $config,
                'rootDir' => $this->projectDir,
                'context' => $context,
            ];

            // Add delivery_note-specific parameter
            if ($documentType === 'delivery_note') {
                $parameters['orderDelivery'] = $order->getDeliveries()?->first();
            }

            // Render HTML via Twig — bypasses all renderer decorators
            $language = $order->getLanguage();
            $html = $this->templateRenderer->render(
                $template,
                $parameters,
                $context,
                $order->getSalesChannelId(),
                $order->getLanguageId(),
                $language?->getLocale()?->getCode() ?? 'en-GB',
            );

            if ($fileType === 'html') {
                return new Response($html, 200, ['Content-Type' => 'text/html']);
            }

            // Convert HTML to PDF via Dompdf
            $dompdf = new Dompdf();
            $dompdf->setOptions(new Options([
                'isRemoteEnabled' => true,
            ]));
            $dompdf->setPaper(
                $config->getPageSize() ?? 'a4',
                $config->getPageOrientation() ?? 'portrait',
            );
            $dompdf->loadHtml($html);
            $dompdf->render();

            $pdf = (string) $dompdf->output();

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="preview_' . $documentType . '.pdf"',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}

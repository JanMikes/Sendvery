<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exceptions\OgImageContentNotFoundException;
use App\Services\OgImage\OgImageRenderer;
use App\Value\OgImageType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams cached PNG OG-images for `{tool|kb|health}/{slug}` shares.
 *
 * Two fallback paths protect every share URL — an unknown slug from a
 * resolver redirects to the static brand fallback; any other render
 * failure (GD allocation, font missing, disk full) also redirects.
 * A broken OG card is worse than a generic one, so we never 5xx here.
 */
final class OgImageController extends AbstractController
{
    public function __construct(
        private readonly OgImageRenderer $renderer,
        private readonly Packages $assetPackages,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/og/{type}/{slug}',
        name: 'og_image',
        requirements: [
            'type' => 'tool|kb|health',
            'slug' => '[a-zA-Z0-9_-]+',
        ],
        methods: ['GET'],
    )]
    public function __invoke(string $type, string $slug): Response
    {
        $imageType = OgImageType::from($type);

        try {
            $path = $this->renderer->render($imageType, $slug);
        } catch (OgImageContentNotFoundException $exception) {
            $this->logger->info('OG image content not found, serving fallback.', [
                'type' => $type,
                'slug' => $slug,
                'exception' => $exception->getMessage(),
            ]);

            return $this->fallbackRedirect();
        } catch (\Throwable $exception) {
            $this->logger->error('OG image render failed, serving fallback.', [
                'type' => $type,
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return $this->fallbackRedirect();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=2592000, immutable');

        return $response;
    }

    private function fallbackRedirect(): Response
    {
        return $this->redirect($this->assetPackages->getUrl('images/og-default.webp'));
    }
}

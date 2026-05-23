<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatusController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/status', name: 'status')]
    public function __invoke(): Response
    {
        return $this->render('legal/status.html.twig', [
            'statusData' => $this->loadStatusData($this->projectDir.'/var/status.json'),
        ]);
    }

    /**
     * @return array{overall: string, updated_at: string|null, components: list<array{name: string, status: string}>}
     */
    private function loadStatusData(string $path): array
    {
        $parsed = $this->parseStatusFile($path);
        if (null !== $parsed) {
            return $parsed;
        }

        return [
            'overall' => 'operational',
            'updated_at' => null,
            'components' => [
                ['name' => 'Web application', 'status' => 'operational'],
                ['name' => 'Email ingestion workers', 'status' => 'operational'],
                ['name' => 'DMARC report parser', 'status' => 'operational'],
                ['name' => 'DNS health checker', 'status' => 'operational'],
                ['name' => 'AI Insights service', 'status' => 'operational'],
            ],
        ];
    }

    /**
     * @return array{overall: string, updated_at: string|null, components: list<array{name: string, status: string}>}|null
     */
    private function parseStatusFile(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $overall = $decoded['overall'] ?? null;
        $updatedAt = $decoded['updated_at'] ?? null;
        $components = $decoded['components'] ?? null;

        if (!is_string($overall) || !is_array($components)) {
            return null;
        }
        if (null !== $updatedAt && !is_string($updatedAt)) {
            return null;
        }

        $normalisedComponents = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                return null;
            }
            $name = $component['name'] ?? null;
            $status = $component['status'] ?? null;
            if (!is_string($name) || !is_string($status)) {
                return null;
            }
            $normalisedComponents[] = ['name' => $name, 'status' => $status];
        }

        return [
            'overall' => $overall,
            'updated_at' => $updatedAt,
            'components' => $normalisedComponents,
        ];
    }
}

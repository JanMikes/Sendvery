<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class KnowledgeBaseArticleController extends AbstractController
{
    #[Route('/learn/{slug}', name: 'knowledge_base_article')]
    public function __invoke(string $slug): Response
    {
        $guide = null;

        foreach (KnowledgeBaseIndexController::GUIDES as $g) {
            if ($g['slug'] === $slug) {
                $guide = $g;
                break;
            }
        }

        if ($guide === null) {
            throw new NotFoundHttpException();
        }

        $template = sprintf('knowledge_base/articles/%s.html.twig', $slug);

        return $this->render($template, [
            'guide' => $guide,
            'guides' => KnowledgeBaseIndexController::GUIDES,
        ]);
    }
}

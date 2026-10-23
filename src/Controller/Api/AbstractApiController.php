<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/** JSON lisible (UTF-8 et « / » non échappés) pour toutes les réponses de l'API. */
abstract class AbstractApiController extends AbstractController
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $context
     */
    protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        return parent::json($data, $status, $headers, $context + [
            'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ]);
    }
}

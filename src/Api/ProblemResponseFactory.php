<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réponses d'erreur RFC 7807 (application/problem+json).
 */
final class ProblemResponseFactory
{
    public const CONTENT_TYPE = 'application/problem+json';
    public const TYPE_BASE = 'https://netops.example/problems/';

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $extra   membres d'extension (violations, blockers…)
     */
    public function create(int $status, string $detail, array $headers = [], array $extra = [], ?string $type = null): JsonResponse
    {
        $body = [
            'type' => $type ?? self::defaultType($status),
            'title' => self::title($status),
            'status' => $status,
            'detail' => $detail,
        ] + $extra;

        $response = new JsonResponse($body, $status, $headers + ['Content-Type' => self::CONTENT_TYPE]);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $response;
    }

    public static function title(int $status): string
    {
        return match ($status) {
            400 => 'Requête invalide',
            401 => 'Authentification requise',
            403 => 'Accès refusé',
            404 => 'Ressource introuvable',
            405 => 'Méthode non autorisée',
            409 => 'Conflit',
            412 => 'Précondition non remplie',
            415 => 'Type de contenu non supporté',
            422 => 'Données invalides',
            429 => 'Trop de requêtes',
            default => $status >= 500 ? 'Erreur interne' : (Response::$statusTexts[$status] ?? 'Erreur'),
        };
    }

    private static function defaultType(int $status): string
    {
        return match ($status) {
            401 => self::TYPE_BASE.'unauthorized',
            403 => self::TYPE_BASE.'forbidden',
            404 => self::TYPE_BASE.'not-found',
            409 => self::TYPE_BASE.'conflict',
            422 => self::TYPE_BASE.'validation-error',
            default => 'about:blank',
        };
    }
}

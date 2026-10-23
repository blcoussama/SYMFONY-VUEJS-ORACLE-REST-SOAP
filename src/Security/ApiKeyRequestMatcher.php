<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Sélectionne le pare-feu sans état « api_key » : appel de /api/ portant un en-tête X-API-Key.
 * Sans cet en-tête, /api/ passe par le pare-feu « main » (session du tableau Kanban).
 */
final class ApiKeyRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/') && $request->headers->has(ApiKeyAuthenticator::HEADER);
    }
}

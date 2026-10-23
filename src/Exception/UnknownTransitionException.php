<?php

declare(strict_types=1);

namespace App\Exception;

final class UnknownTransitionException extends \DomainException
{
    public function __construct(public readonly string $transition)
    {
        parent::__construct(\sprintf('Transition inconnue : « %s ».', $transition));
    }
}

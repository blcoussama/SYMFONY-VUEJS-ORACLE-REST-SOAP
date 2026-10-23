<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\ProblemResponseFactory;
use App\Exception\BusinessRuleException;
use App\Exception\IncidentConflictException;
use App\Exception\TransitionRefusedException;
use App\Exception\UnknownTransitionException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Convertit toute erreur sous /api/ en réponse RFC 7807 :
 * 404 (ressource ou transition inconnue), 403, 409 (transition refusée, conflit de version),
 * 422 (validation, avec le détail par champ), 500 sans fuite d'information.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ProblemResponseFactory $problems,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (priorité 1), qui transforme AccessDeniedException en 403/401.
        return [KernelEvents::EXCEPTION => ['onKernelException', -10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $validation = $this->findValidationFailure($exception);

        $response = match (true) {
            null !== $validation => $this->problems->create(422, 'Les données envoyées sont invalides.', [], ['violations' => $this->violations($validation)]),
            $exception instanceof BusinessRuleException => $this->problems->create(422, $exception->getMessage(), [], ['violations' => [['field' => $exception->field, 'message' => $exception->getMessage()]]]),
            $exception instanceof UnknownTransitionException => $this->problems->create(404, $exception->getMessage()),
            $exception instanceof TransitionRefusedException => $this->problems->create(
                $exception->isForbidden() ? 403 : 409,
                $exception->getMessage(),
                [],
                ['transition' => $exception->transition, 'blockers' => $exception->getBlockers()],
                $exception->isForbidden() ? null : ProblemResponseFactory::TYPE_BASE.'transition-refused',
            ),
            $exception instanceof IncidentConflictException, $exception instanceof OptimisticLockException => $this->problems->create(409, (new IncidentConflictException())->getMessage(), [], [], ProblemResponseFactory::TYPE_BASE.'version-conflict'),
            $exception instanceof HttpExceptionInterface => $this->problems->create(
                $exception->getStatusCode(),
                $this->httpDetail($exception),
                array_filter($exception->getHeaders(), 'is_string'),
            ),
            default => null,
        };

        if (null === $response) {
            $this->logger->error('Erreur interne de l’API : {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
            $response = $this->problems->create(500, $this->debug ? $exception->getMessage() : 'Une erreur interne est survenue.');
        }

        $event->setResponse($response);
    }

    private function httpDetail(HttpExceptionInterface $exception): string
    {
        $status = $exception->getStatusCode();
        $message = $exception->getMessage();

        // Les messages techniques de Symfony (route, convertisseur…) ne sont pas exposés.
        return match (true) {
            404 === $status && ('' === $message || str_contains($message, 'No route found') || str_contains($message, 'object not found')) => 'Ressource introuvable.',
            403 === $status && ('' === $message || 'Access Denied.' === $message) => 'Vous n’avez pas le droit d’effectuer cette action.',
            405 === $status => 'Méthode HTTP non autorisée pour cette ressource.',
            default => '' !== $message ? $message : ProblemResponseFactory::title($status),
        };
    }

    private function findValidationFailure(\Throwable $exception): ?ValidationFailedException
    {
        for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof ValidationFailedException) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function violations(ValidationFailedException $exception): array
    {
        $result = [];
        /** @var ConstraintViolationInterface $violation */
        foreach ($exception->getViolations() as $violation) {
            $result[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }

        return $result;
    }
}

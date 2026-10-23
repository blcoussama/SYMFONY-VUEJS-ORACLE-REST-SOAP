<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Dto\CreateIncidentInput;
use App\Api\Dto\IncidentListQuery;
use App\Controller\Api\IncidentController;
use App\Enum\IncidentStatus;
use App\Exception\TransitionRefusedException;
use App\Workflow\IncidentGuardListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Component\Workflow\TransitionBlockerList;

final class ApiInputTest extends TestCase
{
    public function testStatusListIsParsedCaseInsensitively(): void
    {
        $query = new IncidentListQuery(status: 'nouveau, EN_COURS');

        self::assertSame([IncidentStatus::Nouveau, IncidentStatus::EnCours], $query->statuses());
    }

    public function testQueryValidation(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate(new IncidentListQuery(status: 'NOUVEAU', priority: 'P1', limit: 100)));
        $violations = $validator->validate(new IncidentListQuery(status: 'INCONNU', priority: 'P9', limit: 101));
        self::assertSame(['status', 'priority', 'limit'], array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($violations)));
    }

    public function testCreateInputValidation(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate(new CreateIncidentInput('Ko', '', 'TNG-0042', 'PANNE', 'P1'));

        $fields = array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($violations));
        self::assertContains('title', $fields);
        self::assertContains('description', $fields);
        self::assertContains('category', $fields);
        self::assertNotContains('siteCode', $fields);
    }

    /**
     * @return iterable<string, array{string|null, int|null}>
     */
    public static function ifMatchHeaders(): iterable
    {
        yield 'absent' => [null, null];
        yield 'étoile' => ['*', null];
        yield 'ETag fort' => ['"7"', 7];
        yield 'ETag faible' => ['W/"7"', 7];
        yield 'nombre nu' => ['12', 12];
        yield 'illisible' => ['"abc"', -1];
    }

    #[DataProvider('ifMatchHeaders')]
    public function testIfMatchParsing(?string $header, ?int $expected): void
    {
        $request = new Request();
        if (null !== $header) {
            $request->headers->set('If-Match', $header);
        }

        self::assertSame($expected, IncidentController::versionFromIfMatch($request));
    }

    public function testRefusalKind(): void
    {
        $forbidden = new TransitionRefusedException('clore', new TransitionBlockerList([new TransitionBlocker('Réservé', IncidentGuardListener::CODE_FORBIDDEN)]));
        $state = new TransitionRefusedException('clore', new TransitionBlockerList([new TransitionBlocker('x', TransitionBlocker::BLOCKED_BY_MARKING)]));

        self::assertTrue($forbidden->isForbidden());
        self::assertFalse($state->isForbidden());
        self::assertSame('Cette action n’est pas possible dans le statut actuel de l’incident.', $state->getMessage());
    }
}

<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\SharedKernel\Application\Problem;

enum LoginApplicationKind: string
{
    case HOMEOWNER = 'homeowner';
    case ADMIN = 'admin';

    public static function fromInput(string $value): self
    {
        $application = self::tryFrom(mb_strtolower(trim($value)));
        if ($application === null) {
            throw new Problem(
                422,
                'Validation failed',
                'applicationKind must be homeowner or admin.',
            );
        }

        return $application;
    }
}

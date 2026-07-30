<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Domain;

enum AiMode: string
{
    case ManualOnly = 'manual_only';
    case ServerProxy = 'server_proxy';
    case LocalDirect = 'local_direct';
}

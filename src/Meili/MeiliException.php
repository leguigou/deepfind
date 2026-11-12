<?php

declare(strict_types=1);

namespace DeepFind\Meili;

final class MeiliException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly ?string $meiliCode = null)
    {
        parent::__construct($message, $code);
    }
}

<?php

declare(strict_types=1);

namespace DeepFind\Import;

/** Import volontairement interrompu (contrôle de sécurité) : l'index en ligne n'est pas modifié. */
final class AbortImport extends \RuntimeException
{
}

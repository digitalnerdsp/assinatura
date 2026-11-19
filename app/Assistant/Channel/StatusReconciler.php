<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

/** Concilia os avisos de preço guardados (número sem vínculo ou preço ainda não cadastrado). */
final class StatusReconciler
{
    public function __construct(private readonly StatusProcessor $processor)
    {
    }

    public function run(): int
    {
        return $this->processor->reconcile();
    }
}

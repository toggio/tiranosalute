<?php

// Aggregazione dei dati esposti nelle dashboard statistiche,
// con esecuzione preventiva della manutenzione sulle visite scadute.

declare(strict_types=1);

namespace App\Services;

use App\Repositories\StatsRepository;

final class StatsService
{
    public function __construct(
        private readonly StatsRepository $stats,
        private readonly AppointmentService $appointments
    )
    {
    }

    public function dashboard(): array
    {
        $this->appointments->runLifecycleMaintenance();

        return [
            'global' => $this->stats->globalSummary(),
            'by_doctor' => $this->stats->byDoctor(),
            'by_category' => $this->stats->byCategory(),
        ];
    }
}

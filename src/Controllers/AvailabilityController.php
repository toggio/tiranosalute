<?php

// Controller per lettura e modifica delle disponibilità dei medici,
// con delega dei controlli di ruolo al servizio dedicato.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthContext;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AvailabilityService;

final class AvailabilityController
{
    public function __construct(private readonly AvailabilityService $availability)
    {
    }

    public function get(AuthContext $auth, Request $request): array
    {
        $doctorId = (int)$request->getRouteParam('id');
        return $this->availability->getDoctorAvailability($auth, $doctorId);
    }

    public function set(AuthContext $auth, Request $request): array
    {
        $doctorId = (int)$request->getRouteParam('id');
        $payload = $request->allBody();
        if (!array_key_exists('slots', $payload)) {
            throw new HttpException('slots è obbligatorio', 422, 'VALIDATION_ERROR');
        }

        $rows = $payload['slots'];
        if (!is_array($rows)) {
            throw new HttpException('slots deve essere un array', 422, 'VALIDATION_ERROR');
        }

        return $this->availability->setDoctorAvailability($auth, $doctorId, $rows);
    }
}

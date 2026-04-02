<?php

// Controller delle API dedicate alle visite:
// ricerca disponibilità, prenotazione, dettaglio e cambi di stato.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthContext;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AppointmentService;

final class AppointmentController
{
    public function __construct(private readonly AppointmentService $appointments)
    {
    }

    public function searchAvailability(AuthContext $auth, Request $request): array
    {
        $from = (string)$request->getQueryParam('from', '');
        $to = (string)$request->getQueryParam('to', '');
        $visitCategory = trim((string)$request->getQueryParam('visit_category', ''));
        $limit = (int)$request->getQueryParam('limit', 10);
        $doctorId = (int)$request->getQueryParam('doctor_id', 0);
        $patientId = null;

        if ($auth->role() === 'PATIENT') {
            $id = (int)($auth->user['patient_id'] ?? 0);
            $patientId = $id > 0 ? $id : null;
        } elseif (in_array($auth->role(), ['RECEPTION', 'INTEGRATOR'], true)) {
            $requestedPatientId = (int)$request->getQueryParam('patient_id', 0);
            $patientId = $requestedPatientId > 0 ? $requestedPatientId : null;
        }

        if ($from === '' || $to === '' || $visitCategory === '') {
            throw new HttpException('from, to e visit_category sono obbligatori', 422, 'VALIDATION_ERROR');
        }

        return $this->appointments->searchAvailability(
            $visitCategory,
            $from,
            $to,
            $limit,
            $doctorId > 0 ? $doctorId : null,
            $patientId
        );
    }

    public function book(AuthContext $auth, Request $request): array
    {
        $appointment = $this->appointments->bookAppointment($auth, $request->allBody());
        $appointment['__status_code'] = 201;
        return $appointment;
    }

    public function listDoctors(): array
    {
        return $this->appointments->listActiveDoctors();
    }

    public function list(AuthContext $auth, Request $request): array
    {
        return $this->appointments->listAppointments($auth, $request->getQuery());
    }

    public function detail(AuthContext $auth, Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        return $this->appointments->getAppointmentById($auth, $id);
    }

    public function cancel(AuthContext $auth, Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        $reason = (string)($request->allBody()['reason'] ?? '');
        return $this->appointments->cancelAppointment($auth, $id, $reason);
    }

    public function start(AuthContext $auth, Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        return $this->appointments->startAppointment($auth, $id);
    }

    public function complete(AuthContext $auth, Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        $report = (string)($request->allBody()['report_text'] ?? '');
        return $this->appointments->completeAppointmentWithReport($auth, $id, $report);
    }
}

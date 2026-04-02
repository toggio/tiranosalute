<?php

// Controller delle funzioni amministrative su pazienti,
// medici, staff interno e dashboard statistiche.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\AppointmentService;
use App\Services\StatsService;
use App\Services\UserManagementService;

final class AdminController
{
    public function __construct(
        private readonly UserManagementService $users,
        private readonly AppointmentService $appointments,
        private readonly StatsService $stats
    ) {
    }

    public function listPatients(Request $request): array
    {
        return $this->users->listPatients($request->getQuery());
    }

    public function createPatient(Request $request): array
    {
        $patient = $this->users->createPatient($request->allBody());
        $patient['__status_code'] = 201;
        return $patient;
    }

    public function updatePatient(Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        return $this->users->updatePatient($id, $request->allBody());
    }

    public function listDoctors(): array
    {
        return $this->users->listDoctors();
    }

    public function listStaff(): array
    {
        return $this->users->listStaff();
    }

    public function createStaff(Request $request): array
    {
        $user = $this->users->createStaff($request->allBody());
        $user['__status_code'] = 201;
        return $user;
    }

    public function updateStaff(Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        return $this->users->updateStaff($id, $request->allBody());
    }

    public function createDoctor(Request $request): array
    {
        $doctor = $this->users->createDoctor($request->allBody());
        $doctor['__status_code'] = 201;
        return $doctor;
    }

    public function updateDoctor(Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        return $this->users->updateDoctor($id, $request->allBody());
    }

    public function statsDashboard(): array
    {
        return $this->stats->dashboard();
    }
}

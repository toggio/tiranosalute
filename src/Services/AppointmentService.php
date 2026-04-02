<?php

// Servizio principale per la gestione delle visite.
// Contiene ricerca degli slot, ranking del medico consigliato,
// prenotazione finale e transizioni di stato.

declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\HttpException;
use App\Repositories\AppointmentRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Support\PublicApi;

final class AppointmentService
{
    // K=5 mantiene stabile la stima per i medici con poco storico, riportandola verso la media globale della categoria.
    private const RANKING_SMOOTHING_K = 5;
    private const DEFAULT_DURATION_MINUTES = 15.0;
    private const DEFAULT_DELAY_MINUTES = 0.0;
    private const AUTO_CANCEL_AFTER_HOURS = 12;
    private const AUTO_CANCEL_REASON = 'scaduta';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly AppointmentRepository $appointments,
        private readonly AvailabilityRepository $availabilities,
        private readonly DoctorRepository $doctors,
        private readonly PatientRepository $patients,
        private readonly CategoryRepository $categories,
        private readonly ReportService $reports,
        private readonly array $config
    ) {
    }

    public function runLifecycleMaintenance(): int
    {
        $expiredBefore = date('Y-m-d H:i:s', strtotime('-' . self::AUTO_CANCEL_AFTER_HOURS . ' hours'));
        $expiredAppointments = $this->appointments->listExpiredBookedAppointments($expiredBefore);
        if ($expiredAppointments === []) {
            return 0;
        }

        $cancelledAt = date('Y-m-d H:i:s');
        $cancelled = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($expiredAppointments as $row) {
                $appointmentId = (int)($row['id'] ?? 0);
                if ($appointmentId <= 0) {
                    continue;
                }

                $wasCancelled = $this->appointments->markCancelledIfBooked(
                    $appointmentId,
                    null,
                    null,
                    self::AUTO_CANCEL_REASON,
                    $cancelledAt
                );
                if (!$wasCancelled) {
                    continue;
                }

                $this->appointments->addStatusHistory(
                    $appointmentId,
                    'PRENOTATA',
                    'ANNULLATA',
                    null,
                    self::AUTO_CANCEL_REASON,
                    $cancelledAt
                );
                $cancelled++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $cancelled;
    }

    public function searchAvailability(
        string $visitCategory,
        string $from,
        string $to,
        int $limit = 10,
        ?int $preferredDoctorId = null,
        ?int $patientId = null
    ): array {
        $this->runLifecycleMaintenance();
        $category = $this->resolveVisitCategory($visitCategory);
        $slotMinutes = (int)($this->config['appointment_slot_minutes'] ?? 15);

        $startAt = $this->parseDateTimeStrict($from);
        $endAt = $this->parseDateTimeStrict($to);
        if ($startAt === null || $endAt === null) {
            throw new HttpException('Intervallo disponibilità non valido', 422, 'VALIDATION_ERROR');
        }
        $startTs = $startAt->getTimestamp();
        $endTs = $endAt->getTimestamp();
        if ($startTs >= $endTs) {
            throw new HttpException('Intervallo disponibilità non valido', 422, 'VALIDATION_ERROR');
        }

        if ($patientId !== null && $patientId > 0 && !$this->patients->find($patientId)) {
            throw new HttpException('patient_id non valido', 422, 'VALIDATION_ERROR');
        }

        $cursor = $this->alignToSlot($startTs, $slotMinutes);
        $results = [];

        while ($cursor + ($slotMinutes * 60) <= $endTs && count($results) < $limit) {
            $slotStart = date('Y-m-d H:i:s', $cursor);
            $slotEnd = date('Y-m-d H:i:s', $cursor + ($slotMinutes * 60));

            if (substr($slotStart, 0, 10) !== substr($slotEnd, 0, 10)) {
                $cursor += $slotMinutes * 60;
                continue;
            }

            if ($patientId !== null && $patientId > 0 && $this->appointments->existsPatientConflict($patientId, $slotStart, $slotEnd)) {
                $cursor += $slotMinutes * 60;
                continue;
            }

            $candidates = $this->resolveAvailableDoctorsForSlot($slotStart, $slotEnd, $preferredDoctorId);
            if ($candidates !== []) {
                $best = $this->pickBestDoctorForSlot($candidates, $category, $slotStart);
                $rankedCandidates = $best['ranked_candidates'] ?? [$best];
                $doctorRows = $this->doctors->findByIds($candidates);
                $doctorMap = [];
                foreach ($doctorRows as $doctor) {
                    $doctorMap[(int)$doctor['id']] = $doctor;
                }

                $slotDoctors = [];
                $recommendedDoctor = null;
                $alternativeDoctors = [];
                foreach ($rankedCandidates as $candidate) {
                    $doctorId = (int)$candidate['doctor_id'];
                    if (!isset($doctorMap[$doctorId])) {
                        continue;
                    }

                    $doctor = $doctorMap[$doctorId];
                    $doctorPayload = [
                        'id' => (int)$doctorId,
                        'first_name' => $doctor['first_name'],
                        'last_name' => $doctor['last_name'],
                        'recommended' => (int)$best['doctor_id'] === (int)$doctorId,
                        'estimated_duration_minutes' => (float)$candidate['estimated_duration_minutes'],
                        'estimated_delay_minutes' => (float)$candidate['estimated_delay_minutes'],
                        'daily_load' => (int)$candidate['daily_load'],
                        'weekly_load' => (int)$candidate['weekly_load'],
                        'samples' => (int)$candidate['samples'],
                        'score' => (float)$candidate['score'],
                    ];
                    $slotDoctors[] = $doctorPayload;

                    if ($doctorPayload['recommended']) {
                        $recommendedDoctor = $doctorPayload;
                        continue;
                    }

                    $alternativeDoctors[] = $doctorPayload;
                }

                $results[] = [
                    'slot_start' => $slotStart,
                    'slot_end' => $slotEnd,
                    'recommended_doctor_id' => (int)$best['doctor_id'],
                    'recommended_doctor' => $recommendedDoctor,
                    'alternative_doctors' => $alternativeDoctors,
                    'ranking_context' => [
                        'candidate_count' => count($slotDoctors),
                        'uses_comparative_ranking' => $preferredDoctorId === null && count($slotDoctors) > 1,
                    ],
                    'doctors' => $slotDoctors,
                ];
            }

            $cursor += $slotMinutes * 60;
        }

        return $results;
    }

    public function bookAppointment(AuthContext $auth, array $payload): array
    {
        $this->runLifecycleMaintenance();
        $visitCategoryName = trim((string)$this->payloadValue($payload, ['visit_category'], ''));
        $visitReason = trim((string)$this->payloadValue($payload, ['visit_reason'], ''));
        $notesValue = $this->payloadValue($payload, ['notes'], null);
        $notes = $notesValue !== null ? trim((string)$notesValue) : null;
        $notes = $notes === '' ? null : $notes;

        if ($visitCategoryName === '' || $visitReason === '') {
            throw new HttpException('visit_category e visit_reason sono obbligatori', 422, 'VALIDATION_ERROR');
        }

        $visitCategory = $this->resolveVisitCategory($visitCategoryName);
        $patientId = $this->resolvePatientIdForBooking($auth, $payload);
        $this->assertPatientHasNoActiveAppointment($patientId);
        $slotMinutes = (int)($this->config['appointment_slot_minutes'] ?? 15);

        $selectedDoctorId = (int)$this->payloadValue($payload, ['selected_doctor_id'], 0);
        $selectedSlotStart = trim((string)$this->payloadValue($payload, ['slot_start'], ''));

        if ($selectedSlotStart === '' || $selectedDoctorId <= 0) {
            throw new HttpException('slot_start e selected_doctor_id sono obbligatori', 422, 'VALIDATION_ERROR');
        }

        $slotAt = $this->parseDateTimeStrict($selectedSlotStart);
        if ($slotAt === null) {
            throw new HttpException('slot_start non valido', 422, 'VALIDATION_ERROR');
        }
        $slotTs = $slotAt->getTimestamp();
        $alignedSlotTs = $this->alignToSlot($slotTs, $slotMinutes);
        if ($alignedSlotTs !== $slotTs) {
            throw new HttpException(
                'slot_start deve essere allineato alla griglia di 15 minuti',
                422,
                'VALIDATION_ERROR'
            );
        }

        $slotStart = date('Y-m-d H:i:s', $alignedSlotTs);
        $slotEnd = date('Y-m-d H:i:s', strtotime($slotStart . ' +' . $slotMinutes . ' minutes'));

        $candidates = $this->resolveAvailableDoctorsForSlot($slotStart, $slotEnd, null);
        if ($candidates === []) {
            throw new HttpException('Nessuna disponibilità sullo slot selezionato', 409, 'CONFLICT');
        }

        if (!in_array($selectedDoctorId, $candidates, true)) {
            throw new HttpException('Il medico selezionato non è disponibile nello slot scelto', 409, 'CONFLICT');
        }

        $doctorId = $selectedDoctorId;

        $this->pdo->beginTransaction();
        try {
            if ($this->appointments->existsDoctorConflict($doctorId, $slotStart, $slotEnd)) {
                throw new HttpException('Conflitto: slot medico non più disponibile', 409, 'CONFLICT');
            }
            if ($this->appointments->existsPatientConflict($patientId, $slotStart, $slotEnd)) {
                throw new HttpException('Conflitto: paziente già prenotato nello stesso orario', 409, 'CONFLICT');
            }

            $appointmentId = $this->appointments->create([
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'visit_category_id' => (int)$visitCategory['id'],
                'visit_reason' => $visitReason,
                'notes' => $notes,
                'scheduled_start' => $slotStart,
                'scheduled_end' => $slotEnd,
                'status' => 'PRENOTATA',
                'created_by_user_id' => $auth->id(),
            ]);

            $this->appointments->addStatusHistory(
                $appointmentId,
                null,
                'PRENOTATA',
                $auth->id(),
                'Prenotazione iniziale'
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            if ($e instanceof \PDOException) {
                $message = strtolower($e->getMessage());
                if (str_contains($message, 'appointments.patient_id')) {
                    throw new HttpException(
                        'Il paziente ha già una visita attiva e non può prenotarne un\'altra.',
                        409,
                        'CONFLICT'
                    );
                }
                $doctorSlotConflict = str_contains($message, 'appointments.doctor_id')
                    && str_contains($message, 'appointments.scheduled_start');
                if ($doctorSlotConflict || str_contains($message, 'uq_appointments_doctor_active_slot')) {
                    throw new HttpException('Conflitto: slot medico non più disponibile', 409, 'CONFLICT');
                }
            }
            throw $e;
        }

        return $this->getAppointmentById($auth, $appointmentId);
    }

    public function listAppointments(AuthContext $auth, array $filters = []): array
    {
        $this->runLifecycleMaintenance();
        $filters = array_intersect_key(
            $filters,
            array_flip(['mode', 'status', 'visit_category', 'doctor_id', 'from_date', 'to_date', 'q', 'limit'])
        );

        if ($auth->role() === 'PATIENT') {
            $filters['patient_id'] = (int)$auth->user['patient_id'];
        }
        if ($auth->role() === 'DOCTOR') {
            $filters['doctor_id'] = (int)$auth->user['doctor_id'];
        }

        $rows = $this->appointments->list($filters);
        return array_map(static function (array $row): array {
            $row['report_id'] = $row['report_id'] !== null ? (int)$row['report_id'] : null;
            $row['has_report'] = $row['report_id'] !== null;
            return PublicApi::appointment($row);
        }, $rows);
    }

    public function listActiveDoctors(): array
    {
        return array_map(
            static fn(array $row): array => PublicApi::doctor($row),
            $this->doctors->listActive()
        );
    }

    public function getAppointmentById(AuthContext $auth, int $appointmentId): array
    {
        $this->runLifecycleMaintenance();
        $appointment = $this->appointments->findById($appointmentId);
        if (!$appointment) {
            throw new HttpException('Visita non trovata', 404, 'NOT_FOUND');
        }

        $this->assertCanViewAppointment($auth, $appointment);
        $appointment['history'] = $this->appointments->listStatusHistory($appointmentId);
        $appointment['report_id'] = $appointment['report_id'] !== null ? (int)$appointment['report_id'] : null;
        $appointment['has_report'] = $appointment['report_id'] !== null;

        return PublicApi::appointment($appointment);
    }

    public function cancelAppointment(AuthContext $auth, int $appointmentId, string $reason): array
    {
        $this->runLifecycleMaintenance();
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException('reason è obbligatorio', 422, 'VALIDATION_ERROR');
        }

        $appointment = $this->appointments->findById($appointmentId);
        if (!$appointment) {
            throw new HttpException('Visita non trovata', 404, 'NOT_FOUND');
        }

        $this->assertNotAutomaticallyExpired($appointment);

        $this->assertCanCancelAppointment($auth, $appointment);

        if ($appointment['status'] !== 'PRENOTATA') {
            throw new HttpException('Solo una visita PRENOTATA può essere annullata', 409, 'CONFLICT');
        }

        if ($auth->role() === 'PATIENT') {
            $visitDate = new \DateTimeImmutable($appointment['scheduled_start']);
            $deadline = $visitDate->modify('-1 day')->setTime(23, 59, 59);
            $now = new \DateTimeImmutable('now');
            if ($now > $deadline) {
                throw new HttpException(
                    'Il paziente può annullare solo fino alla mezzanotte del giorno precedente',
                    403,
                    'FORBIDDEN'
                );
            }
        }

        $cancelAt = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->appointments->markCancelled($appointmentId, $auth->role(), $auth->id(), $reason, $cancelAt);
            $this->appointments->addStatusHistory(
                $appointmentId,
                'PRENOTATA',
                'ANNULLATA',
                $auth->id(),
                $reason,
                $cancelAt
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getAppointmentById($auth, $appointmentId);
    }

    public function startAppointment(AuthContext $auth, int $appointmentId): array
    {
        $this->runLifecycleMaintenance();
        if ($auth->role() !== 'DOCTOR') {
            throw new HttpException('Solo il medico può avviare la visita', 403, 'FORBIDDEN');
        }

        $appointment = $this->appointments->findById($appointmentId);
        if (!$appointment) {
            throw new HttpException('Visita non trovata', 404, 'NOT_FOUND');
        }

        $this->assertNotAutomaticallyExpired($appointment);

        if ((int)$appointment['doctor_id'] !== (int)$auth->user['doctor_id']) {
            throw new HttpException('Puoi avviare solo le tue visite', 403, 'FORBIDDEN');
        }

        if ($appointment['status'] !== 'PRENOTATA') {
            throw new HttpException('Solo una visita PRENOTATA può passare a IN CORSO', 409, 'CONFLICT');
        }

        $startedAt = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->appointments->markStarted($appointmentId, $startedAt);
            $this->appointments->addStatusHistory($appointmentId, 'PRENOTATA', 'IN_CORSO', $auth->id(), null, $startedAt);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getAppointmentById($auth, $appointmentId);
    }

    public function completeAppointmentWithReport(AuthContext $auth, int $appointmentId, string $reportText): array
    {
        $this->runLifecycleMaintenance();
        if ($auth->role() !== 'DOCTOR') {
            throw new HttpException('Solo il medico può concludere la visita', 403, 'FORBIDDEN');
        }

        $reportText = trim($reportText);
        if ($reportText === '') {
            throw new HttpException('report_text è obbligatorio', 422, 'VALIDATION_ERROR');
        }

        $appointment = $this->appointments->findById($appointmentId);
        if (!$appointment) {
            throw new HttpException('Visita non trovata', 404, 'NOT_FOUND');
        }

        $this->assertNotAutomaticallyExpired($appointment);

        if ((int)$appointment['doctor_id'] !== (int)$auth->user['doctor_id']) {
            throw new HttpException('Puoi concludere solo le tue visite', 403, 'FORBIDDEN');
        }

        if ($appointment['status'] !== 'IN_CORSO') {
            throw new HttpException('Solo una visita IN CORSO può essere conclusa', 409, 'CONFLICT');
        }

        $this->pdo->beginTransaction();
        try {
            $endedAt = date('Y-m-d H:i:s');
            $this->appointments->markCompleted($appointmentId, $endedAt);
            $this->appointments->addStatusHistory($appointmentId, 'IN_CORSO', 'CONCLUSA', $auth->id(), null, $endedAt);
            $report = $this->reports->createForCompletedAppointment($appointment, $reportText);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'appointment' => $this->getAppointmentById($auth, $appointmentId),
            'report' => $report,
        ];
    }

    private function resolvePatientIdForBooking(AuthContext $auth, array $payload): int
    {
        if ($auth->role() === 'PATIENT') {
            $patientId = (int)($auth->user['patient_id'] ?? 0);
            if ($patientId <= 0) {
                throw new HttpException('Utente paziente non correttamente configurato', 500, 'SERVER_ERROR');
            }
            return $patientId;
        }

        if (!in_array($auth->role(), ['RECEPTION', 'INTEGRATOR'], true)) {
            throw new HttpException('Ruolo non autorizzato alla prenotazione', 403, 'FORBIDDEN');
        }

        $patientId = (int)$this->payloadValue($payload, ['patient_id'], 0);
        if ($patientId <= 0 || !$this->patients->find($patientId)) {
            throw new HttpException('patient_id non valido', 422, 'VALIDATION_ERROR');
        }

        return $patientId;
    }

    private function assertPatientHasNoActiveAppointment(int $patientId): void
    {
        $activeAppointment = $this->appointments->findActiveAppointmentForPatient($patientId);
        if (!$activeAppointment) {
            return;
        }

        $appointmentId = (int)($activeAppointment['id'] ?? 0);
        $scheduledStart = (string)($activeAppointment['scheduled_start'] ?? '');
        $message = 'Il paziente ha già una visita attiva e non può prenotarne un\'altra.';
        if ($appointmentId > 0 && $scheduledStart !== '') {
            $message = sprintf(
                'Il paziente ha già una visita attiva (#%d del %s) e non può prenotarne un\'altra.',
                $appointmentId,
                $scheduledStart
            );
        }

        throw new HttpException($message, 409, 'CONFLICT');
    }

    private function assertNotAutomaticallyExpired(array $appointment): void
    {
        if (
            ($appointment['status'] ?? '') === 'ANNULLATA'
            && ($appointment['cancellation_reason'] ?? '') === self::AUTO_CANCEL_REASON
        ) {
            throw new HttpException(
                'La visita è stata annullata automaticamente perché scaduta.',
                409,
                'CONFLICT'
            );
        }
    }

    private function assertCanViewAppointment(AuthContext $auth, array $appointment): void
    {
        if ($auth->role() === 'PATIENT' && (int)$appointment['patient_id'] !== (int)$auth->user['patient_id']) {
            throw new HttpException('Accesso negato', 403, 'FORBIDDEN');
        }

        if ($auth->role() === 'DOCTOR' && (int)$appointment['doctor_id'] !== (int)$auth->user['doctor_id']) {
            throw new HttpException('Accesso negato', 403, 'FORBIDDEN');
        }
    }

    private function assertCanCancelAppointment(AuthContext $auth, array $appointment): void
    {
        if ($auth->role() === 'PATIENT' && (int)$appointment['patient_id'] !== (int)$auth->user['patient_id']) {
            throw new HttpException('Accesso negato', 403, 'FORBIDDEN');
        }

        if ($auth->role() === 'DOCTOR' && (int)$appointment['doctor_id'] !== (int)$auth->user['doctor_id']) {
            throw new HttpException('Puoi annullare solo le tue visite', 403, 'FORBIDDEN');
        }

        if (!in_array($auth->role(), ['PATIENT', 'DOCTOR', 'RECEPTION', 'INTEGRATOR'], true)) {
            throw new HttpException('Ruolo non autorizzato', 403, 'FORBIDDEN');
        }
    }

    private function resolveVisitCategory(string $visitCategory): array
    {
        $category = $this->categories->findByName(trim($visitCategory));
        if (!$category) {
            throw new HttpException('Categoria visita non valida', 422, 'VALIDATION_ERROR');
        }

        return [
            'id' => (int)$category['id'],
            'name' => (string)$category['name'],
        ];
    }

    private function alignToSlot(int $timestamp, int $slotMinutes): int
    {
        $slotSeconds = $slotMinutes * 60;
        $remainder = $timestamp % $slotSeconds;
        if ($remainder === 0) {
            return $timestamp;
        }

        return $timestamp + ($slotSeconds - $remainder);
    }

    private function resolveAvailableDoctorsForSlot(string $slotStart, string $slotEnd, ?int $preferredDoctorId = null): array
    {
        $doctorIds = $this->availabilities->findAvailableDoctorIdsForSlot($slotStart, $slotEnd);
        if ($preferredDoctorId !== null && $preferredDoctorId > 0) {
            $doctorIds = in_array($preferredDoctorId, $doctorIds, true) ? [$preferredDoctorId] : [];
        }

        $candidates = [];
        foreach ($doctorIds as $doctorId) {
            if ($this->appointments->existsDoctorConflict($doctorId, $slotStart, $slotEnd)) {
                continue;
            }
            $candidates[] = (int)$doctorId;
        }

        return $candidates;
    }

    private function pickBestDoctorForSlot(array $doctorIds, array $visitCategory, string $slotStart): array
    {
        // Ranking slot-based:
        // 1) recupera lo storico per categoria del singolo medico e della categoria globale
        // 2) applica smoothing verso la media globale se il campione del medico è piccolo
        // 3) aggiunge il carico giornaliero e settimanale
        // 4) normalizza solo sui medici candidati nello stesso slot
        // 5) ordina per score crescente
        $globalStats = $this->appointments->getGlobalCategoryRankingStats((int)$visitCategory['id']);
        $globalAvgDuration = $globalStats['avg_duration_minutes'] !== null
            ? (float)$globalStats['avg_duration_minutes']
            : self::DEFAULT_DURATION_MINUTES;
        $globalAvgDelay = $globalStats['avg_delay_minutes'] !== null
            ? max(0.0, (float)$globalStats['avg_delay_minutes'])
            : self::DEFAULT_DELAY_MINUTES;
        $date = substr($slotStart, 0, 10);
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($slotStart)));

        $scored = [];
        foreach ($doctorIds as $doctorId) {
            $stats = $this->appointments->getDoctorCategoryRankingStats((int)$doctorId, (int)$visitCategory['id']);
            $samples = (int)$stats['samples'];
            $estimatedDuration = $this->smoothMetric(
                $stats['avg_duration_minutes'],
                $samples,
                $globalAvgDuration,
                self::RANKING_SMOOTHING_K
            );
            $estimatedDelay = max(0.0, $this->smoothMetric(
                $stats['avg_delay_minutes'],
                $samples,
                $globalAvgDelay,
                self::RANKING_SMOOTHING_K
            ));

            $weeklyLoad = $this->appointments->countDoctorWeeklyLoad((int)$doctorId, $weekStart);
            $dailyLoad = $this->appointments->countByDoctorInDay((int)$doctorId, $date);

            $scored[] = [
                'doctor_id' => (int)$doctorId,
                'estimated_duration_minutes' => round($estimatedDuration, 2),
                'estimated_delay_minutes' => round($estimatedDelay, 2),
                'weekly_load' => $weeklyLoad,
                'daily_load' => $dailyLoad,
                'samples' => $samples,
            ];
        }

        // La normalizzazione è locale allo slot: confrontiamo solo i candidati disponibili nello stesso intervallo.
        $scored = $this->normalizeCandidates($scored, 'estimated_duration_minutes', 'duration_norm');
        $scored = $this->normalizeCandidates($scored, 'estimated_delay_minutes', 'delay_norm');
        $scored = $this->normalizeCandidates($scored, 'daily_load', 'daily_norm');
        $scored = $this->normalizeCandidates($scored, 'weekly_load', 'weekly_norm');

        foreach ($scored as &$candidate) {
            // Punteggio semplice: durata pesa di più, poi ritardo, poi carico giornaliero e settimanale.
            $candidate['score'] = round(
                ($candidate['duration_norm'] * 0.50)
                + ($candidate['delay_norm'] * 0.25)
                + ($candidate['daily_norm'] * 0.20)
                + ($candidate['weekly_norm'] * 0.05),
                6
            );
        }
        unset($candidate);

        usort($scored, static function (array $a, array $b): int {
            return [$a['score'], $a['daily_load'], $a['weekly_load'], $a['doctor_id']]
                <=> [$b['score'], $b['daily_load'], $b['weekly_load'], $b['doctor_id']];
        });

        $best = $scored[0];
        $best['ranked_candidates'] = $scored;

        return $best;
    }

    private function smoothMetric(?float $doctorAverage, int $samples, float $globalAverage, int $priorWeight): float
    {
        if ($doctorAverage === null || $samples <= 0) {
            return $globalAverage;
        }

        return (($doctorAverage * $samples) + ($globalAverage * $priorWeight)) / ($samples + $priorWeight);
    }

    private function normalizeCandidates(array $candidates, string $sourceKey, string $targetKey): array
    {
        if ($candidates === []) {
            return $candidates;
        }

        $values = array_map(
            static fn(array $candidate): float => (float)$candidate[$sourceKey],
            $candidates
        );
        $min = min($values);
        $max = max($values);
        $range = $max - $min;

        foreach ($candidates as &$candidate) {
            $candidate[$targetKey] = $range == 0.0
                ? 0.0
                : (((float)$candidate[$sourceKey]) - $min) / $range;
        }
        unset($candidate);

        return $candidates;
    }

    private function payloadValue(array $payload, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return $default;
    }

    private function parseDateTimeStrict(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $dateTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if ($dateTime === false) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (
            is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)
        ) {
            return null;
        }

        return $dateTime->format('Y-m-d H:i:s') === $value ? $dateTime : null;
    }
}


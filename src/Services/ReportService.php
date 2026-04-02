<?php

// Servizio per creazione, cifratura e lettura dei referti clinici
// secondo il modello envelope encryption adottato nel progetto.

declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\HttpException;
use App\Repositories\AppointmentRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Support\PublicApi;

final class ReportService
{
    private const CONTENT_CIPHER = 'aes-256-gcm';
    private const KEK_WRAP_CIPHER = 'aes-256-gcm';
    private const KEK_VERSION = 'kek-v1';

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly AppointmentRepository $appointments,
        private readonly UserRepository $users,
        private readonly array $config
    ) {
    }

    public function createForCompletedAppointment(array $appointment, string $reportText): array
    {
        if (!empty($appointment['id']) && $this->reports->findByAppointmentId((int)$appointment['id'])) {
            throw new HttpException('Referto già esistente per questa visita', 409, 'CONFLICT');
        }

        $plaintextPayload = json_encode([
            'appointment_id' => (int)$appointment['id'],
            'visit_category' => $appointment['visit_category'],
            'report_text' => $reportText,
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Envelope encryption:
        // 1) Generate a random DEK for each report.
        // 2) Encrypt report content with DEK.
        // 3) Wrap DEK for each authorized recipient with the master KEK.
        $dek = random_bytes(32);
        $contentIv = random_bytes(12);
        $contentTag = '';
        $ciphertext = openssl_encrypt(
            $plaintextPayload,
            self::CONTENT_CIPHER,
            $dek,
            OPENSSL_RAW_DATA,
            $contentIv,
            $contentTag
        );

        if ($ciphertext === false) {
            throw new HttpException('Errore cifratura contenuto referto', 500, 'CRYPTO_ERROR');
        }

        $reportId = $this->reports->create(
            (int)$appointment['id'],
            (int)$appointment['doctor_id'],
            base64_encode($ciphertext),
            base64_encode($contentIv),
            base64_encode($contentTag),
            self::CONTENT_CIPHER
        );

        $recipientUserIds = [];
        if (!empty($appointment['doctor_user_id'])) {
            $recipientUserIds[] = (int)$appointment['doctor_user_id'];
        }
        if (!empty($appointment['patient_user_id'])) {
            $recipientUserIds[] = (int)$appointment['patient_user_id'];
        }
        foreach ($this->users->listIntegrators() as $integrator) {
            $recipientUserIds[] = (int)$integrator['id'];
        }

        $recipientUserIds = array_values(array_unique($recipientUserIds));

        foreach ($recipientUserIds as $recipientId) {
            $aad = sprintf('report:%d:user:%d', $reportId, $recipientId);
            $wrapIv = random_bytes(12);
            $wrapTag = '';
            $wrappedDek = openssl_encrypt(
                $dek,
                self::KEK_WRAP_CIPHER,
                $this->masterKey(),
                OPENSSL_RAW_DATA,
                $wrapIv,
                $wrapTag,
                $aad
            );

            if ($wrappedDek === false) {
                throw new HttpException('Errore wrapping DEK', 500, 'CRYPTO_ERROR');
            }

            $this->reports->addEncryptedDek(
                $reportId,
                $recipientId,
                base64_encode($wrappedDek),
                base64_encode($wrapIv),
                base64_encode($wrapTag),
                self::KEK_VERSION
            );
        }

        return [
            'id' => $reportId,
            'appointment_id' => (int)$appointment['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function listVisibleReports(AuthContext $auth, string $taxCode = ''): array
    {
        $taxCode = strtoupper(trim($taxCode));
        if ($auth->role() === 'INTEGRATOR' && $taxCode === '') {
            throw new HttpException('tax_code è obbligatorio per INTEGRATOR', 422, 'VALIDATION_ERROR');
        }

        if ($auth->role() !== 'INTEGRATOR') {
            $taxCode = '';
        }

        return array_map([PublicApi::class, 'report'], $this->reports->listVisibleForUser(
            $auth->id(),
            $auth->role(),
            $auth->user['patient_id'],
            $auth->user['doctor_id'],
            $taxCode
        ));
    }

    public function decryptReportForUser(int $reportId, AuthContext $auth): array
    {
        if ($auth->role() === 'RECEPTION') {
            throw new HttpException('La reception non può accedere ai referti', 403, 'FORBIDDEN');
        }

        $report = $this->reports->findById($reportId);
        if (!$report) {
            throw new HttpException('Referto non trovato', 404, 'NOT_FOUND');
        }

        $keyRow = $this->reports->findRecipientKey($reportId, $auth->id());
        if (!$keyRow) {
            throw new HttpException('Utente non autorizzato alla decrittazione del referto', 403, 'FORBIDDEN');
        }

        $aad = sprintf('report:%d:user:%d', $reportId, $auth->id());
        $dek = openssl_decrypt(
            base64_decode($keyRow['encrypted_dek'], true),
            self::KEK_WRAP_CIPHER,
            $this->masterKey(),
            OPENSSL_RAW_DATA,
            base64_decode($keyRow['iv'], true),
            base64_decode($keyRow['tag'], true),
            $aad
        );

        if ($dek === false) {
            throw new HttpException('Errore decrittazione DEK', 500, 'CRYPTO_ERROR');
        }

        $plaintext = openssl_decrypt(
            base64_decode($report['cipher_text'], true),
            $report['algorithm'],
            $dek,
            OPENSSL_RAW_DATA,
            base64_decode($report['iv'], true),
            base64_decode($report['tag'], true)
        );

        if ($plaintext === false) {
            throw new HttpException('Errore decrittazione contenuto referto', 500, 'CRYPTO_ERROR');
        }

        $payload = json_decode($plaintext, true);
        if (!is_array($payload)) {
            throw new HttpException('Payload referto non valido', 500, 'CRYPTO_ERROR');
        }

        return PublicApi::report([
            'id' => (int)$report['id'],
            'appointment_id' => (int)$report['appointment_id'],
            'created_at' => $report['created_at'],
            'visit_category' => $report['visit_category'],
            'scheduled_start' => $report['scheduled_start'],
            'started_at' => $report['started_at'] ?? null,
            'ended_at' => $report['ended_at'] ?? null,
            'visit_reason' => $report['visit_reason'] ?? null,
            'patient_first_name' => $report['patient_first_name'] ?? null,
            'patient_last_name' => $report['patient_last_name'] ?? null,
            'patient_tax_code' => $report['patient_tax_code'] ?? null,
            'doctor_first_name' => $report['doctor_first_name'] ?? null,
            'doctor_last_name' => $report['doctor_last_name'] ?? null,
            'report' => $payload,
        ]);
    }

    public function findByAppointment(int $appointmentId): ?array
    {
        return $this->reports->findByAppointmentId($appointmentId);
    }

    private function masterKey(): string
    {
        $raw = $this->config['report_master_key_b64'] ?? '';
        $decoded = base64_decode($raw, true);
        if ($decoded === false || $decoded === '') {
            $decoded = (string)$raw;
        }

        // Derive fixed 32-byte KEK for AES-256 from configured master key material.
        return hash('sha256', $decoded, true);
    }
}

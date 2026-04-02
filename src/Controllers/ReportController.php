<?php

// Controller dei referti clinici:
// elenco, dettaglio e stampa nel rispetto dei vincoli di accesso.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthContext;
use App\Core\Request;
use App\Services\ReportService;

final class ReportController
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function list(AuthContext $auth, Request $request): array
    {
        $taxCode = (string)$request->getQueryParam('tax_code', '');
        return $this->reports->listVisibleReports($auth, $taxCode);
    }

    public function detail(AuthContext $auth, Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        return $this->reports->decryptReportForUser($id, $auth);
    }

    public function download(AuthContext $auth, Request $request): array
    {
        $id = (int)$request->getRouteParam('id');
        $content = $this->reports->decryptReportForUser($id, $auth);
        $performedAt = $content['started_at'] ?: $content['scheduled_start'];
        $performedAtLabel = $this->formatDateTimeLabel($performedAt);

        $durationMinutes = null;
        if (!empty($content['started_at']) && !empty($content['ended_at'])) {
            try {
                $start = new \DateTimeImmutable((string)$content['started_at']);
                $end = new \DateTimeImmutable((string)$content['ended_at']);
                $durationMinutes = max(0, (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60));
            } catch (\Throwable) {
                $durationMinutes = null;
            }
        }

        $filename = 'referto-' . $id . '.html';
        $reportText = nl2br(htmlspecialchars((string)($content['report']['report_text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $html = '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Referto #' . $id . '</title>';
        $html .= '<style>';
        $html .= 'body{font-family:Georgia,serif;margin:32px;color:#222;}';
        $html .= '.sheet{max-width:840px;margin:0 auto;}';
        $html .= 'h1{margin:0 0 8px 0;font-size:30px;}';
        $html .= '.meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 24px;margin:20px 0 28px 0;}';
        $html .= '.label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#666;}';
        $html .= '.value{font-size:16px;font-weight:600;}';
        $html .= '.report{border:1px solid #d7d7d7;padding:20px;line-height:1.6;white-space:normal;}';
        $html .= '.actions{margin:0 0 24px 0;}';
        $html .= '.company{margin:0 0 20px 0;color:#4f4f4f;font-size:14px;}';
        $html .= '.footer{margin-top:18px;color:#666;font-size:12px;}';
        $html .= '@media print {.actions{display:none;} body{margin:0;} .sheet{max-width:none;}}';
        $html .= '</style></head><body><div class="sheet">';
        $html .= '<div class="actions"><button onclick="window.print()">Stampa</button></div>';
        $html .= '<h1>Tirano Salute s.r.l.</h1><p class="company">Referto clinico #' . $id . '</p>';
        $html .= '<div class="meta">';
        $html .= '<div><div class="label">Appuntamento</div><div class="value">#' . (int)$content['appointment_id'] . '</div></div>';
        $html .= '<div><div class="label">Categoria visita</div><div class="value">' . htmlspecialchars((string)$content['visit_category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
        $html .= '<div><div class="label">Paziente</div><div class="value">' . htmlspecialchars(trim((string)($content['patient_first_name'] ?? '-') . ' ' . (string)($content['patient_last_name'] ?? '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
        $html .= '<div><div class="label">Medico</div><div class="value">' . htmlspecialchars(trim((string)($content['doctor_first_name'] ?? '-') . ' ' . (string)($content['doctor_last_name'] ?? '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
        $html .= '<div><div class="label">Motivo visita</div><div class="value">' . htmlspecialchars((string)($content['visit_reason'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
        $html .= '<div><div class="label">Prestazione eseguita</div><div class="value">' . htmlspecialchars($performedAtLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
        $html .= '<div><div class="label">Durata</div><div class="value">' . htmlspecialchars($durationMinutes !== null ? $durationMinutes . ' minuti' : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
        $html .= '<div><div class="label">Creato il</div><div class="value">' . htmlspecialchars($this->formatDateTimeLabel($content['created_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
        $html .= '</div>';
        $html .= '<div class="report">' . $reportText . '</div>';
        $html .= '<div class="footer">Tirano Salute s.r.l. - Tel.: 0342-0087654</div>';
        $html .= '</div></body></html>';

        return [
            '__raw_response' => true,
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ],
            'body' => $html,
        ];
    }

    private function formatDateTimeLabel(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '-';
        }

        try {
            $dateTime = new \DateTimeImmutable($value);
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string)$value;
        }
    }
}

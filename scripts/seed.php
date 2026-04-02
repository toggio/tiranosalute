<?php

// Dataset dimostrativo usato per prove e schermate di progetto.
// Genera anagrafiche, turni, storico visite e referti con profili medici riconoscibili.

declare(strict_types=1);

$config = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Database;
use App\Repositories\AppointmentRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Services\ReportService;

$pdo = Database::getConnection($config);
$pdo->exec('PRAGMA foreign_keys = ON');

$tables = [
    'report_keys',
    'reports',
    'appointment_status_history',
    'appointments',
    'doctor_availability',
    'api_tokens',
    'web_sessions',
    'users',
    'patients',
    'doctors',
    'category_visits',
];

foreach ($tables as $table) {
    $pdo->exec('DELETE FROM ' . $table);
}
$pdo->exec('DELETE FROM sqlite_sequence');

$now = date('Y-m-d H:i:s');
$slotMinutes = (int)($config['appointment_slot_minutes'] ?? 15);

$insertCategory = $pdo->prepare('INSERT INTO category_visits (name) VALUES (:name)');
foreach ($config['visit_categories'] as $categoryName) {
    $insertCategory->execute(['name' => $categoryName]);
}

$categoryIds = [];
foreach ($pdo->query('SELECT id, name FROM category_visits ORDER BY id')->fetchAll() as $row) {
    $categoryIds[$row['name']] = (int)$row['id'];
}

$insertPatient = $pdo->prepare(
    'INSERT INTO patients (first_name, last_name, tax_code, email, created_at, updated_at)
     VALUES (:first_name, :last_name, :tax_code, :email, :created_at, :updated_at)'
);
$insertDoctor = $pdo->prepare(
    'INSERT INTO doctors (first_name, last_name, email, internal_code, active, created_at, updated_at)
     VALUES (:first_name, :last_name, :email, :internal_code, :active, :created_at, :updated_at)'
);
$insertUser = $pdo->prepare(
    'INSERT INTO users (role, email, password_hash, first_name, last_name, active, must_change_password, patient_id, doctor_id, created_at, updated_at)
     VALUES (:role, :email, :password_hash, :first_name, :last_name, :active, :must_change_password, :patient_id, :doctor_id, :created_at, :updated_at)'
);
$insertAvailability = $pdo->prepare(
    'INSERT INTO doctor_availability (doctor_id, weekday, start_time, end_time, valid_from, valid_to, created_at, updated_at)
     VALUES (:doctor_id, :weekday, :start_time, :end_time, NULL, NULL, :created_at, :updated_at)'
);
$insertAppointment = $pdo->prepare(
    'INSERT INTO appointments (
        patient_id, doctor_id, visit_category_id, visit_reason, notes,
        scheduled_start, scheduled_end, status, created_by_user_id,
        cancellation_by_role, cancellation_by_user_id, cancellation_reason, canceled_at,
        started_at, ended_at, created_at, updated_at
    ) VALUES (
        :patient_id, :doctor_id, :visit_category_id, :visit_reason, :notes,
        :scheduled_start, :scheduled_end, :status, :created_by_user_id,
        :cancellation_by_role, :cancellation_by_user_id, :cancellation_reason, :canceled_at,
        :started_at, :ended_at, :created_at, :updated_at
    )'
);
$insertHistory = $pdo->prepare(
    'INSERT INTO appointment_status_history (appointment_id, from_status, to_status, changed_by_user_id, changed_at, note)
     VALUES (:appointment_id, :from_status, :to_status, :changed_by_user_id, :changed_at, :note)'
);

$defaultPassword = 'Demo1234!';
$passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);

$insertUser->execute([
    'role' => 'RECEPTION',
    'email' => 'reception@tiranosalute.local',
    'password_hash' => $passwordHash,
    'first_name' => 'Sara',
    'last_name' => 'Bianchi',
    'active' => 1,
    'must_change_password' => 0,
    'patient_id' => null,
    'doctor_id' => null,
    'created_at' => $now,
    'updated_at' => $now,
]);
$receptionUserId = (int)$pdo->lastInsertId();

$insertUser->execute([
    'role' => 'INTEGRATOR',
    'email' => 'integrator@tiranosalute.local',
    'password_hash' => $passwordHash,
    'first_name' => 'Account',
    'last_name' => 'Integrator',
    'active' => 1,
    'must_change_password' => 0,
    'patient_id' => null,
    'doctor_id' => null,
    'created_at' => $now,
    'updated_at' => $now,
]);
$integratorUserId = (int)$pdo->lastInsertId();

$birthPlaceCodes = ['F205', 'H501'];
$monthCodes = [
    1 => 'A',
    2 => 'B',
    3 => 'C',
    4 => 'D',
    5 => 'E',
    6 => 'H',
    7 => 'L',
    8 => 'M',
    9 => 'P',
    10 => 'R',
    11 => 'S',
    12 => 'T',
];
$oddMap = [
    '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
    'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
    'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
    'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
];
$evenMap = [];
foreach (range('A', 'Z') as $index => $letter) {
    $evenMap[$letter] = $index;
}
foreach (range(0, 9) as $digit) {
    $evenMap[(string)$digit] = $digit;
}
$normalizeLetters = static function (string $value): string {
    $upper = strtoupper($value);
    return preg_replace('/[^A-Z]/', '', $upper) ?? '';
};
$encodeSurname = static function (string $surname) use ($normalizeLetters): string {
    $letters = $normalizeLetters($surname);
    $consonants = preg_replace('/[AEIOU]/', '', $letters) ?? '';
    $vowels = preg_replace('/[^AEIOU]/', '', $letters) ?? '';
    return str_pad(substr($consonants . $vowels . 'XXX', 0, 3), 3, 'X');
};
$encodeName = static function (string $name) use ($normalizeLetters): string {
    $letters = $normalizeLetters($name);
    $consonants = preg_replace('/[AEIOU]/', '', $letters) ?? '';
    if (strlen($consonants) >= 4) {
        return $consonants[0] . $consonants[2] . $consonants[3];
    }
    $vowels = preg_replace('/[^AEIOU]/', '', $letters) ?? '';
    return str_pad(substr($consonants . $vowels . 'XXX', 0, 3), 3, 'X');
};
$buildTaxCode = static function (
    string $firstName,
    string $lastName,
    string $birthDate,
    string $sex,
    string $birthPlaceCode
) use ($encodeSurname, $encodeName, $monthCodes, $oddMap, $evenMap): string {
    $date = new DateTimeImmutable($birthDate);
    $year = $date->format('y');
    $month = $monthCodes[(int)$date->format('n')] ?? 'A';
    $day = (int)$date->format('j');
    if (strtoupper($sex) === 'F') {
        $day += 40;
    }

    $partial = $encodeSurname($lastName)
        . $encodeName($firstName)
        . $year
        . $month
        . str_pad((string)$day, 2, '0', STR_PAD_LEFT)
        . strtoupper($birthPlaceCode);

    $sum = 0;
    $chars = str_split($partial);
    foreach ($chars as $index => $char) {
        $sum += (($index + 1) % 2 === 1)
            ? ($oddMap[$char] ?? 0)
            : ($evenMap[$char] ?? 0);
    }

    return $partial . chr(($sum % 26) + ord('A'));
};
$buildUniqueTaxCode = static function (
    string $firstName,
    string $lastName,
    string $birthDate,
    string $sex,
    string $birthPlaceCode,
    array $birthPlaceCodes,
    array &$usedTaxCodes
) use ($buildTaxCode): string {
    $date = new DateTimeImmutable($birthDate);
    $placeIndex = array_search($birthPlaceCode, $birthPlaceCodes, true);
    $placeIndex = $placeIndex === false ? 0 : (int)$placeIndex;

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $buildTaxCode(
            $firstName,
            $lastName,
            $date->format('Y-m-d'),
            $sex,
            $birthPlaceCodes[$placeIndex % count($birthPlaceCodes)]
        );
        if (!isset($usedTaxCodes[$candidate])) {
            $usedTaxCodes[$candidate] = true;
            return $candidate;
        }

        $date = $date->modify('+1 day');
        if ($attempt % 2 === 1) {
            $placeIndex++;
        }
    }

    throw new RuntimeException('Impossibile generare un codice fiscale univoco per ' . $firstName . ' ' . $lastName);
};

$patients = [
    ['Giulia', 'Rossi', 'giulia.rossi@example.com', '1990-01-01', 'F', 'F205'],
    ['Luca', 'Fontana', 'luca.fontana@example.com', '1987-03-10', 'M', 'H501'],
    ['Anna', 'Galli', 'anna.galli@example.com', '1992-04-10', 'F', 'F205'],
    ['Paolo', 'Greco', 'paolo.greco@example.com', '1984-05-20', 'M', 'H501'],
    ['Elena', 'Marini', 'elena.marini@example.com', '1995-06-20', 'F', 'F205'],
    ['Davide', 'Riva', 'davide.riva@example.com', '1989-07-10', 'M', 'H501'],
    ['Chiara', 'Testa', 'chiara.testa@example.com', '1991-08-01', 'F', 'F205'],
    ['Stefano', 'Villa', 'stefano.villa@example.com', '1986-07-10', 'M', 'H501'],
    ['Marta', 'Brenna', 'marta.brenna@example.com', '1992-09-01', 'F', 'F205'],
    ['Diego', 'Sanna', 'diego.sanna@example.com', '1988-01-10', 'M', 'H501'],
];

$extraFirstNames = [
    ['Andrea', 'M'], ['Beatrice', 'F'], ['Claudia', 'F'], ['Daniele', 'M'], ['Emanuele', 'M'],
    ['Federica', 'F'], ['Giorgio', 'M'], ['Irene', 'F'], ['Jacopo', 'M'], ['Laura', 'F'],
    ['Matteo', 'M'], ['Nicole', 'F'], ['Omar', 'M'], ['Patrizia', 'F'], ['Riccardo', 'M'],
    ['Serena', 'F'], ['Tommaso', 'M'], ['Valeria', 'F'], ['Walter', 'M'], ['Zoe', 'F'],
];
$extraLastNames = [
    'Colombo', 'Sala', 'Perego', 'Rossi', 'Bianchi', 'Ferrari', 'Fontana', 'Esposito', 'Gallo', 'Conti',
    'Bianco', 'Moretti', 'Bruno', 'Marino', 'Lombardi', 'Pagani', 'Ferrero', 'Cattaneo', 'Giordano', 'Cassano',
];
$usedTaxCodes = [];
$patientRows = [];

foreach ($patients as $patient) {
    [$firstName, $lastName, $email, $birthDate, $sex, $birthPlaceCode] = $patient;
    $patientRows[] = [
        $firstName,
        $lastName,
        $buildUniqueTaxCode($firstName, $lastName, $birthDate, $sex, $birthPlaceCode, $birthPlaceCodes, $usedTaxCodes),
        $email,
    ];
}

$patientIndex = 1;
while (count($patientRows) < 300) {
    [$firstName, $sex] = $extraFirstNames[$patientIndex % count($extraFirstNames)];
    $lastName = $extraLastNames[intdiv($patientIndex, count($extraFirstNames)) % count($extraLastNames)];
    $email = sprintf(
        '%s.%s.%03d@demo.tiranosalute.local',
        strtolower($firstName),
        strtolower($lastName),
        $patientIndex
    );
    $birthDate = sprintf(
        '%04d-%02d-%02d',
        1972 + ($patientIndex % 28),
        (($patientIndex * 3) % 12) + 1,
        (($patientIndex * 5) % 28) + 1
    );
    $birthPlaceCode = $birthPlaceCodes[$patientIndex % count($birthPlaceCodes)];
    $patientRows[] = [
        $firstName,
        $lastName,
        $buildUniqueTaxCode($firstName, $lastName, $birthDate, $sex, $birthPlaceCode, $birthPlaceCodes, $usedTaxCodes),
        $email,
    ];
    $patientIndex++;
}

$patientIds = [];
foreach ($patientRows as $patient) {
    $insertPatient->execute([
        'first_name' => $patient[0],
        'last_name' => $patient[1],
        'tax_code' => strtoupper($patient[2]),
        'email' => strtolower($patient[3]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $patientId = (int)$pdo->lastInsertId();
    $patientIds[] = $patientId;

    $insertUser->execute([
        'role' => 'PATIENT',
        'email' => strtolower($patient[3]),
        'password_hash' => $passwordHash,
        'first_name' => $patient[0],
        'last_name' => $patient[1],
        'active' => 1,
        'must_change_password' => 0,
        'patient_id' => $patientId,
        'doctor_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

$doctors = [
    ['Alberto', 'Neri', 'alberto.neri@tiranosalute.local', 'TS-MB-001'],
    ['Francesca', 'Conti', 'francesca.conti@tiranosalute.local', 'TS-MB-002'],
    ['Marta', 'Leoni', 'marta.leoni@tiranosalute.local', 'TS-MB-003'],
    ['Giovanni', 'Ferri', 'giovanni.ferri@tiranosalute.local', 'TS-MB-004'],
    ['Silvia', 'Pini', 'silvia.pini@tiranosalute.local', 'TS-MB-005'],
];

$doctorIds = [];
$doctorUserIds = [];
foreach ($doctors as $doctor) {
    $insertDoctor->execute([
        'first_name' => $doctor[0],
        'last_name' => $doctor[1],
        'email' => $doctor[2],
        'internal_code' => $doctor[3],
        'active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $doctorId = (int)$pdo->lastInsertId();
    $doctorIds[] = $doctorId;

    $insertUser->execute([
        'role' => 'DOCTOR',
        'email' => $doctor[2],
        'password_hash' => $passwordHash,
        'first_name' => $doctor[0],
        'last_name' => $doctor[1],
        'active' => 1,
        'must_change_password' => 0,
        'patient_id' => null,
        'doctor_id' => $doctorId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $doctorUserIds[$doctorId] = (int)$pdo->lastInsertId();
}

$doctorSchedule = [
    $doctorIds[0] => [
        1 => [['09:00', '11:00']],
        2 => [['09:00', '11:00']],
        3 => [['14:00', '16:00']],
        4 => [['09:00', '11:00']],
        5 => [['14:00', '16:00']],
    ],
    $doctorIds[1] => [
        1 => [['09:00', '11:00']],
        2 => [['14:00', '16:00']],
        3 => [['09:00', '11:00']],
        4 => [['09:00', '11:00']],
        5 => [['14:00', '16:00']],
    ],
    $doctorIds[2] => [
        1 => [['14:00', '16:00']],
        2 => [['09:00', '11:00']],
        3 => [['09:00', '11:00']],
        4 => [['14:00', '16:00']],
        5 => [['09:00', '11:00']],
    ],
    $doctorIds[3] => [
        1 => [['09:00', '11:00']],
        2 => [['14:00', '16:00']],
        3 => [['14:00', '16:00']],
        4 => [['09:00', '11:00']],
        5 => [['09:00', '11:00']],
    ],
    $doctorIds[4] => [
        1 => [['14:00', '16:00']],
        2 => [['09:00', '11:00']],
        3 => [['14:00', '16:00']],
        4 => [['14:00', '16:00']],
        5 => [['09:00', '11:00']],
    ],
];

foreach ($doctorSchedule as $doctorId => $weeklyWindows) {
    foreach ($weeklyWindows as $weekday => $windows) {
        foreach ($windows as $window) {
            $insertAvailability->execute([
                'doctor_id' => $doctorId,
                'weekday' => $weekday,
                'start_time' => $window[0],
                'end_time' => $window[1],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

$categories = $config['visit_categories'];
$doctorProfileIndex = array_flip($doctorIds);
$doctorCategoryProfiles = [
    $doctorIds[0] => [
        'prima visita' => ['duration_mean' => 13, 'duration_jitter' => 1, 'delay_mean' => 1, 'delay_jitter' => 1],
        'prescrizione' => ['duration_mean' => 9, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'certificato' => ['duration_mean' => 10, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'controllo esami' => ['duration_mean' => 12, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'visita di controllo' => ['duration_mean' => 13, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
    ],
    $doctorIds[1] => [
        'prima visita' => ['duration_mean' => 17, 'duration_jitter' => 2, 'delay_mean' => 3, 'delay_jitter' => 1],
        'prescrizione' => ['duration_mean' => 6, 'duration_jitter' => 1, 'delay_mean' => 0, 'delay_jitter' => 1],
        'certificato' => ['duration_mean' => 9, 'duration_jitter' => 1, 'delay_mean' => 1, 'delay_jitter' => 1],
        'controllo esami' => ['duration_mean' => 13, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'visita di controllo' => ['duration_mean' => 14, 'duration_jitter' => 2, 'delay_mean' => 3, 'delay_jitter' => 1],
    ],
    $doctorIds[2] => [
        'prima visita' => ['duration_mean' => 17, 'duration_jitter' => 2, 'delay_mean' => 4, 'delay_jitter' => 1],
        'prescrizione' => ['duration_mean' => 10, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'certificato' => ['duration_mean' => 11, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'controllo esami' => ['duration_mean' => 7, 'duration_jitter' => 1, 'delay_mean' => 0, 'delay_jitter' => 1],
        'visita di controllo' => ['duration_mean' => 12, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
    ],
    $doctorIds[3] => [
        'prima visita' => ['duration_mean' => 18, 'duration_jitter' => 2, 'delay_mean' => 5, 'delay_jitter' => 1],
        'prescrizione' => ['duration_mean' => 9, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'certificato' => ['duration_mean' => 4, 'duration_jitter' => 1, 'delay_mean' => 0, 'delay_jitter' => 1],
        'controllo esami' => ['duration_mean' => 12, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'visita di controllo' => ['duration_mean' => 14, 'duration_jitter' => 2, 'delay_mean' => 3, 'delay_jitter' => 1],
    ],
    $doctorIds[4] => [
        'prima visita' => ['duration_mean' => 16, 'duration_jitter' => 2, 'delay_mean' => 3, 'delay_jitter' => 1],
        'prescrizione' => ['duration_mean' => 10, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'certificato' => ['duration_mean' => 11, 'duration_jitter' => 1, 'delay_mean' => 2, 'delay_jitter' => 1],
        'controllo esami' => ['duration_mean' => 11, 'duration_jitter' => 1, 'delay_mean' => 1, 'delay_jitter' => 1],
        'visita di controllo' => ['duration_mean' => 8, 'duration_jitter' => 1, 'delay_mean' => 0, 'delay_jitter' => 1],
    ],
];
$doctorCategoryRotation = [
    $doctorIds[0] => ['prima visita', 'controllo esami', 'prima visita', 'visita di controllo', 'prescrizione', 'prima visita', 'certificato', 'controllo esami', 'visita di controllo', 'prima visita'],
    $doctorIds[1] => ['prescrizione', 'certificato', 'prescrizione', 'visita di controllo', 'prima visita', 'prescrizione', 'controllo esami', 'certificato', 'prescrizione', 'visita di controllo'],
    $doctorIds[2] => ['controllo esami', 'visita di controllo', 'controllo esami', 'prima visita', 'prescrizione', 'controllo esami', 'certificato', 'visita di controllo', 'controllo esami', 'prima visita'],
    $doctorIds[3] => ['certificato', 'prescrizione', 'certificato', 'prima visita', 'controllo esami', 'certificato', 'visita di controllo', 'prescrizione', 'certificato', 'prima visita'],
    $doctorIds[4] => ['visita di controllo', 'prima visita', 'visita di controllo', 'controllo esami', 'certificato', 'visita di controllo', 'prescrizione', 'prima visita', 'visita di controllo', 'controllo esami'],
];
$doctorDailyVolume = [
    $doctorIds[0] => [2, 3],
    $doctorIds[1] => [2, 3],
    $doctorIds[2] => [2, 3],
    $doctorIds[3] => [2, 3],
    $doctorIds[4] => [2, 3],
];
$doctorOperationalBias = [
    $doctorIds[0] => ['duration_shift' => -1, 'delay_shift' => -1, 'overrun_chance' => 6, 'overrun_min' => 1, 'overrun_max' => 2],
    $doctorIds[1] => ['duration_shift' => -1, 'delay_shift' => 0, 'overrun_chance' => 8, 'overrun_min' => 1, 'overrun_max' => 2],
    $doctorIds[2] => ['duration_shift' => 1, 'delay_shift' => 1, 'overrun_chance' => 12, 'overrun_min' => 1, 'overrun_max' => 3],
    $doctorIds[3] => ['duration_shift' => 2, 'delay_shift' => 2, 'overrun_chance' => 16, 'overrun_min' => 2, 'overrun_max' => 4],
    $doctorIds[4] => ['duration_shift' => 1, 'delay_shift' => 0, 'overrun_chance' => 10, 'overrun_min' => 1, 'overrun_max' => 3],
];
$reasonByCategory = [
    'prima visita' => ['Dolore ricorrente', 'Stanchezza cronica', 'Nuovo consulto generale', 'Sintomi respiratori persistenti'],
    'prescrizione' => ['Rinnovo terapia', 'Adeguamento dosaggio', 'Piano terapeutico trimestrale'],
    'certificato' => ['Certificato sportivo non agonistico', 'Certificato malattia breve', 'Certificato idoneita lavorativa'],
    'controllo esami' => ['Discussione esami ematici', 'Follow-up ecografia', 'Valutazione referti di laboratorio'],
    'visita di controllo' => ['Follow-up terapia', 'Controllo pressione', 'Controllo andamento clinico'],
];

$buildSlotsForDate = static function (string $date, array $windows, int $slotMinutes): array {
    $slots = [];
    foreach ($windows as $window) {
        $cursor = strtotime($date . ' ' . $window[0]);
        $endTs = strtotime($date . ' ' . $window[1]);
        while ($cursor !== false && $cursor + ($slotMinutes * 60) <= $endTs) {
            $slots[] = date('Y-m-d H:i:s', $cursor);
            $cursor += $slotMinutes * 60;
        }
    }

    return $slots;
};

mt_srand(83);

$createdHistorical = 0;
$createdFuture = 0;
$createdCancelled = 0;
$occupiedPatientStarts = [];
$patientsWithActiveFuture = [];

$pickAvailablePatientId = static function (
    string $start,
    array $patientIds,
    array &$occupiedPatientStarts,
    array &$patientsWithActiveFuture,
    bool $requireFreeActiveSlot = false
): int {
    $pool = $patientIds;
    shuffle($pool);

    foreach ($pool as $patientId) {
        if ($requireFreeActiveSlot && isset($patientsWithActiveFuture[$patientId])) {
            continue;
        }

        if (!isset($occupiedPatientStarts[$patientId][$start])) {
            $occupiedPatientStarts[$patientId][$start] = true;
            if ($requireFreeActiveSlot) {
                $patientsWithActiveFuture[$patientId] = true;
            }
            return $patientId;
        }
    }

    throw new RuntimeException('Impossibile assegnare un paziente libero per lo slot ' . $start);
};

for ($daysAgo = 98; $daysAgo >= 4; $daysAgo--) {
    $date = date('Y-m-d', strtotime('-' . $daysAgo . ' days'));
    $weekday = (int)date('N', strtotime($date));
    if ($weekday > 5) {
        continue;
    }

    foreach ($doctorSchedule as $doctorId => $weeklyWindows) {
        $windows = $weeklyWindows[$weekday] ?? [];
        if ($windows === []) {
            continue;
        }

        [$minDaily, $maxDaily] = $doctorDailyVolume[$doctorId];
        $target = $minDaily + (($daysAgo + $doctorProfileIndex[$doctorId]) % (($maxDaily - $minDaily) + 1));
        if ($target === 0) {
            continue;
        }

        $slotPool = $buildSlotsForDate($date, $windows, $slotMinutes);
        shuffle($slotPool);
        $selectedSlots = array_slice($slotPool, 0, min($target, count($slotPool)));
        sort($selectedSlots);
        $previousEndedAtTs = null;

        foreach ($selectedSlots as $slotIndex => $start) {
            $end = date('Y-m-d H:i:s', strtotime($start . ' +' . $slotMinutes . ' minutes'));
            $rotation = $doctorCategoryRotation[$doctorId];
            $categoryName = $rotation[($daysAgo + $doctorProfileIndex[$doctorId] + $slotIndex) % count($rotation)];
            $profile = $doctorCategoryProfiles[$doctorId][$categoryName];
            $operationalBias = $doctorOperationalBias[$doctorId];
            $reasonOptions = $reasonByCategory[$categoryName];
            $reason = $reasonOptions[mt_rand(0, count($reasonOptions) - 1)];
            $notes = mt_rand(0, 100) > 74 ? 'Annotazioni cliniche demo sintetiche' : '';
            $patientId = $pickAvailablePatientId($start, $patientIds, $occupiedPatientStarts, $patientsWithActiveFuture);

            $delay = max(
                0,
                $profile['delay_mean']
                + mt_rand(-$profile['delay_jitter'], $profile['delay_jitter'])
                + $operationalBias['delay_shift']
            );
            $scheduledStartTs = strtotime($start);
            $rawStartedAtTs = $scheduledStartTs + ($delay * 60);
            $startedAtTs = $previousEndedAtTs === null ? $rawStartedAtTs : max($rawStartedAtTs, $previousEndedAtTs);
            $startedAt = date('Y-m-d H:i:s', $startedAtTs);
            $duration = $profile['duration_mean']
                + mt_rand(-$profile['duration_jitter'], $profile['duration_jitter'])
                + $operationalBias['duration_shift'];
            if (mt_rand(1, 100) <= $operationalBias['overrun_chance']) {
                $duration += mt_rand($operationalBias['overrun_min'], $operationalBias['overrun_max']);
            }
            $duration = max(5, min(30, $duration));
            $endedAtTs = $startedAtTs + ($duration * 60);
            $endedAt = date('Y-m-d H:i:s', $endedAtTs);
            $previousEndedAtTs = $endedAtTs;
            $createdAt = date('Y-m-d H:i:s', strtotime($start . ' -' . mt_rand(1, 10) . ' days -' . mt_rand(1, 9) . ' hours'));

            $insertAppointment->execute([
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'visit_category_id' => $categoryIds[$categoryName],
                'visit_reason' => $reason,
                'notes' => $notes,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'status' => 'CONCLUSA',
                'created_by_user_id' => $receptionUserId,
                'cancellation_by_role' => null,
                'cancellation_by_user_id' => null,
                'cancellation_reason' => null,
                'canceled_at' => null,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'created_at' => $createdAt,
                'updated_at' => $endedAt,
            ]);
            $appointmentId = (int)$pdo->lastInsertId();
            $createdHistorical++;

            $insertHistory->execute([
                'appointment_id' => $appointmentId,
                'from_status' => null,
                'to_status' => 'PRENOTATA',
                'changed_by_user_id' => $receptionUserId,
                'changed_at' => $createdAt,
                'note' => 'Prenotazione demo',
            ]);
            $insertHistory->execute([
                'appointment_id' => $appointmentId,
                'from_status' => 'PRENOTATA',
                'to_status' => 'IN_CORSO',
                'changed_by_user_id' => $doctorUserIds[$doctorId],
                'changed_at' => $startedAt,
                'note' => null,
            ]);
            $insertHistory->execute([
                'appointment_id' => $appointmentId,
                'from_status' => 'IN_CORSO',
                'to_status' => 'CONCLUSA',
                'changed_by_user_id' => $doctorUserIds[$doctorId],
                'changed_at' => $endedAt,
                'note' => null,
            ]);
        }
    }
}

$futureSlotPool = [];
for ($dayOffset = 10; $dayOffset <= 35; $dayOffset++) {
    $date = date('Y-m-d', strtotime('+' . $dayOffset . ' days'));
    $weekday = (int)date('N', strtotime($date));
    if ($weekday > 5) {
        continue;
    }

    foreach ($doctorSchedule as $doctorId => $weeklyWindows) {
        $windows = $weeklyWindows[$weekday] ?? [];
        foreach ($buildSlotsForDate($date, $windows, $slotMinutes) as $slotStart) {
            $futureSlotPool[] = [
                'doctor_id' => $doctorId,
                'slot_start' => $slotStart,
            ];
        }
    }
}

usort($futureSlotPool, static fn(array $a, array $b): int => [$a['slot_start'], $a['doctor_id']] <=> [$b['slot_start'], $b['doctor_id']]);

$futureBookedSlots = [];
$usedFutureSlotKeys = [];
for ($i = 0; $i < count($futureSlotPool) && count($futureBookedSlots) < 18; $i += 4) {
    $slot = $futureSlotPool[$i];
    $key = $slot['doctor_id'] . '@' . $slot['slot_start'];
    if (isset($usedFutureSlotKeys[$key])) {
        continue;
    }

    $futureBookedSlots[] = $slot;
    $usedFutureSlotKeys[$key] = true;
}

$futureCancelledSlots = [];
for ($i = 2; $i < count($futureSlotPool) && count($futureCancelledSlots) < 10; $i += 6) {
    $slot = $futureSlotPool[$i];
    $key = $slot['doctor_id'] . '@' . $slot['slot_start'];
    if (isset($usedFutureSlotKeys[$key])) {
        continue;
    }

    $futureCancelledSlots[] = $slot;
    $usedFutureSlotKeys[$key] = true;
}

foreach ($futureBookedSlots as $index => $slot) {
    $start = $slot['slot_start'];
    $end = date('Y-m-d H:i:s', strtotime($start . ' +' . $slotMinutes . ' minutes'));
    $doctorId = (int)$slot['doctor_id'];
    $categoryName = $categories[$index % count($categories)];
    $reasonOptions = $reasonByCategory[$categoryName];
    $reason = $reasonOptions[$index % count($reasonOptions)];
    $patientId = $pickAvailablePatientId($start, $patientIds, $occupiedPatientStarts, $patientsWithActiveFuture, true);
    $createdAt = date('Y-m-d H:i:s', strtotime($start . ' -' . mt_rand(2, 14) . ' days -' . mt_rand(1, 6) . ' hours'));

    $insertAppointment->execute([
        'patient_id' => $patientId,
        'doctor_id' => $doctorId,
        'visit_category_id' => $categoryIds[$categoryName],
        'visit_reason' => $reason,
        'notes' => 'Prenotazione demo futura',
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'status' => 'PRENOTATA',
        'created_by_user_id' => $receptionUserId,
        'cancellation_by_role' => null,
        'cancellation_by_user_id' => null,
        'cancellation_reason' => null,
        'canceled_at' => null,
        'started_at' => null,
        'ended_at' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $appointmentId = (int)$pdo->lastInsertId();
    $createdFuture++;

    $insertHistory->execute([
        'appointment_id' => $appointmentId,
        'from_status' => null,
        'to_status' => 'PRENOTATA',
        'changed_by_user_id' => $receptionUserId,
        'changed_at' => $createdAt,
        'note' => 'Prenotazione demo futura',
    ]);
}

foreach ($futureCancelledSlots as $index => $slot) {
    $start = $slot['slot_start'];
    $end = date('Y-m-d H:i:s', strtotime($start . ' +' . $slotMinutes . ' minutes'));
    $doctorId = (int)$slot['doctor_id'];
    $patientId = $pickAvailablePatientId($start, $patientIds, $occupiedPatientStarts, $patientsWithActiveFuture);
    $categoryName = $categories[($index + 2) % count($categories)];
    $createdAt = date('Y-m-d H:i:s', strtotime($start . ' -' . mt_rand(3, 12) . ' days -' . mt_rand(1, 5) . ' hours'));
    $cancelledAt = date('Y-m-d H:i:s', strtotime($start . ' -8 hours'));

    $insertAppointment->execute([
        'patient_id' => $patientId,
        'doctor_id' => $doctorId,
        'visit_category_id' => $categoryIds[$categoryName],
        'visit_reason' => 'Prenotazione non confermata',
        'notes' => '',
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'status' => 'ANNULLATA',
        'created_by_user_id' => $receptionUserId,
        'cancellation_by_role' => 'RECEPTION',
        'cancellation_by_user_id' => $receptionUserId,
        'cancellation_reason' => 'Agenda riorganizzata',
        'canceled_at' => $cancelledAt,
        'started_at' => null,
        'ended_at' => null,
        'created_at' => $createdAt,
        'updated_at' => $cancelledAt,
    ]);
    $appointmentId = (int)$pdo->lastInsertId();
    $createdCancelled++;

    $insertHistory->execute([
        'appointment_id' => $appointmentId,
        'from_status' => null,
        'to_status' => 'PRENOTATA',
        'changed_by_user_id' => $receptionUserId,
        'changed_at' => $createdAt,
        'note' => 'Prenotazione demo',
    ]);
    $insertHistory->execute([
        'appointment_id' => $appointmentId,
        'from_status' => 'PRENOTATA',
        'to_status' => 'ANNULLATA',
        'changed_by_user_id' => $receptionUserId,
        'changed_at' => $cancelledAt,
        'note' => 'Agenda riorganizzata',
    ]);
}

$autoCancelledSlot = null;
for ($daysBack = 2; $daysBack <= 10; $daysBack++) {
    $date = date('Y-m-d', strtotime('-' . $daysBack . ' days'));
    $weekday = (int)date('N', strtotime($date));
    $windows = $doctorSchedule[$doctorIds[0]][$weekday] ?? [];
    if ($windows === []) {
        continue;
    }

    $slots = $buildSlotsForDate($date, $windows, $slotMinutes);
    if ($slots === []) {
        continue;
    }

    $autoCancelledSlot = [
        'doctor_id' => $doctorIds[0],
        'slot_start' => $slots[0],
    ];
    break;
}

if ($autoCancelledSlot !== null) {
    $start = $autoCancelledSlot['slot_start'];
    $end = date('Y-m-d H:i:s', strtotime($start . ' +' . $slotMinutes . ' minutes'));
    $doctorId = (int)$autoCancelledSlot['doctor_id'];
    $patientId = $pickAvailablePatientId($start, $patientIds, $occupiedPatientStarts, $patientsWithActiveFuture);
    $categoryName = $categories[0];
    $createdAt = date('Y-m-d H:i:s', strtotime($start . ' -3 days -2 hours'));
    $cancelledAt = date('Y-m-d H:i:s', strtotime($start . ' +13 hours'));

    $insertAppointment->execute([
        'patient_id' => $patientId,
        'doctor_id' => $doctorId,
        'visit_category_id' => $categoryIds[$categoryName],
        'visit_reason' => 'Prenotazione demo scaduta',
        'notes' => '',
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'status' => 'ANNULLATA',
        'created_by_user_id' => $receptionUserId,
        'cancellation_by_role' => null,
        'cancellation_by_user_id' => null,
        'cancellation_reason' => 'scaduta',
        'canceled_at' => $cancelledAt,
        'started_at' => null,
        'ended_at' => null,
        'created_at' => $createdAt,
        'updated_at' => $cancelledAt,
    ]);
    $appointmentId = (int)$pdo->lastInsertId();
    $createdCancelled++;

    $insertHistory->execute([
        'appointment_id' => $appointmentId,
        'from_status' => null,
        'to_status' => 'PRENOTATA',
        'changed_by_user_id' => $receptionUserId,
        'changed_at' => $createdAt,
        'note' => 'Prenotazione demo',
    ]);
    $insertHistory->execute([
        'appointment_id' => $appointmentId,
        'from_status' => 'PRENOTATA',
        'to_status' => 'ANNULLATA',
        'changed_by_user_id' => null,
        'changed_at' => $cancelledAt,
        'note' => 'scaduta',
    ]);
}

$appointmentRepo = new AppointmentRepository($pdo);
$reportRepo = new ReportRepository($pdo);
$userRepo = new UserRepository($pdo);
$reportService = new ReportService($reportRepo, $appointmentRepo, $userRepo, $config);

$completedIds = $pdo->query("SELECT id FROM appointments WHERE status = 'CONCLUSA' ORDER BY id ASC")->fetchAll();
$reportsCreated = 0;
foreach ($completedIds as $row) {
    $appointment = $appointmentRepo->findById((int)$row['id']);
    if (!$appointment) {
        continue;
    }

    $reason = $appointment['visit_reason'] ?? 'Valutazione clinica';
    $reportText = sprintf(
        "Referto visita %s.\nSintesi clinica: %s.\nDecorso regolare e indicazioni terapeutiche fornite.\nControllo consigliato secondo piano clinico.",
        $appointment['visit_category'],
        $reason
    );
    $reportService->createForCompletedAppointment($appointment, $reportText);
    $reportsCreated++;
}

$counts = [
    'patients' => (int)$pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
    'doctors' => (int)$pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn(),
    'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'categories' => (int)$pdo->query('SELECT COUNT(*) FROM category_visits')->fetchColumn(),
    'historical_appointments' => $createdHistorical,
    'future_appointments' => $createdFuture,
    'cancelled_appointments' => $createdCancelled,
    'reports_created' => $reportsCreated,
    'integrator_users' => 1,
];

echo "Seed completato\n";
foreach ($counts as $key => $value) {
    echo "- {$key}: {$value}\n";
}

echo "\nCredenziali demo (password comune: {$defaultPassword})\n";
echo "- INTEGRATOR: integrator@tiranosalute.local\n";
echo "- RECEPTION: reception@tiranosalute.local\n";
echo "- DOCTOR: alberto.neri@tiranosalute.local\n";
echo "- PATIENT: giulia.rossi@example.com\n";

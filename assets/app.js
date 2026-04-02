/*
 * Interfaccia Vue con dipendenze locali vendorizzate.
 * Riunisce router, stato globale, dashboard, prenotazioni, anagrafiche e referti.
 */
(() => {
    const { createApp, reactive, ref, computed, onMounted, watch } = Vue;
    const { createRouter, createWebHashHistory } = VueRouter;

    const state = reactive({
        initialized: false,
        apiBase: '/api',
        basePath: '',
        user: null,
        csrfToken: '',
        csrfCookieName: 'ts_csrf',
        flash: { type: '', text: '' },
    });

    const weekdays = [
        { value: 1, label: 'Lunedì' },
        { value: 2, label: 'Martedì' },
        { value: 3, label: 'Mercoledì' },
        { value: 4, label: 'Giovedì' },
        { value: 5, label: 'Venerdì' },
        { value: 6, label: 'Sabato' },
        { value: 7, label: 'Domenica' },
    ];
    const MIN_PASSWORD_LENGTH = 8;

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function toApiDateTime(date) {
        return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())} ${pad2(date.getHours())}:${pad2(date.getMinutes())}:00`;
    }

    function parseApiDateTime(value) {
        if (!value) return null;

        const raw = String(value).trim();
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
        if (match) {
            return new Date(
                Number(match[1]),
                Number(match[2]) - 1,
                Number(match[3]),
                Number(match[4]),
                Number(match[5]),
                Number(match[6])
            );
        }

        const parsed = new Date(raw);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function formatDateTime(value) {
        if (!value) return '-';

        const raw = String(value).trim();
        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) {
            return raw;
        }

        const normalized = raw.replace('T', ' ').replace(/(\.\d+)?Z$/, '');
        const match = normalized.match(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/);
        if (match) {
            return `${match[1]} ${match[2]}`;
        }

        const parsed = parseApiDateTime(raw);
        if (!parsed) {
            return raw;
        }

        return `${parsed.getFullYear()}-${pad2(parsed.getMonth() + 1)}-${pad2(parsed.getDate())} ${pad2(parsed.getHours())}:${pad2(parsed.getMinutes())}:${pad2(parsed.getSeconds())}`;
    }

    function translateApiError(message) {
        const normalized = String(message || '').trim();
        if (!normalized) {
            return 'Si è verificato un errore inatteso.';
        }

        const exactTranslations = {
            'email e password sono obbligatori': 'Email e Password sono obbligatori.',
            'from, to e visit_category sono obbligatori': 'Intervallo e categoria visita sono obbligatori.',
            'visit_category e visit_reason sono obbligatori': 'Categoria visita e motivo sono obbligatori.',
            'slot_start e selected_doctor_id sono obbligatori': 'Slot e medico selezionato sono obbligatori.',
            'slot_start non valido': 'Lo slot selezionato non è valido.',
            'Payload JSON non valido': 'Il payload JSON non è valido.',
            'reason è obbligatorio': "Il motivo dell'annullamento è obbligatorio.",
            'report_text è obbligatorio': 'Il referto è obbligatorio.',
            'patient_id non valido': 'Seleziona un paziente valido.',
            'tax_code è obbligatorio per INTEGRATOR': "Per l'account integrator il codice fiscale è obbligatorio.",
            'Categoria visita non valida': 'La categoria visita selezionata non è valida.',
            'Intervallo disponibilità non valido': "L'intervallo di ricerca non è valido.",
            'Codice fiscale già in uso': 'Il codice fiscale indicato è già in uso.',
            'Codice interno già in uso': 'Il codice interno indicato è già in uso.',
            'first_name, last_name, tax_code, email e password sono obbligatori': 'Nome, Cognome, Codice fiscale, Email e Password sono obbligatori.',
            'first_name, last_name, email, internal_code e password sono obbligatori': 'Nome, Cognome, Email, Codice interno e Password sono obbligatori.',
            'first_name, last_name, email e password sono obbligatori': 'Nome, Cognome, Email e Password sono obbligatori.',
            'first_name, last_name ed email sono obbligatori': 'Nome, Cognome ed Email sono obbligatori.',
            'Da questa API puoi creare solo utenti RECEPTION': 'Puoi creare solo utenti reception.',
            'Il ruolo dello staff gestibile da questa API è solo RECEPTION': 'Il ruolo gestibile da questa schermata è solo reception.',
            'Email già in uso': "L'email indicata è già in uso.",
            'La password iniziale deve avere almeno 8 caratteri': 'La password iniziale deve avere almeno 8 caratteri.',
            'La nuova password deve avere almeno 8 caratteri': 'La nuova password deve avere almeno 8 caratteri.',
            'La password di reset deve avere almeno 8 caratteri': 'La password di reset deve avere almeno 8 caratteri.',
        };

        if (exactTranslations[normalized]) {
            return exactTranslations[normalized];
        }

        if (normalized.startsWith('Disponibilità non valida alla riga ')) {
            return normalized + '.';
        }
        if (normalized.startsWith('Intervallo orario non valido alla riga ')) {
            return normalized + '.';
        }

        return normalized;
    }

    function humanStatus(status) {
        return status === 'IN_CORSO' ? 'IN CORSO' : status;
    }

    function roleLabel(role) {
        if (role === 'PATIENT') return 'Paziente';
        if (role === 'DOCTOR') return 'Medico';
        if (role === 'RECEPTION') return 'Reception';
        if (role === 'INTEGRATOR') return 'Integrator';
        return role;
    }

    function doctorFullName(doctor) {
        if (!doctor) return '-';
        return `${doctor.first_name || ''} ${doctor.last_name || ''}`.trim();
    }

    function normalizedPassword(value) {
        return String(value ?? '').trim();
    }

    function passwordValidationMessage(password, isReset = false) {
        const normalized = normalizedPassword(password);
        if (normalized !== '' && normalized.length < MIN_PASSWORD_LENGTH) {
            return isReset
                ? 'La password di reset deve avere almeno 8 caratteri.'
                : 'La password iniziale deve avere almeno 8 caratteri.';
        }

        return '';
    }

    function isValidEmail(value) {
        const normalized = String(value ?? '').trim();
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized);
    }

    function patientCancelDeadline(scheduledStart) {
        const visitDate = parseApiDateTime(scheduledStart);
        if (!visitDate) return null;

        return new Date(
            visitDate.getFullYear(),
            visitDate.getMonth(),
            visitDate.getDate() - 1,
            23,
            59,
            59
        );
    }

    function availabilityOptionLabel(doctor, isRecommended) {
        const base = doctorFullName(doctor);
        return isRecommended ? `${base} (consigliato)` : base;
    }

    function setFlash(type, text) {
        state.flash.type = type;
        state.flash.text = text;
        if (!text) {
            return;
        }
        setTimeout(() => {
            if (state.flash.text === text) {
                state.flash.type = '';
                state.flash.text = '';
            }
        }, 3200);
    }

    function readCookie(name) {
        const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${escaped}=([^;]*)`));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function syncCsrfTokenFromCookie() {
        state.csrfToken = readCookie(state.csrfCookieName || 'ts_csrf');
    }

    async function api(path, options = {}) {
        const method = options.method || 'GET';
        const headers = { Accept: 'application/json' };
        const isWrite = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method.toUpperCase());

        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        if (isWrite) {
            syncCsrfTokenFromCookie();
        }
        if (isWrite && state.csrfToken) {
            headers['X-CSRF-Token'] = state.csrfToken;
        }
        if (options.headers) {
            Object.assign(headers, options.headers);
        }

        const res = await fetch(state.apiBase + path, {
            method,
            headers,
            credentials: 'include',
            body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
        });

        let payload = {};
        try {
            payload = await res.json();
        } catch (_) {
        }

        if (!res.ok) {
            throw new Error(translateApiError(payload?.error?.message || `Errore HTTP ${res.status}`));
        }

        return payload.data !== undefined ? payload.data : payload;
    }

    function roleHome(role) {
        if (role === 'PATIENT') return '/patient/dashboard';
        if (role === 'DOCTOR') return '/doctor/dashboard';
        if (role === 'RECEPTION') return '/reception/dashboard';
        if (role === 'INTEGRATOR') return '/integrator/dashboard';
        return '/login';
    }

    function canReadReports(role) {
        return role === 'PATIENT' || role === 'DOCTOR' || role === 'INTEGRATOR';
    }

    function canInlineReport(role) {
        return canReadReports(role);
    }

    function historyActorLabel(entry) {
        if (!entry) return '-';

        const name = `${entry.first_name || ''} ${entry.last_name || ''}`.trim();
        if (!name && !entry.role) {
            return 'Sistema';
        }
        if (!name) {
            return roleLabel(entry.role);
        }
        if (!entry.role) {
            return name;
        }

        return `${name} (${roleLabel(entry.role)})`;
    }

    async function bootstrap() {
        if (state.initialized) return;

        try {
            const metaRes = await fetch('api/meta', { credentials: 'include' });
            const metaPayload = await metaRes.json();
            const meta = metaPayload.data || {};
            state.apiBase = meta.api_base || '/api';
            state.basePath = meta.base_path || '';
            state.csrfCookieName = meta.csrf_cookie_name || 'ts_csrf';
        } catch (_) {
            state.apiBase = '/api';
            state.basePath = '';
            state.csrfCookieName = 'ts_csrf';
        }

        try {
            state.user = await api('/me');
            syncCsrfTokenFromCookie();
        } catch (_) {
            state.user = null;
            state.csrfToken = '';
        }

        state.initialized = true;
    }

    const StatusBadge = {
        props: ['status'],
        computed: {
            cls() {
                if (this.status === 'ANNULLATA') return 'badge error';
                if (this.status === 'IN_CORSO') return 'badge warning';
                if (this.status === 'CONCLUSA') return 'badge success';
                if (this.status === 'PRENOTATA') return 'badge info';
                return 'badge';
            },
            label() {
                return humanStatus(this.status);
            },
        },
        template: `<span :class="cls">{{ label }}</span>`,
    };

    const LoginView = {
        setup() {
            const form = reactive({ email: '', password: '' });
            const loading = ref(false);
            const error = ref('');
            const swaggerUrl = computed(() => (state.basePath || '') + '/swagger');
            const quickUsers = [
                { label: 'Paziente', email: 'giulia.rossi@example.com' },
                { label: 'Medico', email: 'alberto.neri@tiranosalute.local' },
                { label: 'Reception', email: 'reception@tiranosalute.local' },
                { label: 'Integrator', email: 'integrator@tiranosalute.local' },
            ];

            function fillDemo(email) {
                form.email = email;
                form.password = 'Demo1234!';
            }

            async function submit() {
                loading.value = true;
                error.value = '';
                try {
                    const data = await api('/login', { method: 'POST', body: form });
                    state.user = data.user;
                    syncCsrfTokenFromCookie();
                    if (state.user.must_change_password) {
                        setFlash('success', 'Accesso effettuato. Prima di continuare aggiorna la password.');
                        router.push('/change-password');
                    } else {
                        setFlash('success', 'Accesso effettuato');
                        router.push(roleHome(state.user.role));
                    }
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            return { form, loading, error, submit, swaggerUrl, quickUsers, fillDemo };
        },
        template: `
        <div class="auth-page">
            <div class="auth-box card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:10px;">
                    <h1>Tirano Salute</h1>
                    <a class="btn-muted" :href="swaggerUrl" target="_blank">Swagger/OpenAPI</a>
                </div>
                <div v-if="error" class="alert error">{{ error }}</div>
                <form class="grid" @submit.prevent="submit">
                    <div>
                        <label>Email</label>
                        <input v-model="form.email" type="email" />
                    </div>
                    <div>
                        <label>Password</label>
                        <input v-model="form.password" type="password" />
                    </div>
                    <div class="toolbar">
                        <button
                            type="button"
                            class="btn-muted"
                            v-for="u in quickUsers"
                            :key="u.label"
                            @click="fillDemo(u.email)"
                        >
                            {{ u.label }}
                        </button>
                    </div>
                    <button type="submit" class="btn-primary" :disabled="loading">
                        {{ loading ? 'Accesso...' : 'Accedi' }}
                    </button>
                </form>
            </div>
        </div>`,
    };

    const RoleDashboard = {
        props: ['title'],
        setup() {
            const loading = ref(true);
            const error = ref('');
            const kpi = reactive({
                appointments: 0,
                booked: 0,
                inProgress: 0,
                completed: 0,
                cancelled: 0,
            });

            onMounted(async () => {
                try {
                    const list = await api('/appointments');
                    kpi.appointments = list.length;
                    kpi.booked = list.filter((x) => x.status === 'PRENOTATA').length;
                    kpi.inProgress = list.filter((x) => x.status === 'IN_CORSO').length;
                    kpi.completed = list.filter((x) => x.status === 'CONCLUSA').length;
                    kpi.cancelled = list.filter((x) => x.status === 'ANNULLATA').length;
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            });

            return { loading, error, kpi };
        },
        template: `
        <div>
            <h2>{{ title }}</h2>
            <div class="card" v-if="loading">Caricamento...</div>
            <div class="alert error" v-else-if="error">{{ error }}</div>
            <div class="grid grid-3" v-else>
                <div class="kpi"><div>Visite totali</div><div class="value">{{ kpi.appointments }}</div></div>
                <div class="kpi"><div>Prenotate</div><div class="value">{{ kpi.booked }}</div></div>
                <div class="kpi"><div>In corso</div><div class="value">{{ kpi.inProgress }}</div></div>
                <div class="kpi"><div>Concluse</div><div class="value">{{ kpi.completed }}</div></div>
                <div class="kpi"><div>Annullate</div><div class="value">{{ kpi.cancelled }}</div></div>
            </div>
        </div>`,
    };

    const BookingView = {
        props: ['adminMode'],
        setup(props) {
            const router = VueRouter.useRouter();
            const categories = ref([]);
            const doctors = ref([]);
            const slots = ref([]);
            const resultsMeta = ref({ preferred_doctor_id: null });
            const selectedChoice = ref(null);
            const selectedPatient = ref(null);
            const patientResults = ref([]);
            const patientSearching = ref(false);
            const patientSearchDone = ref(false);
            const loading = ref(false);
            const booking = ref(false);
            const error = ref('');
            const success = ref('');
            const searched = ref(false);
            const PATIENT_SEARCH_LIMIT = 15;

            const form = reactive({
                patient_id: '',
                visit_category: '',
                visit_reason: '',
                notes: '',
                firstAvailability: true,
                from: '',
                to: '',
                preferred_doctor_id: '',
            });
            const patientSearch = reactive({
                name: '',
                tax_code: '',
            });

            function normalizeDateStart(value) {
                if (!value) return '';
                return value + ' 00:00:00';
            }

            function normalizeDateEnd(value) {
                if (!value) return '';
                return value + ' 23:59:59';
            }

            function normalizeSlots(rawSlots) {
                return (Array.isArray(rawSlots) ? rawSlots : []).map((slot) => {
                    const recommendedDoctor = slot.recommended_doctor || (slot.doctors || [])[0] || null;
                    const alternatives = Array.isArray(slot.alternative_doctors) ? slot.alternative_doctors : [];
                    const fallbackDoctors = Array.isArray(slot.doctors) ? slot.doctors : [];
                    const seen = new Set();
                    const availableDoctors = [];

                    [recommendedDoctor, ...alternatives, ...fallbackDoctors].forEach((doctor) => {
                        if (!doctor || seen.has(Number(doctor.id))) {
                            return;
                        }
                        seen.add(Number(doctor.id));
                        availableDoctors.push(doctor);
                    });

                    return {
                        ...slot,
                        recommended_doctor: recommendedDoctor,
                        available_doctors: availableDoctors,
                        selected_doctor_id: recommendedDoctor ? Number(recommendedDoctor.id) : null,
                    };
                });
            }

            function selectedDoctorForSlot(slot) {
                return (slot.available_doctors || []).find((doctor) => Number(doctor.id) === Number(slot.selected_doctor_id))
                    || slot.recommended_doctor
                    || (slot.available_doctors || [])[0]
                    || null;
            }

            function showsFixedDoctor(slot) {
                return !!resultsMeta.value.preferred_doctor_id || isSlotSelected(slot);
            }

            function displayedDoctorName(slot) {
                if (isSlotSelected(slot) && selectedChoice.value) {
                    return selectedChoice.value.doctor_name;
                }

                return doctorFullName(selectedDoctorForSlot(slot));
            }

            function isSelected(slot, doctor = null) {
                const selectedDoctor = doctor || selectedDoctorForSlot(slot);
                if (!selectedChoice.value || !selectedDoctor) {
                    return false;
                }

                return selectedChoice.value.slot_start === slot.slot_start
                    && Number(selectedChoice.value.doctor_id) === Number(selectedDoctor.id);
            }

            function isSlotSelected(slot) {
                return !!selectedChoice.value && selectedChoice.value.slot_start === slot.slot_start;
            }

            function clearAvailabilityResults() {
                slots.value = [];
                resultsMeta.value = { preferred_doctor_id: null };
                selectedChoice.value = null;
                searched.value = false;
            }

            function resetBookingState() {
                clearAvailabilityResults();
                selectedPatient.value = null;
                patientResults.value = [];
                patientSearchDone.value = false;
                form.patient_id = '';
                form.visit_category = '';
                form.visit_reason = '';
                form.notes = '';
                form.firstAvailability = true;
                form.from = '';
                form.to = '';
                form.preferred_doctor_id = '';
                patientSearch.name = '';
                patientSearch.tax_code = '';
            }

            async function bootstrapData() {
                loading.value = true;
                error.value = '';
                try {
                    const [cats, docs] = await Promise.all([api('/categories'), api('/doctors')]);
                    categories.value = cats;
                    doctors.value = docs;
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            async function searchPatients() {
                if (!props.adminMode) {
                    return;
                }

                error.value = '';
                const name = patientSearch.name.trim();
                const taxCode = patientSearch.tax_code.trim().toUpperCase();
                if (!name && !taxCode) {
                    error.value = 'Inserisci nome o codice fiscale del paziente.';
                    return;
                }

                patientSearching.value = true;
                patientSearchDone.value = false;
                try {
                    const query = new URLSearchParams({ limit: String(PATIENT_SEARCH_LIMIT) });
                    if (name) {
                        query.set('name', name);
                    }
                    if (taxCode) {
                        query.set('tax_code', taxCode);
                    }
                    patientResults.value = await api('/patients?' + query.toString());
                    patientSearchDone.value = true;
                } catch (e) {
                    error.value = e.message;
                } finally {
                    patientSearching.value = false;
                }
            }

            function selectPatient(patient) {
                clearAvailabilityResults();
                selectedPatient.value = patient;
                form.patient_id = String(patient.id);
                patientResults.value = [];
                patientSearchDone.value = false;
            }

            function clearPatientSearch() {
                patientSearch.name = '';
                patientSearch.tax_code = '';
                patientResults.value = [];
                patientSearchDone.value = false;
            }

            function clearSelectedPatient() {
                clearAvailabilityResults();
                selectedPatient.value = null;
                form.patient_id = '';
                clearPatientSearch();
            }

            async function searchSlots() {
                error.value = '';
                success.value = '';
                slots.value = [];
                selectedChoice.value = null;
                searched.value = true;

                if (props.adminMode && !form.patient_id) {
                    error.value = 'Seleziona il paziente';
                    return;
                }
                if (!form.visit_category) {
                    error.value = 'Seleziona la categoria visita prima della ricerca';
                    return;
                }

                const from = form.firstAvailability
                    ? toApiDateTime(new Date())
                    : normalizeDateStart(form.from);
                const to = form.firstAvailability
                    ? toApiDateTime(new Date(Date.now() + 1000 * 60 * 60 * 24 * 30))
                    : normalizeDateEnd(form.to);

                if (!from || !to) {
                    error.value = 'Intervallo temporale non valido';
                    return;
                }

                if (!form.firstAvailability && from > to) {
                    error.value = 'La data "Da" deve essere precedente o uguale alla data "A"';
                    return;
                }

                const query = new URLSearchParams({
                    from,
                    to,
                    limit: '10',
                    visit_category: form.visit_category,
                });
                if (form.preferred_doctor_id) {
                    query.set('doctor_id', String(form.preferred_doctor_id));
                }
                if (props.adminMode && form.patient_id) {
                    query.set('patient_id', String(form.patient_id));
                }

                loading.value = true;
                try {
                    resultsMeta.value = {
                        preferred_doctor_id: form.preferred_doctor_id ? Number(form.preferred_doctor_id) : null,
                    };
                    const data = await api('/availability/search?' + query.toString());
                    slots.value = normalizeSlots(data);
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            function selectSlot(slot, doctor = null) {
                const chosenDoctor = doctor || selectedDoctorForSlot(slot);
                if (!chosenDoctor) {
                    return;
                }

                selectedChoice.value = {
                    slot_start: slot.slot_start,
                    slot_end: slot.slot_end,
                    doctor_id: Number(chosenDoctor.id),
                    doctor_name: doctorFullName(chosenDoctor),
                    recommended: !!chosenDoctor.recommended,
                    score: chosenDoctor.score,
                };
            }

            function updateSlotDoctor(slot) {
                if (isSlotSelected(slot)) {
                    selectSlot(slot);
                }
            }

            async function book() {
                error.value = '';
                success.value = '';

                if (!form.visit_category || !form.visit_reason) {
                    error.value = 'Categoria visita e motivo sono obbligatori';
                    return;
                }
                if (props.adminMode && !form.patient_id) {
                    error.value = 'Seleziona il paziente';
                    return;
                }
                if (!selectedChoice.value) {
                    error.value = 'Seleziona uno slot disponibile prima di confermare';
                    return;
                }

                const payload = {
                    visit_category: form.visit_category,
                    visit_reason: form.visit_reason,
                    notes: form.notes,
                    slot_start: selectedChoice.value.slot_start,
                    selected_doctor_id: Number(selectedChoice.value.doctor_id),
                };

                if (props.adminMode) {
                    payload.patient_id = Number(form.patient_id);
                }

                booking.value = true;
                try {
                    const appointment = await api('/appointments/book', {
                        method: 'POST',
                        body: payload,
                    });
                    resetBookingState();
                    success.value = '';
                    setFlash(
                        'success',
                        `Prenotazione completata per il ${formatDateTime(appointment.scheduled_start)} con ${appointment.doctor_first_name} ${appointment.doctor_last_name}`
                    );
                    await router.push('/appointments/' + appointment.id);
                } catch (e) {
                    error.value = e.message;
                } finally {
                    booking.value = false;
                }
            }

            onMounted(bootstrapData);

            return {
                categories,
                doctors,
                slots,
                resultsMeta,
                selectedChoice,
                selectedPatient,
                patientResults,
                patientSearching,
                patientSearchDone,
                loading,
                booking,
                error,
                success,
                searched,
                form,
                patientSearch,
                PATIENT_SEARCH_LIMIT,
                searchSlots,
                searchPatients,
                selectPatient,
                clearPatientSearch,
                clearSelectedPatient,
                selectSlot,
                updateSlotDoctor,
                book,
                isSelected,
                isSlotSelected,
                showsFixedDoctor,
                displayedDoctorName,
                formatDateTime,
                doctorFullName,
                availabilityOptionLabel,
            };
        },
        template: `
        <div>
            <h2>{{ adminMode ? 'Prenota Visita' : 'Nuova Prenotazione' }}</h2>
            <div class="card">
                <div v-if="error" class="alert error">{{ error }}</div>
                <div v-if="success" class="alert success">{{ success }}</div>
                <div v-if="loading" class="small">Caricamento...</div>

                <div class="grid grid-2">
                    <div v-if="adminMode" style="grid-column:1/-1">
                        <label>Paziente</label>
                        <div v-if="selectedPatient" class="picker-summary">
                            <div>{{ selectedPatient.first_name }} {{ selectedPatient.last_name }}</div>
                            <div class="small">CF: {{ selectedPatient.tax_code }} | {{ selectedPatient.email }}</div>
                            <div class="toolbar" style="margin-top:8px;">
                                <button class="btn-muted" @click="clearSelectedPatient">Cambia paziente</button>
                            </div>
                        </div>
                        <template v-else>
                            <div class="grid grid-2">
                                <div>
                                    <label>Nome o cognome</label>
                                    <input v-model="patientSearch.name" placeholder="Es. Giulia Rossi" />
                                </div>
                                <div>
                                    <label>Codice fiscale</label>
                                    <input v-model="patientSearch.tax_code" />
                                </div>
                            </div>
                            <div class="toolbar" style="margin-top:8px;">
                                <button class="btn-primary" @click="searchPatients">{{ patientSearching ? 'Ricerca...' : 'Cerca paziente' }}</button>
                                <button class="btn-muted" @click="clearPatientSearch">Cancella ricerca</button>
                            </div>
                            <div class="small" v-if="patientSearchDone">Risultati limitati a {{ PATIENT_SEARCH_LIMIT }} pazienti.</div>
                            <div class="desktop-table table-wrap" v-if="patientResults.length">
                                <table>
                                    <thead><tr><th>ID</th><th>Nome</th><th>CF</th><th>Email</th><th></th></tr></thead>
                                    <tbody>
                                        <tr v-for="patient in patientResults" :key="'book-patient-' + patient.id">
                                            <td>#{{ patient.id }}</td>
                                            <td>{{ patient.first_name }} {{ patient.last_name }}</td>
                                            <td>{{ patient.tax_code }}</td>
                                            <td>{{ patient.email }}</td>
                                            <td><button class="btn-muted" @click="selectPatient(patient)">Seleziona</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mobile-cards" v-if="patientResults.length">
                                <div class="mobile-card" v-for="patient in patientResults" :key="'book-patient-mobile-' + patient.id">
                                    <div><strong>#{{ patient.id }}</strong> - {{ patient.first_name }} {{ patient.last_name }}</div>
                                    <div><strong>CF:</strong> {{ patient.tax_code }}</div>
                                    <div><strong>Email:</strong> {{ patient.email }}</div>
                                    <button class="btn-muted" style="margin-top:8px;" @click="selectPatient(patient)">Seleziona</button>
                                </div>
                            </div>
                            <div class="empty" v-else-if="patientSearchDone && !patientSearching">Nessun paziente trovato con i filtri indicati.</div>
                        </template>
                    </div>
                    <div>
                        <label>Categoria visita</label>
                        <select v-model="form.visit_category">
                            <option value="">Seleziona</option>
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </div>
                    <div>
                        <label>Motivo visita</label>
                        <input v-model="form.visit_reason" />
                    </div>
                    <div>
                        <label>Preferenza medico (opzionale)</label>
                        <select v-model="form.preferred_doctor_id">
                            <option value="">Nessuna preferenza</option>
                            <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.first_name }} {{ d.last_name }}</option>
                        </select>
                    </div>
                    <div style="grid-column:1/-1">
                        <label>Note</label>
                        <textarea v-model="form.notes"></textarea>
                    </div>
                </div>

                <div class="check-field">
                    <label class="inline-check">
                        <input type="checkbox" v-model="form.firstAvailability" />
                        Prima disponibilità
                    </label>
                </div>

                <div class="grid grid-2" v-if="!form.firstAvailability">
                    <div>
                        <label>Da</label>
                        <input type="date" v-model="form.from" />
                    </div>
                    <div>
                        <label>A</label>
                        <input type="date" v-model="form.to" />
                    </div>
                </div>

                <div class="toolbar" style="margin-top:10px;">
                    <button class="btn-muted" @click="searchSlots">Cerca disponibilità</button>
                    <button class="btn-primary" :disabled="booking || !selectedChoice" @click="book">{{ booking ? 'Prenotazione...' : 'Conferma prenotazione' }}</button>
                </div>
            </div>

            <div class="card slot-choice-summary" v-if="selectedChoice">
                <h3>Slot scelto</h3>
                <p><strong>Orario:</strong> {{ formatDateTime(selectedChoice.slot_start) }} - {{ formatDateTime(selectedChoice.slot_end) }}</p>
                <p><strong>Medico:</strong> {{ selectedChoice.doctor_name }}</p>
                <p class="small" v-if="resultsMeta.preferred_doctor_id">Hai selezionato una disponibilità del medico indicato.</p>
                <p class="small" v-else-if="selectedChoice.recommended">Selezione basata sulla raccomandazione del sistema per quello slot.</p>
                <p class="small" v-else>Hai scelto un medico alternativo disponibile nello stesso slot.</p>
            </div>

            <div class="card" v-if="slots.length">
                <h3>Slot disponibili (prime 10 disponibilità orarie)</h3>
                <div class="desktop-table table-wrap">
                    <table>
                        <thead>
                            <tr><th>Selez.</th><th>Inizio</th><th>Fine</th><th>Medico</th></tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="slot in slots"
                                :key="slot.slot_start"
                                :class="isSlotSelected(slot) ? 'slot-row-selected' : ''"
                            >
                                <td>
                                    <button class="btn-muted" @click="selectSlot(slot)">
                                        {{ isSlotSelected(slot) ? 'Selezionato' : 'Scegli' }}
                                    </button>
                                </td>
                                <td>{{ formatDateTime(slot.slot_start) }}</td>
                                <td>{{ formatDateTime(slot.slot_end) }}</td>
                                <td>
                                    <template v-if="showsFixedDoctor(slot)">
                                        <span>{{ displayedDoctorName(slot) }}</span>
                                        <div class="small" v-if="isSlotSelected(slot) && selectedChoice && selectedChoice.recommended && !resultsMeta.preferred_doctor_id">Medico consigliato</div>
                                    </template>
                                    <template v-else>
                                        <select v-model="slot.selected_doctor_id" @change="updateSlotDoctor(slot)">
                                            <option
                                                v-for="doctor in slot.available_doctors"
                                                :key="slot.slot_start + '-' + doctor.id"
                                                :value="Number(doctor.id)"
                                            >
                                                {{ availabilityOptionLabel(doctor, !resultsMeta.preferred_doctor_id && !!doctor.recommended) }}
                                            </option>
                                        </select>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-cards">
                    <div class="mobile-card" v-for="slot in slots" :key="slot.slot_start + '-m'" :class="isSlotSelected(slot) ? 'slot-row-selected' : ''">
                        <div><strong>Inizio:</strong> {{ formatDateTime(slot.slot_start) }}</div>
                        <div><strong>Fine:</strong> {{ formatDateTime(slot.slot_end) }}</div>
                        <div style="margin-top:8px;">
                            <label>Medico</label>
                            <template v-if="showsFixedDoctor(slot)">
                                <div>{{ displayedDoctorName(slot) }}</div>
                                <div class="small" v-if="isSlotSelected(slot) && selectedChoice && selectedChoice.recommended && !resultsMeta.preferred_doctor_id">Medico consigliato</div>
                            </template>
                            <template v-else>
                                <select v-model="slot.selected_doctor_id" @change="updateSlotDoctor(slot)">
                                    <option
                                        v-for="doctor in slot.available_doctors"
                                        :key="slot.slot_start + '-m-' + doctor.id"
                                        :value="Number(doctor.id)"
                                    >
                                        {{ availabilityOptionLabel(doctor, !resultsMeta.preferred_doctor_id && !!doctor.recommended) }}
                                    </option>
                                </select>
                            </template>
                        </div>
                        <button class="btn-muted" style="margin-top:8px;" @click="selectSlot(slot)">
                            {{ isSlotSelected(slot) ? 'Selezionato' : 'Scegli' }}
                        </button>
                    </div>
                </div>
            </div>
            <div class="empty" v-else-if="searched && !loading && !error">Nessuno slot trovato nell'intervallo richiesto.</div>
        </div>`,
    };

    const AppointmentListView = {
        components: { StatusBadge },
        props: {
            title: String,
            endpoint: String,
            mode: String,
            showPatient: Boolean,
            showDoctor: Boolean,
            detailLabel: String,
            showStatusInTodo: {
                type: Boolean,
                default: false,
            },
        },
        setup(props) {
            const route = VueRouter.useRoute();
            const list = ref([]);
            const doctors = ref([]);
            const categories = ref([]);
            const loading = ref(true);
            const error = ref('');
            const MAX_RESULTS = 30;

            const filters = reactive({
                from_date: '',
                to_date: '',
                status: '',
                visit_category: '',
                doctor_id: '',
                q: '',
            });

            async function loadLookups() {
                categories.value = await api('/categories');
                doctors.value = await api('/doctors');
            }

            async function loadAppointments() {
                loading.value = true;
                error.value = '';
                try {
                    const query = new URLSearchParams();
                    query.set('limit', String(MAX_RESULTS));
                    if (props.mode) query.set('mode', props.mode);
                    if (filters.from_date) query.set('from_date', filters.from_date);
                    if (filters.to_date) query.set('to_date', filters.to_date);
                    if (filters.status) query.set('status', filters.status);
                    if (filters.visit_category) query.set('visit_category', filters.visit_category);
                    if (filters.doctor_id) query.set('doctor_id', filters.doctor_id);
                    if (filters.q) query.set('q', filters.q);
                    const suffix = query.toString() ? '?' + query.toString() : '';
                    list.value = await api(props.endpoint + suffix);
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            async function clearFilters() {
                filters.from_date = '';
                filters.to_date = '';
                filters.status = '';
                filters.visit_category = '';
                filters.doctor_id = '';
                filters.q = '';
                await loadAppointments();
            }

            onMounted(async () => {
                try {
                    await loadLookups();
                } catch (_) {
                }
                await loadAppointments();
            });

            watch(
                () => route.path,
                async (newPath, oldPath) => {
                    if (newPath !== oldPath) {
                        filters.from_date = '';
                        filters.to_date = '';
                        filters.status = '';
                        filters.visit_category = '';
                        filters.doctor_id = '';
                        filters.q = '';
                    }
                    await loadAppointments();
                }
            );

            function appointmentRowClass(appointment) {
                return {
                    'appointment-row-booked': appointment.status === 'PRENOTATA',
                    'appointment-row-in-progress': appointment.status === 'IN_CORSO',
                    'appointment-row-cancelled': appointment.status === 'ANNULLATA',
                };
            }

            function appointmentCardClass(appointment) {
                return {
                    'appointment-card-booked': appointment.status === 'PRENOTATA',
                    'appointment-card-in-progress': appointment.status === 'IN_CORSO',
                    'appointment-card-cancelled': appointment.status === 'ANNULLATA',
                };
            }

            return {
                list,
                doctors,
                categories,
                loading,
                error,
                filters,
                loadAppointments,
                clearFilters,
                MAX_RESULTS,
                appointmentRowClass,
                appointmentCardClass,
                formatDateTime,
            };
        },
        template: `
        <div>
            <h2>{{ title }}</h2>
            <div class="card">
                <div class="grid grid-3">
                    <div>
                        <label>Da data</label>
                        <input type="date" v-model="filters.from_date" />
                    </div>
                    <div>
                        <label>A data</label>
                        <input type="date" v-model="filters.to_date" />
                    </div>
                    <div v-if="mode !== 'todo' && mode !== 'history'">
                        <label>Stato</label>
                        <select v-model="filters.status">
                            <option value="">Tutti</option>
                            <option value="PRENOTATA">PRENOTATA</option>
                            <option value="IN_CORSO">IN CORSO</option>
                            <option value="CONCLUSA">CONCLUSA</option>
                            <option value="ANNULLATA">ANNULLATA</option>
                        </select>
                    </div>
                    <div>
                        <label>Categoria</label>
                        <select v-model="filters.visit_category">
                            <option value="">Tutte</option>
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </div>
                    <div v-if="showDoctor">
                        <label>Medico</label>
                        <select v-model="filters.doctor_id">
                            <option value="">Tutti</option>
                            <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.first_name }} {{ d.last_name }}</option>
                        </select>
                    </div>
                    <div>
                        <label>Ricerca libera</label>
                        <input v-model="filters.q" placeholder="Motivo, nome, note..." />
                    </div>
                </div>
                <div class="toolbar" style="margin-top:8px;">
                    <button class="btn-primary" @click="loadAppointments">Applica filtri</button>
                    <button class="btn-muted" @click="clearFilters">Cancella filtri</button>
                </div>
            </div>

            <div class="card" v-if="loading">Caricamento...</div>
            <div class="alert error" v-else-if="error">{{ error }}</div>
            <div class="card" v-else>
                <div class="small" v-if="list.length >= MAX_RESULTS">Visualizzazione limitata a {{ MAX_RESULTS }} visite. Usa i filtri per restringere.</div>
                <div class="desktop-table table-wrap" v-if="list.length">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Data</th>
                                <th>Categoria</th>
                                <th>Motivo</th>
                                <th v-if="showPatient">Paziente</th>
                                <th v-if="showDoctor">Medico</th>
                                <th v-if="mode !== 'todo' || showStatusInTodo">Stato</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in list" :key="a.id" :class="appointmentRowClass(a)">
                                <td>#{{ a.id }}</td>
                                <td>{{ formatDateTime(a.started_at || a.scheduled_start) }}</td>
                                <td>{{ a.visit_category }}</td>
                                <td>{{ a.visit_reason }}</td>
                                <td v-if="showPatient">{{ a.patient_first_name }} {{ a.patient_last_name }}</td>
                                <td v-if="showDoctor">{{ a.doctor_first_name }} {{ a.doctor_last_name }}</td>
                                <td v-if="mode !== 'todo' || showStatusInTodo"><StatusBadge :status="a.status" /></td>
                                <td class="appointment-row-actions">
                                    <div class="table-actions">
                                        <router-link class="btn-muted" :to="'/appointments/' + a.id">{{ detailLabel || 'Dettaglio' }}</router-link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-cards" v-if="list.length">
                    <div class="mobile-card" v-for="a in list" :key="a.id + '-m'" :class="appointmentCardClass(a)">
                        <div class="appointment-card-body">
                            <div><strong>#{{ a.id }}</strong> - {{ formatDateTime(a.started_at || a.scheduled_start) }}</div>
                            <div><strong>Categoria:</strong> {{ a.visit_category }}</div>
                            <div><strong>Motivo:</strong> {{ a.visit_reason }}</div>
                            <div v-if="showPatient"><strong>Paziente:</strong> {{ a.patient_first_name }} {{ a.patient_last_name }}</div>
                            <div v-if="showDoctor"><strong>Medico:</strong> {{ a.doctor_first_name }} {{ a.doctor_last_name }}</div>
                            <div v-if="mode !== 'todo' || showStatusInTodo"><StatusBadge :status="a.status" /></div>
                        </div>
                        <div class="toolbar" style="margin-top:8px;">
                            <router-link class="btn-muted" :to="'/appointments/' + a.id">{{ detailLabel || 'Dettaglio' }}</router-link>
                        </div>
                    </div>
                </div>
                <div class="empty" v-if="!list.length">Nessuna visita trovata.</div>
            </div>
        </div>`,
    };

    const AppointmentDetailView = {
        components: { StatusBadge },
        setup() {
            const route = VueRouter.useRoute();
            const appointment = ref(null);
            const report = ref(null);
            const loading = ref(true);
            const reportLoading = ref(false);
            const error = ref('');
            const reportError = ref('');
            const cancelReason = ref('');
            const cancelError = ref('');
            const canceling = ref(false);
            const reportText = ref('');
            const completeError = ref('');

            const canCancel = computed(() => {
                if (!appointment.value) return false;
                if (appointment.value.status !== 'PRENOTATA') return false;
                if (state.user.role === 'PATIENT') {
                    const deadline = patientCancelDeadline(appointment.value.scheduled_start);
                    return !deadline || Date.now() <= deadline.getTime();
                }

                return ['DOCTOR', 'RECEPTION', 'INTEGRATOR'].includes(state.user.role);
            });
            const canStart = computed(() => appointment.value && appointment.value.status === 'PRENOTATA' && state.user.role === 'DOCTOR');
            const canComplete = computed(() => appointment.value && appointment.value.status === 'IN_CORSO' && state.user.role === 'DOCTOR');
            const formatStatus = (status) => (status ? humanStatus(status) : '-');

            async function loadAppointment() {
                loading.value = true;
                error.value = '';
                report.value = null;
                reportError.value = '';
                completeError.value = '';

                try {
                    appointment.value = await api('/appointments/' + route.params.id);
                    if (appointment.value.has_report && canInlineReport(state.user.role)) {
                        reportLoading.value = true;
                        try {
                            report.value = await api('/reports/' + appointment.value.report_id);
                        } catch (e) {
                            reportError.value = e.message;
                        } finally {
                            reportLoading.value = false;
                        }
                    }
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            async function cancelAppointment() {
                cancelError.value = '';
                if (!cancelReason.value.trim()) {
                    cancelError.value = "Il motivo dell'annullamento è obbligatorio.";
                    return;
                }

                canceling.value = true;
                try {
                    appointment.value = await api('/appointments/' + route.params.id + '/cancel', {
                        method: 'POST',
                        body: { reason: cancelReason.value.trim() },
                    });
                    cancelReason.value = '';
                    cancelError.value = '';
                    setFlash('success', 'Visita annullata');
                } catch (e) {
                    cancelError.value = translateApiError(e.message);
                } finally {
                    canceling.value = false;
                }
            }

            async function startVisit() {
                try {
                    appointment.value = await api('/appointments/' + route.params.id + '/start', {
                        method: 'POST',
                        body: {},
                    });
                    setFlash('success', 'Visita in corso');
                    await loadAppointment();
                } catch (e) {
                    error.value = e.message;
                }
            }

            async function completeVisit() {
                completeError.value = '';
                if (!reportText.value.trim()) {
                    completeError.value = 'Il referto è obbligatorio.';
                    return;
                }

                try {
                    await api('/appointments/' + route.params.id + '/complete', {
                        method: 'POST',
                        body: { report_text: reportText.value.trim() },
                    });
                    reportText.value = '';
                    completeError.value = '';
                    setFlash('success', 'Visita conclusa con referto');
                    await loadAppointment();
                } catch (e) {
                    completeError.value = translateApiError(e.message);
                }
            }

            onMounted(loadAppointment);

            return {
                state,
                appointment,
                report,
                loading,
                reportLoading,
                error,
                reportError,
                cancelReason,
                cancelError,
                canceling,
                reportText,
                completeError,
                canCancel,
                canStart,
                canComplete,
                cancelAppointment,
                startVisit,
                completeVisit,
                canInlineReport,
                formatStatus,
                formatDateTime,
                historyActorLabel,
                roleLabel,
            };
        },
        template: `
        <div>
            <h2>Dettaglio Visita</h2>
            <div class="card" v-if="loading">Caricamento...</div>
            <div class="alert error" v-else-if="error">{{ error }}</div>
            <div v-else-if="appointment" class="card">
                <div class="grid grid-2 detail-grid">
                    <div><strong>ID:</strong> #{{ appointment.id }}</div>
                    <div><StatusBadge :status="appointment.status" /></div>
                    <div><strong>Data prevista:</strong> {{ formatDateTime(appointment.scheduled_start) }}</div>
                    <div><strong>Categoria:</strong> {{ appointment.visit_category }}</div>
                    <div><strong>Motivo:</strong> {{ appointment.visit_reason }}</div>
                    <div><strong>Note:</strong> {{ appointment.notes || '-' }}</div>
                    <div v-if="state.user.role !== 'PATIENT'"><strong>Paziente:</strong> {{ appointment.patient_first_name }} {{ appointment.patient_last_name }}</div>
                    <div v-if="state.user.role !== 'DOCTOR'"><strong>Medico:</strong> {{ appointment.doctor_first_name }} {{ appointment.doctor_last_name }}</div>
                    <div><strong>Inizio visita:</strong> {{ formatDateTime(appointment.started_at) }}</div>
                    <div><strong>Fine visita:</strong> {{ formatDateTime(appointment.ended_at) }}</div>
                    <div v-if="appointment.status === 'ANNULLATA'"><strong>Annullata il:</strong> {{ formatDateTime(appointment.canceled_at) }}</div>
                    <div v-if="appointment.status === 'ANNULLATA'"><strong>Motivo annullamento:</strong> {{ appointment.cancellation_reason || '-' }}</div>
                </div>

                <div class="card" v-if="canCancel" style="margin-top:12px;">
                    <h3>Annulla visita</h3>
                    <label>Motivo annullamento</label>
                    <textarea class="no-resize" v-model="cancelReason"></textarea>
                    <div v-if="cancelError" class="alert error" style="margin-top:10px; margin-bottom:10px;">{{ cancelError }}</div>
                    <button class="btn-danger" :disabled="canceling" @click="cancelAppointment">{{ canceling ? 'Annullamento...' : 'Conferma annullamento' }}</button>
                </div>

                <div class="card" v-if="canStart" style="margin-top:12px;">
                    <h3>Inizio visita</h3>
                    <button class="btn-primary" @click="startVisit">Inizia visita</button>
                </div>

                <div class="card" v-if="canComplete" style="margin-top:12px;">
                    <h3>Chiudi visita con referto</h3>
                    <label>Referto</label>
                    <textarea v-model="reportText"></textarea>
                    <div v-if="completeError" class="alert error" style="margin-top:10px; margin-bottom:10px;">{{ completeError }}</div>
                    <button class="btn-primary" @click="completeVisit">Concludi visita</button>
                </div>

                <div class="card" v-if="appointment.has_report && canInlineReport(state.user.role)" style="margin-top:12px;">
                    <h3>Referto</h3>
                    <div v-if="reportLoading">Caricamento referto...</div>
                    <div v-else-if="reportError" class="alert error">{{ reportError }}</div>
                    <div v-else-if="report">
                        <p><strong>Prestazione eseguita:</strong> {{ formatDateTime(report.started_at || report.scheduled_start) }}</p>
                        <p><strong>Creato il:</strong> {{ formatDateTime(report.created_at) }}</p>
                        <pre class="detail-pre">{{ report.report.report_text }}</pre>
                        <a class="btn-muted" :href="state.apiBase + '/reports/' + report.id + '/download'">Download / stampa</a>
                    </div>
                </div>

                <div class="card" style="margin-top:12px;">
                    <h3>Storico stati</h3>
                    <div class="desktop-table table-wrap" v-if="appointment.history && appointment.history.length">
                        <table>
                            <thead><tr><th>Da</th><th>A</th><th>Quando</th><th>Utente</th><th>Nota</th></tr></thead>
                            <tbody>
                                <tr v-for="h in appointment.history" :key="h.id">
                                    <td>{{ formatStatus(h.from_status) }}</td>
                                    <td>{{ formatStatus(h.to_status) }}</td>
                                    <td>{{ formatDateTime(h.changed_at) }}</td>
                                    <td>{{ historyActorLabel(h) }}</td>
                                    <td>{{ h.note || '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mobile-cards" v-if="appointment.history && appointment.history.length">
                        <div class="mobile-card" v-for="h in appointment.history" :key="h.id + '-m'">
                            <div><strong>Da:</strong> {{ formatStatus(h.from_status) }}</div>
                            <div><strong>A:</strong> {{ formatStatus(h.to_status) }}</div>
                            <div><strong>Quando:</strong> {{ formatDateTime(h.changed_at) }}</div>
                            <div><strong>Utente:</strong> {{ historyActorLabel(h) }}</div>
                            <div><strong>Nota:</strong> {{ h.note || '-' }}</div>
                        </div>
                    </div>
                    <div class="empty" v-else>Nessuna transizione.</div>
                </div>
            </div>
        </div>`,
    };

    const AvailabilityView = {
        setup() {
            const route = VueRouter.useRoute();
            const list = ref([]);
            const loading = ref(true);
            const saving = ref(false);
            const error = ref('');
            const success = ref('');
            const doctorId = computed(() => route.params.doctorId || state.user.doctor_id);

            async function load() {
                loading.value = true;
                error.value = '';
                try {
                    list.value = await api('/doctors/' + doctorId.value + '/availability');
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            function addRow() {
                list.value.push({ weekday: 1, start_time: '09:00', end_time: '11:00' });
            }

            function removeRow(index) {
                list.value.splice(index, 1);
            }

            async function save() {
                saving.value = true;
                error.value = '';
                success.value = '';
                try {
                    await api('/doctors/' + doctorId.value + '/availability', {
                        method: 'PUT',
                        body: { slots: list.value },
                    });
                    success.value = 'Disponibilità aggiornata';
                } catch (e) {
                    error.value = e.message;
                } finally {
                    saving.value = false;
                }
            }

            onMounted(load);

            return { list, loading, saving, error, success, weekdays, addRow, removeRow, save };
        },
        template: `
        <div>
            <h2>Disponibilità Medico</h2>
            <div class="card" v-if="loading">Caricamento...</div>
            <div class="card" v-else>
                <div v-if="error" class="alert error">{{ error }}</div>
                <div v-if="success" class="alert success">{{ success }}</div>
                <div class="table-wrap" v-if="list.length">
                    <table>
                        <thead><tr><th>Giorno</th><th>Inizio</th><th>Fine</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="(row, idx) in list" :key="idx">
                                <td>
                                    <select v-model.number="row.weekday">
                                        <option v-for="d in weekdays" :key="d.value" :value="d.value">{{ d.label }}</option>
                                    </select>
                                </td>
                                <td><input type="time" v-model="row.start_time" /></td>
                                <td><input type="time" v-model="row.end_time" /></td>
                                <td><button class="btn-danger" @click="removeRow(idx)">Rimuovi</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="empty" v-else>Nessuna disponibilità configurata.</div>
                <div class="toolbar" style="margin-top:10px;">
                    <button class="btn-muted" @click="addRow">Aggiungi fascia</button>
                    <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Salvataggio...' : 'Salva disponibilità' }}</button>
                </div>
            </div>
        </div>`,
    };

    const PatientsView = {
        setup() {
            const list = ref([]);
            const loading = ref(true);
            const error = ref('');
            const MAX_RESULTS = 30;
            const form = reactive({
                id: null,
                first_name: '',
                last_name: '',
                tax_code: '',
                email: '',
                password: '',
                active: true,
            });
            const filters = reactive({
                name: '',
                tax_code: '',
            });
            const hasFilters = computed(() => !!filters.name.trim() || !!filters.tax_code.trim());
            const showLimitNote = computed(() => !hasFilters.value || list.value.length >= MAX_RESULTS);

            async function load() {
                loading.value = true;
                error.value = '';
                try {
                    const query = new URLSearchParams({ limit: String(MAX_RESULTS) });
                    if (filters.name.trim()) {
                        query.set('name', filters.name.trim());
                    }
                    if (filters.tax_code.trim()) {
                        query.set('tax_code', filters.tax_code.trim());
                    }
                    list.value = await api('/patients?' + query.toString());
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            function edit(row) {
                form.id = row.id;
                form.first_name = row.first_name;
                form.last_name = row.last_name;
                form.tax_code = row.tax_code;
                form.email = row.email;
                form.password = '';
                form.active = !!row.active;
            }

            function reset() {
                form.id = null;
                form.first_name = '';
                form.last_name = '';
                form.tax_code = '';
                form.email = '';
                form.password = '';
                form.active = true;
            }

            async function clearFilters() {
                filters.name = '';
                filters.tax_code = '';
                await load();
            }

            function isEditing(row) {
                return Number(form.id) === Number(row.id);
            }

            async function save() {
                error.value = '';
                if (!isValidEmail(form.email)) {
                    error.value = 'Email non valida.';
                    return;
                }
                const passwordError = passwordValidationMessage(form.password, !!form.id);
                if (passwordError) {
                    error.value = passwordError;
                    return;
                }
                try {
                    if (form.id) {
                        await api('/patients/' + form.id, {
                            method: 'PUT',
                            body: {
                                first_name: form.first_name,
                                last_name: form.last_name,
                                tax_code: form.tax_code,
                                email: form.email,
                                active: form.active,
                                password: form.password,
                            },
                        });
                        setFlash('success', 'Paziente aggiornato');
                    } else {
                        await api('/patients', {
                            method: 'POST',
                            body: {
                                first_name: form.first_name,
                                last_name: form.last_name,
                                tax_code: form.tax_code,
                                email: form.email,
                                password: form.password,
                                active: form.active,
                            },
                        });
                        setFlash('success', 'Paziente creato');
                    }
                    reset();
                    await load();
                } catch (e) {
                    error.value = e.message;
                }
            }

            onMounted(load);
            return {
                list,
                loading,
                error,
                form,
                edit,
                reset,
                save,
                filters,
                load,
                clearFilters,
                MAX_RESULTS,
                showLimitNote,
                isEditing,
            };
        },
        template: `
        <div>
            <h2>Gestione Pazienti</h2>
            <div class="card">
                <div class="grid grid-2">
                    <div>
                        <label>Nome</label>
                        <input v-model="filters.name" placeholder="Nome o cognome" />
                    </div>
                    <div>
                        <label>Codice fiscale</label>
                        <input v-model="filters.tax_code" />
                    </div>
                </div>
                <div class="toolbar" style="margin-top:8px;">
                    <button class="btn-primary" @click="load">Applica filtri</button>
                    <button class="btn-muted" @click="clearFilters">Cancella filtri</button>
                </div>
            </div>
            <div class="card" :class="form.id ? 'editing-form-card' : ''">
                <div v-if="error" class="alert error">{{ error }}</div>
                <h3 style="margin-bottom:12px;">{{ form.id ? 'Modifica paziente #' + form.id : 'Nuovo paziente' }}</h3>
                <div class="grid grid-2">
                    <div><label>Nome</label><input v-model="form.first_name" /></div>
                    <div><label>Cognome</label><input v-model="form.last_name" /></div>
                    <div><label>Codice fiscale</label><input v-model="form.tax_code" /></div>
                    <div><label>Email</label><input type="email" v-model="form.email" /></div>
                    <div><label>{{ form.id ? 'Password di reset' : 'Password iniziale' }}</label><input type="password" v-model="form.password" :placeholder="form.id ? 'Se la imposti, l\\'utente dovrà cambiarla al prossimo accesso' : 'Minimo 8 caratteri'" /></div>
                    <div class="check-field">
                        <label class="inline-check">
                            <input type="checkbox" v-model="form.active" />
                            Attivo
                        </label>
                    </div>
                </div>
                <div class="toolbar" style="margin-top:10px;">
                    <button class="btn-primary" @click="save">{{ form.id ? 'Aggiorna' : 'Crea nuovo' }}</button>
                    <button class="btn-muted" @click="reset">Annulla</button>
                </div>
            </div>
            <div class="card">
                <div class="small" v-if="showLimitNote">Visualizzazione limitata a {{ MAX_RESULTS }} pazienti. Usa i filtri per restringere.</div>
                <div class="desktop-table table-wrap" v-if="!loading && list.length">
                    <table>
                        <thead><tr><th>ID</th><th>Nome</th><th>CF</th><th>Email</th><th>Attivo</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="p in list" :key="p.id" :class="isEditing(p) ? 'editing-row' : ''">
                                <td>#{{ p.id }}</td>
                                <td>{{ p.first_name }} {{ p.last_name }}</td>
                                <td>{{ p.tax_code }}</td>
                                <td>{{ p.email }}</td>
                                <td>{{ p.active ? 'Si' : 'No' }}</td>
                                <td><button class="btn-muted" @click="edit(p)">Modifica</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-cards" v-if="!loading && list.length">
                    <div class="mobile-card" v-for="p in list" :key="p.id + '-m'" :class="isEditing(p) ? 'editing-row-card' : ''">
                        <div><strong>#{{ p.id }}</strong> - {{ p.first_name }} {{ p.last_name }}</div>
                        <div><strong>CF:</strong> {{ p.tax_code }}</div>
                        <div><strong>Email:</strong> {{ p.email }}</div>
                        <div><strong>Attivo:</strong> {{ p.active ? 'Si' : 'No' }}</div>
                        <button class="btn-muted" style="margin-top:8px;" @click="edit(p)">Modifica</button>
                    </div>
                </div>
                <div class="card" v-if="loading">Caricamento...</div>
                <div class="empty" v-else-if="!list.length">Nessun paziente.</div>
            </div>
        </div>`,
    };

    const DoctorsView = {
        setup() {
            const router = VueRouter.useRouter();
            const list = ref([]);
            const loading = ref(true);
            const error = ref('');
            const form = reactive({
                id: null,
                first_name: '',
                last_name: '',
                email: '',
                internal_code: '',
                active: true,
                password: '',
            });

            async function load() {
                loading.value = true;
                error.value = '';
                try {
                    list.value = await api('/doctors?scope=all');
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            function edit(row) {
                form.id = row.id;
                form.first_name = row.first_name;
                form.last_name = row.last_name;
                form.email = row.email;
                form.internal_code = row.internal_code;
                form.active = !!row.active;
                form.password = '';
            }

            function reset() {
                form.id = null;
                form.first_name = '';
                form.last_name = '';
                form.email = '';
                form.internal_code = '';
                form.active = true;
                form.password = '';
            }

            function isEditing(row) {
                return Number(form.id) === Number(row.id);
            }

            async function save() {
                error.value = '';
                if (!isValidEmail(form.email)) {
                    error.value = 'Email non valida.';
                    return;
                }
                const passwordError = passwordValidationMessage(form.password, !!form.id);
                if (passwordError) {
                    error.value = passwordError;
                    return;
                }
                try {
                    if (form.id) {
                        await api('/doctors/' + form.id, {
                            method: 'PUT',
                            body: {
                                first_name: form.first_name,
                                last_name: form.last_name,
                                email: form.email,
                                internal_code: form.internal_code,
                                active: form.active,
                                password: form.password,
                            },
                        });
                        setFlash('success', 'Medico aggiornato');
                    } else {
                        await api('/doctors', {
                            method: 'POST',
                            body: {
                                first_name: form.first_name,
                                last_name: form.last_name,
                                email: form.email,
                                internal_code: form.internal_code,
                                active: form.active,
                                password: form.password,
                            },
                        });
                        setFlash('success', 'Medico creato');
                    }
                    reset();
                    await load();
                } catch (e) {
                    error.value = e.message;
                }
            }

            onMounted(load);
            return { list, loading, error, form, edit, reset, save, router, isEditing };
        },
        template: `
        <div>
            <h2>Gestione Medici</h2>
            <div class="card" :class="form.id ? 'editing-form-card' : ''">
                <div v-if="error" class="alert error">{{ error }}</div>
                <h3 style="margin-bottom:12px;">{{ form.id ? 'Modifica medico #' + form.id : 'Nuovo medico' }}</h3>
                <div class="grid grid-2">
                    <div><label>Nome</label><input v-model="form.first_name" /></div>
                    <div><label>Cognome</label><input v-model="form.last_name" /></div>
                    <div><label>Email</label><input type="email" v-model="form.email" /></div>
                    <div><label>Codice interno</label><input v-model="form.internal_code" /></div>
                    <div><label>{{ form.id ? 'Password di reset' : 'Password iniziale' }}</label><input type="password" v-model="form.password" :placeholder="form.id ? 'Se la imposti, l\\'utente dovrà cambiarla al prossimo accesso' : 'Minimo 8 caratteri'" /></div>
                    <div class="check-field">
                        <label class="inline-check">
                            <input type="checkbox" v-model="form.active" />
                            Attivo
                        </label>
                    </div>
                </div>
                <div class="toolbar" style="margin-top:10px;">
                    <button class="btn-primary" @click="save">{{ form.id ? 'Aggiorna' : 'Crea nuovo' }}</button>
                    <button class="btn-muted" @click="reset">Annulla</button>
                </div>
            </div>
            <div class="card">
                <div class="desktop-table table-wrap" v-if="!loading && list.length">
                    <table>
                        <thead><tr><th>ID</th><th>Nome</th><th>Email</th><th>Codice</th><th>Attivo</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="d in list" :key="d.id" :class="isEditing(d) ? 'editing-row' : ''">
                                <td>#{{ d.id }}</td>
                                <td>{{ d.first_name }} {{ d.last_name }}</td>
                                <td>{{ d.email }}</td>
                                <td>{{ d.internal_code }}</td>
                                <td>{{ d.active ? 'Si' : 'No' }}</td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn-muted" @click="edit(d)">Modifica</button>
                                        <button class="btn-primary" @click="router.push('/availability/' + d.id)">Disponibilità</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-cards" v-if="!loading && list.length">
                    <div class="mobile-card" v-for="d in list" :key="d.id + '-m'" :class="isEditing(d) ? 'editing-row-card' : ''">
                        <div><strong>#{{ d.id }}</strong> - {{ d.first_name }} {{ d.last_name }}</div>
                        <div><strong>Email:</strong> {{ d.email }}</div>
                        <div><strong>Codice:</strong> {{ d.internal_code }}</div>
                        <div><strong>Attivo:</strong> {{ d.active ? 'Si' : 'No' }}</div>
                        <div class="toolbar" style="margin-top:8px;">
                            <button class="btn-muted" @click="edit(d)">Modifica</button>
                            <button class="btn-primary" @click="router.push('/availability/' + d.id)">Disponibilità</button>
                        </div>
                    </div>
                </div>
                <div class="card" v-if="loading">Caricamento...</div>
                <div class="empty" v-else-if="!list.length">Nessun medico.</div>
            </div>
        </div>`,
    };

    const StaffView = {
        setup() {
            const list = ref([]);
            const loading = ref(true);
            const error = ref('');
            const form = reactive({
                id: null,
                first_name: '',
                last_name: '',
                email: '',
                active: true,
                password: '',
            });

            async function load() {
                loading.value = true;
                error.value = '';
                try {
                    const users = await api('/staff');
                    list.value = users.filter((user) => user.role === 'RECEPTION');
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            function edit(row) {
                form.id = row.id;
                form.first_name = row.first_name;
                form.last_name = row.last_name;
                form.email = row.email;
                form.active = !!row.active;
                form.password = '';
            }

            function reset() {
                form.id = null;
                form.first_name = '';
                form.last_name = '';
                form.email = '';
                form.active = true;
                form.password = '';
            }

            function isEditing(row) {
                return Number(form.id) === Number(row.id);
            }

            async function save() {
                error.value = '';
                if (!isValidEmail(form.email)) {
                    error.value = 'Email non valida.';
                    return;
                }
                const passwordError = passwordValidationMessage(form.password, !!form.id);
                if (passwordError) {
                    error.value = passwordError;
                    return;
                }
                try {
                    if (form.id) {
                        await api('/staff/' + form.id, {
                            method: 'PUT',
                            body: {
                                first_name: form.first_name,
                                last_name: form.last_name,
                                email: form.email,
                                active: form.active,
                                password: form.password,
                            },
                        });
                        setFlash('success', 'Utente reception aggiornato');
                    } else {
                        await api('/staff', {
                            method: 'POST',
                            body: {
                                first_name: form.first_name,
                                last_name: form.last_name,
                                email: form.email,
                                active: form.active,
                                password: form.password,
                            },
                        });
                        setFlash('success', 'Utente reception creato');
                    }
                    reset();
                    await load();
                } catch (e) {
                    error.value = e.message;
                }
            }

            onMounted(load);
            return { list, loading, error, form, edit, reset, save, isEditing };
        },
        template: `
        <div>
            <h2>Gestione Reception</h2>
            <div class="card" :class="form.id ? 'editing-form-card' : ''">
                <div v-if="error" class="alert error">{{ error }}</div>
                <h3 style="margin-bottom:12px;">{{ form.id ? 'Modifica utente #' + form.id : 'Nuovo utente reception' }}</h3>
                <div class="grid grid-2">
                    <div><label>Email</label><input type="email" v-model="form.email" /></div>
                    <div><label>Nome</label><input v-model="form.first_name" /></div>
                    <div><label>Cognome</label><input v-model="form.last_name" /></div>
                    <div><label>{{ form.id ? 'Password di reset' : 'Password iniziale' }}</label><input type="password" v-model="form.password" :placeholder="form.id ? 'Se la imposti, l\\'utente dovrà cambiarla al prossimo accesso' : 'Minimo 8 caratteri'" /></div>
                    <div class="check-field">
                        <label class="inline-check">
                            <input type="checkbox" v-model="form.active" />
                            Attivo
                        </label>
                    </div>
                </div>
                <div class="toolbar" style="margin-top:10px;">
                    <button class="btn-primary" @click="save">{{ form.id ? 'Aggiorna' : 'Crea nuovo' }}</button>
                    <button class="btn-muted" @click="reset">Annulla</button>
                </div>
            </div>
            <div class="card">
                <div class="desktop-table table-wrap" v-if="!loading && list.length">
                    <table>
                        <thead><tr><th>ID</th><th>Nome</th><th>Email</th><th>Attivo</th><th>Cambio password</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="user in list" :key="user.id" :class="isEditing(user) ? 'editing-row' : ''">
                                <td>#{{ user.id }}</td>
                                <td>{{ user.first_name }} {{ user.last_name }}</td>
                                <td>{{ user.email }}</td>
                                <td>{{ user.active ? 'Si' : 'No' }}</td>
                                <td>{{ user.must_change_password ? 'Si' : 'No' }}</td>
                                <td><button class="btn-muted" @click="edit(user)">Modifica</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-cards" v-if="!loading && list.length">
                    <div class="mobile-card" v-for="user in list" :key="user.id + '-m'" :class="isEditing(user) ? 'editing-row-card' : ''">
                        <div><strong>#{{ user.id }}</strong> - {{ user.first_name }} {{ user.last_name }}</div>
                        <div><strong>Email:</strong> {{ user.email }}</div>
                        <div><strong>Attivo:</strong> {{ user.active ? 'Si' : 'No' }}</div>
                        <div><strong>Cambio password:</strong> {{ user.must_change_password ? 'Si' : 'No' }}</div>
                        <button class="btn-muted" style="margin-top:8px;" @click="edit(user)">Modifica</button>
                    </div>
                </div>
                <div class="card" v-if="loading">Caricamento...</div>
                <div class="empty" v-else-if="!list.length">Nessun utente reception.</div>
            </div>
        </div>`,
    };
    const ReportsListView = {
        setup() {
            const list = ref([]);
            const loading = ref(false);
            const error = ref('');
            const searched = ref(false);
            const taxCode = ref('');

            async function loadReports() {
                error.value = '';
                if (!taxCode.value.trim()) {
                    error.value = 'Inserisci un codice fiscale';
                    list.value = [];
                    searched.value = true;
                    return;
                }

                loading.value = true;
                try {
                    const query = new URLSearchParams();
                    query.set('tax_code', taxCode.value.trim().toUpperCase());
                    const suffix = query.toString() ? '?' + query.toString() : '';
                    list.value = await api('/reports' + suffix);
                    searched.value = true;
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            }

            async function searchReports() {
                await loadReports();
            }

            function clearSearch() {
                taxCode.value = '';
                list.value = [];
                searched.value = false;
                error.value = '';
            }

            return {
                list,
                loading,
                error,
                searched,
                taxCode,
                searchReports,
                clearSearch,
                formatDateTime,
            };
        },
        template: `
        <div>
            <h2>Visualizza Referti</h2>
            <div class="card">
                <div class="grid grid-2">
                    <div>
                        <label>Codice fiscale paziente</label>
                        <input v-model="taxCode" @keyup.enter="searchReports" placeholder="RSSGLI90A41F205X" />
                    </div>
                </div>
                <div class="toolbar" style="margin-top:8px;">
                    <button class="btn-primary" @click="searchReports">Cerca referti</button>
                    <button class="btn-muted" @click="clearSearch">Pulisci</button>
                </div>
                <div class="small" style="margin-top:8px;">Ricerca disponibile solo per l'account integrator tramite codice fiscale del paziente.</div>
            </div>

            <div class="card" v-if="loading">Caricamento...</div>
            <div class="alert error" v-else-if="error">{{ error }}</div>
            <div class="card" v-else-if="searched">
                <div class="desktop-table table-wrap" v-if="list.length">
                    <table>
                        <thead><tr><th>ID</th><th>Appuntamento</th><th>Paziente</th><th>Codice fiscale</th><th>Medico</th><th>Categoria</th><th>Creato il</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="r in list" :key="r.id">
                                <td>#{{ r.id }}</td>
                                <td>#{{ r.appointment_id }}</td>
                                <td>{{ r.patient_first_name }} {{ r.patient_last_name }}</td>
                                <td>{{ r.patient_tax_code || '-' }}</td>
                                <td>{{ r.doctor_first_name }} {{ r.doctor_last_name }}</td>
                                <td>{{ r.visit_category }}</td>
                                <td>{{ formatDateTime(r.created_at) }}</td>
                                <td><router-link class="btn-muted" :to="'/reports/' + r.id">Apri</router-link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-cards" v-if="list.length">
                    <div class="mobile-card" v-for="r in list" :key="r.id + '-m'">
                        <div><strong>Referto:</strong> #{{ r.id }}</div>
                        <div><strong>Appuntamento:</strong> #{{ r.appointment_id }}</div>
                        <div><strong>Paziente:</strong> {{ r.patient_first_name }} {{ r.patient_last_name }}</div>
                        <div><strong>Codice fiscale:</strong> {{ r.patient_tax_code || '-' }}</div>
                        <div><strong>Medico:</strong> {{ r.doctor_first_name }} {{ r.doctor_last_name }}</div>
                        <div><strong>Categoria:</strong> {{ r.visit_category }}</div>
                        <div><strong>Creato il:</strong> {{ formatDateTime(r.created_at) }}</div>
                        <router-link class="btn-muted" :to="'/reports/' + r.id" style="margin-top:8px;">Apri</router-link>
                    </div>
                </div>
                <div class="empty" v-else>Nessun referto trovato per il codice fiscale indicato.</div>
            </div>
        </div>`,
    };

    const ReportDetailView = {
        setup() {
            const route = VueRouter.useRoute();
            const data = ref(null);
            const loading = ref(true);
            const error = ref('');

            onMounted(async () => {
                try {
                    data.value = await api('/reports/' + route.params.id);
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            });

            return { data, loading, error, state, formatDateTime };
        },
        template: `
        <div>
            <h2>Dettaglio Referto</h2>
            <div class="card" v-if="loading">Caricamento...</div>
            <div class="alert error" v-else-if="error">{{ error }}</div>
            <div class="card" v-else-if="data">
                <p><strong>Referto #{{ data.id }}</strong> - appuntamento #{{ data.appointment_id }}</p>
                <p><strong>Categoria:</strong> {{ data.visit_category }}</p>
                <p><strong>Prestazione eseguita:</strong> {{ formatDateTime(data.started_at || data.scheduled_start) }}</p>
                <p><strong>Creato il:</strong> {{ formatDateTime(data.created_at) }}</p>
                <p class="small">Tirano Salute s.r.l. - Tel.: 0342-0087654</p>
                <pre class="detail-pre">{{ data.report.report_text }}</pre>
                <a class="btn-muted" :href="state.apiBase + '/reports/' + data.id + '/download'">Download / stampa</a>
            </div>
        </div>`,
    };

    const StatsView = {
        setup() {
            const stats = ref(null);
            const loading = ref(true);
            const error = ref('');

            onMounted(async () => {
                try {
                    stats.value = await api('/stats');
                } catch (e) {
                    error.value = e.message;
                } finally {
                    loading.value = false;
                }
            });

            return { stats, loading, error };
        },
        template: `
        <div>
            <h2>Dashboard Statistiche</h2>
            <div class="card" v-if="loading">Caricamento...</div>
            <div class="alert error" v-else-if="error">{{ error }}</div>
            <div v-else-if="stats">
                <div class="grid grid-2">
                    <div class="kpi"><div>Pazienti totali</div><div class="value">{{ stats.global.total_patients ?? '-' }}</div></div>
                    <div class="kpi"><div>Medici totali</div><div class="value">{{ stats.global.total_doctors ?? '-' }}</div></div>
                    <div class="kpi"><div>Durata media visita</div><div class="value">{{ stats.global.avg_visit_duration_minutes ?? '-' }}</div></div>
                    <div class="kpi"><div>Ritardo medio</div><div class="value">{{ stats.global.avg_delay_minutes ?? '-' }}</div></div>
                </div>
                <div class="small" style="margin:12px 0 14px 0;">Tutti i tempi sono espressi in minuti.</div>
                <div class="card" style="margin-top:8px;">
                    <h3>Performance per medico</h3>
                    <div class="desktop-table table-wrap">
                        <table>
                            <thead><tr><th>Medico</th><th>Visite</th><th>Annullate</th><th>Durata media</th><th>Ritardo medio</th><th>Performance score</th></tr></thead>
                            <tbody>
                                <tr v-for="d in stats.by_doctor" :key="d.doctor_id">
                                    <td>{{ d.first_name }} {{ d.last_name }}</td>
                                    <td>{{ d.total_visits }}</td>
                                    <td>{{ d.cancelled_visits }}</td>
                                    <td>{{ d.avg_duration_minutes ?? '-' }}</td>
                                    <td>{{ d.avg_delay_minutes ?? '-' }}</td>
                                    <td>{{ d.performance_score ?? '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mobile-cards">
                        <div class="mobile-card" v-for="d in stats.by_doctor" :key="d.doctor_id + '-m'">
                            <div><strong>{{ d.first_name }} {{ d.last_name }}</strong></div>
                            <div><strong>Visite:</strong> {{ d.total_visits }}</div>
                            <div><strong>Annullate:</strong> {{ d.cancelled_visits }}</div>
                            <div><strong>Durata media:</strong> {{ d.avg_duration_minutes ?? '-' }}</div>
                            <div><strong>Ritardo medio:</strong> {{ d.avg_delay_minutes ?? '-' }}</div>
                            <div><strong>Performance score:</strong> {{ d.performance_score ?? '-' }}</div>
                        </div>
                    </div>
                </div>
                <div class="card" style="margin-top:8px;">
                    <h3>Visite per categoria</h3>
                    <div class="desktop-table table-wrap">
                        <table>
                            <thead><tr><th>Categoria</th><th>Visite</th><th>Annullate</th><th>Durata media</th></tr></thead>
                            <tbody>
                                <tr v-for="c in stats.by_category" :key="c.visit_category">
                                    <td>{{ c.visit_category }}</td>
                                    <td>{{ c.total_visits }}</td>
                                    <td>{{ c.cancelled_visits }}</td>
                                    <td>{{ c.avg_duration_minutes ?? '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mobile-cards">
                        <div class="mobile-card" v-for="c in stats.by_category" :key="c.visit_category + '-m'">
                            <div><strong>{{ c.visit_category }}</strong></div>
                            <div><strong>Visite:</strong> {{ c.total_visits }}</div>
                            <div><strong>Annullate:</strong> {{ c.cancelled_visits }}</div>
                            <div><strong>Durata media:</strong> {{ c.avg_duration_minutes ?? '-' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`,
    };

    const ChangePasswordView = {
        setup() {
            const router = VueRouter.useRouter();
            const form = reactive({ current_password: '', new_password: '', confirm_new_password: '' });
            const saving = ref(false);
            const error = ref('');
            const success = ref('');

            async function save() {
                error.value = '';
                success.value = '';

                if (!form.new_password.trim() || !form.confirm_new_password.trim()) {
                    error.value = 'Inserisci e conferma la nuova password.';
                    return;
                }

                if (!form.current_password.trim()) {
                    error.value = 'Inserisci la password corrente.';
                    return;
                }

                if (form.new_password !== form.confirm_new_password) {
                    error.value = 'La nuova password e la conferma non coincidono.';
                    return;
                }
                if (normalizedPassword(form.new_password).length < MIN_PASSWORD_LENGTH) {
                    error.value = 'La nuova password deve avere almeno 8 caratteri.';
                    return;
                }

                saving.value = true;
                try {
                    await api('/change-password', {
                        method: 'POST',
                        body: {
                            current_password: form.current_password,
                            new_password: form.new_password,
                        },
                    });
                    state.user = await api('/me');
                    success.value = 'Password aggiornata';
                    form.current_password = '';
                    form.new_password = '';
                    form.confirm_new_password = '';
                    if (!state.user.must_change_password) {
                        setFlash('success', 'Password aggiornata. Accesso completo ripristinato.');
                        await router.push(roleHome(state.user.role));
                    }
                } catch (e) {
                    error.value = e.message;
                } finally {
                    saving.value = false;
                }
            }

            return { form, saving, error, success, save, state };
        },
        template: `
        <div class="change-password-wrap">
            <h2>Cambio Password</h2>
            <div class="card change-password-card">
                <div v-if="error" class="alert error">{{ error }}</div>
                <div v-if="success" class="alert success">{{ success }}</div>
                <div class="grid change-password-form">
                    <div><label>Password corrente</label><input type="password" v-model="form.current_password" /></div>
                    <div><label>Nuova password</label><input type="password" v-model="form.new_password" /></div>
                    <div><label>Conferma nuova password</label><input type="password" v-model="form.confirm_new_password" /></div>
                    <div class="change-password-actions">
                        <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Aggiornamento...' : 'Aggiorna password' }}</button>
                    </div>
                </div>
            </div>
        </div>`,
    };

    const routes = [
        { path: '/login', component: LoginView },

        { path: '/patient/dashboard', component: { components: { RoleDashboard }, template: '<RoleDashboard title="Dashboard Paziente" />' }, meta: { auth: true, roles: ['PATIENT'] } },
        { path: '/patient/book', component: BookingView, props: { adminMode: false }, meta: { auth: true, roles: ['PATIENT'] } },
        { path: '/patient/appointments', component: AppointmentListView, props: { title: 'Le Mie Visite', endpoint: '/appointments', mode: '', showPatient: false, showDoctor: true, detailLabel: 'Dettaglio' }, meta: { auth: true, roles: ['PATIENT'] } },

        { path: '/doctor/dashboard', component: { components: { RoleDashboard }, template: '<RoleDashboard title="Dashboard Medico" />' }, meta: { auth: true, roles: ['DOCTOR'] } },
        { path: '/doctor/todo', component: AppointmentListView, props: { title: 'Visite Prenotate', endpoint: '/appointments', mode: 'todo', showPatient: true, showDoctor: false, detailLabel: 'Apri', showStatusInTodo: true }, meta: { auth: true, roles: ['DOCTOR'] } },
        { path: '/doctor/history', component: AppointmentListView, props: { title: 'Storico Visite', endpoint: '/appointments', mode: 'history', showPatient: true, showDoctor: false, detailLabel: 'Dettaglio' }, meta: { auth: true, roles: ['DOCTOR'] } },
        { path: '/doctor/availability', component: AvailabilityView, meta: { auth: true, roles: ['DOCTOR'] } },

        { path: '/reception/dashboard', component: { components: { RoleDashboard }, template: '<RoleDashboard title="Dashboard Reception" />' }, meta: { auth: true, roles: ['RECEPTION'] } },
        { path: '/reception/book', component: BookingView, props: { adminMode: true }, meta: { auth: true, roles: ['RECEPTION'] } },
        { path: '/reception/appointments', component: AppointmentListView, props: { title: 'Gestione Visite', endpoint: '/appointments', mode: '', showPatient: true, showDoctor: true, detailLabel: 'Dettaglio' }, meta: { auth: true, roles: ['RECEPTION'] } },
        { path: '/reception/patients', component: PatientsView, meta: { auth: true, roles: ['RECEPTION'] } },
        { path: '/reception/doctors', component: DoctorsView, meta: { auth: true, roles: ['RECEPTION'] } },

        { path: '/integrator/dashboard', component: { components: { RoleDashboard }, template: '<RoleDashboard title="Dashboard Integrator" />' }, meta: { auth: true, roles: ['INTEGRATOR'] } },
        { path: '/integrator/book', component: BookingView, props: { adminMode: true }, meta: { auth: true, roles: ['INTEGRATOR'] } },
        { path: '/integrator/appointments', component: AppointmentListView, props: { title: 'Gestione Visite', endpoint: '/appointments', mode: '', showPatient: true, showDoctor: true, detailLabel: 'Dettaglio' }, meta: { auth: true, roles: ['INTEGRATOR'] } },
        { path: '/integrator/patients', component: PatientsView, meta: { auth: true, roles: ['INTEGRATOR'] } },
        { path: '/integrator/doctors', component: DoctorsView, meta: { auth: true, roles: ['INTEGRATOR'] } },
        { path: '/integrator/staff', component: StaffView, meta: { auth: true, roles: ['INTEGRATOR'] } },
        { path: '/integrator/stats', component: StatsView, meta: { auth: true, roles: ['INTEGRATOR'] } },
        { path: '/integrator/reports', component: ReportsListView, meta: { auth: true, roles: ['INTEGRATOR'] } },

        { path: '/appointments/:id', component: AppointmentDetailView, meta: { auth: true, roles: ['PATIENT', 'DOCTOR', 'RECEPTION', 'INTEGRATOR'] } },
        { path: '/reports/:id', component: ReportDetailView, meta: { auth: true, roles: ['PATIENT', 'DOCTOR', 'INTEGRATOR'] } },
        { path: '/availability/:doctorId', component: AvailabilityView, meta: { auth: true, roles: ['RECEPTION', 'INTEGRATOR'] } },
        { path: '/change-password', component: ChangePasswordView, meta: { auth: true, roles: ['PATIENT', 'DOCTOR', 'RECEPTION', 'INTEGRATOR'] } },

        { path: '/:pathMatch(.*)*', redirect: '/login' },
    ];

    const router = createRouter({
        history: createWebHashHistory(),
        routes,
    });

    router.beforeEach(async (to) => {
        if (!state.initialized) {
            await bootstrap();
        }

        if (!to.meta.auth) {
            if (to.path === '/login' && state.user) {
                return state.user.must_change_password ? '/change-password' : roleHome(state.user.role);
            }
            return true;
        }

        if (!state.user) {
            return '/login';
        }

        if (state.user.must_change_password && to.path !== '/change-password') {
            return '/change-password';
        }

        if (to.meta.roles && !to.meta.roles.includes(state.user.role)) {
            return roleHome(state.user.role);
        }

        return true;
    });

    const App = {
        setup() {
            const route = VueRouter.useRoute();
            const hasUser = computed(() => !!state.user);

            const navItems = computed(() => {
                if (!state.user) return [];
                if (state.user.must_change_password) {
                    return [
                        { to: '/change-password', label: 'Aggiorna password' },
                    ];
                }
                if (state.user.role === 'PATIENT') {
                    return [
                        { to: '/patient/dashboard', label: 'Dashboard' },
                        { to: '/patient/book', label: 'Nuova prenotazione' },
                        { to: '/patient/appointments', label: 'Le mie visite' },
                        { to: '/change-password', label: 'Cambio password' },
                    ];
                }
                if (state.user.role === 'DOCTOR') {
                    return [
                        { to: '/doctor/dashboard', label: 'Dashboard medico' },
                        { to: '/doctor/todo', label: 'Visite prenotate' },
                        { to: '/doctor/history', label: 'Storico visite' },
                        { to: '/doctor/availability', label: 'Disponibilità' },
                        { to: '/change-password', label: 'Cambio password' },
                    ];
                }
                if (state.user.role === 'RECEPTION') {
                    return [
                        { to: '/reception/dashboard', label: 'Dashboard reception' },
                        { to: '/reception/book', label: 'Prenota visita' },
                        { to: '/reception/appointments', label: 'Gestione visite' },
                        { to: '/reception/patients', label: 'Gestione pazienti' },
                        { to: '/reception/doctors', label: 'Gestione medici' },
                        { to: '/change-password', label: 'Cambio password' },
                    ];
                }
                return [
                    { to: '/integrator/dashboard', label: 'Dashboard integrator' },
                    { to: '/integrator/stats', label: 'Dashboard statistiche' },
                    { to: '/integrator/book', label: 'Prenota visita' },
                    { to: '/integrator/appointments', label: 'Gestione visite' },
                    { to: '/integrator/patients', label: 'Gestione pazienti' },
                    { to: '/integrator/doctors', label: 'Gestione medici' },
                    { to: '/integrator/staff', label: 'Gestione reception' },
                    { to: '/integrator/reports', label: 'Visualizza referti' },
                    { to: '/change-password', label: 'Cambio password' },
                ];
            });

            async function logout() {
                try {
                    await api('/logout', { method: 'POST', body: {} });
                } catch (_) {
                }
                state.user = null;
                state.csrfToken = '';
                router.push('/login');
            }

            return { state, route, hasUser, navItems, logout, roleLabel };
        },
        template: `
        <div>
            <div v-if="!hasUser" class="public-shell">
                <router-view></router-view>
                <footer class="site-footer">Tirano Salute s.r.l. - P.IVA 0187650178 - Tel.: 0342-0087654 - progetto by Luca Soltoggio</footer>
            </div>
            <div v-else class="app-shell">
                <aside class="sidebar">
                    <div class="brand">Tirano Salute</div>
                    <div class="sidebar-user">
                        <div class="sidebar-user-name">{{ state.user.first_name }} {{ state.user.last_name }}</div>
                        <div class="sidebar-user-role">{{ roleLabel(state.user.role) }}</div>
                    </div>
                    <router-link v-for="item in navItems" :key="item.to" :to="item.to" class="nav-link">{{ item.label }}</router-link>
                    <button class="btn-muted" style="margin-top:14px; width:100%" @click="logout">Logout</button>
                </aside>
                <main class="main">
                    <div class="topbar"></div>
                    <div v-if="state.user && state.user.must_change_password" class="alert warning">Accesso limitato. La password impostata deve essere cambiata per poter accedere al servizio.</div>
                    <div v-if="state.flash.text" class="alert flash-toast" :class="state.flash.type === 'error' ? 'error' : 'success'">{{ state.flash.text }}</div>
                    <router-view></router-view>
                    <footer class="site-footer site-footer-app">Tirano Salute s.r.l. - P.IVA 0187650178 - Tel.: 0342-0087654 - progetto by Luca Soltoggio</footer>
                </main>
            </div>
        </div>`,
    };

    createApp(App).use(router).component('StatusBadge', StatusBadge).mount('#app');
})();

import { useHttp } from '@inertiajs/react';
import { Search, UserPlus, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import WalkInController from '@/actions/App/Http/Controllers/Staff/WalkInController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { WalkInPatient } from '@/types';

/** What staff type for a patient who has no account yet. The server checks every field again on submit. */
export type NewPatientFields = {
    name: string;
    email: string;
    birthDate: string;
};

export type PatientChoice =
    | { kind: 'existing'; patient: WalkInPatient }
    | { kind: 'new'; fields: NewPatientFields }
    | null;

type Props = {
    choice: PatientChoice;
    onChoose: (choice: PatientChoice) => void;
    /** The server's messages for the patient part of the form, by field. */
    errors: Record<string, string | undefined>;
    /** The day before today, as 'Y-m-d': the latest birth date the server accepts. */
    latestBirthDate: string;
};

/** Wait this long after the last key before asking the server, so typing a name is one request and not one per letter. */
const SEARCH_DELAY_MS = 300;
const MIN_TERM_LENGTH = 2;

/**
 * The "who is the patient" part of the walk-in dialog: search the existing accounts by name or email, or add a new
 * patient (name, email, birth date). The term goes to the server in the body of a POST, so it never lands in an address
 * or a log. Nothing about health is asked for. Only the three search fields come back, and the booking sends just the
 * chosen id (or the new patient's three fields).
 */
export default function WalkInPatientPicker({
    choice,
    onChoose,
    errors,
    latestBirthDate,
}: Props) {
    const queryId = useId();
    const nameId = useId();
    const emailId = useId();
    const birthId = useId();
    const resultsId = useId();
    const search = useHttp<{ q: string }, { patients: WalkInPatient[] }>({
        q: '',
    });
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<WalkInPatient[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [adding, setAdding] = useState(false);
    // Only the newest request may write the list: a slow answer to "ma" must not replace the answer to "maria".
    const latest = useRef(0);
    const queryRef = useRef<HTMLInputElement>(null);
    const nameRef = useRef<HTMLInputElement>(null);

    const term = query.trim();

    // `useHttp` returns a new object on every render. The search below reads the newest one from here, so it can depend
    // on the term alone: the term is what decides when to ask.
    const searchRef = useRef(search);

    useEffect(() => {
        searchRef.current = search;
    });

    useEffect(() => {
        if (term.length < MIN_TERM_LENGTH) {
            latest.current += 1;
            setResults(null);
            setLoading(false);

            return;
        }

        const request = ++latest.current;
        const timer = window.setTimeout(() => {
            setLoading(true);
            searchRef.current.setData('q', term);
            searchRef.current
                .submit(WalkInController.patients())
                .then((response) => {
                    if (request === latest.current) {
                        setResults(response.patients);
                    }
                })
                .catch(() => {
                    if (request === latest.current) {
                        setResults([]);
                    }
                })
                .finally(() => {
                    if (request === latest.current) {
                        setLoading(false);
                    }
                });
        }, SEARCH_DELAY_MS);

        return () => window.clearTimeout(timer);
    }, [term]);

    function startAdding() {
        setAdding(true);
        onChoose({
            kind: 'new',
            fields: { name: term, email: '', birthDate: '' },
        });
        // The field exists on the next render.
        window.setTimeout(() => nameRef.current?.focus(), 0);
    }

    function backToSearch() {
        setAdding(false);
        onChoose(null);
        window.setTimeout(() => queryRef.current?.focus(), 0);
    }

    function changeField(field: keyof NewPatientFields, value: string) {
        if (choice?.kind !== 'new') {
            return;
        }

        onChoose({ kind: 'new', fields: { ...choice.fields, [field]: value } });
    }

    if (choice?.kind === 'existing') {
        return (
            <div className="space-y-2">
                <p className="text-sm font-medium">Patient</p>
                <div className="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 p-3">
                    <div className="min-w-0">
                        <p
                            className="truncate font-medium"
                            data-test="walk-in-patient-name"
                        >
                            {choice.patient.name}
                        </p>
                        <p className="truncate text-sm text-muted-foreground">
                            {choice.patient.email}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            onChoose(null);
                            window.setTimeout(
                                () => queryRef.current?.focus(),
                                0,
                            );
                        }}
                    >
                        Change
                    </Button>
                </div>
                <input
                    type="hidden"
                    name="patient_id"
                    value={choice.patient.id}
                />
                {errors.patient_id && (
                    <p
                        role="alert"
                        className="text-sm text-destructive-foreground"
                    >
                        {errors.patient_id}
                    </p>
                )}
            </div>
        );
    }

    if (adding && choice?.kind === 'new') {
        const { fields } = choice;

        return (
            <fieldset className="space-y-3">
                <div className="flex items-center justify-between gap-3">
                    <legend className="text-sm font-medium">New patient</legend>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={backToSearch}
                    >
                        <X aria-hidden />
                        Search instead
                    </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                    The patient is emailed a link to confirm their address and
                    to choose their own password.
                </p>

                <div className="grid gap-2">
                    <Label htmlFor={nameId}>Full name</Label>
                    <Input
                        id={nameId}
                        ref={nameRef}
                        name="new_patient[name]"
                        autoComplete="off"
                        maxLength={255}
                        value={fields.name}
                        onChange={(event) =>
                            changeField('name', event.target.value)
                        }
                        aria-invalid={!!errors['new_patient.name']}
                        aria-describedby={
                            errors['new_patient.name']
                                ? `${nameId}-error`
                                : undefined
                        }
                    />
                    {errors['new_patient.name'] && (
                        <p
                            id={`${nameId}-error`}
                            className="text-sm text-destructive-foreground"
                        >
                            {errors['new_patient.name']}
                        </p>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor={emailId}>Email</Label>
                    <Input
                        id={emailId}
                        type="email"
                        name="new_patient[email]"
                        autoComplete="off"
                        maxLength={255}
                        value={fields.email}
                        onChange={(event) =>
                            changeField('email', event.target.value)
                        }
                        aria-invalid={!!errors['new_patient.email']}
                        aria-describedby={
                            errors['new_patient.email']
                                ? `${emailId}-error`
                                : undefined
                        }
                    />
                    {errors['new_patient.email'] && (
                        <p
                            id={`${emailId}-error`}
                            className="text-sm text-destructive-foreground"
                        >
                            {errors['new_patient.email']}
                        </p>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor={birthId}>Birth date</Label>
                    <Input
                        id={birthId}
                        type="date"
                        name="new_patient[birth_date]"
                        max={latestBirthDate}
                        value={fields.birthDate}
                        onChange={(event) =>
                            changeField('birthDate', event.target.value)
                        }
                        aria-invalid={!!errors['new_patient.birth_date']}
                        aria-describedby={
                            errors['new_patient.birth_date']
                                ? `${birthId}-error`
                                : undefined
                        }
                    />
                    {errors['new_patient.birth_date'] && (
                        <p
                            id={`${birthId}-error`}
                            className="text-sm text-destructive-foreground"
                        >
                            {errors['new_patient.birth_date']}
                        </p>
                    )}
                </div>

                {errors.new_patient && (
                    <p
                        role="alert"
                        className="text-sm text-destructive-foreground"
                    >
                        {errors.new_patient}
                    </p>
                )}
            </fieldset>
        );
    }

    const searched = term.length >= MIN_TERM_LENGTH && results !== null;

    return (
        <div className="space-y-2">
            <Label htmlFor={queryId}>Find the patient</Label>
            <div className="relative">
                <Search
                    aria-hidden
                    className="pointer-events-none absolute inset-y-0 left-3 my-auto size-4 text-muted-foreground"
                />
                {/* `ps-9`, not `pl-9`: the Input's own `px-3` wins over `pl-9` and puts the text under the icon. */}
                <Input
                    id={queryId}
                    ref={queryRef}
                    type="text"
                    inputMode="search"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="Name or email"
                    maxLength={100}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    aria-describedby={resultsId}
                    className="ps-9"
                    data-test="walk-in-patient-search"
                />
            </div>

            <div id={resultsId} className="space-y-2">
                <p
                    aria-live="polite"
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    {loading && <Spinner />}
                    {term.length < MIN_TERM_LENGTH
                        ? 'Type at least 2 letters of a name or email.'
                        : loading
                          ? 'Searching.'
                          : searched && results.length === 0
                            ? 'No patient found.'
                            : searched
                              ? `${results.length} ${results.length === 1 ? 'patient' : 'patients'} found.`
                              : ''}
                </p>

                {searched && results.length > 0 && (
                    <ul className="max-h-48 divide-y overflow-y-auto rounded-lg border">
                        {results.map((patient) => (
                            <li key={patient.id}>
                                <button
                                    type="button"
                                    onClick={() =>
                                        onChoose({ kind: 'existing', patient })
                                    }
                                    className="flex min-h-11 w-full flex-col items-start px-3 py-2 text-left outline-none hover:bg-accent focus-visible:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-inset"
                                    data-test="walk-in-patient-result"
                                >
                                    <span className="font-medium">
                                        {patient.name}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {patient.email}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Button
                type="button"
                variant="outline"
                className="w-full"
                onClick={startAdding}
                data-test="walk-in-new-patient"
            >
                <UserPlus aria-hidden />
                Add a new patient
            </Button>

            {(errors.patient_id || errors.new_patient) && (
                <p role="alert" className="text-sm text-destructive-foreground">
                    {errors.patient_id ?? errors.new_patient}
                </p>
            )}
        </div>
    );
}

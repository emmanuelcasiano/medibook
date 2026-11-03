import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    Baby,
    CalendarClock,
    Check,
    ClipboardList,
    EyeOff,
    HeartPulse,
    Lock,
    PenLine,
    Phone,
    ShieldPlus,
    Sparkles,
    Stethoscope,
    Syringe,
    UserCheck,
    Users,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import AppLogoMark from '@/components/app-logo-mark';
import DoctorAvatar from '@/components/booking/doctor-avatar';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';
import { services } from '@/routes/booking';
import { home as myHome } from '@/routes/my';
import type { BookableDoctor } from '@/types';

// The page has no props and the services are edited by an admin, so this is plain text about everyday care. The
// real list, with lengths, is one click away on the first booking step.
const careAreas = [
    {
        name: 'General consultation',
        text: 'A check-up for common illnesses and health concerns.',
        Icon: Stethoscope,
    },
    {
        name: 'Pediatric consultation',
        text: 'Care for babies, children and teens.',
        Icon: Baby,
    },
    {
        name: 'Skin consultation',
        text: 'Rashes, acne and other skin concerns.',
        Icon: Sparkles,
    },
    {
        name: 'Annual physical exam',
        text: 'A full yearly check-up with basic screening.',
        Icon: HeartPulse,
    },
    {
        name: 'Vaccination',
        text: 'Routine vaccines for children and adults.',
        Icon: Syringe,
    },
    {
        name: "Women's health consultation",
        text: 'Reproductive and general health for women.',
        Icon: ShieldPlus,
    },
];

// Each one is something the app already does. Nothing here is a number, an award or a promise about the future.
const reassurances = [
    {
        title: 'Free to cancel',
        text: 'Up to 2 hours before your visit.',
        Icon: CalendarClock,
    },
    {
        title: 'Your choice of doctor',
        text: 'Or take the first free time.',
        Icon: UserCheck,
    },
    {
        title: 'Answers stored encrypted',
        text: 'Locked as soon as they are saved.',
        Icon: Lock,
    },
    {
        title: 'Front desk can’t read them',
        text: 'Staff see only that your form is filled in.',
        Icon: EyeOff,
    },
];

const steps = [
    {
        title: 'Pick a time',
        text: 'Choose a service and a doctor, or let us find whoever is free first. You only see times that are really open.',
    },
    {
        title: "Tell us why you're coming",
        text: 'A short form about your symptoms, allergies and medicines. Write it in your own words, at home, with no one rushing you.',
    },
    {
        title: 'Arrive and check in',
        text: 'Your doctor can read your answers before the visit, so the visit starts where it should: with you.',
    },
];

// The path of what a patient writes, in three stops. Each line is a rule the app already enforces.
const answerPath = [
    { title: 'You write', text: 'At home, in your own words.', Icon: PenLine },
    { title: 'Encrypted', text: 'Locked when saved.', Icon: Lock },
    {
        title: 'Your doctor reads',
        text: 'Before the visit, and nobody else.',
        Icon: Stethoscope,
    },
];

const privacyPoints = [
    {
        title: 'Stored encrypted',
        text: 'Your answers and your doctor’s notes are locked when saved.',
        Icon: Lock,
    },
    {
        title: 'Front desk sees only what they need',
        text: 'Staff can see that your form is filled in, never what it says.',
        Icon: Users,
    },
    {
        title: 'A short summary, checked by your doctor',
        text: 'We prepare a brief summary from your answers alone, without your name or contact details. Your doctor reads your full answers and checks the summary.',
        Icon: ClipboardList,
    },
];

const questions = [
    {
        q: 'Can I change or cancel my booking?',
        a: 'Yes. You can cancel from My appointments up to 2 hours before your visit, then book a new time. It’s free.',
    },
    {
        q: 'Do I need an account to look at open times?',
        a: 'No. You can look at services, doctors and open times first. You log in or register just before you confirm.',
    },
    {
        q: 'Do I have to fill in the form before my visit?',
        a: 'It isn’t required, but it helps. Your doctor can read it before you arrive, and you can edit it until you check in.',
    },
    {
        q: 'How many visits can I book at once?',
        a: 'Up to 3 upcoming appointments at a time, and two of your bookings can’t overlap.',
    },
];

// Stock photos from Pexels, kept in public/images/landing at about twice the size they are shown, so they stay sharp
// on a high-density screen without being heavy. They are stock models, not patients or staff of this clinic, and the
// footer says so and credits each photographer.
const photos = {
    hero: {
        src: '/images/landing/hero-doctor.jpg',
        width: 900,
        height: 1350,
        alt: 'A smiling doctor in a white coat with a stethoscope, at her laptop in a clinic.',
    },
    how: {
        src: '/images/landing/how-booking.jpg',
        width: 800,
        height: 1200,
        alt: 'A smiling young woman with a phone in her hand, ready to book.',
    },
    privacy: {
        src: '/images/landing/privacy-consult.jpg',
        width: 1000,
        height: 667,
        alt: 'A smiling doctor talking with a patient in a bright clinic room.',
    },
    closing: {
        src: '/images/landing/closing-family.jpg',
        width: 1000,
        height: 667,
        alt: 'A smiling mother and her young child sitting together on a park bench.',
    },
};

const photoCredits = [
    {
        by: 'Tima Miroshnichenko',
        href: 'https://www.pexels.com/photo/a-smiling-doctor-in-white-lab-coat-with-stethoscope-on-her-neck-8376309/',
    },
    {
        by: 'Ketut Subiyanto',
        href: 'https://www.pexels.com/photo/smiling-young-asian-woman-with-smartphone-having-coffee-break-in-street-cafe-4350178/',
    },
    {
        by: 'cottonbro studio',
        href: 'https://www.pexels.com/photo/a-doctor-talking-the-patient-7579831/',
    },
    {
        by: 'Cherry Aranda',
        href: 'https://www.pexels.com/photo/young-woman-and-child-sitting-on-brown-wooden-bench-4767089/',
    },
];

/**
 * A stock photo. `width` and `height` let the browser hold the space before the file arrives, so the page does not
 * jump. Only the first photo, which is on screen at load, is fetched at once; the rest wait until they are near.
 */
function Photo({
    src,
    alt,
    width,
    height,
    className,
    priority = false,
}: {
    src: string;
    alt: string;
    width: number;
    height: number;
    className?: string;
    priority?: boolean;
}) {
    return (
        <img
            src={src}
            alt={alt}
            width={width}
            height={height}
            loading={priority ? 'eager' : 'lazy'}
            fetchPriority={priority ? 'high' : 'auto'}
            decoding="async"
            className={`rounded-3xl object-cover ${className ?? ''}`}
        />
    );
}

function Kicker({
    children,
    onDark = false,
}: {
    children: React.ReactNode;
    onDark?: boolean;
}) {
    return (
        <span
            className={`text-sm font-semibold tracking-widest uppercase ${onDark ? 'text-mint' : 'text-primary'}`}
        >
            {children}
        </span>
    );
}

const wrap = 'mx-auto w-full max-w-6xl px-4 sm:px-6';
const sectionTitle =
    'mt-3 text-3xl leading-9 font-semibold tracking-tight text-balance md:text-[40px] md:leading-[46px]';
// The header is sticky, so a section reached from its links stops below it.
const anchored = 'scroll-mt-16';
const linkClass =
    'inline-flex min-h-8 items-center rounded-md underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

type Props = {
    /** The doctors a patient can book, as the booking pages receive them. Empty when there are none. */
    doctors: BookableDoctor[];
};

export default function Welcome({ doctors }: Props) {
    const { auth, name } = usePage().props;

    // The "Doctors" link only exists when the section does.
    const pageLinks = [
        ['How it works', '#how'],
        ['Services', '#services'],
        ...(doctors.length > 0 ? [['Doctors', '#doctors']] : []),
        ['Privacy', '#privacy'],
        ['Questions', '#faq'],
    ];

    // Once the hero has scrolled away, its button is gone too, so the header takes over as the way to book.
    const heroRef = useRef<HTMLElement>(null);
    const [pastHero, setPastHero] = useState(false);

    useEffect(() => {
        const hero = heroRef.current;

        if (hero === null || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(([entry]) =>
            setPastHero(!entry.isIntersecting),
        );
        observer.observe(hero);

        return () => observer.disconnect();
    }, []);

    return (
        <>
            <Head title="Book a clinic visit" />

            <div className="min-h-screen bg-background text-foreground">
                <a
                    href="#main"
                    className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:ring-2 focus:ring-ring"
                >
                    Skip to the content
                </a>

                <header className="sticky top-0 z-40 border-b bg-card/90 backdrop-blur">
                    <div
                        className={`${wrap} flex items-center justify-between gap-3 py-2`}
                    >
                        <Link
                            href={home()}
                            className="flex items-center gap-2 rounded-md font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <AppLogoMark className="size-8" />
                            <span className="text-lg tracking-tight">
                                {name}
                            </span>
                        </Link>

                        <nav
                            aria-label="On this page"
                            className="hidden items-center gap-1 text-sm font-medium lg:flex"
                        >
                            {pageLinks.map(([label, href]) => (
                                <a
                                    key={href}
                                    href={href}
                                    className="inline-flex h-11 items-center rounded-md px-3 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {label}
                                </a>
                            ))}
                        </nav>

                        <nav
                            aria-label="Account"
                            className="flex items-center gap-2"
                        >
                            {auth.user ? (
                                // A signed-in user goes to the screen that is theirs.
                                <Button asChild variant="secondary">
                                    <Link
                                        href={
                                            auth.user.role === 'patient'
                                                ? myHome()
                                                : dashboard()
                                        }
                                    >
                                        {auth.user.role === 'patient'
                                            ? 'My appointments'
                                            : 'Go to my screen'}
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="secondary">
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                    {/* A phone has room for two buttons, so Book takes Register's place. */}
                                    <Button
                                        asChild
                                        variant="outline"
                                        className={
                                            pastHero ? 'max-sm:hidden' : ''
                                        }
                                    >
                                        <Link href={register()}>Register</Link>
                                    </Button>
                                </>
                            )}
                            {pastHero && (
                                <Button
                                    asChild
                                    className={auth.user ? 'max-sm:hidden' : ''}
                                >
                                    <Link href={services()}>Book</Link>
                                </Button>
                            )}
                        </nav>
                    </div>
                </header>

                <main id="main" tabIndex={-1} className="focus:outline-none">
                    {/* Hero: a soft teal band with a faint plus pattern that fades out downwards. */}
                    <section
                        ref={heroRef}
                        aria-labelledby="hero-heading"
                        className="relative overflow-hidden bg-linear-to-b from-teal-soft/70 to-background"
                    >
                        <svg
                            aria-hidden
                            className="pointer-events-none absolute inset-0 size-full [mask-image:linear-gradient(to_bottom,black,transparent_85%)] text-teal-ink/[0.08]"
                        >
                            <defs>
                                <pattern
                                    id="plus-pattern"
                                    width="56"
                                    height="56"
                                    patternUnits="userSpaceOnUse"
                                >
                                    <path
                                        d="M28 21v14M21 28h14"
                                        stroke="currentColor"
                                        strokeWidth="2.5"
                                        strokeLinecap="round"
                                    />
                                </pattern>
                            </defs>
                            <rect
                                width="100%"
                                height="100%"
                                fill="url(#plus-pattern)"
                            />
                        </svg>

                        <div
                            className={`${wrap} relative grid items-center gap-10 py-10 md:py-16 lg:grid-cols-[1.1fr_0.9fr] lg:gap-16`}
                        >
                            <div className="space-y-7">
                                <h1
                                    id="hero-heading"
                                    className="text-4xl leading-[1.1] font-semibold tracking-tight text-balance md:text-[56px]"
                                >
                                    See the right doctor,{' '}
                                    <span className="text-primary">
                                        at a time that suits you.
                                    </span>
                                </h1>
                                <p className="max-w-xl text-lg leading-8 text-muted-foreground">
                                    Book a visit in a few taps, tell your doctor
                                    what’s going on before you arrive, and spend
                                    your appointment on what matters to you.
                                </p>
                                <div className="flex flex-wrap gap-3">
                                    <Button
                                        asChild
                                        size="lg"
                                        className="w-full sm:w-auto"
                                    >
                                        <Link href={services()}>
                                            Book an appointment
                                            <ArrowRight aria-hidden />
                                        </Link>
                                    </Button>
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="lg"
                                        className="w-full sm:w-auto"
                                    >
                                        <a href="#how">How it works</a>
                                    </Button>
                                </div>
                                <ul className="space-y-2 text-sm font-medium">
                                    {[
                                        'Free to cancel up to 2 hours before',
                                        'See the doctor you choose, or the first free time',
                                    ].map((tick) => (
                                        <li
                                            key={tick}
                                            className="flex items-center gap-2"
                                        >
                                            <Check
                                                aria-hidden
                                                className="size-4 text-primary"
                                                strokeWidth={2.5}
                                            />
                                            {tick}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            {/* A tall photo on a wide screen, a wide crop on a phone. The crop keeps her face. */}
                            <Photo
                                {...photos.hero}
                                priority
                                className="mx-auto aspect-4/3 w-full max-w-md object-[50%_22%] shadow-lg shadow-teal-ink/10 lg:aspect-3/4 lg:max-w-none lg:object-[50%_18%]"
                            />
                        </div>
                    </section>

                    {/* Trust strip: four things the app already does, right under the hero. */}
                    <section
                        aria-label="Why book here"
                        className="border-y bg-card"
                    >
                        <ul
                            className={`${wrap} grid grid-cols-1 gap-6 py-8 sm:grid-cols-2 lg:grid-cols-4 lg:gap-8`}
                        >
                            {reassurances.map(({ title, text, Icon }) => (
                                <li key={title} className="flex gap-3">
                                    <span
                                        aria-hidden
                                        className="flex size-11 shrink-0 items-center justify-center rounded-full bg-teal-soft text-teal-ink"
                                    >
                                        <Icon className="size-5" />
                                    </span>
                                    <div className="space-y-0.5">
                                        <p className="font-semibold">{title}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {text}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {/* How it works */}
                    <section
                        id="how"
                        aria-labelledby="how-heading"
                        className={`${anchored} py-16 md:py-24`}
                    >
                        <div
                            className={`${wrap} grid items-center gap-10 lg:grid-cols-2 lg:gap-16`}
                        >
                            <Photo
                                {...photos.how}
                                className="mx-auto hidden aspect-4/5 w-full max-w-md object-[50%_30%] shadow-lg shadow-teal-ink/10 lg:block"
                            />
                            <div>
                                <Kicker>How it works</Kicker>
                                <h2 id="how-heading" className={sectionTitle}>
                                    Three steps, about five minutes.
                                </h2>
                                <ol className="mt-8 space-y-6">
                                    {steps.map((step, index) => (
                                        <li
                                            key={step.title}
                                            className="relative flex gap-4"
                                        >
                                            {/* A line from this step's number to the next one. */}
                                            {index < steps.length - 1 && (
                                                <span
                                                    aria-hidden
                                                    className="absolute top-[2.75rem] -bottom-5 left-5 w-px -translate-x-1/2 bg-teal-line"
                                                />
                                            )}
                                            <span
                                                aria-hidden
                                                className="relative flex size-10 shrink-0 items-center justify-center rounded-full bg-primary font-semibold text-primary-foreground"
                                            >
                                                {index + 1}
                                            </span>
                                            <div className="space-y-1">
                                                <h3 className="text-lg font-semibold">
                                                    {step.title}
                                                </h3>
                                                <p className="text-muted-foreground">
                                                    {step.text}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </div>
                    </section>

                    {/* Services */}
                    <section
                        id="services"
                        aria-labelledby="services-heading"
                        className={`${anchored} border-y bg-accent/60 py-16 md:py-24`}
                    >
                        <div className={wrap}>
                            <div className="flex flex-wrap items-end justify-between gap-4">
                                <div className="max-w-xl">
                                    <Kicker>Services</Kicker>
                                    <h2
                                        id="services-heading"
                                        className={sectionTitle}
                                    >
                                        Everyday care for the whole family.
                                    </h2>
                                </div>
                                <p className="max-w-sm text-muted-foreground">
                                    Every visit shows how long it takes before
                                    you book, so you can plan your day around
                                    it.
                                </p>
                            </div>

                            <ul className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {careAreas.map(({ name: area, text, Icon }) => (
                                    <li
                                        key={area}
                                        className="space-y-3 rounded-xl border bg-card p-5 shadow-xs"
                                    >
                                        <span
                                            aria-hidden
                                            className="flex size-11 items-center justify-center rounded-xl bg-teal-soft text-teal-ink"
                                        >
                                            <Icon className="size-5" />
                                        </span>
                                        <h3 className="text-[17px] font-semibold">
                                            {area}
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            {text}
                                        </p>
                                    </li>
                                ))}
                            </ul>

                            <div className="mt-8">
                                <Button asChild variant="outline" size="lg">
                                    <Link href={services()}>
                                        See what is open for booking
                                        <ArrowRight aria-hidden />
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </section>

                    {/* Doctors: the ones a patient can really book, sent by the server. They get initials, not photos: a stock
                        face under a real name would invent a person. */}
                    {doctors.length > 0 && (
                        <section
                            id="doctors"
                            aria-labelledby="doctors-heading"
                            className={`${anchored} py-16 md:py-24`}
                        >
                            <div className={wrap}>
                                <div className="max-w-2xl">
                                    <Kicker>Our doctors</Kicker>
                                    <h2
                                        id="doctors-heading"
                                        className={sectionTitle}
                                    >
                                        Choose who you see, or take the first
                                        free time.
                                    </h2>
                                </div>

                                <ul className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    {doctors.map((doctor) => (
                                        <li
                                            key={doctor.id}
                                            className="space-y-4 rounded-xl border bg-card p-5 shadow-xs"
                                        >
                                            <div className="flex items-center gap-3">
                                                <DoctorAvatar
                                                    name={doctor.name}
                                                />
                                                <div className="min-w-0">
                                                    <h3 className="leading-snug font-semibold">
                                                        {doctor.name}
                                                    </h3>
                                                    <p className="text-sm font-medium text-teal-ink">
                                                        {doctor.specialty}
                                                    </p>
                                                </div>
                                            </div>
                                            {doctor.bio && (
                                                <p className="line-clamp-3 text-sm text-muted-foreground">
                                                    {doctor.bio}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ul>

                                <div className="mt-8">
                                    <Button asChild variant="outline" size="lg">
                                        <Link href={services()}>
                                            Book an appointment
                                            <ArrowRight aria-hidden />
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </section>
                    )}

                    {/* Privacy: the centerpiece. It is the thing a patient worries about most, so it gets the deep teal. */}
                    <section
                        id="privacy"
                        aria-labelledby="privacy-heading"
                        className={`${anchored} bg-deep py-16 text-deep-foreground md:py-24`}
                    >
                        <div className={wrap}>
                            <div className="grid items-center gap-8 lg:grid-cols-[1.1fr_0.9fr] lg:gap-16">
                                <div className="max-w-2xl">
                                    <Kicker onDark>Before your visit</Kicker>
                                    <h2
                                        id="privacy-heading"
                                        className={`${sectionTitle} text-white`}
                                    >
                                        Your doctor reads your answers. Nobody
                                        else does.
                                    </h2>
                                    <p className="mt-4 text-lg text-deep-muted">
                                        What you write before your visit is
                                        private. It goes to your doctor, not the
                                        front desk.
                                    </p>
                                </div>

                                <Photo
                                    {...photos.privacy}
                                    className="aspect-3/2 w-full max-w-lg lg:ml-auto"
                                />
                            </div>

                            <ol
                                aria-label="Where your answers go"
                                className="mt-12 grid items-stretch gap-3 md:grid-cols-[1fr_auto_1fr_auto_1fr] md:gap-4"
                            >
                                {/* Every card and every arrow is its own direct list item (`display: contents` on an
                                    item would drop the list for some screen readers). The arrows are hidden from them:
                                    they only show the direction, and the list order already says it. */}
                                {answerPath.map(
                                    ({ title, text, Icon }, index) => (
                                        <Fragment key={title}>
                                            <li className="flex items-center gap-4 rounded-2xl border border-deep-line bg-deep-line/30 p-5 md:flex-col md:text-center">
                                                <span
                                                    aria-hidden
                                                    className="flex size-12 shrink-0 items-center justify-center rounded-full bg-mint text-deep"
                                                >
                                                    <Icon className="size-6" />
                                                </span>
                                                <div className="space-y-1">
                                                    <p className="text-lg font-semibold text-white">
                                                        {title}
                                                    </p>
                                                    <p className="text-sm text-deep-muted">
                                                        {text}
                                                    </p>
                                                </div>
                                            </li>
                                            {index < answerPath.length - 1 && (
                                                <li
                                                    aria-hidden
                                                    className="flex items-center justify-center text-mint"
                                                >
                                                    <ArrowDown className="size-5 md:hidden" />
                                                    <ArrowRight className="hidden size-6 md:block" />
                                                </li>
                                            )}
                                        </Fragment>
                                    ),
                                )}
                            </ol>

                            <ul className="mt-10 grid gap-4 md:grid-cols-3">
                                {privacyPoints.map(({ title, text, Icon }) => (
                                    <li
                                        key={title}
                                        className="space-y-2 border-t border-deep-line pt-5"
                                    >
                                        <h3 className="flex items-center gap-2 font-semibold text-white">
                                            <Icon
                                                aria-hidden
                                                className="size-5 text-mint"
                                            />
                                            {title}
                                        </h3>
                                        <p className="text-sm leading-6 text-deep-muted">
                                            {text}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>

                    {/* Emergencies */}
                    <section
                        aria-label="Emergencies"
                        className={`${wrap} py-12 md:py-16`}
                    >
                        <div className="flex flex-wrap items-center gap-4 rounded-2xl border border-danger-line bg-danger-soft p-5 text-danger-ink md:p-6">
                            <span
                                aria-hidden
                                className="flex size-12 shrink-0 items-center justify-center rounded-full bg-destructive text-white"
                            >
                                <Phone className="size-5" />
                            </span>
                            <div className="min-w-0 flex-1 basis-64 space-y-1">
                                <p className="text-lg font-semibold">
                                    This is not for emergencies.
                                </p>
                                <p>
                                    If you have chest pain, trouble breathing or
                                    heavy bleeding, call 911 or go to the
                                    nearest emergency room now.
                                </p>
                            </div>
                        </div>
                    </section>

                    {/* FAQ */}
                    <section
                        id="faq"
                        aria-labelledby="faq-heading"
                        className={`${anchored} border-y bg-card py-16 md:py-24`}
                    >
                        <div
                            className={`${wrap} grid items-start gap-8 lg:grid-cols-[1fr_2fr] lg:gap-16`}
                        >
                            <div>
                                <Kicker>Questions</Kicker>
                                <h2 id="faq-heading" className={sectionTitle}>
                                    Good to know
                                </h2>
                                <p className="mt-4 text-muted-foreground">
                                    Still unsure about something? The front desk
                                    is happy to help when you arrive.
                                </p>
                            </div>
                            <div className="divide-y rounded-xl border">
                                {questions.map(({ q, a }, index) => (
                                    <details
                                        key={q}
                                        open={index === 0}
                                        className="group p-5"
                                    >
                                        <summary className="flex min-h-6 cursor-pointer list-none items-center justify-between gap-4 rounded-md font-semibold marker:content-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                            {q}
                                            <span
                                                aria-hidden
                                                className="text-xl leading-none text-primary group-open:rotate-45"
                                            >
                                                +
                                            </span>
                                        </summary>
                                        <p className="mt-3 text-muted-foreground">
                                            {a}
                                        </p>
                                    </details>
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* Closing call to action */}
                    <section
                        aria-labelledby="cta-heading"
                        className={`${wrap} py-16 md:py-24`}
                    >
                        <div className="grid items-center gap-8 overflow-hidden rounded-3xl bg-deep p-8 text-deep-foreground md:p-12 lg:grid-cols-[1.2fr_0.8fr]">
                            <div className="space-y-5">
                                <h2
                                    id="cta-heading"
                                    className="text-3xl leading-9 font-semibold tracking-tight text-balance text-white md:text-[40px] md:leading-[46px]"
                                >
                                    Feeling off?{' '}
                                    <span className="text-mint">
                                        Let’s get you seen.
                                    </span>
                                </h2>
                                <p className="max-w-lg text-lg text-deep-muted">
                                    It takes a few minutes, and you can cancel
                                    up to 2 hours before your visit.
                                </p>
                                <div className="flex flex-wrap gap-3">
                                    <Button
                                        asChild
                                        size="lg"
                                        className="w-full bg-mint text-deep hover:bg-mint/90 sm:w-auto"
                                    >
                                        <Link href={services()}>
                                            Book an appointment
                                        </Link>
                                    </Button>
                                    {!auth.user && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="lg"
                                            className="w-full border-deep-line bg-transparent text-white hover:bg-white/10 hover:text-white sm:w-auto"
                                        >
                                            <Link href={login()}>
                                                I have an account
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <Photo
                                {...photos.closing}
                                className="hidden aspect-4/3 w-full rounded-2xl lg:block"
                            />
                        </div>
                    </section>
                </main>

                <footer className="border-t bg-card">
                    <div
                        className={`${wrap} grid gap-10 py-12 text-sm md:grid-cols-[1.4fr_1fr_1fr]`}
                    >
                        <div className="max-w-sm space-y-4">
                            <Link
                                href={home()}
                                className="flex w-fit items-center gap-2 rounded-md font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <AppLogoMark className="size-8" />
                                <span className="text-lg tracking-tight">
                                    {name}
                                </span>
                            </Link>
                            <p className="text-muted-foreground">
                                Book a clinic visit, tell your doctor what’s
                                going on before you arrive, and keep your
                                answers private.
                            </p>
                            <p className="flex items-start gap-2 rounded-lg border border-danger-line bg-danger-soft p-3 text-danger-ink">
                                <Phone
                                    aria-hidden
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                In an emergency, call 911 or go to the nearest
                                emergency room.
                            </p>
                        </div>

                        <nav aria-label="For patients" className="space-y-2">
                            <p className="font-semibold">For patients</p>
                            <ul className="space-y-1 text-muted-foreground">
                                <li>
                                    <Link
                                        href={services()}
                                        className={linkClass}
                                    >
                                        Book an appointment
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href={auth.user ? myHome() : login()}
                                        className={linkClass}
                                    >
                                        {auth.user
                                            ? 'My appointments'
                                            : 'Log in'}
                                    </Link>
                                </li>
                                {!auth.user && (
                                    <li>
                                        <Link
                                            href={register()}
                                            className={linkClass}
                                        >
                                            Create an account
                                        </Link>
                                    </li>
                                )}
                            </ul>
                        </nav>

                        <nav
                            aria-label="More on this page"
                            className="space-y-2"
                        >
                            <p className="font-semibold">Good to know</p>
                            <ul className="space-y-1 text-muted-foreground">
                                {pageLinks.map(([label, href]) => (
                                    <li key={href}>
                                        <a href={href} className={linkClass}>
                                            {label}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    </div>

                    <div className="border-t">
                        <p
                            className={`${wrap} flex flex-wrap justify-between gap-x-6 gap-y-1 py-4 text-sm text-muted-foreground`}
                        >
                            <span>
                                A demo with fake data. Please don’t enter real
                                health information.
                            </span>
                            <span>All times are Manila time.</span>
                            {/* The people in the photos are stock models, not patients or staff of this clinic. */}
                            <span className="basis-full">
                                Stock photos from Pexels by{' '}
                                {photoCredits.map(({ by, href }, index) => (
                                    <span key={by}>
                                        {index > 0 &&
                                            (index === photoCredits.length - 1
                                                ? ' and '
                                                : ', ')}
                                        <a
                                            href={href}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="underline underline-offset-4 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            {by}
                                            <span className="sr-only">
                                                {' '}
                                                (opens in a new tab)
                                            </span>
                                        </a>
                                    </span>
                                ))}
                                .
                            </span>
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}

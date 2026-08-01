<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface Source {
    portal: string;
    label: string;
    url: string;
    adverts: number;
}

interface PriceChange {
    from: string;
    to: string;
    at: string;
    percent: number;
}

interface Label {
    label: string;
    text: string;
    tone: string;
}

interface Listing {
    id: number;
    title: string;
    score: number;
    labels: Label[];
    description: string | null;
    city: string;
    district: string | null;
    areaM2: number;
    area: string;
    rooms: number;
    floor: number | null;
    priceZloty: number;
    price: string;
    pricePerMeter: string;
    imageUrl: string | null;
    images: string[];
    sources: Source[];
    firstSeenAt: string;
    lastSeenAt: string;
    publishedAt: string | null;
    isPrivateSeller: boolean | null;
    priceHistory: PriceChange[];
    totalPriceChangeZloty: number | null;
    warning: string | null;
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    listings: Listing[];
    pagination: {
        page: number;
        perPage: number;
        total: number;
        lastPage: number;
    };
    filters: {
        city: string | null;
        portal: string | null;
        sort: string;
        drops: boolean;
        priceFrom: number | null;
        priceTo: number | null;
    };
    options: { cities: Option[]; portals: Option[]; sorts: Option[] };
    criteria: {
        minArea: string;
        minRooms: number;
        minPrice: string;
        maxPrice: string;
    };
    stats: {
        total: number;
        byCity: Record<string, number>;
        withPriceDrop: number;
    };
}>();

function apply(changes: Record<string, string | number | boolean | null>) {
    const query: Record<string, string> = {};
    const merged = { ...props.filters, page: 1, ...changes };

    for (const [key, value] of Object.entries(merged)) {
        if (value !== null && value !== '' && value !== false) {
            query[key] = String(value);
        }
    }

    router.get('/', query, { preserveScroll: true, preserveState: true });
}

// Filtering reloads from the server, so without this the controls look dead
// for the duration of the round trip and get clicked twice.
const loading = ref(false);

const hasFilters = computed(
    () =>
        props.filters.city !== null ||
        props.filters.portal !== null ||
        props.filters.drops ||
        props.filters.priceFrom !== null ||
        props.filters.priceTo !== null,
);

function resetFilters() {
    apply({
        city: null,
        portal: null,
        drops: false,
        priceFrom: null,
        priceTo: null,
    });
}

function plural(count: number, one: string, few: string, many: string) {
    const lastDigit = count % 10;
    const lastTwo = count % 100;

    if (count === 1) {
        return one;
    }

    if (lastDigit >= 2 && lastDigit <= 4 && (lastTwo < 12 || lastTwo > 14)) {
        return few;
    }

    return many;
}

// The count belongs in the tab, not just on the page — this app is meant to
// live in a pinned tab and be glanced at.
const pageTitle = computed(
    () =>
        `${props.pagination.total} ${plural(props.pagination.total, 'oferta', 'oferty', 'ofert')}`,
);

const shownRange = computed(() => {
    const first = (props.pagination.page - 1) * props.pagination.perPage + 1;

    return `${first}–${first + props.listings.length - 1}`;
});

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('pl-PL', {
        day: 'numeric',
        month: 'short',
    });

const daysSince = (iso: string) =>
    Math.max(
        0,
        Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000),
    );

const rangeLabel = computed(
    () => `${props.criteria.minPrice} – ${props.criteria.maxPrice}`,
);

// Thinking in round ceilings is how a mortgage conversation actually goes, so
// the common ones are one click rather than two number entries.
const priceCaps = [400_000, 450_000, 500_000, 550_000];

const capIsActive = (cap: number) =>
    props.filters.priceTo === cap && props.filters.priceFrom === null;

function toggleCap(cap: number) {
    apply(
        capIsActive(cap)
            ? { priceFrom: null, priceTo: null }
            : { priceFrom: null, priceTo: cap },
    );
}

// The two labels that answer "is this actually a find?" get the loud styling;
// everything else is supporting detail.
const labelClass = (label: Label) => {
    if (label.tone === 'bad') {
        return 'bg-warn-surface text-warn-ink';
    }

    return label.label === 'below_comfortable_budget' ||
        label.label === 'cheap_per_meter'
        ? 'bg-good-surface text-good-ink ring-1 ring-good-ink/20'
        : 'bg-surface-sunken text-ink-soft';
};

function ageLabel(days: number) {
    if (days === 0) {
        return 'dziś';
    }

    if (days === 1) {
        return 'wczoraj';
    }

    return `${days} dni temu`;
}

// How long the advert has been up on the portal — the number that tells you
// whether the seller is likely to negotiate. Falls back to how long we have
// been watching it for the portals that publish no date.
function listedAgo(listing: Listing) {
    if (listing.publishedAt !== null) {
        return {
            text: `wystawione ${ageLabel(daysSince(listing.publishedAt))}`,
            title: `Ogłoszenie opublikowane ${formatDate(listing.publishedAt)}`,
            stale: daysSince(listing.publishedAt) >= 60,
        };
    }

    return {
        text: `u nas od ${daysSince(listing.firstSeenAt)} dni`,
        title: 'Ten portal nie podaje daty publikacji ogłoszenia',
        stale: false,
    };
}

// Details open in place on purpose: triaging dozens of flats means never
// losing the scroll position or the active filters.
const selected = ref<Listing | null>(null);
const activeImage = ref(0);
const openedFrom = ref<HTMLElement | null>(null);

function open(listing: Listing) {
    openedFrom.value = document.activeElement as HTMLElement | null;
    selected.value = listing;
    activeImage.value = 0;
}

function close() {
    selected.value = null;
    // Back to the card that was opened, so keyboard users don't restart at the
    // top of the grid after every flat they look at.
    openedFrom.value?.focus();
}

const currentImage = computed(
    () => selected.value?.images[activeImage.value] ?? null,
);

function nextImage() {
    if (!selected.value) {
        return;
    }

    activeImage.value = Math.min(
        activeImage.value + 1,
        selected.value.images.length - 1,
    );
}

function previousImage() {
    activeImage.value = Math.max(activeImage.value - 1, 0);
}

function onKeydown(event: KeyboardEvent) {
    if (selected.value === null) {
        return;
    }

    if (event.key === 'Escape') {
        close();
    }

    if (event.key === 'ArrowRight') {
        nextImage();
    }

    if (event.key === 'ArrowLeft') {
        previousImage();
    }
}

// The grid behind the dialog must not scroll away under it.
watch(selected, (listing) => {
    document.body.style.overflow = listing === null ? '' : 'hidden';
});

let stopLoading: (() => void)[] = [];

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    stopLoading = [
        router.on('start', () => (loading.value = true)),
        router.on('finish', () => (loading.value = false)),
    ];
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    stopLoading.forEach((off) => off());
    document.body.style.overflow = '';
});

// Everything worth knowing before deciding to open the portal, in one grid.
const facts = computed(() => {
    const listing = selected.value;

    if (listing === null) {
        return [];
    }

    return [
        { label: 'Metraż', value: listing.area },
        { label: 'Pokoje', value: String(listing.rooms) },
        {
            label: 'Piętro',
            value: listing.floor === null ? '—' : String(listing.floor),
        },
        { label: 'Cena za m²', value: listing.pricePerMeter },
        { label: 'Miasto', value: listing.city },
        { label: 'Dzielnica', value: listing.district ?? '—' },
        {
            label: 'Ogłoszenie z',
            value:
                listing.publishedAt === null
                    ? '—'
                    : `${formatDate(listing.publishedAt)} (${daysSince(listing.publishedAt)} dni)`,
        },
        {
            label: 'Portale',
            value: listing.sources.map((source) => source.label).join(', '),
        },
    ];
});
</script>

<template>
    <Head :title="pageTitle" />

    <div class="min-h-screen bg-canvas text-ink">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <h1 class="text-xl font-semibold tracking-tight">
                    Nasze mieszkania
                </h1>
                <p class="mt-1 text-sm text-muted">
                    Od {{ criteria.minArea }}, min.
                    {{ criteria.minRooms }} pokoje, {{ rangeLabel }}
                </p>

                <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    <span
                        ><strong>{{ stats.total }}</strong> mieszkań</span
                    >
                    <span v-if="stats.withPriceDrop > 0" class="text-good">
                        <strong>{{ stats.withPriceDrop }}</strong> po obniżce
                    </span>
                    <span
                        v-for="option in options.cities"
                        :key="option.value"
                        class="text-muted"
                    >
                        {{ option.label }}:
                        {{ stats.byCity[option.value] ?? 0 }}
                    </span>
                </div>
            </div>
        </header>

        <div
            class="sticky top-0 z-30 border-b border-line bg-surface/85 backdrop-blur"
            :class="loading && 'animate-pulse'"
        >
            <div
                class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:px-6"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-muted">Cena do:</span>
                    <button
                        v-for="cap in priceCaps"
                        :key="cap"
                        class="h-9 cursor-pointer rounded-full px-3 text-sm font-medium transition"
                        :class="
                            capIsActive(cap)
                                ? 'bg-ink text-surface'
                                : 'bg-surface-sunken text-ink-soft hover:bg-surface-hover hover:text-ink'
                        "
                        :aria-pressed="capIsActive(cap)"
                        @click="toggleCap(cap)"
                    >
                        {{ (cap / 1000).toLocaleString('pl-PL') }} tys.
                    </button>

                    <span class="ml-2 text-sm text-muted">lub zakres:</span>
                    <input
                        type="number"
                        inputmode="numeric"
                        step="10000"
                        placeholder="od"
                        aria-label="Cena od"
                        class="h-9 w-28 rounded-md border border-line bg-surface px-3 text-sm text-ink transition hover:border-line-strong"
                        :value="filters.priceFrom ?? ''"
                        @change="
                            apply({
                                priceFrom:
                                    ($event.target as HTMLInputElement).value ||
                                    null,
                            })
                        "
                    />
                    <input
                        type="number"
                        inputmode="numeric"
                        step="10000"
                        placeholder="do"
                        aria-label="Cena do"
                        class="h-9 w-28 rounded-md border border-line bg-surface px-3 text-sm text-ink transition hover:border-line-strong"
                        :value="filters.priceTo ?? ''"
                        @change="
                            apply({
                                priceTo:
                                    ($event.target as HTMLInputElement).value ||
                                    null,
                            })
                        "
                    />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <select
                        class="h-9 cursor-pointer rounded-md border border-line bg-surface px-3 text-sm text-ink transition hover:border-line-strong"
                        :value="filters.city ?? ''"
                        @change="
                            apply({
                                city:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Wszystkie miasta</option>
                        <option
                            v-for="option in options.cities"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>

                    <select
                        class="h-9 cursor-pointer rounded-md border border-line bg-surface px-3 text-sm text-ink transition hover:border-line-strong"
                        :value="filters.portal ?? ''"
                        @change="
                            apply({
                                portal:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Wszystkie portale</option>
                        <option
                            v-for="option in options.portals"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>

                    <select
                        class="h-9 cursor-pointer rounded-md border border-line bg-surface px-3 text-sm text-ink transition hover:border-line-strong"
                        :value="filters.sort"
                        @change="
                            apply({
                                sort: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option
                            v-for="option in options.sorts"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>

                    <label
                        class="flex cursor-pointer items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            class="size-4 cursor-pointer rounded border-line-strong accent-brand"
                            :checked="filters.drops"
                            @change="
                                apply({
                                    drops: ($event.target as HTMLInputElement)
                                        .checked,
                                })
                            "
                        />
                        Tylko po obniżce
                    </label>

                    <button
                        v-if="hasFilters"
                        class="h-9 cursor-pointer rounded-md px-2 text-sm text-muted underline-offset-4 transition hover:text-ink hover:underline"
                        @click="resetFilters"
                    >
                        Wyczyść filtry
                    </button>

                    <span class="ml-auto text-sm text-muted">
                        <template v-if="pagination.total > 0">
                            {{ shownRange }} z {{ pagination.total }}
                        </template>
                        <template v-else>0 wyników</template>
                    </span>
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
            <p
                v-if="listings.length === 0 && hasFilters"
                class="mt-16 text-center text-sm text-muted"
            >
                Żadne mieszkanie nie pasuje do tych filtrów.
                <button
                    class="underline underline-offset-4"
                    @click="resetFilters"
                >
                    Wyczyść je
                </button>
                i spróbuj ponownie.
            </p>

            <p
                v-else-if="listings.length === 0"
                class="mt-16 text-center text-sm text-muted"
            >
                Nic tu jeszcze nie ma. Uruchom
                <code
                    class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-ink-soft"
                    >php artisan listings:scan</code
                >.
            </p>

            <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="listing in listings"
                    :key="listing.id"
                    class="group flex cursor-pointer flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-sm transition hover:-translate-y-0.5 hover:border-brand-ring hover:shadow-lg"
                    role="button"
                    tabindex="0"
                    @click="open(listing)"
                    @keydown.enter="open(listing)"
                >
                    <div class="relative overflow-hidden">
                        <img
                            v-if="listing.imageUrl"
                            :src="listing.imageUrl"
                            :alt="listing.title"
                            loading="lazy"
                            class="aspect-3/2 w-full object-cover transition duration-300 group-hover:scale-[1.03]"
                        />
                        <div
                            v-else
                            class="grid aspect-3/2 w-full place-items-center bg-surface-sunken text-xs text-faint"
                        >
                            Brak zdjęcia
                        </div>
                        <span
                            v-if="listing.images.length > 1"
                            class="absolute right-2 bottom-2 rounded-full bg-black/60 px-2 py-0.5 text-xs text-white"
                        >
                            {{ listing.images.length }} zdjęć
                        </span>
                    </div>

                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex items-baseline justify-between gap-2">
                            <span
                                class="text-lg font-semibold tracking-tight"
                                >{{ listing.price }}</span
                            >
                            <span class="text-xs text-muted">
                                {{ listing.pricePerMeter }}
                            </span>
                        </div>

                        <div
                            v-if="listing.labels.length > 0 || listing.warning"
                            class="flex flex-wrap gap-1"
                        >
                            <span
                                v-for="label in listing.labels"
                                :key="label.label"
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="labelClass(label)"
                            >
                                {{ label.text }}
                            </span>
                            <span
                                v-if="listing.warning"
                                class="rounded-full bg-warn-surface px-2 py-0.5 text-xs font-medium text-warn-ink"
                            >
                                ⚠ sprawdź
                            </span>
                        </div>

                        <h2 class="line-clamp-2 text-sm font-medium">
                            {{ listing.title }}
                        </h2>

                        <p class="text-sm text-muted">
                            {{ listing.city
                            }}<span v-if="listing.district"
                                >, {{ listing.district }}</span
                            >
                            &middot; {{ listing.area }} &middot;
                            {{ listing.rooms }} pok.
                            <span v-if="listing.floor !== null"
                                >&middot; {{ listing.floor }} p.</span
                            >
                        </p>

                        <div
                            class="mt-auto flex flex-wrap items-center gap-2 pt-2"
                        >
                            <a
                                v-for="source in listing.sources"
                                :key="source.portal"
                                :href="source.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="rounded border border-line px-2 py-1 text-xs text-ink-soft transition hover:border-brand hover:bg-surface-hover hover:text-brand"
                                @click.stop
                            >
                                {{ source.label }}
                                <span v-if="source.adverts > 1"
                                    >&times;{{ source.adverts }}</span
                                >
                                &nearr;
                            </a>
                            <span
                                class="ml-auto text-xs"
                                :class="
                                    listedAgo(listing).stale
                                        ? 'font-medium text-warn'
                                        : 'text-faint'
                                "
                                :title="listedAgo(listing).title"
                            >
                                {{ listedAgo(listing).text }}
                            </span>
                        </div>
                    </div>
                </li>
            </ul>

            <nav
                v-if="pagination.lastPage > 1"
                class="mt-8 flex items-center justify-center gap-4 text-sm"
            >
                <button
                    class="cursor-pointer rounded-md border border-line bg-surface px-3 py-2 text-ink-soft transition hover:border-brand hover:text-brand disabled:cursor-default disabled:opacity-40 disabled:hover:border-line disabled:hover:text-ink-soft"
                    :disabled="pagination.page <= 1"
                    @click="apply({ page: pagination.page - 1 })"
                >
                    Poprzednia
                </button>
                <span class="text-muted tabular-nums">
                    {{ pagination.page }} / {{ pagination.lastPage }}
                </span>
                <button
                    class="cursor-pointer rounded-md border border-line bg-surface px-3 py-2 text-ink-soft transition hover:border-brand hover:text-brand disabled:cursor-default disabled:opacity-40 disabled:hover:border-line disabled:hover:text-ink-soft"
                    :disabled="pagination.page >= pagination.lastPage"
                    @click="apply({ page: pagination.page + 1 })"
                >
                    Następna
                </button>
            </nav>
        </div>

        <div
            v-if="selected"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm sm:p-4 lg:p-6"
            role="dialog"
            aria-modal="true"
            :aria-label="selected.title"
            @click.self="close"
        >
            <div
                class="flex h-full w-full max-w-[110rem] flex-col overflow-hidden bg-surface shadow-2xl sm:rounded-xl"
            >
                <div
                    class="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto lg:grid-cols-[minmax(0,1fr)_26rem] lg:overflow-hidden"
                >
                    <div
                        class="relative flex flex-col bg-neutral-950 lg:min-h-0"
                    >
                        <div
                            class="relative flex min-h-0 flex-1 items-center justify-center"
                        >
                            <img
                                v-if="currentImage"
                                :src="currentImage"
                                :alt="selected.title"
                                class="max-h-[45vh] w-full object-contain lg:h-full lg:max-h-full"
                            />
                            <p v-else class="p-16 text-sm text-neutral-400">
                                Brak zdjęć
                            </p>

                            <template v-if="selected.images.length > 1">
                                <button
                                    class="absolute top-1/2 left-3 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-black/60 text-xl text-white transition hover:bg-black/80 disabled:opacity-30"
                                    aria-label="Poprzednie zdjęcie"
                                    :disabled="activeImage === 0"
                                    @click="previousImage"
                                >
                                    &lsaquo;
                                </button>
                                <button
                                    class="absolute top-1/2 right-3 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-black/60 text-xl text-white transition hover:bg-black/80 disabled:opacity-30"
                                    aria-label="Następne zdjęcie"
                                    :disabled="
                                        activeImage ===
                                        selected.images.length - 1
                                    "
                                    @click="nextImage"
                                >
                                    &rsaquo;
                                </button>
                                <span
                                    class="absolute right-3 bottom-3 rounded-full bg-black/60 px-2.5 py-1 text-xs text-white tabular-nums"
                                >
                                    {{ activeImage + 1 }} /
                                    {{ selected.images.length }}
                                </span>
                            </template>
                        </div>

                        <div
                            v-if="selected.images.length > 1"
                            class="flex gap-1.5 overflow-x-auto bg-black/40 p-2"
                        >
                            <button
                                v-for="(image, index) in selected.images"
                                :key="image"
                                class="size-16 shrink-0 cursor-pointer overflow-hidden rounded transition"
                                :class="
                                    index === activeImage
                                        ? 'ring-2 ring-white'
                                        : 'opacity-50 hover:opacity-90'
                                "
                                :aria-label="`Zdjęcie ${index + 1}`"
                                @click="activeImage = index"
                            >
                                <img
                                    :src="image"
                                    alt=""
                                    loading="lazy"
                                    class="size-full object-cover"
                                />
                            </button>
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-col lg:overflow-y-auto">
                        <div
                            class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-line bg-surface p-5"
                        >
                            <div>
                                <p
                                    class="text-2xl font-semibold tracking-tight"
                                >
                                    {{ selected.price }}
                                </p>
                                <p class="mt-0.5 text-sm text-muted">
                                    {{ selected.pricePerMeter }}
                                </p>
                            </div>
                            <button
                                class="grid size-8 shrink-0 cursor-pointer place-items-center rounded-full text-xl text-muted transition hover:bg-surface-hover hover:text-ink"
                                aria-label="Zamknij"
                                @click="close"
                            >
                                &times;
                            </button>
                        </div>

                        <div class="space-y-5 p-5">
                            <div
                                v-if="
                                    selected.labels.length > 0 ||
                                    selected.isPrivateSeller === false
                                "
                                class="flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="label in selected.labels"
                                    :key="label.label"
                                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="labelClass(label)"
                                >
                                    {{ label.text }}
                                </span>
                                <span
                                    v-if="selected.isPrivateSeller === false"
                                    class="rounded-full bg-surface-sunken px-2.5 py-1 text-xs text-ink-soft"
                                >
                                    Biuro nieruchomości
                                </span>
                            </div>

                            <p
                                v-if="selected.warning"
                                class="rounded-md border border-warn/30 bg-warn-surface px-3 py-2 text-xs text-warn-ink"
                            >
                                ⚠ {{ selected.warning }}
                            </p>

                            <h2 class="text-base leading-snug font-medium">
                                {{ selected.title }}
                            </h2>

                            <dl
                                class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm"
                            >
                                <div v-for="fact in facts" :key="fact.label">
                                    <dt class="text-xs text-muted">
                                        {{ fact.label }}
                                    </dt>
                                    <dd class="mt-0.5 font-medium">
                                        {{ fact.value }}
                                    </dd>
                                </div>
                            </dl>

                            <div v-if="selected.priceHistory.length > 0">
                                <h3
                                    class="mb-2 text-xs font-medium tracking-wide text-muted uppercase"
                                >
                                    Historia ceny
                                </h3>
                                <ul class="space-y-1 text-sm">
                                    <li
                                        v-for="change in selected.priceHistory"
                                        :key="change.at"
                                        class="flex items-baseline gap-2"
                                    >
                                        <span
                                            class="w-14 shrink-0 text-xs text-muted tabular-nums"
                                            >{{ formatDate(change.at) }}</span
                                        >
                                        <span class="text-faint line-through">{{
                                            change.from
                                        }}</span>
                                        <span class="font-medium">{{
                                            change.to
                                        }}</span>
                                        <span
                                            class="text-xs font-medium"
                                            :class="
                                                change.percent < 0
                                                    ? 'text-good'
                                                    : 'text-bad'
                                            "
                                        >
                                            {{ change.percent > 0 ? '+' : ''
                                            }}{{ change.percent }}%
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="selected.description">
                                <h3
                                    class="mb-2 text-xs font-medium tracking-wide text-muted uppercase"
                                >
                                    Opis
                                </h3>
                                <p
                                    class="max-h-56 overflow-y-auto text-sm leading-relaxed whitespace-pre-line text-ink-soft"
                                >
                                    {{ selected.description }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-auto border-t border-line bg-surface-sunken p-5"
                        >
                            <h3
                                class="mb-2 text-xs font-medium tracking-wide text-muted uppercase"
                            >
                                Zobacz ogłoszenie
                            </h3>
                            <div class="flex flex-wrap gap-2">
                                <a
                                    v-for="source in selected.sources"
                                    :key="source.portal"
                                    :href="source.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded-md bg-brand px-3 py-2 text-sm font-medium text-on-brand shadow-sm transition hover:bg-brand-hover"
                                >
                                    {{ source.label }}
                                    <span v-if="source.adverts > 1"
                                        >&times;{{ source.adverts }}</span
                                    >
                                    &nearr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

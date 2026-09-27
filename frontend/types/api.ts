/**
 * LexRanked REST API DTOs (lexranked/v1). Mirrors docs/api.md.
 *
 * These are the only shapes the frontend consumes; WordPress internals never
 * leak past lib/wordpress.
 */

export type VerificationState = "unverified" | "pending" | "verified" | "failed" | "expired";
export type CommercialStatus = "free" | "claimed" | "verified" | "featured" | "sponsored" | "premium";

export interface StatusDto {
  status: "ok";
  service: string;
  pluginVersion: string;
  apiVersion: string;
  namespace: string;
}

export interface LocationDto {
  city: string | null;
  citySlug: string | null;
  state: string | null;
  stateSlug: string | null;
  stateCode: string | null;
}

export interface PracticeAreaRef {
  slug: string;
  name: string;
}

export interface EntityRef {
  id: number;
  slug: string;
  name: string;
  path: string;
}

export interface ScoreComponent {
  key: string;
  label: string;
  points: number;
  max: number;
  explanation: string;
  missing: string[];
}

/** Organic ranking data. Never influenced by commercial status. */
export interface RankingBlock {
  score: number | null;
  scoreVersion: string | null;
  calculatedAt: string | null;
  /** Per-component breakdown (detail responses only, when calculated). */
  breakdown?: ScoreComponent[];
}

export interface RankingPosition {
  id: number;
  title: string;
  path: string | null;
  position: number;
  score: number;
  calculatedAt: string;
  isDemo: boolean;
  /** Since API 1.12: the published entries directly above and below in the same run (compare candidates). */
  neighbors?: RankingNeighbor[];
}

export interface RankingNeighbor {
  id: number;
  entityId: number;
  name: string;
  position: number;
}

export interface CommercialBlock {
  /** Since API 1.7: free | claimed | premium (derived from claims and placements). */
  status: CommercialStatus;
  /** Legacy (API < 1.7). Paid placements are now delivered separately as PlacementDto. */
  isPaidPlacement: boolean;
  /** The profile owner has claimed it and an editor confirmed their identity (API 1.7). */
  claimed?: boolean;
  /** A premium profile is live (API 1.7). */
  premium?: boolean;
}

/** Labelled paid content on a premium profile (API 1.7). Never evidence, never scored. */
export interface PremiumContentDto {
  label: string;
  message: string | null;
  ctaUrl: string | null;
  disclosure: string;
}

/** A featured or sponsored placement (GET /placements, API 1.7). Separate from organic entries. */
export interface PlacementDto {
  id: number;
  product: "featured" | "sponsored";
  label: string;
  isPaidPlacement: true;
  disclosure: string;
  entity: LawyerSummary | LawFirmSummary;
}

export interface VerificationBlock {
  status: VerificationState;
  verifiedAt: string | null;
  checks: Record<string, VerificationState>;
}

export interface FreshnessDto {
  category: string;
  maxAgeDays: number;
  lastVerifiedAt: string | null;
  isStale: boolean;
  staleAt: string | null;
}

export interface EvidenceDto {
  field: string;
  value: unknown;
  source: {
    id: number | null;
    name: string | null;
    url: string | null;
    type: string;
    tier: number;
  };
  retrievedAt: string;
  confidence: number;
  verificationStatus: string;
  /** API 1.9: the value after normalisation (the raw value stays in `value`). */
  normalizedValue?: unknown;
  /** How the fact was obtained (API 1.4): manual, seed, structured_data or ai (quote-checked). */
  method?: "manual" | "seed" | "structured_data" | "ai";
}

interface EntityBase {
  id: number;
  /** Stable LexRanked entity ID (API 1.8): survives renames and slug changes; `id` is the CMS record ID. */
  entityId?: number | null;
  /** Since API 1.14: the page eligibility decision (Etap G). */
  eligibility?: EligibilityDto;
  slug: string;
  path: string;
  name: string;
  location: LocationDto | null;
  practiceAreas: PracticeAreaRef[];
  rating: number | null;
  reviewCount: number | null;
  ranking: RankingBlock;
  commercial: CommercialBlock;
  verification: VerificationBlock;
  /** Mock/sample record. Must be visibly labelled and never indexed. */
  isDemo: boolean;
  updatedAt: string | null;
}

export interface LawyerSummary extends EntityBase {
  type: "lawyer";
  firstName: string | null;
  lastName: string | null;
  title: string | null;
  firm: EntityRef | null;
}

export interface LawyerDetail extends LawyerSummary {
  /** Answer-first profile summary (API 1.5). */
  summary?: string | null;
  premiumContent?: PremiumContentDto | null;
  /** Normalised facts with source and freshness (API 1.9). */
  facts?: FactDto[];
  dataQuality?: DataQualityDto | null;
  contact: { website: string | null; phone: string | null };
  address: { zipCode: string | null; country: string | null };
  professional: {
    yearsExperience: number | null;
    barState: string | null;
    barNumber: string | null;
    barStatus: string | null;
    education: Array<{ institution: string | null; degree: string | null; year: string | null }>;
    awards: Array<{ name: string | null; issuer: string | null; year: string | null }>;
    languages: string[];
    /** Since API 1.13. */
    caseTypes?: string[];
    clientTypes?: string[];
  };
  /** Sanitized HTML from the CMS. */
  bio: string;
  freshness: FreshnessDto;
  sources: EvidenceDto[];
  rankings: RankingPosition[];
  createdAt: string | null;
}

export interface LawFirmSummary extends EntityBase {
  type: "law_firm";
  lawyerCount: number;
}

export interface LawFirmDetail extends LawFirmSummary {
  /** Answer-first profile summary (API 1.5). */
  summary?: string | null;
  premiumContent?: PremiumContentDto | null;
  facts?: FactDto[];
  dataQuality?: DataQualityDto | null;
  contact: { website: string | null; phone: string | null; email: string | null };
  address: { street: string | null; zipCode: string | null; country: string | null };
  lawyers: LawyerSummary[];
  description: string;
  freshness: FreshnessDto;
  sources: EvidenceDto[];
  rankings: RankingPosition[];
  createdAt: string | null;
}

export interface RankingEntry {
  position: number;
  score: number;
  scoreVersion: string | null;
  /** Places gained (+) or lost (−) since the previous calculation; null on first run or for new entries. */
  movement: number | null;
  isNew: boolean;
  breakdown: ScoreComponent[];
  /** Why this entry ranks here, from its score components (API 1.11). */
  why?: RankingWhy | null;
  /** What changed since the previous calculation, from snapshot diffs (API 1.11); null when nothing changed. */
  change?: RankingChange | null;
  /** Since API 1.13: the inputs this entry was scored on. */
  keyFacts?: KeyFacts;
  /** Since API 1.13: contextual rankings only — the fact that qualifies this entry. */
  qualification?: QualificationDto | null;
  entity: LawyerSummary | LawFirmSummary;
}

export interface WhyComponent {
  key: string;
  label: string;
  points: number;
  max: number;
  average: number;
}

export interface RankingWhy {
  summary: string;
  strengths: WhyComponent[];
  gaps: WhyComponent[];
  behind: { position: number; entityId: number; name: string | null; scoreGap: number; components: { key: string; label: string; delta: number }[] } | null;
  missing: string[];
}

export interface RankingChange {
  previousPosition: number | null;
  previousScore: number | null;
  scoreDelta: number | null;
  reasons: { type: "entered" | "methodology" | "component" | "input" | "competitor" | "left"; text: string }[];
}

export interface RankingSummary {
  id: number;
  slug: string;
  path: string | null;
  title: string;
  entityType: "lawyer" | "law_firm";
  location: LocationDto | null;
  practiceArea: PracticeAreaRef | null;
  scoreVersion: string | null;
  entryCount: number;
  minEntities: number;
  isThin: boolean;
  indexable: boolean;
  isDemo: boolean;
  updatedAt: string | null;
  /** When the engine last calculated this ranking. */
  calculatedAt: string | null;
  methodologyUrl: string;
  /** Since API 1.13: the "best for" context of a contextual ranking; null for an ordinary one. */
  context?: RankingContextDto | null;
  /** Since API 1.14: the full page eligibility decision (Etap G); decides isThin and indexable. */
  eligibility?: EligibilityDto | null;
}

/** Page eligibility (Etap G): does the page exist, may it be indexed, and why not. */
export interface EligibilityDto {
  exists: boolean;
  indexable: boolean;
  reasons: string[];
  version?: string;
  type?: string;
  checks?: Array<{ key: string; label: string; value: number; required: number; level: "exist" | "index"; passed: boolean }>;
}

export interface PageEligibilityModelDto {
  version: string;
  types: Record<string, Array<{ key: string; label: string; required: number; level: "exist" | "index"; description: string }>>;
}

export type RankingContextType = "case_type" | "client_type" | "language";

export interface RankingContextDto {
  type: RankingContextType;
  value: string;
  /** URL segment after the practice area: car-accidents, spanish-speaking, for-businesses. */
  segment: string;
  label: string;
  /** Fact attribute that proves the context: case_types | client_types | languages. */
  attribute: string;
  eligibility: {
    eligible: boolean;
    reasons: string[];
    qualified: number;
    verified: number;
    parentCount: number;
    minEntities: number;
    minVerified: number;
  };
  calculatedAt: string | null;
  /** The broader ranking this one narrows. */
  parent: { id: number; title: string; path: string | null } | null;
}

/** The stored fact that puts an entry into a contextual ranking. */
export interface QualificationDto {
  attribute: string;
  value: string;
  status: "verified" | "unverified";
  sourceId: number | null;
  claimId: number | null;
  observedAt: string | null;
  verifiedAt: string | null;
  source: { name: string | null; url: string | null; tierLabel: string | null } | null;
}

/** Scored inputs of an entry (the snapshot's own values). */
export interface KeyFacts {
  yearsExperience: number | null;
  barStatus: string | null;
  practiceAreas: string[];
  awards: number;
}

export interface FaqItem {
  question: string;
  answer: string;
}

export interface RankingDetail extends RankingSummary {
  /** Short editorial summary shown above the ranking (plain text). */
  summary: string | null;
  /** Editorial body HTML shown below the ranking (sanitized by the CMS). */
  body: string;
  /** @deprecated Alias of `body` (API 1.1). */
  intro: string;
  faq: FaqItem[];
  editorial: { reviewedBy: string | null; reviewedAt: string | null };
  entries: RankingEntry[];
}

export interface StateDto {
  id: number;
  entityId?: number | null;
  /** Since API 1.14: the page eligibility decision (Etap G). */
  eligibility?: EligibilityDto;
  slug: string;
  name: string;
  code: string | null;
  path: string;
  cityCount: number;
  lawyerCount: number;
  lawFirmCount: number;
  content?: TermContentDto | null;
}

export interface CityDto {
  id: number;
  entityId?: number | null;
  /** Since API 1.14: the page eligibility decision (Etap G). */
  eligibility?: EligibilityDto;
  slug: string;
  name: string;
  path: string;
  state: { slug: string | null; name: string | null; code: string | null };
  lawyerCount: number;
  lawFirmCount: number;
  content?: TermContentDto | null;
}

export interface PracticeAreaDto {
  id: number;
  entityId?: number | null;
  /** Since API 1.14: the page eligibility decision (Etap G). */
  eligibility?: EligibilityDto;
  slug: string;
  name: string;
  description: string;
  path: string;
  lawyerCount: number;
  lawFirmCount: number;
  content?: TermContentDto | null;
}

export interface SourceDto {
  id: number;
  name: string;
  url: string | null;
  /** API 1.9: sources are objects with a publisher, domain, status and check dates. */
  domain?: string | null;
  publisher?: string | null;
  type: string | null;
  tier: number | null;
  tierLabel?: string | null;
  status?: "active" | "unreachable" | "moved" | "retired";
  retrievedAt?: string | null;
  lastCheckedAt?: string | null;
  isDemo: boolean;
}

/** A normalised fact (API 1.9): one per attribute, resolved from the evidence. */
export interface FactDto {
  attribute: string;
  label: string;
  category: string;
  value: unknown;
  unit: string | null;
  status: "verified" | "unverified" | "conflict";
  confidence: number;
  source: { id: number | null; name: string | null; publisher: string | null; url: string | null; type: string | null; tier: number; tierLabel: string };
  claimCount: number;
  observedAt: string;
  verifiedAt: string | null;
  freshness: { category: string; maxAgeDays: number; lastVerifiedAt: string | null; isStale: boolean; staleAt: string | null };
  method: string | null;
}

/** GET /attributes: the data dictionary (API 1.9). */
export interface AttributeDto {
  key: string;
  label: string;
  valueType: string;
  entityTypes: string[];
  category: string;
  layer: "fact" | "derived";
  freshness: string;
  unit: string | null;
  description: string;
}

export interface ApiErrorBody {
  code: string;
  message: string;
  data?: { status?: number };
}

export interface ScoreVersionDto {
  id: string;
  /** Where the engine reads data (API 1.11): profile fields (v1.0) or evidence-backed facts (v1.1+). */
  input?: "profile" | "facts";
  weights: Array<{ key: string; label: string; weight: number }>;
  params: Record<string, number>;
}

export interface ScoreVersionsDto {
  active: string;
  versions: ScoreVersionDto[];
}

/** Editorial content of a state, city or practice-area page (API 1.5). */
export interface TermContentDto {
  summary: string | null;
  /** Sanitized HTML. */
  body: string;
  faq: FaqItem[];
  reviewedBy: string | null;
  reviewedAt: string | null;
}

export interface ArticleSummary {
  /** Since API 1.14: the page eligibility decision (Etap G). */
  eligibility?: EligibilityDto;
  id: number;
  slug: string;
  path: string;
  title: string;
  excerpt: string;
  author: { name: string };
  publishedAt: string | null;
  updatedAt: string | null;
  reviewedBy: string | null;
  reviewedAt: string | null;
  categories: PracticeAreaRef[];
  image: { url: string; width: number; height: number; alt: string } | null;
  wordCount: number;
  readingMinutes: number;
  /** Fewer than 300 words: published but never indexed. */
  isThin: boolean;
  relatedRankingId: number | null;
  isDemo: boolean;
}

export interface ArticleDetail extends ArticleSummary {
  /** Sanitized HTML. */
  body: string;
  relatedRanking: { id: number; title: string; path: string | null } | null;
}

export type EntityType = "lawyer" | "law_firm" | "location" | "practice_area";

/** GET /entities/{id} and /entities/resolve (API 1.8). */
export interface EntityDto {
  entityId: number;
  entityType: EntityType;
  canonicalName: string;
  slug: string;
  status: "active";
  path: string;
  createdAt: string;
  updatedAt: string;
}

/** Data Quality Score of a profile (API 1.10). Documentation quality, NOT a ranking. */
export interface DataQualityDto {
  score: number;
  version: string;
  dimensions: { key: string; label: string; weight: number; score: number; detail: string }[];
  missing: string[];
  unsourced: string[];
  stale: string[];
  conflicts: string[];
  calculatedAt: string;
}

/** GET /data-quality: the published model and a site-wide summary (API 1.10). */
export interface DataQualityModelDto {
  version: string;
  dimensions: { key: string; label: string; weight: number; description: string }[];
  expected: Record<string, Record<string, number>>;
  sourceTierScores: Record<string, number>;
  summary: { count: number; average: number | null; bands: Record<string, number> };
}

/* ---------- Comparison engine (Etap E, API 1.12) ---------- */

export type ComparableType = "lawyer" | "law_firm";

/** missing = not on record; directory = LexRanked's own directory link; derived = computed by LexRanked. */
export type ComparisonCellStatus = "verified" | "unverified" | "conflict" | "missing" | "directory" | "derived";

export interface ComparisonCell {
  /** Post ID of the entity (matches ComparisonEntity.id). */
  id: number;
  value: unknown;
  display: string | null;
  status: ComparisonCellStatus;
  source: { name: string | null; publisher: string | null; tierLabel: string | null; url: string | null } | null;
  checkedAt: string | null;
  isStale: boolean;
  note: string | null;
}

export interface ComparisonRow {
  key: string;
  label: string;
  group: string;
  kind: "number" | "text" | "list" | "objects";
  cells: ComparisonCell[];
  /** Post IDs with the strictly highest value; empty on ties, gaps, conflicts or non-numeric rows. */
  highest: number[];
  /** Items every entity shares (list rows only). */
  shared: string[] | null;
  note: string | null;
}

export interface ComparisonEntity {
  id: number;
  entityId: number | null;
  type: ComparableType;
  name: string;
  path: string;
  location: LocationDto | null;
  firm: { name: string; path: string } | null;
  verification: string;
  isDemo: boolean;
}

export interface ComparisonDto {
  /** Since API 1.14: comparisons exist but stay noindex until curated. */
  eligibility?: EligibilityDto;
  version: string;
  type: ComparableType;
  entities: ComparisonEntity[];
  rows: ComparisonRow[];
  sharedRankings: Array<{
    id: number;
    title: string;
    path: string;
    calculatedAt: string | null;
    positions: Array<{ id: number; position: number; score: number }>;
  }>;
  summary: string[];
  basis: string;
}

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
}

export interface CommercialBlock {
  status: CommercialStatus;
  /** Featured/sponsored placements must be labelled as paid. */
  isPaidPlacement: boolean;
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
}

interface EntityBase {
  id: number;
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
  entity: LawyerSummary | LawFirmSummary;
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
  slug: string;
  name: string;
  code: string | null;
  path: string;
  cityCount: number;
  lawyerCount: number;
  lawFirmCount: number;
}

export interface CityDto {
  id: number;
  slug: string;
  name: string;
  path: string;
  state: { slug: string | null; name: string | null; code: string | null };
  lawyerCount: number;
  lawFirmCount: number;
}

export interface PracticeAreaDto {
  id: number;
  slug: string;
  name: string;
  description: string;
  path: string;
  lawyerCount: number;
  lawFirmCount: number;
}

export interface SourceDto {
  id: number;
  name: string;
  url: string | null;
  type: string | null;
  tier: number | null;
  isDemo: boolean;
}

export interface ApiErrorBody {
  code: string;
  message: string;
  data?: { status?: number };
}

export interface ScoreVersionDto {
  id: string;
  weights: Array<{ key: string; label: string; weight: number }>;
  params: Record<string, number>;
}

export interface ScoreVersionsDto {
  active: string;
  versions: ScoreVersionDto[];
}

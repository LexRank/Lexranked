import type { ClientReviewsDto } from "@/types/api";
import { formatDate, isoDate } from "@/lib/format";
import { googleReviewsUrl } from "@/lib/reviews/validate";
import { StarRating } from "../ui";
import { ReviewForm } from "./ReviewForm";

/**
 * Client reviews on a profile: approved LexRanked reviews, a link to the
 * profile's Google reviews (a plain search link: nothing is fetched or
 * stored) and the review form. Reviews are not part of the LexRank score in
 * v1.2; the methodology page says so.
 */
export function ClientReviews({
  reviews,
  entityType,
  entityId,
  name,
  city,
  state,
}: {
  reviews: ClientReviewsDto | undefined;
  entityType: "lawyer" | "law_firm";
  entityId: number;
  name: string;
  city?: string | null;
  state?: string | null;
}) {
  const count = reviews?.count ?? 0;
  return (
    <section id="reviews" className="card stack" aria-labelledby="reviews-heading">
      <div style={{ display: "flex", justifyContent: "space-between", gap: "1rem", flexWrap: "wrap", alignItems: "baseline" }}>
        <h2 id="reviews-heading" style={{ fontSize: "1.4rem", margin: 0 }}>
          Client reviews
        </h2>
        {count > 0 && reviews?.average != null && <StarRating rating={reviews.average} count={count} />}
      </div>
      {count === 0 ? (
        <p className="muted" style={{ margin: 0 }}>
          No client reviews on LexRanked yet. If {name.replace(/\s*\(Demo\)\s*$/, "")} represented you, your review helps others choose.
        </p>
      ) : (
        <ul className="review-list">
          {reviews?.items.map((r) => (
            <li key={r.id} className="review">
              <div className="review__head">
                <StarRating rating={r.rating} count={null} />
                {r.title && <strong>{r.title}</strong>}
              </div>
              <p className="review__body">{r.body}</p>
              <p className="review__meta muted">
                {r.author}
                {r.serviceYear ? ` · client in ${r.serviceYear}` : ""}
                {r.publishedAt && (
                  <>
                    {" · "}
                    <time dateTime={isoDate(r.publishedAt)}>{formatDate(r.publishedAt)}</time>
                  </>
                )}
                {" · email confirmed, approved by an editor"}
              </p>
            </li>
          ))}
        </ul>
      )}
      <p style={{ margin: 0, fontSize: "0.92rem" }}>
        <a href={googleReviewsUrl(name, city, state)} rel="nofollow noopener noreferrer" target="_blank">
          See reviews on Google Maps
        </a>
        <span className="muted"> (opens Google; LexRanked does not copy or score Google ratings)</span>
      </p>
      <details className="review-form-wrap">
        <summary className="btn btn--navy">Write a review</summary>
        <div style={{ marginTop: "1rem" }}>
          <ReviewForm entityType={entityType} entityId={entityId} entityName={name.replace(/\s*\(Demo\)\s*$/, "")} />
        </div>
      </details>
    </section>
  );
}

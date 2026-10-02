import { describe, expect, it, vi } from "vitest";
import type { ServerEnv } from "@/lib/config/server-env";
import {
  apiRequest,
  authHeader,
  buildUrl,
  isRetryable,
  WordPressApiError,
  WordPressNotConfiguredError,
  type ClientDeps,
} from "@/lib/wordpress/client";

const env: ServerEnv = {
  wordpressApiUrl: "https://wp.example.test/wp-json",
  wordpressUsername: null,
  wordpressAppPassword: null,
  allowIndexing: false,
};

function json(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...headers },
  });
}

function deps(fetchImpl: ClientDeps["fetch"], overrides: Partial<ServerEnv> = {}): ClientDeps & { sleep: ReturnType<typeof vi.fn> } {
  return { fetch: fetchImpl, env: { ...env, ...overrides }, sleep: vi.fn(async () => undefined) };
}

describe("buildUrl", () => {
  it("joins base, namespace and path and drops empty query values", () => {
    expect(buildUrl("https://wp.example.test/wp-json/", "/lawyers", { page: 2, state: "", city: undefined, has_score: true })).toBe(
      "https://wp.example.test/wp-json/lexranked/v1/lawyers?page=2&has_score=true",
    );
  });

  it("encodes query values", () => {
    expect(buildUrl(env.wordpressApiUrl!, "search", { q: "o'brien & co" })).toContain("q=o%27brien+%26+co");
  });
});

describe("authHeader", () => {
  it("is null unless both username and password are set", () => {
    expect(authHeader(env)).toBeNull();
    expect(authHeader({ ...env, wordpressUsername: "api" })).toBeNull();
  });

  it("builds a Basic header", () => {
    const header = authHeader({ ...env, wordpressUsername: "api", wordpressAppPassword: "abcd efgh" });
    expect(header).toBe(`Basic ${Buffer.from("api:abcd efgh").toString("base64")}`);
  });
});

describe("isRetryable", () => {
  it("retries network errors, 429 and 5xx only", () => {
    expect(isRetryable(null)).toBe(true);
    expect(isRetryable(429)).toBe(true);
    expect(isRetryable(503)).toBe(true);
    expect(isRetryable(400)).toBe(false);
    expect(isRetryable(404)).toBe(false);
  });
});

describe("apiRequest", () => {
  it("throws a typed error when WordPress is not configured", async () => {
    const d = deps(vi.fn());
    await expect(apiRequest("status", {}, { ...d, env: { ...env, wordpressApiUrl: null } })).rejects.toBeInstanceOf(
      WordPressNotConfiguredError,
    );
  });

  it("returns data and pagination headers", async () => {
    const fetchMock = vi.fn(async () => json([{ id: 1 }], 200, { "X-WP-Total": "8", "X-WP-TotalPages": "3" }));
    const result = await apiRequest<Array<{ id: number }>>("lawyers", { query: { per_page: 3 } }, deps(fetchMock));
    expect(result).toEqual({ data: [{ id: 1 }], total: 8, totalPages: 3 });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit & { next?: unknown }];
    expect(url).toBe("https://wp.example.test/wp-json/lexranked/v1/lawyers?per_page=3");
    expect(init.next).toEqual({ revalidate: 300, tags: ["lexranked"] });
    expect((init.headers as Record<string, string>).Authorization).toBeUndefined();
  });

  it("sends credentials when configured", async () => {
    const fetchMock = vi.fn(async () => json({}));
    await apiRequest("status", {}, deps(fetchMock, { wordpressUsername: "api", wordpressAppPassword: "pw" }));
    const init = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1];
    expect((init.headers as Record<string, string>).Authorization).toMatch(/^Basic /);
  });

  it("uses no-store when revalidate is 0", async () => {
    const fetchMock = vi.fn(async () => json({}));
    await apiRequest("status", { revalidate: 0 }, deps(fetchMock));
    const init = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1];
    expect(init.cache).toBe("no-store");
  });

  it("retries 5xx with exponential backoff, then succeeds", async () => {
    const fetchMock = vi
      .fn<ClientDeps["fetch"]>()
      .mockResolvedValueOnce(json({ code: "x", message: "down" }, 503))
      .mockResolvedValueOnce(json({ code: "x", message: "down" }, 502))
      .mockResolvedValueOnce(json({ ok: true }));
    const d = deps(fetchMock);
    const result = await apiRequest("status", {}, d);
    expect(result.data).toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(d.sleep.mock.calls.map((c) => c[0])).toEqual([250, 500]);
  });

  it("retries network errors and gives up after the retry budget", async () => {
    const fetchMock = vi.fn(async () => {
      throw new TypeError("fetch failed");
    });
    const error = await apiRequest("status", { retries: 1 }, deps(fetchMock)).catch((e) => e);
    expect(error).toBeInstanceOf(WordPressApiError);
    expect(error.code).toBe("network_error");
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("does not retry 4xx and surfaces the API error code", async () => {
    const fetchMock = vi.fn(async () => json({ code: "lexranked_not_found", message: "Lawyer not found." }, 404));
    const error = await apiRequest("lawyers/x", {}, deps(fetchMock)).catch((e) => e);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(error).toMatchObject({ status: 404, code: "lexranked_not_found", message: "Lawyer not found." });
  });

  it("handles non-JSON error bodies", async () => {
    const fetchMock = vi.fn(async () => new Response("<html>fatal</html>", { status: 400 }));
    const error = await apiRequest("status", {}, deps(fetchMock)).catch((e) => e);
    expect(error).toMatchObject({ status: 400, code: "http_400" });
  });
});

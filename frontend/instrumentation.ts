import type { Instrumentation } from "next";

/**
 * Structured, secret-free error log for every server-side request error
 * (collected by the hosting platform's log drain / monitoring).
 */
export const onRequestError: Instrumentation.onRequestError = async (error, request, context) => {
  const err = error as Error & { digest?: string };
  console.error(
    JSON.stringify({
      level: "error",
      source: "nextjs",
      message: err.message?.slice(0, 500),
      digest: err.digest,
      method: request.method,
      path: request.path.split("?")[0],
      routePath: context.routePath,
      routeType: context.routeType,
      time: new Date().toISOString(),
    }),
  );
};

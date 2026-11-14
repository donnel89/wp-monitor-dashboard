// מקור: drushim.co.il
// עמוד תוצאות החיפוש הציבורי מגיע עם __NEXT_DATA__ שמכיל את המודעות כולל התיאור המלא.
// אנחנו קוראים רק את העמוד הראשון של כל מילת חיפוש (כ-10 מודעות), בקצב איטי.

const BASE = "https://www.drushim.co.il";
const UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36";

function findJobs(node) {
  // מחפש את מערך jobs בתוך dehydratedState בלי להיות תלוי במיקום מדויק
  const queries = node?.props?.pageProps?.dehydratedState?.queries ?? [];
  for (const q of queries) {
    if (q?.queryKey?.[0] !== "search-results") continue;
    return (q.state?.data?.pages ?? []).flatMap((p) => p.jobs ?? []);
  }
  return [];
}

export function parseDrushimHtml(html) {
  const m = html.match(/<script id="__NEXT_DATA__"[^>]*>([\s\S]*?)<\/script>/);
  if (!m) throw new Error("drushim: לא נמצא __NEXT_DATA__ בעמוד (ייתכן שמבנה האתר השתנה)");
  return findJobs(JSON.parse(m[1])).map(normalize);
}

function normalize(j) {
  const company = (j.companyName ?? "").trim();
  return {
    id: `drushim:${j.id}`,
    source: "drushim",
    title: j.title ?? "",
    company,
    companyHidden: !company || /חסוי/.test(company),
    city: j.city ?? "",
    description: j.description ?? "",
    experience: j.experience ?? "",
    scope: j.scope ?? "",
    publishedAt: j.publishedAtIso ?? null,
    url: j.jobUrl ? BASE + j.jobUrl : BASE,
  };
}

export async function searchDrushim(keyword) {
  const url = `${BASE}/jobs/search/${encodeURIComponent(keyword)}/`;
  const res = await fetch(url, {
    headers: { "User-Agent": UA, "Accept-Language": "he-IL,he;q=0.9" },
  });
  if (!res.ok) throw new Error(`drushim: HTTP ${res.status} עבור "${keyword}"`);
  return parseDrushimHtml(await res.text());
}

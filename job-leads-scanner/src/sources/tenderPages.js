// מקור: עמודי מכרזים של גופים ספציפיים (עיריות, חברות ממשלתיות, אוניברסיטאות, תאגידים).
// כל אתר בנוי אחרת, ולכן לא מפענחים מבנה ספציפי: אוספים את כל הקישורים בעמוד,
// ומשאירים את אלה שהטקסט שלהם נראה כמו מכרז בתחום שלנו. הסיווג הסופי נעשה ב-classifyTender.

const UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36";

const TENDER_WORD_RE = /מכרז|RFI|RFP|בקשה לקבלת (מידע|הצעות)|קול קורא|הזמנה להציע|הליך|פנייה לקבלת/i;

const clean = (s) =>
  String(s)
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&quot;/g, '"')
    .replace(/&amp;/g, "&")
    .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d)))
    .replace(/\s+/g, " ")
    .trim();

// מחלץ תאריך אחרון אם מופיע ליד הקישור ("מועד אחרון להגשה: 01/12/2026")
function nearbyDeadline(html, index) {
  const around = clean(html.slice(index, index + 1500));
  const m = around.match(/(?:מועד (?:אחרון|הגשה)|אחרון להגשה|הגשה עד)[^\d]{0,20}(\d{1,2})[./](\d{1,2})[./](\d{2,4})/);
  if (!m) return null;
  const y = m[3].length === 2 ? `20${m[3]}` : m[3];
  return `${y}-${m[2].padStart(2, "0")}-${m[1].padStart(2, "0")}T12:00:00+02:00`;
}

export function parseTenderPage(html, page) {
  const out = new Map();
  for (const m of html.matchAll(/<a\b[^>]*href="([^"#]+)"[^>]*>([\s\S]*?)<\/a>/gi)) {
    const text = clean(m[2]);
    if (text.length < 12 || text.length > 300) continue;
    let url;
    try {
      url = new URL(m[1].replace(/&amp;/g, "&"), page.url).href;
    } catch {
      continue;
    }
    // קישור לקובץ שנקרא "חוברת המכרז" וכדומה: לוקחים רק כשהטקסט מתאר מכרז
    if (!TENDER_WORD_RE.test(text) && !/tender|bid|michraz/i.test(url)) continue;
    if (out.has(url)) continue;
    out.set(url, {
      id: `page:${url}`,
      number: "",
      tenderType: /RFI|בקשה לקבלת מידע/i.test(text) ? "RFI" : /קול קורא/.test(text) ? "קול קורא" : "מכרז",
      title: text,
      publisher: page.name,
      procedureNo: (text.match(/(?:מס['׳]?\s*)?(\d{1,4}\/\d{2,4})/) ?? [])[1] ?? "",
      status: "",
      publishedAt: null,
      deadline: nearbyDeadline(html, m.index),
      url,
      contactName: "",
      contactEmail: "",
      topics: "",
    });
  }
  return [...out.values()];
}

export async function scanTenderPage(page) {
  const res = await fetch(page.url, { headers: { "User-Agent": UA, "Accept-Language": "he-IL,he;q=0.9" } });
  if (!res.ok) throw new Error(`${page.name}: HTTP ${res.status}`);
  return parseTenderPage(await res.text(), page);
}

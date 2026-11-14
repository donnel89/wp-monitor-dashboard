// מקור: mr.gov.il – פורטל מינהל הרכש הממשלתי (מכרזים, RFI, בקשות לקבלת מידע, כוונות התקשרות).
// עמוד החיפוש ועמוד המכרז מוחזרים כ-HTML מוכן מהשרת. אנחנו קוראים את עמוד התוצאות הראשון
// לכל מילת חיפוש (ממוין לפי עדכון אחרון), ונכנסים לעמוד של כל מכרז רלוונטי כדי לקחת איש קשר ומייל.

const BASE = "https://mr.gov.il";
const UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36";

const decode = (s) =>
  String(s ?? "")
    .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16)))
    .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d)))
    .replace(/&nbsp;?/g, " ")
    .replace(/&quot;/g, '"')
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();

const pick = (re, s) => {
  const m = s.match(re);
  return m ? decode(m[1]) : "";
};

// "01/12/2026 12:00" או "01/12/2026 ,12:00" → ISO בשעון ישראל
export function parseIlDate(s) {
  const m = String(s ?? "").match(/(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\D+(\d{1,2}):(\d{2}))?/);
  if (!m) return null;
  const [, d, mo, y, h = "23", mi = "59"] = m;
  return `${y}-${mo.padStart(2, "0")}-${d.padStart(2, "0")}T${h.padStart(2, "0")}:${mi}:00+02:00`;
}

export function parseSearchHtml(html) {
  return html
    .split('<div class="result-container">')
    .slice(1)
    .map((blk) => {
      const path = pick(/href="(\/ilgstorefront\/he\/p\/\d+)"/, blk);
      const num = path.split("/").pop();
      return {
        id: `mrgov:${num}`,
        number: num,
        tenderType: pick(/search-result-sub-head">([^<]*)/, blk),
        title: pick(/search-results-content-head">([\s\S]*?)<\/h2>/, blk),
        publisher: pick(/שם המפרסם:&nbsp;<\/span><span[^>]*>([^<]*)/, blk),
        procedureNo: pick(/מס׳ הליך:&nbsp;<\/span><span[^>]*>([^<]*)/, blk),
        status: pick(/class="status[^"]*">([^<]*)/, blk),
        publishedAt: parseIlDate(pick(/תאריך פרסום:&nbsp;<\/span><span[^>]*>([^<]*)/, blk)),
        deadline: parseIlDate(pick(/מועד אחרון להגשה:&nbsp;<\/span><span[^>]*>([^<]*)/, blk)),
        url: path ? BASE + path : BASE,
      };
    })
    .filter((t) => t.number);
}

export function parseDetailHtml(html) {
  const subjects = [...html.matchAll(/<div id="subjects"[\s\S]*?<\/div>\s*<\/div>/g)]
    .map((m) => decode(m[0]))
    .join(" ");
  return {
    contactName: pick(/bid-contact-name">\s*איש קשר:\s*([^<]*)/, html),
    contactEmail: pick(/bid-contact-mail">[\s\S]*?mailto:([^"]+)"/, html),
    status: pick(/id="status-output"[^>]*>([^<]*)/, html),
    submitStart: parseIlDate(pick(/id="firstSubmittingDate">([^<]*)/, html)),
    deadline: parseIlDate(pick(/id="lastDate">([^<]*)/, html)),
    submitUrl: pick(/window\.open\('(https:\/\/quickbid[^']+)'/, html),
    topics: subjects,
  };
}

async function get(url) {
  const res = await fetch(url, { headers: { "User-Agent": UA, "Accept-Language": "he-IL,he;q=0.9" } });
  if (!res.ok) throw new Error(`mr.gov.il: HTTP ${res.status}`);
  return res.text();
}

export async function searchTenders(query) {
  // ":updateDate:archive:false" = מיון לפי עדכון אחרון והסתרת ארכיון (כמו הפילטר באתר)
  const url = `${BASE}/ilgstorefront/he/search/?q=${encodeURIComponent(`${query}:updateDate:archive:false`)}`;
  return parseSearchHtml(await get(url));
}

export async function fetchTenderDetails(tender) {
  return parseDetailHtml(await get(tender.url));
}

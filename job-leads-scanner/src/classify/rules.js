// סיווג לפי חוקים: עובד בלי API, מהיר וצפוי.
// מחזיר שלושה צירים נפרדים:
//   posterType   – מי פרסם: direct (החברה עצמה) / recruiter (חברת השמה) / hidden (חסוי)
//   employerType – מי המעסיק בפועל: business (עסק עם שיווק פנימי) / agency (סוכנות/משרד פרסום) / unknown
//   relevant     – האם זה בכלל תפקיד שיווק דיגיטלי שאנחנו יכולים להחליף

const has = (re, s) => re.test(s);
const any = (list, s) => list.filter(([re]) => re.test(s));

// ---------- רלוונטיות ----------
const ROLE_RE =
  /קמפיינ|PPC|ממומנ|גוגל|google ads|meta ads|פייסבוק|פרפורמנס|performance market|performance-oriented|growth|צמיחה|שיווק דיגיטל|דיגיטל|SEO|קידום אתרים|סושיאל|רשתות חברתיות|מרקום|marcom|e-?commerce|איקומרס|אתר סחר|מנהל.?ת? שיווק|איש.?ת? שיווק|אשת שיווק|marketing|מדיה|פרסום/i;
const CAMPAIGN_ROLE_RE = /קמפיינ|PPC|ממומנ|google ads|meta ads|פרפורמנס|performance|SEM\b|מדיה ממומנת/i;
// לתיאור בלבד: בלי "performance" כי באנגלית זה מופיע גם בהערכת ביצועים של עובדים
const CAMPAIGN_DESC_RE = /קמפיינ|PPC|ממומנ|google ads|meta ads|פרפורמנס|performance marketing|מדיה ממומנת/i;
const NOT_OUR_ROLE_RE =
  /נציג.?י? מכירות|מזכיר|engineer|developer|מהנדס|מפתח.?ת? (תוכנה|full)|finance|HR business|רכז.?ת? לתפעול|טכני(\s|$|-)|BDR|account executive|customer success|רפרנט|רכז.?ת? גיוס|שירות לקוחות|Business Development|פיתוח עסקי|מיישם/i;

// ---------- סוכנות ----------
const AGENCY_SIGNALS = [
  [/(?<!מול )(?<!מול ה)(?<!עם )משרד (ה)?פרסום(?! ו?יחסי)/, "משרד פרסום"],
  [/סוכנות (פרסום|דיגיטל|שיווק)|בסוכנות|לסוכנות|\bagency\b/i, "סוכנות"],
  [/(משרד|חברת|לחברת|בחברת) (שיווק )?דיגיטל(י)?(?! של)/, "חברת דיגיטל"],
  [/משרד שיווק דיגיטלי|חברת קידום|חברה העוסקת בשיווק דיגיטלי/, "משרד שיווק/קידום"],
  [/תיקי לקוחות|account manager/i, "ניהול תיקי לקוחות"],
  [/לקוחות (ו?פרויקטים )?מ(תחומים|גוון)|מגוון לקוחות|הלקוחות הגדולים בארץ|עבור לקוחות(?!נו)/, "עבודה מול לקוחות רבים"],
  [/מלווה עסקים|affiliates|אפיליאייט/i, "שירות לעסקים"],
  [/הזרוע הדיגיטלית של|אדלר חומסקי|באומן בר ריבנאי|מקאן|גיתם|BBDO|פובליסיס|זרוע דיגיטלית/i, "משרד פרסום מוכר"],
];
// ביטויים שנשמעים כמו סוכנות אבל בעצם מעידים שזה צד המפרסם
const IN_HOUSE_OVERRIDES = [
  [/(מול|עם) (ה?משרד|חברות|משרדי|סוכנויות|הסוכנות|ה?סוכנות) ה?(פרסום|דיגיטל|המדיה)|מול הסוכנות/, "עובד מול סוכנות חיצונית (צד המפרסם)"],
  [/בצד המפרסם|in-?house/i, "צד המפרסם / in-house"],
  [/לצד הלקוח|ממשרד (ה)?פרסום|ניהול ספקים|ספקים (ו)?שותפים חיצוניים|ספקים חיצוניים|לרבות משרד/, "מנהל ספקים/סוכנות מצד הלקוח"],
  [/מחלקת (ה)?שיווק|אגף (ה)?שיווק|צוות (ה)?שיווק|למחלקת/, "מחלקת שיווק פנימית"],
];

// ---------- חברת השמה ----------
const RECRUITER_NAME_RE = /השמה|גיוס|\bHR\b|-HR|הנטר|hunter|JOB ?SPACE|manpower|מנפאואר|אדם מילוא|נישה|יוניק|ness\b|G ?STAT/i;
const RECRUITER_TEXT = [
  [/(לאחד|עבור אחד|לאחת|עבור אחת) מ(לקוחותינו|הלקוחות שלנו)|ללקוח (שלנו|מוביל)|עבור לקוח/, "פרסום בשם לקוח"],
  [/^(דרוש.{0,3} )?(לחברה|לחברת|לרשת|לקבוצת|לבית תוכנה|לסטארט-?אפ) (מובילה|גלובלית|ותיקה|מצליחה|בינלאומית|גדולה|[א-ת"׳']+ (מובילה|גדולה|ותיקה))/, "תיאור עקיף של המעסיק"],
  [/של חברה מובילה|חברה מובילה (ו)?וותיקה|חברה ותיקה וצומחת/, "תיאור עקיף של המעסיק"],
];

// ---------- תעשייה ----------
const INDUSTRIES = [
  [/יבואנ\S* ה?רכב|חברת רכב|לחברת רכב|מוטורס|ליסינג|סוכנות רכב|מותגי רכב|יבוא רכב/g, "רכב"],
  [/אופנה|סניקרס|נייקי|תכשיט|טיפוח|קוסמטיק|beauty|fashion/gi, "אופנה/טיפוח"],
  [/נדל"?ן|בני(י)?ה למגורים|יזמות|פרויקטים למגורים/g, "נדל\"ן"],
  [/תעשיית המזון|מזון|משקאות|מסעד/g, "מזון"],
  [/פינטק|פיננס|ביטוח|אשראי|כרטיסי|בנק/g, "פיננסים/ביטוח"],
  [/רפוא|בית חולים|קליניק|בריאות|מרכז רפואי/g, "בריאות"],
  [/אוניברסיט|מכללה|חינוך|אקדמ/g, "חינוך"],
  [/e-?commerce|איקומרס|אתר סחר|קמעונא|רשת(?!ות)\s+(חנויות|תכשיטי|אופנה|סניפים)/gi, "קמעונאות/איקומרס"],
  [/בית תוכנה|חברת תוכנה|SaaS|הייטק|סטארט-?אפ|startup|טכנולוגיות/gi, "טכנולוגיה"],
  [/תיירות|מלון|נופש/g, "תיירות"],
  [/תקשורת סלולר|ספקית אינטרנט|סלולר/g, "תקשורת"],
];
// בוחר את התעשייה עם הכי הרבה התאמות; כותרת ושם חברה נספרים פעמיים
function detectIndustry(ad) {
  const strong = `${ad.company} ${ad.title}`;
  let best = null;
  let bestScore = 0;
  for (const [re, name] of INDUSTRIES) {
    const score = (strong.match(re)?.length ?? 0) * 2 + (ad.description.match(re)?.length ?? 0);
    if (score > bestScore) (best = name), (bestScore = score);
  }
  return best;
}

// ---------- מה הם צריכים מעבר לקמפיינים (בסיס לפיץ' של "מעטפת") ----------
const NEEDS = [
  [/דפי נחיתה|דף הנחיתה|landing/i, "דפי נחיתה"],
  [/עיצוב|גרפי|קריאייטיב|creative|באנר/i, "עיצוב/קריאייטיב"],
  [/וורדפרס|wordpress|אלמנטור|elementor|ניהול אתר|בניית אתר|אתר ה/i, "אתר"],
  [/תוכן|קופי|copy|כתיבה שיווקית/i, "תוכן וקופי"],
  [/סושיאל|רשתות חברתיות|טיקטוק|אינסטגרם|משפיענ/i, "סושיאל"],
  [/אוטומצי|דיוור|CRM|מייל/i, "אוטומציות ודיוור"],
  [/SEO|קידום אורגני/i, "SEO"],
  [/אנליטיק|analytics|דאטה|GA4|דוחות/i, "אנליטיקס"],
  [/וידאו|צילום|עריכ/, "וידאו"],
];

// ---------- גודל/בשלות (מי פחות סביר שיעבור למיקור חוץ) ----------
const BIG_ORG_RE =
  /אוניברסיט|בית חולים|עירי|משרד ה(?!פרסום)|ממשלת|גלובלית|global|בינלאומית|ציבורית|Head of|Director|VP\b|סמנכ"ל|ישראכרט|בזק|יוניליוור|maersk/i;
const SMB_RE =
  /חצי משרה|משרה חלקית|האדם הראשון|הראשון ש|סטארט-?אפ קטן|עסק (קטן|משפחתי)|צוות קטן|יד ימינו של המנכ"ל|עבודה צמודה עם המנכ"ל|בעל(ת)? העסק/;

function extractEndClientHint(text) {
  const m = text.match(
    /(?:^|\s)(ל(?:חברת|חברה|רשת|קבוצת|בית תוכנה|סטארט-?אפ|מותג)(?:[ \t]+[^\s,.!:;()•-]+){1,4})/
  );
  if (!m) return null;
  const hint = m[1].split(/\s+דרוש|\n/)[0].replace(/[•\s]+$/, "").trim();
  const GENERIC = /^(מובילה?|גלובלית|ותיקה|גדולה|מצליחה|בינלאומית|צומחת|ו?וותיקה|במרכז|הארץ|בתחומה|ו)$/;
  const words = hint.split(/\s+/).slice(1);
  return words.length && words.every((w) => GENERIC.test(w)) ? null : hint;
}

export function classifyByRules(ad) {
  const title = ad.title ?? "";
  const desc = ad.description ?? "";
  const text = `${title}\n${desc}`;
  const reasons = [];

  // רלוונטיות
  const roleHit = has(ROLE_RE, title) || (has(ROLE_RE, desc) && has(CAMPAIGN_DESC_RE, desc));
  const notOurRole = has(NOT_OUR_ROLE_RE, title);
  const relevant = roleHit && !notOurRole;
  if (!relevant) reasons.push(notOurRole ? "התפקיד אינו תפקיד שיווק דיגיטלי שאנחנו מחליפים" : "לא נמצאו מילות מפתח של שיווק דיגיטלי");

  // מפרסם
  let posterType = "direct";
  const recruiterText = any(RECRUITER_TEXT, text);
  if (ad.companyHidden) {
    posterType = "hidden";
    reasons.push("שם המפרסם חסוי");
  } else if (has(RECRUITER_NAME_RE, ad.company)) {
    posterType = "recruiter";
    reasons.push(`שם המפרסם נראה כמו חברת השמה (${ad.company})`);
  } else if (recruiterText.length) {
    // אם שם החברה המפרסמת מופיע בטקסט, זו כנראה החברה עצמה
    const firstWord = ad.company.split(/\s+/)[0];
    if (!firstWord || !text.includes(firstWord)) {
      posterType = "recruiter";
      reasons.push(...recruiterText.map(([, r]) => r));
    }
  }

  // מעסיק
  const overrides = any(IN_HOUSE_OVERRIDES, text);
  const agencyHits = any(AGENCY_SIGNALS, text);
  let employerType = "unknown";
  if (agencyHits.length && !(overrides.length && agencyHits.length < 2)) {
    employerType = "agency";
    reasons.push(...agencyHits.map(([, r]) => `סימן לסוכנות: ${r}`));
  } else {
    if (overrides.length || detectIndustry(ad) || posterType === "direct") {
      employerType = "business";
      reasons.push(...overrides.map(([, r]) => r));
    }
  }

  const industry = detectIndustry(ad);
  const needs = [...new Set(any(NEEDS, text).map(([, n]) => n))];
  const endClientHint = posterType !== "direct" ? extractEndClientHint(text) : null;

  // ציון ליד
  let score = 0;
  if (relevant && employerType !== "agency") {
    score = 40;
    if (has(CAMPAIGN_ROLE_RE, title)) (score += 20), reasons.push("תפקיד ביצועי של ניהול קמפיינים");
    if (employerType === "business") score += 10;
    score += Math.min(needs.length * 5, 15);
    if (has(SMB_RE, text)) (score += 15), reasons.push("סימנים לעסק קטן/בינוני");
    if (has(BIG_ORG_RE, `${ad.company} ${text}`)) (score -= 25), reasons.push("ארגון גדול/בכיר, פחות סביר שיעבור למיקור חוץ");
    if (posterType === "hidden" && !endClientHint) score -= 10;
    const ageDays = ad.publishedAt ? (Date.now() - Date.parse(ad.publishedAt)) / 864e5 : 30;
    if (ageDays <= 3) score += 10;
    else if (ageDays > 21) score -= 10;
    score = Math.max(0, Math.min(100, score));
  }

  return {
    method: "rules",
    relevant,
    posterType,
    employerType,
    category: categoryOf({ relevant, posterType, employerType }),
    industry,
    needs,
    endClientHint,
    score,
    reasons: [...new Set(reasons)],
  };
}

export function categoryOf({ relevant, posterType, employerType }) {
  if (!relevant) return "irrelevant";
  if (employerType === "agency") return "agency";
  if (posterType === "direct") return "direct";
  return "via-recruiter"; // השמה או חסוי, כשהמעסיק עסק
}

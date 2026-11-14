// טיוטת מייל פנייה ראשונה.
// לפי סעיף 30א לחוק התקשורת מותר לפנות לבית עסק פעם אחת בבקשה לקבל הסכמה לדיוור.
// לכן הנוסח הוא פנייה אישית אחת שמבקשת רשות להציג הצעה, וכולל אפשרות להסיר.
import { config } from "./config.js";

function roleFromTitle(title) {
  // מנקה תוספות שיווקיות מהכותרת: "- שכר גבוה!!", סימני קריאה וכו'
  return title
    .replace(/\s*[-–|]\s*(שכר|תנאים|משרה|היברידי|ללא).*$/u, "")
    .replace(/^(דרוש\S*\s+)/u, "")
    .replace(/\s*(ל(חברה|חברת|רשת|קבוצת)\s.*)$/u, "")
    .replace(/[!]+/g, "")
    .trim();
}

const NEED_LINES = {
  "דפי נחיתה": "דפי נחיתה שבנויים להמרה ונבדקים ב-A/B",
  "עיצוב/קריאייטיב": "מעצב/ת קריאייטיב לבאנרים, מודעות וסרטונים קצרים",
  "אתר": "תחזוקה ושיפור של האתר (WordPress / Elementor)",
  "תוכן וקופי": "קופירייטינג למודעות, לדפים ולמיילים",
  "סושיאל": "ניהול תוכן לרשתות החברתיות",
  "אוטומציות ודיוור": "אוטומציות, דיוור וחיבור ל-CRM",
  SEO: "קידום אורגני",
  "אנליטיקס": "מדידה, GA4 ודוחות שקופים",
  "וידאו": "עריכת וידאו למודעות",
};

export function draftEmail(ad, c) {
  const s = config.sender;
  const role = roleFromTitle(ad.title) || "קמפיינר/ית";
  const extras = (c.needs ?? []).map((n) => NEED_LINES[n]).filter(Boolean).slice(0, 3);
  // "ב-" לפני שם לועזי, "ב" צמוד לפני שם בעברית
  const greetingName = ad.companyHidden ? "" : /^[\u0590-\u05FF]/.test(ad.company) ? ` ב${ad.company}` : ` ב-${ad.company}`;

  const subject = `ראיתי שאתם מגייסים ${role}: הצעה שכדאי לשקול לפני שסוגרים`;

  const lines = [
    "שלום,",
    "",
    `ראיתי שאתם${greetingName} מחפשים ${role}.`,
    c.pitchAngle ? c.pitchAngle : null,
    "",
    `אני ${s.senderName} מ-${s.agencyName}. אנחנו מספקים לעסקים את כל מה שהתפקיד הזה אמור לתת, וגם את מה שמסביבו, כשירות חודשי במקום גיוס:`,
    "• קמפיינר/ית מנוסה בגוגל ובמטא שמנהל/ת את התקציב ומחויב/ת ליעדים",
    ...(extras.length
      ? extras.map((e) => `• ${e}`)
      : ["• מעצב/ת ומפתח/ת דפי נחיתה, כך שהקמפיין לא נתקע בגלל חוסר בקריאייטיב או בדף"]),
    "",
    "למה זה עשוי להשתלם לכם יותר מגיוס:",
    "• מתחילים תוך שבוע, בלי חודשים של גיוס, חפיפה ועקומת למידה",
    "• צוות שלם בעלות שבדרך כלל דומה לעלות מעסיק של עובד אחד",
    "• אין תלות באדם אחד: חופשה, מחלה או עזיבה לא עוצרים את הקמפיינים",
    "• בלי התחייבות ארוכה. אם זה לא עובד, עוצרים",
    "",
    "תרצו שאשלח הצעה מסודרת ומותאמת אליכם? אפשר גם שיחה של 15 דקות.",
    "",
    "בברכה,",
    s.senderName,
    s.agencyName,
    s.phone || null,
    s.website || null,
    "",
    "אם הפנייה לא רלוונטית, השיבו \"הסר\" ולא אפנה אליכם שוב.",
  ].filter((l) => l !== null);

  return { subject, body: lines.join("\n") };
}

// קישורים שעוזרים למצוא איש קשר. רק קישורי חיפוש שהמשתמש לוחץ עליהם, בלי סריקה אוטומטית.
export function contactLinks(ad, c) {
  const who = ad.companyHidden ? c.endClientHint : ad.company;
  if (!who) return [];
  const q = (s) => encodeURIComponent(s);
  return [
    { label: "אתר החברה", url: `https://www.google.com/search?q=${q(`${who} אתר רשמי`)}` },
    { label: "מנהל/ת שיווק בלינקדאין", url: `https://www.google.com/search?q=${q(`site:linkedin.com/in "${who}" (שיווק OR marketing OR מנכ"ל OR CEO)`)}` },
    { label: "מייל ליצירת קשר", url: `https://www.google.com/search?q=${q(`"${who}" צור קשר מייל`)}` },
  ];
}

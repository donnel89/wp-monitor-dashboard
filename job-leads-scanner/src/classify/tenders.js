// סיווג מכרזים: האם הנושא הוא שירות שאנחנו מספקים, האם אפשר עוד להגיש, וכמה הוא מתאים.

// נושאים שאנחנו מספקים
const SERVICE_RE =
  /קמפיי?ן|שירותי פרסום|פרסום (דיגיטלי|ברשת|ממומן|ושיווק)|שיווק|ניו ?מדיה|רשתות (ה)?חברתיות|סושיאל|מדיה (דיגיטלית|חברתית)|תכנון (ו)?רכש מדיה|רכש מדיה|דיגיטל(?!י(ים|ות)? (שוברים|תשורה))|קריאייטיב|מיתוג|אתר (ה)?אינטרנט|בניית אתר|הקמת אתר|פיתוח אתר|דפי? נחיתה|תוכן שיווקי|כתיבת תוכן|קופירייט|הסברה|יחסי ציבור|SEO|PPC|קידום (אתרים|אורגני|ממומן)|עיצוב גרפי|גרפיקה|הפקת (וידאו|וידיאו|סרטונים|תוכן)|מדיה והפקה|שירותי (מדיה|הפקה)|(ייעוץ|אסטרטגי)[^|]{0,30}מדיה/i;

// נושאים שנשמעים דומה אבל לא שלנו
const NOT_OURS_RE =
  /ניטור|רישוי|רשיונות|מחשב|שובר|תשורה|חניה|חניון|הדפסה|דפוס|תחזוקה|ביומטר|פורנזי|מודיעין|ציוד|רכבים|BPM|תקשורת נתונים|NPB|ענן|סייבר הגנ|שילוט|מבנים|ניקיון|הסעות|ביטוח|ייעוץ משפטי|פרסום כוונה|פרסום הודעה|פרסום תוצאות|סקירת (ה)?מדיה|בקרת פרסום|לשכות המידע|הכוונת תיירים|פיקוח|עבודות פיתוח|אוהלים/;

// סוגי הליך: מה פתוח לכל ספק ומה כבר נסגר מול ספק ספציפי
const OPEN_TYPES = /מכרז פומבי|מכרז זוטא|RFI|בקשה לקבלת מידע|קול קורא|הליך תחרותי|הזמנה להציע|מכרז מסגרת|מאגר/;
const SEMI_OPEN_TYPES = /כוונה להתקשרות/; // אפשר להגיש התנגדות ולהציע חלופה עד המועד האחרון
const CLOSED_TYPES = /פטור|הארכת|מימוש אופציה|הרחבת התקשרות/;
const CLOSED_STATUS = /חלף|בוטל|נסגר|הסתיים|זוכה/;

const NEEDS = [
  [/קמפיי?ן|ממומן|רכש מדיה|PPC/i, "קמפיינים"],
  [/רשתות|סושיאל|ניו ?מדיה/, "סושיאל"],
  [/אתר|דפי? נחיתה/, "אתר"],
  [/עיצוב|גרפ|קריאייטיב|מיתוג/, "עיצוב/קריאייטיב"],
  [/תוכן|קופי|כתיבה/, "תוכן וקופי"],
  [/וידאו|וידיאו|סרטונ|הפקה/, "וידאו"],
  [/SEO|אורגני/i, "SEO"],
];

export function classifyTender(t) {
  const text = `${t.title} ${t.topics ?? ""}`;
  const reasons = [];
  const daysLeft = t.deadline ? (Date.parse(t.deadline) - Date.now()) / 864e5 : null;

  const relevantTopic = SERVICE_RE.test(text) && !NOT_OURS_RE.test(t.title);
  const closed =
    CLOSED_TYPES.test(t.tenderType) ||
    CLOSED_TYPES.test(t.title.slice(0, 25)) ||
    CLOSED_STATUS.test(t.status ?? "") ||
    (daysLeft !== null && daysLeft < 0);
  const relevant = relevantTopic && !closed;

  if (!relevantTopic) reasons.push("הנושא אינו שירות שיווק/פרסום/דיגיטל שאנחנו מספקים");
  if (closed) reasons.push(daysLeft !== null && daysLeft < 0 ? "המועד להגשה חלף" : `ההליך סגור (${t.tenderType || t.status})`);

  let score = 0;
  if (relevant) {
    score = 50;
    if (OPEN_TYPES.test(t.tenderType)) (score += 25), reasons.push(`הליך פתוח לכל ספק (${t.tenderType})`);
    else if (SEMI_OPEN_TYPES.test(t.tenderType))
      (score -= 15), reasons.push("כוונה להתקשרות עם ספק מסוים. אפשר להגיש התנגדות ולהציע חלופה עד המועד האחרון");
    if (/קמפיי?ן|דיגיטל|רשתות|ניו ?מדיה|ממומן|רכש מדיה|דפי? נחיתה|אתר/.test(t.title))
      (score += 15), reasons.push("ליבת השירות שלנו: דיגיטל / קמפיינים / אתרים");
    if (daysLeft === null) (score -= 10), reasons.push("לא צוין מועד אחרון. צריך לוודא שהמכרז עדיין פתוח");
    else if (daysLeft < 3) (score -= 25), reasons.push(`נשארו פחות מ-3 ימים להגשה`);
    else if (daysLeft < 7) (score -= 10), reasons.push(`נשארו ${Math.floor(daysLeft)} ימים להגשה`);
    else (score += 10), reasons.push(`נשארו ${Math.floor(daysLeft)} ימים להגשה`);
    if (t.contactEmail) score += 5;
    score = Math.max(0, Math.min(100, score));
  }

  return {
    method: "rules",
    kind: "tender",
    relevant,
    posterType: "direct",
    employerType: "business",
    category: relevant ? "tender" : "irrelevant",
    industry: "מגזר ציבורי",
    needs: [...new Set(NEEDS.filter(([re]) => re.test(text)).map(([, n]) => n))],
    endClientHint: null,
    score,
    daysLeft: daysLeft === null ? null : Math.floor(daysLeft),
    reasons,
  };
}

// פנייה למפרסם המכרז: הבעת עניין ובקשה לקבל עדכונים והבהרות. לא הצעה שיווקית.
export function draftTenderEmail(t, sender) {
  const ref = [t.procedureNo && `מס' הליך ${t.procedureNo}`, `מס' פרסום ${t.number}`].filter(Boolean).join(", ");
  return {
    subject: `${t.title.slice(0, 80)} (${ref}): הבעת עניין`,
    body: [
      `שלום${t.contactName ? ` ${t.contactName}` : ""},`,
      "",
      `אנחנו ב-${sender.agencyName} מתעניינים בהליך "${t.title}" (${ref}), ומתכוונים להגיש הצעה.`,
      "",
      "נשמח לקבל:",
      "• את כל מסמכי ההליך והעדכונים, אם יש כאלה שלא פורסמו באתר",
      "• הודעה על כנס ספקים או סיור מציעים, אם מתקיים",
      "• אישור שכתובת המייל הזו רשומה לקבלת תשובות לשאלות הבהרה",
      "",
      `${sender.agencyName} היא סוכנות שיווק דיגיטלי שמספקת צוות מלא: ניהול קמפיינים, עיצוב וקריאייטיב, בניית אתרים ודפי נחיתה, ותוכן.`,
      "",
      "בברכה,",
      sender.senderName,
      sender.agencyName,
      sender.phone || null,
      sender.website || null,
    ]
      .filter((l) => l !== null)
      .join("\n"),
  };
}

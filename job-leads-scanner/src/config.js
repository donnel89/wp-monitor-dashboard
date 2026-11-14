// הגדרות הסורק. אפשר לדרוס כל ערך דרך config.local.json בתיקיית הפרויקט.
import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");

const defaults = {
  // מילות חיפוש שנשלחות לאתרי הדרושים
  keywords: [
    "קמפיינר",
    "קמפיינרית",
    "PPC",
    "מנהל קמפיינים",
    "ניהול קמפיינים",
    "שיווק דיגיטלי",
    "מנהל שיווק דיגיטלי",
    "Performance",
    "SEM",
    "פרפורמנס",
  ],

  // מילות חיפוש למכרזים ב-mr.gov.il
  tenderQueries: [
    "קמפיין",
    "שירותי פרסום",
    "שיווק",
    "דיגיטל",
    "רשתות חברתיות",
    "ניו מדיה",
    "מדיה",
    "אתר אינטרנט",
    "מיתוג",
    "הסברה",
    "תוכן",
  ],

  // עמודי מכרזים של גופים ספציפיים (עיריות, חברות ממשלתיות, אוניברסיטאות, תאגידים).
  // הכלי סורק את הקישורים בעמוד ומוסיף כליד כל מכרז שהכותרת שלו בתחום שלנו.
  // הוסיפו כאן כל עמוד שרוצים לעקוב אחריו.
  tenderPages: [
    { name: "רשות החדשנות", url: "https://innovationisrael.org.il/bid/" },
    { name: "אוניברסיטת תל אביב", url: "https://tenders.tau.ac.il/" },
    { name: "הרשות לפיתוח ירושלים", url: "https://www.jda.gov.il/tenders/" },
  ],

  // סנכרון לבורד במאנדיי. הטוקן נקרא מ-MONDAY_API_TOKEN (לא לשמור אותו כאן).
  monday: {
    boardId: 18439955656,
    minScore: 45, // רק לידים מהציון הזה ומעלה נכנסים לבורד
    assigneeId: 95427431, // Maoz Moshe Olamy
    groups: {
      tender: "topics", // 🏛️ מכרזים פתוחים
      hot: "group_mm93e7pj", // 🔥 משרות – לידים חמים
      other: "group_mm93d56x", // משרות – לידים נוספים
    },
    columns: {
      status: "color_mm932nf7",
      assignee: "multiple_person_mm93gxc1",
      kind: "color_mm939znr",
      score: "numeric_mm93zz6q",
      company: "text_mm93agt2",
      endClient: "text_mm93x48h",
      contactName: "text_mm936x48",
      email: "email_mm93qajh",
      deadline: "date_mm93z2re",
      url: "link_mm93exz4",
      contactSearch: "link_mm937jcm",
      industry: "text_mm9349hz",
      city: "text_mm93jsk0",
      needs: "dropdown_mm93ca68",
      publishedAt: "date_mm93fvnd",
      reasons: "long_text_mm939v87",
      emailSubject: "text_mm93gjx4",
      emailBody: "long_text_mm93nc0y",
      leadId: "text_mm93rayb",
    },
  },

  // השהיה בין בקשות (מילישניות) כדי לא להעמיס על האתרים
  requestDelayMs: 1500,

  // מודעות ישנות מזה לא נכנסות לדוח
  maxAgeDays: 30,

  // ציון מינימלי כדי שליד יוצג בלשונית "לידים חמים"
  hotLeadScore: 60,

  // פרטי השולח ונוסח ההצעה. ערכו ב-config.local.json
  sender: {
    agencyName: "STRA",
    senderName: "מעוז",
    phone: "",
    website: "",
  },

  // מודל Claude לסיווג ולמיילים (רק כש-ANTHROPIC_API_KEY מוגדר)
  claude: {
    model: "claude-opus-5-5",
    effort: "low",
  },
};

function deepMerge(base, extra) {
  const out = { ...base };
  for (const [k, v] of Object.entries(extra ?? {})) {
    out[k] =
      v && typeof v === "object" && !Array.isArray(v) && typeof base[k] === "object"
        ? deepMerge(base[k], v)
        : v;
  }
  return out;
}

const localPath = join(ROOT, "config.local.json");
export const config = existsSync(localPath)
  ? deepMerge(defaults, JSON.parse(readFileSync(localPath, "utf8")))
  : defaults;

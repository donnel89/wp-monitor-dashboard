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

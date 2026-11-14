// סיווג עם Claude. רץ רק כש-ANTHROPIC_API_KEY מוגדר (או פרופיל של `ant auth login`).
// מקבל גם את תוצאת החוקים כרמז, ומחזיר את אותו מבנה כמו classifyByRules.
import Anthropic from "@anthropic-ai/sdk";
import { betaJSONSchemaOutputFormat } from "@anthropic-ai/sdk/helpers/beta/json-schema";
import { config } from "../config.js";
import { categoryOf } from "./rules.js";

const SCHEMA = {
  type: "object",
  additionalProperties: false,
  required: [
    "relevant",
    "posterType",
    "employerType",
    "industry",
    "endClientGuess",
    "needs",
    "score",
    "reasons",
    "pitchAngle",
  ],
  properties: {
    relevant: {
      type: "boolean",
      description: "האם התפקיד הוא תפקיד שיווק דיגיטלי/קמפיינים שסוכנות חיצונית יכולה לספק כשירות",
    },
    posterType: { type: "string", enum: ["direct", "recruiter", "hidden"] },
    employerType: { type: "string", enum: ["business", "agency", "unknown"] },
    industry: { type: ["string", "null"] },
    endClientGuess: {
      type: ["string", "null"],
      description: "כשהמפרסם הוא חברת השמה או חסוי: תיאור או ניחוש של המעסיק האמיתי, אחרת null",
    },
    needs: {
      type: "array",
      items: { type: "string" },
      description: "שירותים שהמודעה מרמזת שהם צריכים מעבר לקמפיינים: דפי נחיתה, עיצוב, תוכן, אתר, סושיאל, אוטומציות וכו'",
    },
    score: {
      type: "integer",
      description: "0-100: כמה סביר שהעסק יעדיף מיקור חוץ לסוכנות במקום גיוס. 0 לסוכנויות ולא רלוונטי",
    },
    reasons: { type: "array", items: { type: "string" }, description: "2-4 נימוקים קצרים בעברית" },
    pitchAngle: {
      type: "string",
      description: "משפט אחד בעברית: הזווית הכי חזקה לפנייה לחברה הזו",
    },
  },
};

const SYSTEM = `אתה מנתח מודעות דרושים בישראל עבור סוכנות שיווק דיגיטלי שמציעה צוות מלא במיקור חוץ: קמפיינר, מעצב, מפתח דפי נחיתה, קופירייטר.
המטרה: למצוא עסקים שמגייסים קמפיינר או איש שיווק דיגיטלי לעבודה פנימית, ושאפשר להציע להם את השירות במקום הגיוס.

הגדרות:
- posterType: "direct" אם החברה המפרסמת היא המעסיקה. "recruiter" אם מדובר בחברת השמה או במפרסם שמגייס "עבור לקוח". "hidden" אם שם המפרסם חסוי.
- employerType: "agency" אם המעסיק בפועל הוא סוכנות, משרד פרסום, חברת דיגיטל או משרד קידום שמשרת לקוחות. אלה מתחרים ולא לידים. "business" אם זה עסק שמשווק את עצמו. שים לב: משפט כמו "עבודה מול משרד הפרסום" או "מעבר ממשרד פרסום לצד הלקוח" מעיד על business.
- score: ציון גבוה לעסקים קטנים ובינוניים שמגייסים תפקיד ביצועי (קמפיינים, PPC, דיגיטל), במיוחד כשהמודעה מבקשת גם עיצוב, דפי נחיתה או תוכן. ציון נמוך לארגונים גדולים, מוסדות ציבוריים ותפקידי הנהלה בכירים. 0 לסוכנויות ולמשרות לא רלוונטיות.
ענה בעברית.`;

let client;
function getClient() {
  client ??= new Anthropic();
  return client;
}

export async function classifyWithClaude(ad, rulesResult) {
  const response = await getClient().beta.messages.parse({
    model: config.claude.model,
    max_tokens: 2000,
    betas: ["server-side-fallback-2026-07-01"],
    fallbacks: "default",
    output_config: {
      effort: config.claude.effort,
      format: betaJSONSchemaOutputFormat(SCHEMA),
    },
    system: SYSTEM,
    messages: [
      {
        role: "user",
        content: [
          `מפרסם: ${ad.company || "(ריק)"}${ad.companyHidden ? " (חסוי)" : ""}`,
          `כותרת: ${ad.title}`,
          `עיר: ${ad.city}`,
          `תיאור:\n${ad.description}`,
          "",
          `רמז מסיווג אוטומטי לפי חוקים (עלול לטעות): ${rulesResult.category}, ${rulesResult.reasons.join("; ")}`,
        ].join("\n"),
      },
    ],
  });

  if (response.stop_reason === "refusal" || !response.parsed_output) {
    throw new Error(`Claude לא החזיר סיווג (stop_reason=${response.stop_reason})`);
  }
  const out = response.parsed_output;
  return {
    method: "claude",
    relevant: out.relevant,
    posterType: out.posterType,
    employerType: out.employerType,
    category: categoryOf(out),
    industry: out.industry ?? rulesResult.industry,
    needs: out.needs,
    endClientHint: out.endClientGuess,
    score: out.relevant && out.employerType !== "agency" ? out.score : 0,
    reasons: out.reasons,
    pitchAngle: out.pitchAngle,
  };
}

export function hasClaudeCredentials() {
  return Boolean(process.env.ANTHROPIC_API_KEY || process.env.ANTHROPIC_AUTH_TOKEN || process.env.ANTHROPIC_PROFILE);
}

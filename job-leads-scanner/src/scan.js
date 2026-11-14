#!/usr/bin/env node
// שימוש:
//   node src/scan.js                 סריקה + סיווג + דוח
//   node src/scan.js --no-ai         בלי Claude, רק חוקים
//   node src/scan.js --report-only   רק לבנות מחדש את הדוח מהנתונים השמורים
//   node src/scan.js --keywords "קמפיינר,PPC"
//   node src/scan.js --add ads.json  הוספת מודעות ידנית (לינקדאין, פייסבוק וכו')
//   node src/scan.js --reclassify    לסווג מחדש גם מודעות שכבר סווגו
//   node src/scan.js --no-jobs       בלי מודעות דרושים (רק מכרזים)
//   node src/scan.js --no-tenders    בלי מכרזים (רק מודעות דרושים)
//   node src/scan.js --no-monday     בלי סנכרון למאנדיי (גם כשיש MONDAY_API_TOKEN)
//   node src/scan.js --export-monday out.json   שמירת הפריטים שממתינים למאנדיי לקובץ (לבדיקה)
import { readFileSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import { join } from "node:path";
import { config, ROOT } from "./config.js";
import { searchDrushim } from "./sources/drushim.js";
import { classifyByRules } from "./classify/rules.js";
import { classifyWithClaude, hasClaudeCredentials } from "./classify/claude.js";
import { draftEmail, contactLinks } from "./email.js";
import { renderReport } from "./report.js";
import { searchTenders, fetchTenderDetails } from "./sources/mrgov.js";
import { scanTenderPage } from "./sources/tenderPages.js";
import { classifyTender, draftTenderEmail } from "./classify/tenders.js";
import { syncToMonday, shouldSync, createItemVariables, itemName } from "./monday.js";

const args = process.argv.slice(2);
const flag = (name) => args.includes(name);
const opt = (name) => {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : undefined;
};

const DATA_DIR = join(ROOT, "data");
const STORE = join(DATA_DIR, "leads.json");
mkdirSync(DATA_DIR, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const log = (...a) => console.log(...a);

function loadStore() {
  return existsSync(STORE) ? JSON.parse(readFileSync(STORE, "utf8")) : {};
}

function loadManualAds(path) {
  // JSON: [{ company, title, description, url, city? }]
  const list = JSON.parse(readFileSync(path, "utf8"));
  return list.map((a, i) => ({
    id: a.id ?? `manual:${a.url ?? `${a.company}-${a.title}-${i}`}`,
    source: a.source ?? "manual",
    title: a.title ?? "",
    company: a.company ?? "",
    companyHidden: !a.company || /חסוי/.test(a.company),
    city: a.city ?? "",
    description: a.description ?? "",
    experience: "",
    scope: "",
    publishedAt: a.publishedAt ?? new Date().toISOString(),
    url: a.url ?? "",
  }));
}

async function collect() {
  const keywords = opt("--keywords")?.split(",").map((s) => s.trim()).filter(Boolean) ?? config.keywords;
  const found = new Map();
  for (const kw of keywords) {
    try {
      const ads = await searchDrushim(kw);
      log(`  drushim · "${kw}": ${ads.length} מודעות`);
      for (const ad of ads) {
        const prev = found.get(ad.id);
        found.set(ad.id, { ...ad, keywords: [...new Set([...(prev?.keywords ?? []), kw])] });
      }
    } catch (e) {
      log(`  ⚠ drushim · "${kw}": ${e.message}`);
    }
    await sleep(config.requestDelayMs);
  }
  const manual = opt("--add");
  if (manual) {
    const ads = loadManualAds(manual);
    log(`  ידני · ${manual}: ${ads.length} מודעות`);
    for (const ad of ads) found.set(ad.id, { ...ad, keywords: ["ידני"] });
  }
  return [...found.values()];
}

// ---------- מכרזים ----------
const GOV_DOMAINS = {
  "mof.gov.il": "משרד האוצר",
  "health.gov.il": "משרד הבריאות",
  "education.gov.il": "משרד החינוך",
  "tourism.gov.il": "משרד התיירות",
  "energy.gov.il": "משרד האנרגיה והתשתיות",
  "moag.gov.il": "משרד החקלאות",
  "lapam.gov.il": "לשכת הפרסום הממשלתית (לפ\"מ)",
  "mot.gov.il": "משרד התחבורה",
  "justice.gov.il": "משרד המשפטים",
  "molsa.gov.il": "משרד הרווחה",
  "mfa.gov.il": "משרד החוץ",
};
function publisherOf(t) {
  if (t.publisher) return t.publisher;
  const domain = (t.contactEmail ?? "").split("@")[1] ?? "";
  if (GOV_DOMAINS[domain]) return `${GOV_DOMAINS[domain]}${t.contactName ? ` – ${t.contactName}` : ""}`;
  return t.contactName || (domain ? `גוף ציבורי (${domain})` : "גוף ציבורי");
}

function tenderToLead(t, classification, prev, now) {
  return {
    ...prev,
    id: t.id,
    kind: "tender",
    source: t.id.startsWith("mrgov:") ? "mr.gov.il" : t.publisher,
    title: t.title,
    company: publisherOf(t),
    companyHidden: false,
    city: "",
    description: [t.tenderType, t.procedureNo && `מס' הליך ${t.procedureNo}`, t.status, t.topics && `נושאים: ${t.topics}`]
      .filter(Boolean)
      .join(" · "),
    publishedAt: t.publishedAt,
    deadline: t.deadline,
    url: t.url,
    submitUrl: t.submitUrl || "",
    tenderType: t.tenderType,
    procedureNo: t.procedureNo,
    number: t.number,
    contactName: t.contactName || "",
    contactEmail: t.contactEmail || "",
    firstSeen: prev?.firstSeen ?? now,
    lastSeen: now,
    classification,
    contactLinks: t.submitUrl ? [{ label: "הגשת שאלות והצעות", url: t.submitUrl }] : [],
    email: classification.relevant ? draftTenderEmail(t, config.sender) : null,
  };
}

async function collectTenders(store, now) {
  const found = new Map();
  for (const q of config.tenderQueries) {
    try {
      const list = await searchTenders(q);
      log(`  mr.gov.il · "${q}": ${list.length} פרסומים`);
      for (const t of list) found.set(t.id, t);
    } catch (e) {
      log(`  ⚠ mr.gov.il · "${q}": ${e.message}`);
    }
    await sleep(config.requestDelayMs);
  }
  for (const page of config.tenderPages) {
    try {
      const list = await scanTenderPage(page);
      log(`  ${page.name}: ${list.length} קישורים למכרזים`);
      for (const t of list) found.set(t.id, t);
    } catch (e) {
      log(`  ⚠ ${page.name}: ${e.message}`);
    }
    await sleep(config.requestDelayMs);
  }

  let added = 0;
  for (const t of found.values()) {
    const prev = store[t.id];
    if (prev && !flag("--reclassify")) {
      prev.lastSeen = now;
      continue;
    }
    // סינון ראשון לפי הכותרת, ורק אז כניסה לעמוד המכרז לפרטי קשר
    if (!classifyTender(t).relevant) continue;
    if (t.id.startsWith("mrgov:")) {
      try {
        // לא דורסים ערך קיים בשדה ריק מעמוד הפרטים
        for (const [k, v] of Object.entries(await fetchTenderDetails(t))) if (v) t[k] = v;
      } catch (e) {
        log(`  ⚠ פרטי מכרז ${t.number}: ${e.message}`);
      }
      await sleep(config.requestDelayMs / 2);
    }
    const classification = classifyTender(t);
    if (!classification.relevant) continue;
    store[t.id] = tenderToLead(t, classification, prev, now);
    if (!prev) added++;
  }
  return added;
}

async function classify(ad, useAI) {
  const rules = classifyByRules(ad);
  // שולחים ל-Claude רק מה שהחוקים לא פסלו כלא רלוונטי לגמרי, כדי לחסוך עלות
  if (!useAI || (!rules.relevant && rules.reasons.some((r) => r.startsWith("לא נמצאו")))) return rules;
  try {
    return await classifyWithClaude(ad, rules);
  } catch (e) {
    log(`  ⚠ Claude נכשל על "${ad.title}": ${e.message}. משתמש בחוקים`);
    return rules;
  }
}

// חברה שזוהתה כסוכנות באחת המודעות שלה היא סוכנות גם בשאר המודעות
function propagateAgencies(store) {
  const leads = Object.values(store);
  const agencies = new Set(
    leads
      .filter((l) => !l.companyHidden && l.classification.category === "agency")
      .map((l) => l.company)
  );
  for (const l of leads) {
    const c = l.classification;
    if (l.companyHidden || !agencies.has(l.company) || c.category === "agency" || c.category === "irrelevant") continue;
    l.classification = {
      ...c,
      employerType: "agency",
      category: "agency",
      score: 0,
      reasons: [...c.reasons, "החברה זוהתה כסוכנות במודעה אחרת שלה"],
    };
    l.email = null;
  }
}

function toCsv(leads) {
  const cols = ["ציון", "קטגוריה", "חברה", "רמז ללקוח סופי", "כותרת", "עיר", "תעשייה", "צרכים", "פורסם", "קישור", "נושא מייל"];
  const esc = (v) => `"${String(v ?? "").replace(/"/g, '""')}"`;
  const rows = leads.map((l) => [
    l.classification.score,
    l.classification.category,
    l.company,
    l.classification.endClientHint,
    l.title,
    l.city,
    l.classification.industry,
    (l.classification.needs ?? []).join(" / "),
    l.publishedAt?.slice(0, 10),
    l.url,
    l.email?.subject,
  ]);
  return "﻿" + [cols, ...rows].map((r) => r.map(esc).join(",")).join("\n");
}

async function main() {
  const store = loadStore();
  const now = new Date().toISOString();

  if (!flag("--report-only")) {
    const useAI = !flag("--no-ai") && hasClaudeCredentials();
    log(`סורק... (סיווג: ${useAI ? `Claude (${config.claude.model})` : "חוקים בלבד"})`);
    const ads = flag("--no-jobs") ? [] : await collect();

    // כפילויות: אותה חברה ואותה כותרת שמתפרסמות כמה פעמים (למשל בכמה ערים)
    const seenKeys = new Set(
      Object.values(store).filter((l) => !l.companyHidden).map((l) => `${l.company}|${l.title}`)
    );
    let added = 0;
    for (const ad of ads) {
      const existing = store[ad.id];
      const dupKey = `${ad.company}|${ad.title}`;
      if (!existing && !ad.companyHidden && seenKeys.has(dupKey)) continue;
      seenKeys.add(dupKey);

      if (existing && !flag("--reclassify")) {
        existing.lastSeen = now;
        existing.keywords = [...new Set([...(existing.keywords ?? []), ...(ad.keywords ?? [])])];
        continue;
      }
      const classification = await classify(ad, useAI);
      const lead = {
        ...existing,
        ...ad,
        firstSeen: existing?.firstSeen ?? now,
        lastSeen: now,
        classification,
        contactLinks: contactLinks(ad, classification),
        email:
          classification.score > 0 && ["direct", "via-recruiter"].includes(classification.category)
            ? draftEmail(ad, classification)
            : null,
      };
      store[ad.id] = lead;
      if (!existing) added++;
    }
    log(`נוספו ${added} מודעות דרושים חדשות`);

    if (!flag("--no-tenders")) {
      log("סורק מכרזים...");
      const addedTenders = await collectTenders(store, now);
      log(`נוספו ${addedTenders} מכרזים רלוונטיים חדשים`);
    }
    writeFileSync(STORE, JSON.stringify(store, null, 1));
    log(`סה"כ במאגר: ${Object.keys(store).length}`);
  }

  propagateAgencies(store);

  const cutoff = Date.now() - config.maxAgeDays * 864e5;
  const leads = Object.values(store)
    .filter((l) =>
      l.kind === "tender"
        ? !l.deadline || Date.parse(l.deadline) >= Date.now() // מכרז מוצג עד המועד האחרון
        : !l.publishedAt || Date.parse(l.publishedAt) >= cutoff
    )
    .sort((a, b) => b.classification.score - a.classification.score);

  writeFileSync(join(DATA_DIR, "leads.csv"), toCsv(leads.filter((l) => l.classification.score > 0)));
  const reportPath = join(ROOT, "report.html");
  writeFileSync(reportPath, renderReport(leads, { generatedAt: now, hotLeadScore: config.hotLeadScore }));

  // ---------- מאנדיי ----------
  const exportPath = opt("--export-monday");
  if (exportPath) {
    const pending = leads.filter((l) => shouldSync(l) && !l.mondayItemId);
    writeFileSync(
      exportPath,
      JSON.stringify(pending.map((l) => ({ leadId: l.id, name: itemName(l), variables: createItemVariables(l) })), null, 1)
    );
    log(`נשמרו ${pending.length} פריטים שממתינים למאנדיי: ${exportPath}`);
  } else if (!flag("--no-monday") && !flag("--report-only")) {
    const created = await syncToMonday(leads, log);
    if (created) log(`📋 נוספו ${created} לידים חדשים לבורד במאנדיי`);
    writeFileSync(STORE, JSON.stringify(store, null, 1));
  }

  const by = (cat) => leads.filter((l) => l.classification.category === cat).length;
  const hot = leads.filter((l) => l.kind !== "tender" && l.classification.score >= config.hotLeadScore).length;
  log("");
  log(`🔥 לידים חמים (ציון ${config.hotLeadScore}+): ${hot}`);
  log(`🏢 חברות ישירות: ${by("direct")}`);
  log(`🤝 דרך השמה / חסוי: ${by("via-recruiter")}`);
  log(`🏛️ מכרזים פתוחים: ${by("tender")}`);
  log(`🚫 סוכנויות (סוננו): ${by("agency")}`);
  log(`·  לא רלוונטי: ${by("irrelevant")}`);
  log("");
  log(`הדוח: ${reportPath}`);
  log(`CSV (לייבוא ל-monday / אקסל): ${join(DATA_DIR, "leads.csv")}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

#!/usr/bin/env node
// שימוש:
//   node src/scan.js                 סריקה + סיווג + דוח
//   node src/scan.js --no-ai         בלי Claude, רק חוקים
//   node src/scan.js --report-only   רק לבנות מחדש את הדוח מהנתונים השמורים
//   node src/scan.js --keywords "קמפיינר,PPC"
//   node src/scan.js --add ads.json  הוספת מודעות ידנית (לינקדאין, פייסבוק וכו')
//   node src/scan.js --reclassify    לסווג מחדש גם מודעות שכבר סווגו
import { readFileSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import { join } from "node:path";
import { config, ROOT } from "./config.js";
import { searchDrushim } from "./sources/drushim.js";
import { classifyByRules } from "./classify/rules.js";
import { classifyWithClaude, hasClaudeCredentials } from "./classify/claude.js";
import { draftEmail, contactLinks } from "./email.js";
import { renderReport } from "./report.js";

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
    const ads = await collect();

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
    writeFileSync(STORE, JSON.stringify(store, null, 1));
    log(`נוספו ${added} מודעות חדשות. סה"כ במאגר: ${Object.keys(store).length}`);
  }

  propagateAgencies(store);

  const cutoff = Date.now() - config.maxAgeDays * 864e5;
  const leads = Object.values(store)
    .filter((l) => !l.publishedAt || Date.parse(l.publishedAt) >= cutoff)
    .sort((a, b) => b.classification.score - a.classification.score);

  writeFileSync(join(DATA_DIR, "leads.csv"), toCsv(leads.filter((l) => l.classification.score > 0)));
  const reportPath = join(ROOT, "report.html");
  writeFileSync(reportPath, renderReport(leads, { generatedAt: now, hotLeadScore: config.hotLeadScore }));

  const by = (cat) => leads.filter((l) => l.classification.category === cat).length;
  const hot = leads.filter((l) => l.classification.score >= config.hotLeadScore).length;
  log("");
  log(`🔥 לידים חמים (ציון ${config.hotLeadScore}+): ${hot}`);
  log(`🏢 חברות ישירות: ${by("direct")}`);
  log(`🤝 דרך השמה / חסוי: ${by("via-recruiter")}`);
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
